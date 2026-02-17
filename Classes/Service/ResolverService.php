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

use Exception;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

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
     * @var array<string, array<string, mixed>>
     */
    protected array $relations;

    /**
     * @var Schema|null
     */
    protected ?Schema $schema = null;

    /**
     * Batch loader cache for relations to avoid N+1 queries
     * @var array<string, array<int|string, mixed>>
     */
    protected array $batchCache = [];

    protected int $defaultLimit;

    protected int $maxLimit;

    /**
     * Pagination args that must not be treated as WHERE conditions
     */
    protected const PAGINATION_ARGS = ['first', 'after'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationManagerInterface $configurationManager,
        private readonly FrontendInterface $cache,
        private readonly LoggerInterface $logger
    ) {
        $settings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'typograph'
        );

        $this->schemaFiles = $settings['schemaFiles'] ?? [];
        $this->tableMapping = $settings['tableMapping'] ?? [];
        $this->relations = $this->flattenRelationsConfig($settings['relations'] ?? []);
        $this->defaultLimit = (int)($settings['pagination']['defaultLimit'] ?? 20);
        $this->maxLimit = (int)($settings['pagination']['maxLimit'] ?? 100);
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
     * @return array<string, array<string, mixed>>
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
                $flattened[$key] = $config;
            }
        }

        return $flattened;
    }

    public function process(string $json): mixed
    {
        $input = json_decode($json, true);

        if (!is_array($input) || !isset($input['query'])) {
            $this->logger->error('Invalid JSON input or missing query field');
            return json_encode(null);
        }

        $query = $input['query'];
        $variableValues = isset($input['variables']) ? $input['variables'] : null;
        $schema = $this->getSchema();

        $rootFields = $this->getRootFieldNames($query);

        try {
            $root = [];
            $result = GraphQL::executeQuery(
                $schema,
                $query,
                $this->getRootFieldNames($query),
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
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $output = null;
        }

        return json_encode($output, JSON_UNESCAPED_SLASHES);
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
                $document = $this->cache->get(self::CACHE_IDENTIFIER);
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
     * @param  array<string>     $root    Array of root field names or empty array
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
        // Note that we need to check whether this is actually one of our
        // defined root aliases for a table to query. Only for these we can
        // and want to run a database query.
        if (is_array($root) && isset($root[0]) && in_array($root[0], $rootTables)) {
            $rootTable = $this->tableMapping[$root[0]];
            $isConnection = $this->isConnectionType($info);

            // Separate pagination args from filter args
            $filterArgs = [];
            $paginationArgs = [];
            foreach ($args as $key => $value) {
                if (in_array($key, self::PAGINATION_ARGS)) {
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
                    $afterCursor = $paginationArgs['after'] ?? null;

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

                        $totalCount = (int)$countBuilder
                            ->executeQuery()
                            ->fetchOne();
                    }

                    return $this->buildConnectionResponse($records, $totalCount, $afterCursor, $first);
                }

                // Plain list type (non-connection)
                if ($conditions !== []) {
                    $queryBuilder->where(...$conditions);
                }

                $result = $queryBuilder
                    ->executeQuery()
                    ->fetchAllAssociative();
            } catch (Exception $e) {
                $this->logger->error($e->getMessage());
                $result = [];
            }

            return $result;
        }

        // Nested field resolution (Model.foo, Model.bar, etc.)
        // Check if this field is a relation
        if ($this->isRelationField($info)) {
            return $this->resolveRelation($root, $info);
        }

        // Look up the field directly first (handles camelCase keys from
        // connection responses like pageInfo, hasNextPage, totalCount, etc.),
        // then fall back to snake_case conversion for database column names.
        $fieldName = $info->fieldName;
        if (array_key_exists($fieldName, $root)) {
            return $root[$fieldName];
        }
        $snakeCaseFieldName = GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
        return $root[$snakeCaseFieldName] ?? null;
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

        // Unwrap ListOfType to get to the actual type
        if ($type instanceof ListOfType) {
            $type = $type->getWrappedType();
        }

        // Check if it's an ObjectType (not a scalar)
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
        if (in_array($storageType, ['uid', 'commaSeparated'])) {
            // Get the source field (column) name
            $sourceField = $relationConfig['sourceField'] ?? GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
            $sourceValue = $root[$sourceField] ?? null;

            if ($sourceValue === null || $sourceValue === '') {
                return $isList ? [] : null;
            }
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
                        (int)$root['uid'],
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
                        (int)$root['uid'],
                        $info
                    );

                default:
                    $this->logger->error("Unknown storage type: {$storageType} for relation {$relationKey}");
                    return $isList ? [] : null;
            }
        } catch (Exception $e) {
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
     * @return array<string, mixed>|null
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
     * @return array<array<string, mixed>>
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
     * @param array<string, mixed> $relationConfig
     * @param ResolveInfo $info
     * @return array<array<string, mixed>>
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

        $targetUids = array_column($mmRecords, $mmTargetField);

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

        // Cache individual records for potential reuse
        foreach ($records as $record) {
            $cacheKey = "{$targetTable}:{$record['uid']}";
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
     * @return array<array<string, mixed>>
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

            // Cache and index by UID
            foreach ($fetchedRecords as $record) {
                $uid = (int)$record['uid'];
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

        foreach ($info->getFieldSelection(1) as $field => $_) {
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
        $nodeFields = $selection['edges']['node'] ?? [];

        $nodeType = $this->getNodeTypeFromConnection($info);
        if ($nodeType === null) {
            return $fields;
        }

        $typeFields = $nodeType->getFields();

        foreach ($nodeFields as $fieldName => $sub) {
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

            // Only include scalar fields
            if (!($fieldType instanceof ObjectType)) {
                $snakeCaseField = GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
                if (!in_array($snakeCaseField, $fields)) {
                    $fields[] = $snakeCaseField;
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
            $edges[] = [
                'cursor' => $this->encodeCursor((int)$record['uid']),
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

        // Unwrap ListOfType to get the actual object type
        if ($parentType instanceof ListOfType) {
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

                // Unwrap ListOfType if present
                if ($fieldType instanceof ListOfType) {
                    $fieldType = $fieldType->getWrappedType();
                }

                // Only include if it's NOT an ObjectType (i.e., it's a scalar)
                if (!($fieldType instanceof ObjectType)) {
                    $snakeCaseField = GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
                    if (!in_array($snakeCaseField, $fields)) {
                        $fields[] = $snakeCaseField;
                    }
                }
            }
        }

        return $fields;
    }
}
