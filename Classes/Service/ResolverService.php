<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2025 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>, Academy of Sciences and Literature | Mainz
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace Digicademy\TypoGraph\Service;

use Digicademy\TypoGraph\Comparator\ComparatorInterface;
use Digicademy\TypoGraph\Transformer\TransformerRegistry;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\{
    ListOfType,
    NonNull,
    ObjectType,
    ResolveInfo
};
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @phpstan-type RelationConfig array{
 *     storageType?: string,
 *     sourceField?: string,
 *     targetType?: string,
 *     mmTable?: string,
 *     foreignKeyField?: string,
 *     mmSourceField?: string,
 *     mmTargetField?: string,
 *     mmSortingField?: string,
 * }
 */
class ResolverService
{
    protected const CACHE_IDENTIFIER = 'typograph_cached_schema';

    /**
     * @var array<string>
     */
    protected array $schemaFiles;

    /**
     * @var array<string, string>
     */
    protected array $tableMapping;

    /**
     * @var array<string, RelationConfig>
     */
    protected array $relations;

    /**
     * Configured post-fetch field transforms, keyed by GraphQL type name
     * and then by GraphQL field name. The value is the short transform
     * name used to look an implementation up in {@see TransformerRegistry}.
     *
     * @var array<string, array<string, string>>
     */
    protected array $fieldTransforms;

    /**
     * The PSR-7 request currently being processed. Set by {@see process()}
     * and cleared on return so that transformers can read request
     * attributes (Site, language, routing) via the registry's contract
     * without having to pipe the request through every resolver path.
     */
    protected ?ServerRequestInterface $currentRequest = null;

    /**
     * @var Schema|null
     */
    protected ?Schema $schema = null;

    /**
     * Batch loader cache for relations to avoid N+1 queries
     * @var array<string, array<int|string, mixed>|null>
     */
    protected array $batchCache = [];

    protected int $defaultLimit;

    protected int $maxLimit;

    /**
     * Reserved argument names that must not be treated as WHERE conditions.
     * Includes pagination args and the optional sortBy argument.
     */
    protected const RESERVED_ARGS = ['first', 'after', 'sortBy'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FrontendInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly TransformerRegistry $transformerRegistry,
        private readonly ?ComparatorInterface $comparator = null
    ) {
        $this->schemaFiles = [];
        $this->tableMapping = [];
        $this->relations = [];
        $this->fieldTransforms = [];
        $this->defaultLimit = 20;
        $this->maxLimit = 100;
    }

    /**
     * Configure the resolver from a settings array (e.g. from site configuration).
     *
     * @param array<string, mixed> $settings
     */
    public function configure(array $settings): void
    {
        $schemaFiles = $settings['schemaFiles'] ?? [];
        $this->schemaFiles = is_array($schemaFiles)
            ? array_values(array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $schemaFiles))
            : [];

