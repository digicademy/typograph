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
            $output = $result->toArray();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $output = null;
        }

        return json_encode($output);
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
     * Developer note: because the TYPO3 cache can handle object, we can store
     * the document object directly and do not have to convert to/from an array
     * as in the graphql-php example.
     *
     * @see https://webonyx.github.io/graphql-php/schema-definition-language/#performance-considerations
     * @return Schema
     */
    protected function getSchema(): Schema
    {
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

        return BuildSchema::build($document);
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

            try {
                $queryBuilder = $this->connectionPool
                    ->getQueryBuilderForTable($rootTable);

                // We only want to fetch fields we need.
                // @see https://webonyx.github.io/graphql-php/data-fetching/#optimize-resolvers
                $fields = [];
                foreach ($info->getFieldSelection(1) as $field => $_) {
                    array_push($fields, GeneralUtility::camelCaseToLowerCaseUnderscored($field));
                }
                $queryBuilder
                    ->select(...$fields)
                    ->from($rootTable);

                // If we have arguments, add `where` conditions.
                // @see https://docs.typo3.org/permalink/t3coreapi:database-query-builder-where
                if ($args !== []) {
                    $conditions = [];
                    foreach ($args as $key => $value) {
                        array_push(
                            $conditions,
                            $queryBuilder->expr()->eq(
                                GeneralUtility::camelCaseToLowerCaseUnderscored($key),
                                $queryBuilder->createNamedParameter($value)
                            )
                        );
                    }
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
        // Convert camelCase field name to snake_case and look it up in the array
        $fieldName = $info->fieldName;
        $snakeCaseFieldName = GeneralUtility::camelCaseToLowerCaseUnderscored($fieldName);
        return $root[$snakeCaseFieldName];
    }
}
