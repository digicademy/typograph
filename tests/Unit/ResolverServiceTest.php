<?php

namespace Tests\Unit;

use Codeception\Test\Unit;
use Digicademy\TypoGraph\Service\ResolverService;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Definition\ResolveInfo;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class ResolverServiceTest extends Unit
{
    private const USER_SCHEMA = <<<'GRAPHQL'
type Query {
    user(id: Int): User
}

type User {
    id: Int
    name: String
}
GRAPHQL;

    private ConnectionPool $connectionPool;
    private ConfigurationManagerInterface $configurationManager;
    private FrontendInterface $cache;
    private LoggerInterface $logger;
    private ResolverService $service;

    protected function _before(): void
    {
        $this->connectionPool = $this->makeEmpty(ConnectionPool::class);
        $this->configurationManager = $this->makeEmpty(ConfigurationManagerInterface::class);
        $this->cache = $this->makeEmpty(FrontendInterface::class);
        $this->logger = $this->makeEmpty(LoggerInterface::class);
    }

    // =========================================================================
    // Constructor & Configuration Tests
    // =========================================================================

    public function testConstructorLoadsSettingsCorrectly(): void
    {
        $settings = [
            'schemaFiles' => ['EXT:typograph/Resources/Private/GraphQL/schema.graphql'],
            'tableMapping' => ['users' => 'fe_users', 'posts' => 'tt_content'],
        ];

        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => $settings,
            ]
        );

        $service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

        verify($service)->instanceOf(ResolverService::class);
    }

    public function testConstructorHandlesMissingSettings(): void
    {
        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => [],
            ]
        );

        $service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

        verify($service)->instanceOf(ResolverService::class);
    }

    // =========================================================================
    // process() Method Tests
    // =========================================================================

    public function testProcessValidGraphQLQuery(): void
    {
        $queryBuilder = $this->createMockQueryBuilder([['id' => 1, 'name' => 'John Doe']]);

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->setupServiceWithSchema(self::USER_SCHEMA);

        $queryJson = json_encode([
            'query' => '{ user(id: 1) { id name } }',
        ]);

        $result = $this->service->process($queryJson);
        verify($result)->isString();

        $decoded = json_decode($result, true);
        verify($decoded)->notEmpty();
    }

    public function testProcessWithVariables(): void
    {
        $queryBuilder = $this->createMockQueryBuilder([['id' => 42, 'name' => 'Jane Doe']]);

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->setupServiceWithSchema(self::USER_SCHEMA);

        $queryJson = json_encode([
            'query' => 'query GetUser($userId: Int) { user(id: $userId) { id name } }',
            'variables' => ['userId' => 42],
        ]);

        $result = $this->service->process($queryJson);
        verify($result)->isString();
        verify(json_decode($result, true))->notEmpty();
    }

    public function testProcessInvalidJson(): void
    {
        $this->setupServiceWithSchema('type Query { test: String }');

        $invalidJson = '{ invalid json ';

        $result = $this->service->process($invalidJson);
        verify($result)->isString();
    }

    // =========================================================================
    // getRootFieldNames() Tests
    // =========================================================================

    public function testGetRootFieldNamesWithSingleField(): void
    {
        $this->setupServiceWithSchema('type Query { user: String }');

        $query = '{ user { id name } }';
        $rootFields = $this->invokePrivateMethod($this->service, 'getRootFieldNames', [$query]);

        verify($rootFields)->equals(['user']);
    }

    public function testGetRootFieldNamesWithMultipleFields(): void
    {
        $this->setupServiceWithSchema('type Query { user: String, post: String }');

        $query = '{ user { id } post { title } }';
        $rootFields = $this->invokePrivateMethod($this->service, 'getRootFieldNames', [$query]);

        verify($rootFields)->arrayContains('user');
        verify($rootFields)->arrayContains('post');
        verify(count($rootFields))->equals(2);
    }

    // =========================================================================
    // getSchema() Method Tests
    // =========================================================================

    public function testGetSchemaInProductionWithCacheHit(): void
    {
        $schemaContent = 'type Query { test: String }';
        $cachedDocument = \GraphQL\Language\Parser::parse($schemaContent);

        $this->cache = $this->makeEmpty(
            FrontendInterface::class,
            [
                'has' => true,
                'get' => $cachedDocument,
            ]
        );

        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => [
                    'schemaFiles' => [],
                    'tableMapping' => ['users' => 'fe_users'],
                ],
            ]
        );

        // Use construct() to mock readSchemaFiles() as fallback for development mode
        // This ensures the test works regardless of Environment::getContext() value
        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs(),
            ['readSchemaFiles' => $schemaContent]
        );

        $schema = $this->invokePrivateMethod($this->service, 'getSchema');
        verify($schema)->instanceOf(\GraphQL\Type\Schema::class);
    }

    public function testGetSchemaInProductionWithCacheMiss(): void
    {
        $schemaContent = 'type Query { test: String }';
        $setWasCalled = false;

        $this->cache = $this->makeEmpty(
            FrontendInterface::class,
            [
                'has' => false,
                'set' => function ($identifier, $document) use (&$setWasCalled) {
                    $setWasCalled = true;
                    verify($identifier)->equals('typograph_cached_schema');
                    verify($document)->instanceOf(DocumentNode::class);
                },
            ]
        );

        $this->setupServiceWithSchemaForCacheTest($schemaContent);

        $schema = $this->invokePrivateMethod($this->service, 'getSchema');
        verify($schema)->instanceOf(\GraphQL\Type\Schema::class);
        verify($setWasCalled)->true();
    }

    // =========================================================================
    // readSchemaFiles() Method Tests
    // =========================================================================

    public function testReadSchemaFilesWithSingleFile(): void
    {
        $schemaContent = 'type Query { hello: String }';
        $this->createServiceWithMockedReadSchemaFiles($schemaContent);

        $result = $this->invokePrivateMethod($this->service, 'readSchemaFiles');
        verify($result)->equals($schemaContent);
    }

    public function testReadSchemaFilesWithMultipleFiles(): void
    {
        $content1 = 'type Query { hello: String }';
        $content2 = 'type User { id: Int name: String }';
        $combinedContent = $content1 . $content2;

        $this->createServiceWithMockedReadSchemaFiles($combinedContent);

        $result = $this->invokePrivateMethod($this->service, 'readSchemaFiles');
        verify($result)->equals($combinedContent);
    }

    public function testReadSchemaFilesWithEmptyArray(): void
    {
        $this->createServiceWithMockedReadSchemaFiles('');

        $result = $this->invokePrivateMethod($this->service, 'readSchemaFiles');
        verify($result)->equals('');
    }

    // =========================================================================
    // resolve() Method Tests
    // =========================================================================

    public function testResolveRootFieldWithValidTable(): void
    {
        $this->setupServiceWithSchema('type Query { users: [User] } type User { id: Int }');

        $queryBuilder = $this->createMockQueryBuilder([['id' => 1], ['id' => 2]]);

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['id' => true]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['users'], [], null, $resolveInfo]
        );

        verify($result)->isArray();
    }

    public function testResolveRootFieldWithArguments(): void
    {
        $this->setupServiceWithSchema('type Query { users: [User] } type User { id: Int name: String }');

        $queryBuilder = $this->createFluentQueryBuilder([['id' => 1, 'name' => 'John']]);

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['id' => true, 'name' => true]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['users'], ['id' => 1], null, $resolveInfo]
        );

        verify($result)->isArray();
        verify($result)->notEmpty();
    }

    public function testResolveRootFieldNotInTableMapping(): void
    {
        $this->setupServiceWithSchema('type Query { test: String } type User { id: Int }');
        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['id' => true]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['unknownField'], [], null, $resolveInfo]
        );

        verify($result)->null();
    }

    public function testResolveNestedFieldConvertsToSnakeCase(): void
    {
        $this->setupServiceWithSchema('type Query { test: String }');
        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo([], 'userName');
        $root = ['user_name' => 'John Doe'];

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [$root, [], null, $resolveInfo]
        );

        verify($result)->equals('John Doe');
    }

    public function testResolveNestedFieldWithCamelCaseConversion(): void
    {
        $this->setupServiceWithSchema('type Query { test: String }');
        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo([], 'createdAt');
        $root = ['created_at' => '2025-01-01'];

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [$root, [], null, $resolveInfo]
        );

        verify($result)->equals('2025-01-01');
    }

    public function testResolveDatabaseErrorLogsAndReturnsEmpty(): void
    {
        $this->setupServiceWithSchema('type Query { users: [User] } type User { id: Int }');

        $queryBuilder = $this->makeEmpty(QueryBuilder::class, [
            'select' => function () {
                throw new \Exception('Database error');
            },
        ]);

        $loggerCalled = false;
        $this->logger = $this->makeEmpty(
            LoggerInterface::class,
            [
                'error' => function ($message) use (&$loggerCalled) {
                    $loggerCalled = true;
                    verify($message)->equals('Database error');
                },
            ]
        );

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['id' => true]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['users'], [], null, $resolveInfo]
        );

        verify($result)->equals([]);
        verify($loggerCalled)->true();
    }

    public function testResolveWithMultipleWhereConditions(): void
    {
        $this->setupServiceWithSchema('type Query { users: [User] } type User { id: Int status: String }');

        $whereConditions = [];
        $queryBuilder = $this->createFluentQueryBuilder(
            [['id' => 1, 'status' => 'active']],
            null,
            $whereConditions
        );

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['id' => true, 'status' => true]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['users'], ['id' => 1, 'status' => 'active'], null, $resolveInfo]
        );

        verify($result)->isArray();
        verify(count($whereConditions))->equals(2);
    }

    // =========================================================================
    // Relation Resolution Tests
    // =========================================================================

    public function testIsRelationFieldDetectsObjectType(): void
    {
        $schema = 'type Query { taxonomy: Taxonomy } type Taxonomy { name: String disciplines: [Discipline] } type Discipline { name: String }';
        $this->setupServiceWithSchema($schema);

        // Create ResolveInfo for an object field
        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $this->makeEmpty(\GraphQL\Type\Definition\ListOfType::class, [
                'getWrappedType' => $this->makeEmpty(ObjectType::class),
            ]),
        ]);

        $result = $this->invokePrivateMethod($this->service, 'isRelationField', [$resolveInfo]);
        verify($result)->true();
    }

    public function testIsRelationFieldReturnsFalseForScalar(): void
    {
        $schema = 'type Query { taxonomy: Taxonomy } type Taxonomy { name: String }';
        $this->setupServiceWithSchema($schema);

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $this->makeEmpty(\GraphQL\Type\Definition\StringType::class),
        ]);

        $result = $this->invokePrivateMethod($this->service, 'isRelationField', [$resolveInfo]);
        verify($result)->false();
    }

    public function testResolveUidRelation(): void
    {
        $schema = 'type Query { taxonomy: Taxonomy } type Taxonomy { discipline: Discipline } type Discipline { name: String }';

        $settings = [
            'schemaFiles' => [],
            'tableMapping' => [
                'taxonomy' => 'tx_taxonomy',
                'Discipline' => 'tx_discipline',
            ],
            'relations' => [
                'Taxonomy.discipline' => [
                    'sourceField' => 'discipline',
                    'targetType' => 'Discipline',
                    'storageType' => 'uid',
                ],
            ],
        ];

        $this->setupServiceWithSettings($settings, $schema);

        $queryBuilder = $this->createMockQueryBuilder([['uid' => 5, 'name' => 'Computer Science']]);
        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['name' => true], 'discipline');
        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'getFieldSelection' => ['name' => true],
            'fieldName' => 'discipline',
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolveUidRelation',
            ['tx_discipline', 5, $resolveInfo]
        );

        verify($result)->isArray();
        verify($result['name'])->equals('Computer Science');
    }

    public function testResolveCommaSeparatedRelation(): void
    {
        $schema = 'type Query { taxonomy: Taxonomy } type Taxonomy { disciplines: [Discipline] } type Discipline { name: String }';

        $settings = [
            'schemaFiles' => [],
            'tableMapping' => [
                'taxonomy' => 'tx_taxonomy',
                'Discipline' => 'tx_discipline',
            ],
            'relations' => [
                'Taxonomy.disciplines' => [
                    'sourceField' => 'disciplines',
                    'targetType' => 'Discipline',
                    'storageType' => 'commaSeparated',
                ],
            ],
        ];

        $this->setupServiceWithSettings($settings, $schema);

        $queryBuilder = $this->createMockQueryBuilder([
            ['uid' => 1, 'name' => 'Math'],
            ['uid' => 2, 'name' => 'Physics'],
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['name' => true], 'disciplines');

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolveCommaSeparatedRelation',
            ['tx_discipline', '1,2', $resolveInfo]
        );

        verify($result)->isArray();
        verify(count($result))->equals(2);
    }

    public function testBatchLoadRecordsAvoidsNPlusOne(): void
    {
        $schema = 'type Query { test: String }';
        $this->setupServiceWithSchema($schema);

        $queryCallCount = 0;
        $queryBuilder = $this->makeEmpty(QueryBuilder::class, [
            'select' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'from' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'where' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'expr' => $this->makeEmpty(ExpressionBuilder::class, [
                'in' => function () {
                    return 'mock_condition';
                },
            ]),
            'executeQuery' => function () use (&$queryCallCount) {
                $queryCallCount++;
                $statement = $this->makeEmpty(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [
                        ['uid' => 1, 'name' => 'Record 1'],
                        ['uid' => 2, 'name' => 'Record 2'],
                        ['uid' => 3, 'name' => 'Record 3'],
                    ],
                ]);
                return $statement;
            },
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['name' => true]);

        // First call - should execute query
        $result1 = $this->invokePrivateMethod(
            $this->service,
            'batchLoadRecords',
            ['tx_test', [1, 2, 3], $resolveInfo]
        );

        verify($queryCallCount)->equals(1);
        verify(count($result1))->equals(3);

        // Second call with same UIDs - should use cache, not execute query again
        $result2 = $this->invokePrivateMethod(
            $this->service,
            'batchLoadRecords',
            ['tx_test', [1, 2], $resolveInfo]
        );

        verify($queryCallCount)->equals(1); // Still 1, not 2!
        verify(count($result2))->equals(2);
    }

    public function testResolveRelationWithMissingConfig(): void
    {
        $schema = 'type Query { taxonomy: Taxonomy } type Taxonomy { disciplines: [Discipline] } type Discipline { name: String }';

        $settings = [
            'schemaFiles' => [],
            'tableMapping' => ['taxonomy' => 'tx_taxonomy'],
            'relations' => [], // No relation config
        ];

        $this->setupServiceWithSettings($settings, $schema);

        $loggerCalled = false;
        $this->logger = $this->makeEmpty(LoggerInterface::class, [
            'warning' => function ($message) use (&$loggerCalled) {
                $loggerCalled = true;
                verify($message)->stringContainsString('Taxonomy.disciplines');
                verify($message)->stringContainsString('not configured');
            },
        ]);

        $this->service = $this->createService();

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'parentType' => $this->makeEmpty(ObjectType::class, ['name' => 'Taxonomy']),
            'fieldName' => 'disciplines',
            'returnType' => $this->makeEmpty(\GraphQL\Type\Definition\ListOfType::class),
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolveRelation',
            [['discipline' => '1'], $resolveInfo]
        );

        verify($result)->null();
        verify($loggerCalled)->true();
    }

    public function testResolveForeignKeyRelation(): void
    {
        $schema = 'type Query { taxonomy: Taxonomy } type Taxonomy { disciplines: [Discipline] } type Discipline { name: String }';

        $settings = [
            'schemaFiles' => [],
            'tableMapping' => [
                'taxonomy' => 'tx_taxonomy',
                'Discipline' => 'tx_discipline',
            ],
            'relations' => [
                'Taxonomy.disciplines' => [
                    'targetType' => 'Discipline',
                    'storageType' => 'foreignKey',
                    'foreignKeyField' => 'discipline_taxonomy',
                ],
            ],
        ];

        $this->setupServiceWithSettings($settings, $schema);

        // Mock query builder to return multiple disciplines with same name
        $queryBuilder = $this->createMockQueryBuilder([
            ['uid' => 1, 'name' => 'Physics', 'discipline_taxonomy' => 5],
            ['uid' => 2, 'name' => 'Physics', 'discipline_taxonomy' => 5],
            ['uid' => 3, 'name' => 'Mathematics', 'discipline_taxonomy' => 5],
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['name' => true], 'disciplines');

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolveForeignKeyRelation',
            ['tx_discipline', 'discipline_taxonomy', 5, $resolveInfo]
        );

        verify($result)->isArray();
        verify(count($result))->equals(3);
        verify($result[0]['name'])->equals('Physics');
        verify($result[1]['name'])->equals('Physics'); // Duplicate name, different UID
        verify($result[2]['name'])->equals('Mathematics');
    }

    public function testForeignKeyRelationCachesRecords(): void
    {
        $schema = 'type Query { test: String }';
        $this->setupServiceWithSchema($schema);

        $queryBuilder = $this->createMockQueryBuilder([
            ['uid' => 10, 'name' => 'Record 10'],
            ['uid' => 20, 'name' => 'Record 20'],
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $resolveInfo = $this->createMockResolveInfo(['name' => true]);

        // Execute foreign key relation
        $result = $this->invokePrivateMethod(
            $this->service,
            'resolveForeignKeyRelation',
            ['tx_test', 'parent_id', 5, $resolveInfo]
        );

        verify(count($result))->equals(2);

        // Verify records are cached - access via reflection
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('batchCache');
        $property->setAccessible(true);
        $cache = $property->getValue($this->service);

        verify(isset($cache['tx_test:10']))->true();
        verify(isset($cache['tx_test:20']))->true();
        verify($cache['tx_test:10']['name'])->equals('Record 10');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function setupServiceWithSettings(array $settings, string $schemaContent): void
    {
        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => $settings,
            ]
        );

        $this->cache = $this->makeEmpty(
            FrontendInterface::class,
            [
                'has' => false,
                'set' => function () {
                },
            ]
        );

        $schema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schemaContent)
        );

        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs(),
            ['getSchema' => $schema]
        );
    }

    private function setupServiceWithSchema(string $schemaContent): void
    {
        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => [
                    'schemaFiles' => [],
                    'tableMapping' => ['users' => 'fe_users', 'user' => 'fe_users'],
                ],
            ]
        );

        $this->cache = $this->makeEmpty(
            FrontendInterface::class,
            [
                'has' => false,
                'set' => function () {
                },
            ]
        );

        $schema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schemaContent)
        );

        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs(),
            ['getSchema' => $schema]
        );
    }

    private function setupServiceWithSchemaForCacheTest(string $schemaContent): void
    {
        $this->configurationManager = $this->createEmptyConfigurationManager();

        // Mock readSchemaFiles() instead of getSchema() to allow the real
        // getSchema() logic to run, which will properly call cache->set()
        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs(),
            ['readSchemaFiles' => $schemaContent]
        );
    }

    private function createService(): ResolverService
    {
        return new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );
    }

    private function createEmptyConfigurationManager(): ConfigurationManagerInterface
    {
        return $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => [
                    'schemaFiles' => [],
                    'tableMapping' => [],
                ],
            ]
        );
    }

    private function createServiceWithMockedReadSchemaFiles(string $content): void
    {
        $this->configurationManager = $this->createEmptyConfigurationManager();

        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs(),
            ['readSchemaFiles' => $content]
        );
    }

    private function getServiceConstructorArgs(): array
    {
        return [
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger,
        ];
    }

    private function createMockQueryBuilder(array $returnData): QueryBuilder
    {
        $queryBuilder = $this->makeEmpty(QueryBuilder::class, [
            'select' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'from' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'where' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'expr' => $this->makeEmpty(ExpressionBuilder::class, [
                'eq' => function () {
                    return 'mock_condition';
                },
            ]),
            'createNamedParameter' => function ($value) {
                return "'{$value}'";
            },
            'executeQuery' => function () use ($returnData) {
                $statement = $this->makeEmpty(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => $returnData,
                ]);
                return $statement;
            },
        ]);

        return $queryBuilder;
    }

    private function createFluentQueryBuilder(
        array $returnData,
        ?ExpressionBuilder $expressionBuilder = null,
        ?array &$capturedConditions = null
    ): QueryBuilder {
        if ($expressionBuilder === null) {
            $expressionBuilder = $this->makeEmpty(ExpressionBuilder::class, [
                'eq' => function ($field, $value) {
                    return "{$field} = {$value}";
                },
            ]);
        }

        $queryBuilder = $this->makeEmpty(QueryBuilder::class, [
            'select' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'from' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'where' => function (...$conditions) use (&$queryBuilder, &$capturedConditions) {
                if ($capturedConditions !== null) {
                    $capturedConditions = $conditions;
                }
                return $queryBuilder;
            },
            'expr' => $expressionBuilder,
            'createNamedParameter' => function ($value) {
                return "'{$value}'";
            },
            'executeQuery' => function () use ($returnData) {
                $statement = $this->makeEmpty(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => $returnData,
                ]);
                return $statement;
            },
        ]);

        return $queryBuilder;
    }

    private function createMockResolveInfo(array $fieldSelection, string $fieldName = 'users'): ResolveInfo
    {
        return $this->makeEmpty(ResolveInfo::class, [
            'getFieldSelection' => $fieldSelection,
            'fieldName' => $fieldName,
        ]);
    }

    private function invokePrivateMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