        $tableMapping = $settings['tableMapping'] ?? [];
        $this->tableMapping = is_array($tableMapping)
            ? array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $tableMapping)
            : [];

        $relations = $settings['relations'] ?? [];
        $this->relations = $this->flattenRelationsConfig(is_array($relations) ? $relations : []);

        $fieldTransforms = $settings['fieldTransforms'] ?? [];
        $this->fieldTransforms = $this->normalizeFieldTransformsConfig(
            is_array($fieldTransforms) ? $fieldTransforms : []
        );

        $pagination = $settings['pagination'] ?? [];
        $pagination = is_array($pagination) ? $pagination : [];
        $rawDefault = $pagination['defaultLimit'] ?? null;
        $this->defaultLimit = is_numeric($rawDefault) ? (int)$rawDefault : 20;
        $rawMax = $pagination['maxLimit'] ?? null;
        $this->maxLimit = is_numeric($rawMax) ? (int)$rawMax : 100;

        $this->schema = null;
        $this->batchCache = [];
    }

    /**
     * Flatten nested TypoScript relation configuration into dot-separated keys
     *
     * TypoScript parses "Taxonomy.disciplines" as nested arrays:
     * ['Taxonomy' => ['disciplines' => [...]]]
     *
     * This method flattens it to:
     * ['Taxonomy.disciplines' => [...]]
     *
     * @param array<string, mixed> $relations
     * @return array<string, RelationConfig>
     */
    protected function flattenRelationsConfig(array $relations): array
    {
        $flattened = [];

        foreach ($relations as $typeName => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $fieldName => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $key = "{$typeName}.{$fieldName}";
                /** @var RelationConfig $config */
                $flattened[$key] = $config;
            }
        }

        return $flattened;
    }

    /**
     * Validate and normalize the raw `fieldTransforms` subtree coming
     * from site configuration into a strongly-typed two-level map
     * (`TypeName -> FieldName -> transformName`). Invalid or empty
     * entries are silently dropped — configuration errors surface later
     * as "transformer not registered" warnings when the field is
     * actually requested.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array<string, string>>
     */
    protected function normalizeFieldTransformsConfig(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $typeName => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $perType = [];
            foreach ($fields as $fieldName => $transformName) {
                if (
                    !is_string($fieldName)
                    || !is_string($transformName)
                    || $transformName === ''
                ) {
                    continue;
                }
                $perType[$fieldName] = $transformName;
            }
            if ($perType !== []) {
                $normalized[$typeName] = $perType;
            }
        }
        return $normalized;
    }

    /**
     * @param  string $json
     * @param  ServerRequestInterface|null $request The current PSR-7
     *     request. Optional for backwards compatibility: when omitted,
     *     post-fetch transforms are skipped because most of them rely
     *     on request-attached attributes (Site, language, routing).
     * @return string|null
     */
    public function process(string $json, ?ServerRequestInterface $request = null): ?string
    {
        $this->currentRequest = $request;

        try {
            return $this->processInternal($json);
        } finally {
            $this->currentRequest = null;
        }
    }

    /**
     * Body of {@see process()}, extracted so the outer method can own
     * the `$currentRequest` lifecycle via try/finally without wrapping
     * the whole execution block in an extra level of indentation.
     */
    protected function processInternal(string $json): ?string
    {
        $input = json_decode($json, true);

        if (!is_array($input) || !isset($input['query']) || !is_string($input['query'])) {
            $this->logger->error('Invalid JSON input or missing query field');
            return json_encode(null) ?: null;
        }

        $query = $input['query'];
        // `variables` is user-supplied JSON. GraphQL expects a map keyed
        // by variable name (string); filter out any numeric-keyed entries
        // that json_decode could produce, both for runtime safety and to
        // satisfy static analysis (`array<string, mixed>`).
        $rawVariables = $input['variables'] ?? null;
        $variableValues = null;
        if (is_array($rawVariables)) {
            $variableValues = [];
            foreach ($rawVariables as $key => $value) {
                if (is_string($key)) {
                    $variableValues[$key] = $value;
                }
            }
        }
        $schema = $this->getSchema();

        try {
            $rootFields = $this->getRootFieldNames($query);
            $result = GraphQL::executeQuery(
                $schema,
                $query,
                $rootFields,
                null,
                $variableValues,
                null,
                fn($root, array $args, $context, ResolveInfo $info) => $this->resolve($root, $args, $context, $info)
            );

            foreach ($result->errors as $error) {
                $previous = $error->getPrevious();
                $this->logger->error(
                    $error->getMessage(),
                    [
                        'path' => $error->path,
                        'previous' => $previous?->getMessage(),
                        'trace' => $previous?->getTraceAsString(),
                    ]
                );
            }

            $output = $result->toArray();
        } catch (\GraphQL\Error\Error $e) {
            // GraphQL-level errors (e.g. syntax errors) are returned as a
            // structured error response rather than silently swallowed.
            $this->logger->error($e->getMessage());
            $output = (new ExecutionResult(null, [$e]))->toArray();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $output = null;
        }

        return json_encode($output, JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * Method to get the query root field names from the GraphQL query string.
     * @param  string $query A GraphQL query string
     * @return array<string>
     */
    protected function getRootFieldNames(string $query): array
    {
        $ast = Parser::parse($query);
        $rootFields = [];

        foreach ($ast->definitions[0]->selectionSet->selections as $selection) {
            $rootFields[] = $selection->name->value;
        }

        return $rootFields;
    }

    /**
     * Method to get GrapQL schema. If the schema data are not cached yet, they
     * will be loaded from the available file(s) and written to the cache before
     * passed on. Otherwise, they'll be directly fetched from the cache.
     *
     * Developer note: because the TYPO3 cache can handle objects, we can store
     * the document object directly and do not have to convert to/from an array
     * like it is done in the graphql-php example.
     *
     * @see https://webonyx.github.io/graphql-php/schema-definition-language/#performance-considerations
     * @return Schema
     */
    protected function getSchema(): Schema
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        if (Environment::getContext()->isDevelopment()) {
            $document = Parser::parse($this->readSchemaFiles());
        } else {
            // If we have a cached schema, we're gonna use it.
            if ($this->cache->has(self::CACHE_IDENTIFIER)) {
                $cached = $this->cache->get(self::CACHE_IDENTIFIER);
                assert($cached instanceof DocumentNode);
                $document = $cached;
                // Otherwise we parse the schema file(s) and cache them before
                // proceeding.
            } else {
                $document = Parser::parse($this->readSchemaFiles());
                DocumentValidator::assertValidSDL($document);
                $this->cache->set(self::CACHE_IDENTIFIER, $document);
            }
        }

        $this->schema = BuildSchema::build($document);
        return $this->schema;
    }

    /**
     * Method to read schema data from all configured schema files and
     * concatenate them.
     *
     * @return string The concatenated schema data
     */
    protected function readSchemaFiles(): string
    {
        $schemaString = '';
        foreach ($this->schemaFiles as $schemaFile) {
            $schemaString .= file_get_contents(
                GeneralUtility::getFileAbsFileName($schemaFile)
            );
        }
        return $schemaString;
    }

    /**
     * Method to resolve GraphQL queries, using the TYPO3 query builder to fetch
     * relevant data from the database.
     *
     * Note that we do not use Extbase repositories and models here. Next to
     * flexibility of use cases, this is primarily due to difference in
     * performance: using domain repositories to fetch data takes about 60 times
     * longer and cannot be optimised if only certain fields are required by a
     * query.
     *
     * @param  array<array-key, mixed>  $root    Array of root field names or parent DB record
     * @param  array<string|int> $args    GraphQL query arguments
     * @param  array<mixed>      $context Context data (currently not in use)
     * @param  ResolveInfo       $info    Info about query details, e.g., fields
     *                                    queried for
     * @return mixed
     * @see https://docs.typo3.org/permalink/t3coreapi:database-query-builder
     */
    protected function resolve(
        array $root,
        array $args,
        $context,
        ResolveInfo $info
    ) {
        $rootTables = array_keys($this->tableMapping);

        // Root field resolution (Query.model)
        //
        // $root is the numeric array returned by getRootFieldNames(), which
        // signals we are at the Query level. isset($root[0]) distinguishes this
        // from nested resolver calls where $root is an associative DB record.
        // The actual field being resolved comes from $info->fieldName — using
        // $root[0] would always resolve the first root field's table, breaking
        // queries that request multiple root fields simultaneously.
        if (isset($root[0]) && in_array($info->fieldName, $rootTables)) {
            $rootTable = $this->tableMapping[$info->fieldName];
            $isConnection = $this->isConnectionType($info);

            // Detect whether the return type is a list (e.g. taxonomies) or a
            // singular nullable object (e.g. taxonomy). Unwrap one NonNull level
            // to reach ListOfType if present.
            $unwrappedReturnType = $info->returnType instanceof NonNull
                ? $info->returnType->getWrappedType()
                : $info->returnType;
            $isList = $unwrappedReturnType instanceof ListOfType;

            // Separate reserved args (pagination, sorting) from filter args
            $filterArgs = [];
            $paginationArgs = [];
            $sortByOverride = null;
            foreach ($args as $key => $value) {
                if ($key === 'sortBy') {
                    $sortByOverride = is_string($value) ? GeneralUtility::camelCaseToLowerCaseUnderscored($value) : null;
                } elseif (in_array($key, self::RESERVED_ARGS)) {
                    $paginationArgs[$key] = $value;
                } else {
                    $filterArgs[$key] = $value;
                }
            }

            try {
                $queryBuilder = $this->connectionPool
                    ->getQueryBuilderForTable($rootTable);

                // Determine which fields to SELECT
                if ($isConnection) {
                    $fields = $this->getConnectionFieldSelection($info);
                } else {
                    $fields = $this->getScalarFieldsFromSelection($info);
                }

                $queryBuilder
                    ->select(...$fields)
                    ->from($rootTable);

                // Build filter WHERE clauses from non-pagination args
                $conditions = [];
                foreach ($filterArgs as $key => $value) {
                    $conditions[] = $queryBuilder->expr()->eq(
                        GeneralUtility::camelCaseToLowerCaseUnderscored($key),
                        $queryBuilder->createNamedParameter($value)
                    );
                }

                if ($isConnection) {
                    // Cursor-based pagination
                    $first = isset($paginationArgs['first'])
                        ? min((int)$paginationArgs['first'], $this->maxLimit)
                        : $this->defaultLimit;
                    $afterCursor = isset($paginationArgs['after']) ? (string)$paginationArgs['after'] : null;

                    if ($afterCursor !== null) {
                        $afterUid = $this->decodeCursor($afterCursor);
                        $conditions[] = $queryBuilder->expr()->gt(
                            'uid',
                            $queryBuilder->createNamedParameter($afterUid)
                        );
                    }

                    if ($conditions !== []) {
                        $queryBuilder->where(...$conditions);
                    }

                    $queryBuilder
                        ->orderBy('uid', 'ASC')
                        ->setMaxResults($first + 1);

                    $records = $queryBuilder
                        ->executeQuery()
                        ->fetchAllAssociative();

                    // Sort records within the current page using the
                    // injected comparator. The extra lookahead record
                    // (used for hasNextPage detection) is excluded from
                    // sorting and re-appended afterward.
                    if (count($records) > $first) {
                        $pageRecords = array_slice($records, 0, $first);
                        $pageRecords = $this->applySorting($pageRecords, $sortByOverride);
                        $pageRecords = $this->applyFieldTransforms($pageRecords, $info);
                        // Preserve the extra lookahead record (used by
                        // buildConnectionResponse for `hasNextPage`) as an
                        // array rather than `end()`'s mixed|false, so
                        // PHPStan can type the merged list correctly.
                        $lookahead = array_slice($records, -1);
                        $records = array_merge($pageRecords, $lookahead);
                    } else {
                        $records = $this->applySorting($records, $sortByOverride);
                        $records = $this->applyFieldTransforms($records, $info);
                    }

                    // Determine totalCount only if requested in selection
                    $totalCount = 0;
                    $selection = $info->getFieldSelection(1);
                    if (isset($selection['totalCount'])) {
                        $countBuilder = $this->connectionPool
                            ->getQueryBuilderForTable($rootTable);
                        $countBuilder
                            ->count('uid')
                            ->from($rootTable);

                        // Apply same filter conditions (not cursor/limit)
                        $countConditions = [];
                        foreach ($filterArgs as $key => $value) {
                            $countConditions[] = $countBuilder->expr()->eq(
                                GeneralUtility::camelCaseToLowerCaseUnderscored($key),
                                $countBuilder->createNamedParameter($value)
                            );
                        }
                        if ($countConditions !== []) {
                            $countBuilder->where(...$countConditions);
                        }

                        $count = $countBuilder
                            ->executeQuery()
                            ->fetchOne();
                        $totalCount = is_int($count) || is_string($count) ? (int)$count : 0;
                    }

                    return $this->buildConnectionResponse($records, $totalCount, $afterCursor, $first);
                }

                // Plain list or singular object (non-connection)
                if ($conditions !== []) {
                    $queryBuilder->where(...$conditions);
                }

                if ($isList) {
                    $result = $queryBuilder
                        ->executeQuery()
                        ->fetchAllAssociative();
                    $result = $this->applySorting($result, $sortByOverride);
                    $result = $this->applyFieldTransforms($result, $info);
                } else {
                    $fetched = $queryBuilder
                        ->executeQuery()
                        ->fetchAssociative();
                    if ($fetched !== false) {
                        $transformed = $this->applyFieldTransforms([$fetched], $info);
                        $result = $transformed[0] ?? $fetched;
                    } else {
                        $result = null;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
                $result = $isList ? [] : null;
            }

            return $result;
        }

        // Nested field resolution (Model.foo, Model.bar, etc.)
        //
        // Connection/edge wrapper fields (edges, pageInfo, node, …) are pre-built
        // arrays stored directly in $root by buildConnectionResponse(). Return them
        // immediately so they are never mistaken for DB relations.
        $fieldName = $info->fieldName;
        if (array_key_exists($fieldName, $root) && is_array($root[$fieldName])) {
            return $root[$fieldName];
        }

        // Check if this field is a relation
        if ($this->isRelationField($info)) {
            return $this->resolveRelation($root, $info);
        }

        // Look up the field directly first (handles camelCase keys from
        // connection responses like pageInfo, hasNextPage, totalCount, etc.),
        // then fall back to snake_case conversion for database column names.
        if (array_key_exists($fieldName, $root)) {
            return $root[$fieldName];
        }
        $snakeCaseFieldName = GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
        return $root[$snakeCaseFieldName] ?? null;
    }

    /**
     * Apply configured post-fetch transforms to a list of records, based
     * on the GraphQL entity type implied by `$info->returnType`.
     *
     * The entity type is derived by unwrapping NonNull/ListOfType
     * wrappers on the return type and, for Relay-style Connection types,
     * stepping into `edges.node` via {@see getNodeTypeFromConnection()}.
     * Transforms are applied in place against the DB column name derived
     * from the configured GraphQL field name (via
     * `camelCaseToLowerCaseUnderscored`), so config consistently uses
     * the GraphQL naming.
     *
     * Records are returned unchanged when no transforms are configured
     * for the type, when no request is attached (see {@see process()}),
     * or when the transformer cannot be resolved. Exceptions thrown by
     * individual transformers are logged rather than propagated, so one
     * misbehaving transformer cannot abort an entire response.
     *
     * @param array<array<string, mixed>> $records
     * @return array<array<string, mixed>>
     */
    protected function applyFieldTransforms(array $records, ResolveInfo $info): array
    {
        if ($this->currentRequest === null || $records === [] || $this->fieldTransforms === []) {
            return $records;
        }

        $typeName = $this->resolveEntityTypeName($info);
        if ($typeName === null || !isset($this->fieldTransforms[$typeName])) {
            return $records;
        }

        $transforms = $this->fieldTransforms[$typeName];
        foreach ($records as $i => $record) {
            foreach ($transforms as $graphqlField => $transformName) {
                $dbColumn = GeneralUtility::camelCaseToLowerCaseUnderscored($graphqlField);
                if (!array_key_exists($dbColumn, $record)) {
                    continue;
                }

                $transformer = $this->transformerRegistry->get($transformName);
                if ($transformer === null) {
                    $this->logger->warning(sprintf(
                        'No transformer registered under "%s" (configured for %s.%s). '
                        . 'Register it in Configuration/Services.yaml under TransformerRegistry.',
                        $transformName,
                        $typeName,
                        $graphqlField
                    ));
                    continue;
                }

                try {
                    $records[$i][$dbColumn] = $transformer->transform(
                        $record[$dbColumn],
                        $this->currentRequest
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'Transformer "%s" failed on %s.%s: %s',
                        $transformName,
                        $typeName,
                        $graphqlField,
                        $e->getMessage()
                    ));
                }
            }
        }

        return $records;
    }

    /**
     * Return the GraphQL object type name that `$info->returnType` ultimately
     * points to, unwrapping NonNull/ListOfType wrappers and stepping into
     * `edges.node` for Relay Connection types. Returns `null` when the
     * return type does not resolve to a named object type.
     */
    protected function resolveEntityTypeName(ResolveInfo $info): ?string
    {
        $type = $info->returnType;
        while ($type instanceof NonNull || $type instanceof ListOfType) {
            $type = $type->getWrappedType();
        }
        if (!($type instanceof ObjectType)) {
            return null;
        }
        if (str_ends_with($type->name, 'Connection')) {
            return $this->getNodeTypeFromConnection($info)?->name;
        }
        return $type->name;
    }

    /**
     * Sort records using the configured comparator, if applicable.
     *
     * Sorting is applied only when a ComparatorInterface implementation
     * has been injected and a sort field is provided via the `sortBy`
     * query argument.
     *
     * If the sort field does not exist in the record arrays, records are
     * compared on empty strings, effectively preserving the original order.
     *
     * @param array<array<string, mixed>> $records Records to sort
     * @param string|null $sortField Database column name (snake_case) to sort by
     * @return array<array<string, mixed>> Sorted records, or original order if no sorting applies
     */
    protected function applySorting(array $records, ?string $sortField): array
    {
        if ($this->comparator === null || $sortField === null || $sortField === '') {
            return $records;
        }

        usort($records, function (array $a, array $b) use ($sortField): int {
            // Records hold mixed scalars (ints, strings, bool flags, …).
            // Coerce only what is safely castable to string; fall back to
            // '' so non-scalar values don't bubble up as PHPStan warnings
            // or surprising sort order.
            $rawA = $a[$sortField] ?? null;
            $rawB = $b[$sortField] ?? null;
            $valA = is_scalar($rawA) ? (string)$rawA : '';
            $valB = is_scalar($rawB) ? (string)$rawB : '';
            return $this->comparator->compare($valA, $valB);
        });

        return $records;
    }

    /**
     * Check if a field represents a relation based on its type
     *
     * @param ResolveInfo $info
     * @return bool
     */
    protected function isRelationField(ResolveInfo $info): bool
    {
        $type = $info->returnType;

        // Peel all NonNull and ListOf wrappers to reach the named type.
        // [Discipline!] is ListOf(NonNull(Discipline)), so a single unwrap
        // of ListOfType leaves NonNull(Discipline) which is not an ObjectType.
        while ($type instanceof NonNull || $type instanceof ListOfType) {
            $type = $type->getWrappedType();
        }

        return $type instanceof ObjectType;
    }

    /**
     * Resolve a relation field
     *
     * @param array<string, mixed> $root Parent record data
     * @param ResolveInfo $info Field resolution info
     * @return mixed
     */
    protected function resolveRelation(array $root, ResolveInfo $info)
    {
        $parentType = $info->parentType->name;
        $fieldName = $info->fieldName;
        $relationKey = "{$parentType}.{$fieldName}";

        // Check if relation is configured
        if (!isset($this->relations[$relationKey])) {
            $this->logger->warning(
                "Relation {$relationKey} is not configured in TypoScript. " .
                "Please add configuration under plugin.tx_typograph.settings.relations.{$relationKey}"
            );
            return null;
        }

        $relationConfig = $this->relations[$relationKey];
        $isList = $info->returnType instanceof ListOfType;

        // Determine storage type
        $storageType = $relationConfig['storageType'] ?? 'uid';

        // For uid and commaSeparated, we need a source field value
        // For foreignKey and mmTable, we use the parent UID directly
        $sourceValue = null;
        if (in_array($storageType, ['uid', 'commaSeparated'])) {
            // Get the source field (column) name
            $sourceField = $relationConfig['sourceField'] ?? GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
            $sourceValue = $root[$sourceField] ?? null;

            if ($sourceValue === null || $sourceValue === '') {
                return $isList ? [] : null;
            }
            assert(is_int($sourceValue) || is_string($sourceValue));
        }

        // Get target table
        $targetType = $relationConfig['targetType'] ?? null;
        if ($targetType === null) {
            $this->logger->error("Relation {$relationKey} is missing targetType configuration");
            return $isList ? [] : null;
        }

        $targetTable = $this->tableMapping[$targetType] ?? null;
        if ($targetTable === null) {
            $this->logger->error("Target type {$targetType} is not mapped to a table in tableMapping");
            return $isList ? [] : null;
        }

        try {
            $parentUid = $root['uid'] ?? null;
            assert(is_int($parentUid) || is_string($parentUid));

            switch ($storageType) {
                case 'uid':
                    return $this->resolveUidRelation($targetTable, (int)$sourceValue, $info);

                case 'commaSeparated':
                    return $this->resolveCommaSeparatedRelation($targetTable, (string)$sourceValue, $info);

                case 'mmTable':
                    $mmTable = $relationConfig['mmTable'] ?? null;
                    if ($mmTable === null) {
                        $this->logger->error("Relation {$relationKey} with storageType=mmTable is missing mmTable configuration");
                        return [];
                    }
                    return $this->resolveMmRelation(
                        $targetTable,
                        $mmTable,
                        (int)$parentUid,
                        $relationConfig,
                        $info
                    );

                case 'foreignKey':
                    $foreignKeyField = $relationConfig['foreignKeyField'] ?? null;
                    if ($foreignKeyField === null) {
                        $this->logger->error("Relation {$relationKey} with storageType=foreignKey is missing foreignKeyField configuration");
                        return [];
                    }
                    return $this->resolveForeignKeyRelation(
                        $targetTable,
                        $foreignKeyField,
                        (int)$parentUid,
                        $info
                    );

                default:
                    $this->logger->error("Unknown storage type: {$storageType} for relation {$relationKey}");
                    return $isList ? [] : null;
            }
        } catch (\Exception $e) {
            $this->logger->error("Error resolving relation {$relationKey}: " . $e->getMessage());
            return $isList ? [] : null;
        }
    }

    /**
     * Resolve a single UID relation
     *
     * @param string $targetTable
     * @param int $uid
     * @param ResolveInfo $info
     * @return array<int|string, mixed>|null
     */
    protected function resolveUidRelation(string $targetTable, int $uid, ResolveInfo $info): ?array
    {
        $cacheKey = "{$targetTable}:{$uid}";

        if (!isset($this->batchCache[$cacheKey])) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($targetTable);
            $fields = $this->getRequestedFields($info);

            $result = $queryBuilder
                ->select(...$fields)
                ->from($targetTable)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
                ->executeQuery()
                ->fetchAssociative();

            if ($result !== false) {
                // Apply field transforms before caching so that subsequent
                // cache hits return the already-transformed record without
                // re-running potentially expensive transformers.
                $transformed = $this->applyFieldTransforms([$result], $info);
                $result = $transformed[0] ?? $result;
            }

            $this->batchCache[$cacheKey] = $result !== false ? $result : null;
        }

        return $this->batchCache[$cacheKey];
    }

    /**
     * Resolve comma-separated UIDs relation
     *
     * @param string $targetTable
     * @param string $uids Comma-separated UIDs
     * @param ResolveInfo $info
     * @return array<array<int|string, mixed>>
     */
    protected function resolveCommaSeparatedRelation(string $targetTable, string $uids, ResolveInfo $info): array
    {
        $uidArray = array_filter(array_map('intval', explode(',', $uids)));

        if (empty($uidArray)) {
            return [];
        }

        return $this->batchLoadRecords($targetTable, $uidArray, $info);
    }

    /**
     * Resolve MM table relation
     *
     * @param string $targetTable
     * @param string $mmTable
     * @param int $sourceUid
     * @param RelationConfig $relationConfig
     * @param ResolveInfo $info
     * @return array<array<int|string, mixed>>
     */
    protected function resolveMmRelation(
        string $targetTable,
        string $mmTable,
        int $sourceUid,
        array $relationConfig,
        ResolveInfo $info
    ): array {
        $mmSourceField = $relationConfig['mmSourceField'] ?? 'uid_local';
        $mmTargetField = $relationConfig['mmTargetField'] ?? 'uid_foreign';
        $mmSortingField = $relationConfig['mmSortingField'] ?? 'sorting';

        // Query MM table to get target UIDs
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTable);
        $mmRecords = $queryBuilder
            ->select($mmTargetField, $mmSortingField)
            ->from($mmTable)
            ->where($queryBuilder->expr()->eq($mmSourceField, $queryBuilder->createNamedParameter($sourceUid)))
            ->orderBy($mmSortingField)
            ->executeQuery()
            ->fetchAllAssociative();

        if (empty($mmRecords)) {
            return [];
        }

        /** @var list<int> $targetUids */
        $targetUids = array_map(
            static fn(mixed $v): int => is_int($v) ? $v : (int)(is_string($v) ? $v : 0),
            array_column($mmRecords, $mmTargetField)
        );

        return $this->batchLoadRecords($targetTable, $targetUids, $info);
    }

    /**
     * Resolve foreign key relation (inverse relation)
     *
     * Used when the target table has a foreign key field pointing back to the source record.
     * This handles "sloppy MM" scenarios where multiple target records reference the same
     * source record via a foreign key column.
     *
     * @param string $targetTable Target table to query
     * @param string $foreignKeyField Column in target table that references source UID
     * @param int $sourceUid UID of the source record
     * @param ResolveInfo $info Field resolution info
     * @return array<array<string, mixed>>
     */
    protected function resolveForeignKeyRelation(
        string $targetTable,
        string $foreignKeyField,
        int $sourceUid,
        ResolveInfo $info
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($targetTable);
        $fields = $this->getRequestedFields($info);

        $records = $queryBuilder
            ->select(...$fields)
            ->from($targetTable)
            ->where($queryBuilder->expr()->eq($foreignKeyField, $queryBuilder->createNamedParameter($sourceUid)))
            ->executeQuery()
            ->fetchAllAssociative();

        // Apply field transforms before caching so that the cached
        // records match what is returned to the resolver.
        $records = $this->applyFieldTransforms($records, $info);

        // Cache individual records for potential reuse
        foreach ($records as $record) {
            /** @var int|string $uid */
            $uid = $record['uid'];
            $cacheKey = $targetTable . ':' . $uid;
            $this->batchCache[$cacheKey] = $record;
        }

        return $records;
    }

    /**
     * Batch load records by UIDs to avoid N+1 queries
     *
     * @param string $table
     * @param array<int> $uids
     * @param ResolveInfo $info
     * @return array<array<int|string, mixed>>
     */
    protected function batchLoadRecords(string $table, array $uids, ResolveInfo $info): array
    {
        $uids = array_unique($uids);
        $uncachedUids = [];
        $results = [];

        // Check which UIDs are already cached
        foreach ($uids as $uid) {
            $cacheKey = "{$table}:{$uid}";
            if (isset($this->batchCache[$cacheKey])) {
                $results[$uid] = $this->batchCache[$cacheKey];
            } else {
                $uncachedUids[] = $uid;
            }
        }

        // Fetch uncached records in a single query
        if (!empty($uncachedUids)) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $fields = $this->getRequestedFields($info);

            $fetchedRecords = $queryBuilder
                ->select(...$fields)
                ->from($table)
                ->where($queryBuilder->expr()->in('uid', $uncachedUids))
                ->executeQuery()
                ->fetchAllAssociative();

            // Apply field transforms before caching so that the cached
            // records match what is returned to the resolver and future
            // cache hits do not re-run transformers.
            $fetchedRecords = $this->applyFieldTransforms($fetchedRecords, $info);

            // Cache and index by UID
            foreach ($fetchedRecords as $record) {
                /** @var int|string $recordUid */
                $recordUid = $record['uid'];
                $uid = (int)$recordUid;
                $cacheKey = "{$table}:{$uid}";
                $this->batchCache[$cacheKey] = $record;
                $results[$uid] = $record;
            }
        }

        // Return records in the same order as requested UIDs
        $orderedResults = [];
        foreach ($uids as $uid) {
            if (isset($results[$uid])) {
                $orderedResults[] = $results[$uid];
            }
        }

        return $orderedResults;
    }

    /**
     * Get the list of fields requested in the GraphQL query
     *
     * @param ResolveInfo $info
     * @return array<string>
     */
    protected function getRequestedFields(ResolveInfo $info): array
    {
        $fields = ['uid']; // Always include UID for caching

        // Unwrap the return type to reach the ObjectType so we can inspect
        // each selected field's own type and skip relation fields.
        $type = $info->returnType;
        while ($type instanceof NonNull || $type instanceof ListOfType) {
            $type = $type->getWrappedType();
        }

        if (!($type instanceof ObjectType)) {
            return $fields;
        }

        $typeFields = $type->getFields();

        foreach ($info->getFieldSelection(1) as $field => $_) {
            if (!isset($typeFields[$field])) {
                continue;
            }

            $fieldType = $typeFields[$field]->getType();
            while ($fieldType instanceof NonNull || $fieldType instanceof ListOfType) {
                $fieldType = $fieldType->getWrappedType();
            }

            if ($fieldType instanceof ObjectType) {
                // For uid/commaSeparated relations the FK source column is a scalar
                // in the DB but maps to an ObjectType in GraphQL. Include it in
                // SELECT so resolveRelation() can read $root[$sourceField].
                $relationKey = "{$type->name}.{$field}";
                if (isset($this->relations[$relationKey])) {
                    $storageType = $this->relations[$relationKey]['storageType'] ?? '';
                    if (in_array($storageType, ['uid', 'commaSeparated'])) {
                        $sourceField = $this->relations[$relationKey]['sourceField']
                            ?? GeneralUtility::camelCaseToLowerCaseUnderscored($field);
                        if (!in_array($sourceField, $fields)) {
                            $fields[] = $sourceField;
                        }
                    }
                }
                continue;
            }

            $snakeCaseField = GeneralUtility::camelCaseToLowerCaseUnderscored($field);
            if (!in_array($snakeCaseField, $fields)) {
                $fields[] = $snakeCaseField;
            }
        }

        return $fields;
    }

    /**
     * Encode a UID into an opaque cursor string
     */
    protected function encodeCursor(int $uid): string
    {
        return base64_encode('cursor:' . $uid);
    }

    /**
     * Decode an opaque cursor string back to a UID
     *
     * @throws \InvalidArgumentException
     */
    protected function decodeCursor(string $cursor): int
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false || !str_starts_with($decoded, 'cursor:')) {
            throw new \InvalidArgumentException('Invalid cursor: ' . $cursor);
        }
        return (int)substr($decoded, 7);
    }

    /**
     * Check if the return type of a root query field is a Connection type
     */
    protected function isConnectionType(ResolveInfo $info): bool
    {
        $type = $info->returnType;

        // Unwrap NonNull
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        if ($type instanceof ObjectType) {
            return str_ends_with($type->name, 'Connection');
        }

        return false;
    }

    /**
     * Get the underlying node ObjectType from a Connection return type.
     * Navigates Connection → edges → [EdgeType] → node → NodeType.
     */
    protected function getNodeTypeFromConnection(ResolveInfo $info): ?ObjectType
    {
        $type = $info->returnType;

        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        if (!($type instanceof ObjectType)) {
            return null;
        }

        $edgesField = $type->getField('edges');
        $edgesType = $edgesField->getType();

        // Unwrap ListOfType and NonNull to get EdgeType
        while (!($edgesType instanceof ObjectType)) {
            if ($edgesType instanceof ListOfType || $edgesType instanceof NonNull) {
                $edgesType = $edgesType->getWrappedType();
            } else {
                return null;
            }
        }

        $nodeField = $edgesType->getField('node');
        $nodeType = $nodeField->getType();

        // Unwrap NonNull
        if ($nodeType instanceof NonNull) {
            $nodeType = $nodeType->getWrappedType();
        }

        return $nodeType instanceof ObjectType ? $nodeType : null;
    }

    /**
     * Extract the entity fields requested inside edges.node from a Connection query.
     * Walks the field selection to find edges → node → {fields}.
     *
     * @return array<string> Snake_case field names for SELECT
     */
    protected function getConnectionFieldSelection(ResolveInfo $info): array
    {
        $fields = ['uid']; // Always include UID for cursor generation

        $selection = $info->getFieldSelection(3);
        $edgesSelection = $selection['edges'] ?? [];
        $nodeFields = is_array($edgesSelection) ? ($edgesSelection['node'] ?? []) : [];

        $nodeType = $this->getNodeTypeFromConnection($info);
        if ($nodeType === null || !is_array($nodeFields)) {
            return $fields;
        }

        $typeFields = $nodeType->getFields();

        foreach ($nodeFields as $fieldName => $sub) {
            $fieldName = (string)$fieldName;
            if (!isset($typeFields[$fieldName])) {
                continue;
            }

            $fieldDef = $typeFields[$fieldName];
            $fieldType = $fieldDef->getType();

            // Unwrap ListOfType/NonNull
            if ($fieldType instanceof ListOfType) {
                $fieldType = $fieldType->getWrappedType();
            }
            if ($fieldType instanceof NonNull) {
                $fieldType = $fieldType->getWrappedType();
            }

            // Only include scalar fields; for uid/commaSeparated relations also
            // include the FK source column even though the GraphQL type is ObjectType.
            if (!($fieldType instanceof ObjectType)) {
                $snakeCaseField = GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
                if (!in_array($snakeCaseField, $fields)) {
                    $fields[] = $snakeCaseField;
                }
            } else {
                $relationKey = "{$nodeType->name}.{$fieldName}";
                if (isset($this->relations[$relationKey])) {
                    $storageType = $this->relations[$relationKey]['storageType'] ?? '';
                    if (in_array($storageType, ['uid', 'commaSeparated'])) {
                        $sourceField = $this->relations[$relationKey]['sourceField']
                            ?? GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
                        if (!in_array($sourceField, $fields)) {
                            $fields[] = $sourceField;
                        }
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Build the Relay Connection response structure
     *
     * @param array<array<string, mixed>> $records Records fetched (may include +1 extra)
     * @param int $totalCount Total matching records (0 if not requested)
     * @param string|null $afterCursor The after cursor used in the query
     * @param int $first The requested page size
     * @return array<string, mixed>
     */
    protected function buildConnectionResponse(array $records, int $totalCount, ?string $afterCursor, int $first): array
    {
        $hasNextPage = count($records) > $first;

        // Slice to requested size
        $records = array_slice($records, 0, $first);

        $edges = [];
        foreach ($records as $record) {
            /** @var int|string $recordUid */
            $recordUid = $record['uid'];
            $edges[] = [
                'cursor' => $this->encodeCursor((int)$recordUid),
                'node' => $record,
            ];
        }

        $pageInfo = [
            'hasNextPage' => $hasNextPage,
            'hasPreviousPage' => $afterCursor !== null,
            'startCursor' => !empty($edges) ? $edges[0]['cursor'] : null,
            'endCursor' => !empty($edges) ? $edges[count($edges) - 1]['cursor'] : null,
        ];

        return [
            'edges' => $edges,
            'pageInfo' => $pageInfo,
            'totalCount' => $totalCount,
        ];
    }

    /**
     * Get only scalar (non-relation) fields from the GraphQL selection
     *
     * This filters out object-type fields (relations) that should not be included
     * in the SELECT query for the root table.
     *
     * @param ResolveInfo $info
     * @return array<string>
     */
    protected function getScalarFieldsFromSelection(ResolveInfo $info): array
    {
        $fields = ['uid']; // Always include UID

        $parentType = $info->returnType;

        // Unwrap NonNull and ListOfType to reach the underlying ObjectType.
        // Return types like [Taxonomy!]! are represented as
        // NonNull(ListOf(NonNull(Taxonomy))), so both wrappers must be peeled.
        if ($parentType instanceof NonNull) {
            $parentType = $parentType->getWrappedType();
        }
        if ($parentType instanceof ListOfType) {
            $parentType = $parentType->getWrappedType();
        }
        if ($parentType instanceof NonNull) {
            $parentType = $parentType->getWrappedType();
        }

        // Get all fields from the parent type definition
        if ($parentType instanceof ObjectType) {
            $typeFields = $parentType->getFields();

            foreach ($info->getFieldSelection(1) as $fieldName => $_) {
                // Skip if field doesn't exist in type definition
                if (!isset($typeFields[$fieldName])) {
                    continue;
                }

                $fieldDef = $typeFields[$fieldName];
                $fieldType = $fieldDef->getType();

                // Unwrap ListOfType and NonNull to reach the inner type.
                // [Discipline!] is ListOf(NonNull(Discipline)); without peeling
                // the NonNull the ObjectType check below would pass incorrectly
                // and add the relation field name to the SELECT columns.
                if ($fieldType instanceof ListOfType) {
                    $fieldType = $fieldType->getWrappedType();
                }
                if ($fieldType instanceof NonNull) {
                    $fieldType = $fieldType->getWrappedType();
                }

                // Only include if it's NOT an ObjectType (i.e., it's a scalar);
                // for uid/commaSeparated relations also include the FK source column.
                if (!($fieldType instanceof ObjectType)) {
                    $snakeCaseField = GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
                    if (!in_array($snakeCaseField, $fields)) {
                        $fields[] = $snakeCaseField;
                    }
                } else {
                    $relationKey = "{$parentType->name}.{$fieldName}";
                    if (isset($this->relations[$relationKey])) {
                        $storageType = $this->relations[$relationKey]['storageType'] ?? '';
                        if (in_array($storageType, ['uid', 'commaSeparated'])) {
                            $sourceField = $this->relations[$relationKey]['sourceField']
                                ?? GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
                            if (!in_array($sourceField, $fields)) {
                                $fields[] = $sourceField;
                            }
                        }
                    }
                }
            }
        }

        return $fields;
    }
}
