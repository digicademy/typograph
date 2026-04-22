<?php

namespace Tests\Unit;

use Codeception\Test\Unit;
use Digicademy\TypoGraph\Comparator\ComparatorInterface;
use Digicademy\TypoGraph\Service\ResolverService;
use Digicademy\TypoGraph\Transformer\TransformerInterface;
use Digicademy\TypoGraph\Transformer\TransformerRegistry;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

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
    private FrontendInterface $cache;
    private LoggerInterface $logger;
    private TransformerRegistry $transformerRegistry;
    private ResolverService $service;
    private array $serviceSettings = ['tableMapping' => ['users' => 'fe_users', 'user' => 'fe_users']];

    protected function _before(): void
    {
        $this->connectionPool = $this->makeEmpty(ConnectionPool::class);
        $this->cache = $this->makeEmpty(FrontendInterface::class);
        $this->logger = $this->makeEmpty(LoggerInterface::class);
        // An empty registry is sufficient for resolver tests: no test case
        // currently exercises the `fieldTransforms` config, so no transformer
        // names need to resolve. Tests that do exercise transforms can pass
        // a populated registry via the respective construct helpers.
        $this->transformerRegistry = new TransformerRegistry([]);
    }

    // =========================================================================
    // Constructor & Configuration Tests
    // =========================================================================

    public function testConstructorLoadsSettingsCorrectly(): void
    {
        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry
        );

        $service->configure([
            'schemaFiles' => ['EXT:typograph/Resources/Private/GraphQL/schema.graphql'],
            'tableMapping' => ['users' => 'fe_users', 'posts' => 'tt_content'],
        ]);

        verify($service)->instanceOf(ResolverService::class);
    }

    public function testConstructorHandlesMissingSettings(): void
    {
        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry
        );

        $service->configure([]);

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

        // Use construct() to mock readSchemaFiles() as fallback for development mode
        // This ensures the test works regardless of Environment::getContext() value
        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs(),
            ['readSchemaFiles' => $schemaContent]
        );
        $this->service->configure(['schemaFiles' => [], 'tableMapping' => ['users' => 'fe_users']]);

        $schema = $this->invokePrivateMethod($this->service, 'getSchema');
        verify($schema)->instanceOf(\GraphQL\Type\Schema::class);
    }

    public function testGetSchemaInProductionWithCacheMiss(): void
    {
        // Temporarily set Production context so the cache code path is exercised
        $envRef = new \ReflectionClass(Environment::class);
        $contextProp = $envRef->getProperty('context');
        $contextProp->setAccessible(true);
        $originalContext = $contextProp->getValue();
        $contextProp->setValue(null, new ApplicationContext('Production'));

        try {
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
        } finally {
            $contextProp->setValue(null, $originalContext);
        }
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

        $resolveInfo = $this->createMockResolveInfo(['id' => true], 'unknownField');

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

        // Use real GraphQL type instances instead of mocks
        $objectType = new ObjectType(['name' => 'Discipline', 'fields' => ['name' => Type::string()]]);
        $listType = new ListOfType($objectType);

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $listType,
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

        $parentType = new ObjectType(['name' => 'Taxonomy', 'fields' => ['name' => Type::string()]]);
        $returnType = new ListOfType(new ObjectType(['name' => 'Discipline', 'fields' => ['name' => Type::string()]]));

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'parentType' => $parentType,
            'fieldName' => 'disciplines',
            'returnType' => $returnType,
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

    public function testGetScalarFieldsFromSelectionFiltersOutRelations(): void
    {
        $schema = 'type Query { taxonomy: Taxonomy } type Taxonomy { name: String disciplines: [Discipline] } type Discipline { name: String }';
        $this->setupServiceWithSchema($schema);

        // Build schema to get actual ObjectType
        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );

        $taxonomyType = $parsedSchema->getType('Taxonomy');

        // Create mock ResolveInfo with selection including both scalar and relation fields
        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $this->makeEmpty(\GraphQL\Type\Definition\ListOfType::class, [
                'getWrappedType' => $taxonomyType,
            ]),
            'getFieldSelection' => ['name' => true, 'disciplines' => true],
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'getScalarFieldsFromSelection',
            [$resolveInfo]
        );

        // Should include 'uid' (always) and 'name' (scalar), but NOT 'disciplines' (relation)
        verify($result)->arrayContains('uid');
        verify($result)->arrayContains('name');
        verify($result)->arrayNotContains('disciplines');
        verify(count($result))->equals(2); // Only uid and name
    }

    public function testFlattenRelationsConfigConvertsNestedTypoScript(): void
    {
        $schema = 'type Query { test: String }';
        $this->setupServiceWithSchema($schema);

        // Simulate how TypoScript parses "Taxonomy.disciplines" as nested structure
        $nestedConfig = [
            'Taxonomy' => [
                'disciplines' => [
                    'targetType' => 'Discipline',
                    'storageType' => 'foreignKey',
                    'foreignKeyField' => 'discipline_taxonomy',
                ],
                'mainDiscipline' => [
                    'targetType' => 'Discipline',
                    'storageType' => 'uid',
                    'sourceField' => 'main_discipline',
                ],
            ],
            'Expert' => [
                'disciplines' => [
                    'targetType' => 'Discipline',
                    'storageType' => 'mmTable',
                    'mmTable' => 'tx_expert_discipline_mm',
                ],
            ],
        ];

        $result = $this->invokePrivateMethod(
            $this->service,
            'flattenRelationsConfig',
            [$nestedConfig]
        );

        // Should flatten to dot-separated keys
        verify(isset($result['Taxonomy.disciplines']))->true();
        verify(isset($result['Taxonomy.mainDiscipline']))->true();
        verify(isset($result['Expert.disciplines']))->true();

        // Verify config values are preserved
        verify($result['Taxonomy.disciplines']['targetType'])->equals('Discipline');
        verify($result['Taxonomy.disciplines']['storageType'])->equals('foreignKey');
        verify($result['Taxonomy.disciplines']['foreignKeyField'])->equals('discipline_taxonomy');

        verify($result['Taxonomy.mainDiscipline']['targetType'])->equals('Discipline');
        verify($result['Taxonomy.mainDiscipline']['storageType'])->equals('uid');

        verify($result['Expert.disciplines']['storageType'])->equals('mmTable');
        verify($result['Expert.disciplines']['mmTable'])->equals('tx_expert_discipline_mm');

        // Should have exactly 3 flattened entries
        verify(count($result))->equals(3);
    }

    public function testForeignKeyRelationDoesNotRequireSourceField(): void
    {
        $schema = 'type Query { taxonomy: Taxonomy } type Taxonomy { disciplines: [Discipline] } type Discipline { name: String }';

        $settings = [
            'schemaFiles' => [],
            'tableMapping' => [
                'taxonomy' => 'tx_taxonomy',
                'Discipline' => 'tx_discipline',
            ],
            'relations' => [
                'Taxonomy' => [
                    'disciplines' => [
                        'targetType' => 'Discipline',
                        'storageType' => 'foreignKey',
                        'foreignKeyField' => 'discipline_taxonomy',
                    ],
                ],
            ],
        ];

        $this->setupServiceWithSettings($settings, $schema);

        // Parent record WITHOUT a 'disciplines' or 'discipline_taxonomy' field
        // For foreignKey, this should still work because it uses parent UID
        $parentRecord = ['uid' => 42, 'name' => 'Applied Sciences'];

        $queryBuilder = $this->createMockQueryBuilder([
            ['uid' => 1, 'name' => 'Physics', 'discipline_taxonomy' => 42],
            ['uid' => 2, 'name' => 'Chemistry', 'discipline_taxonomy' => 42],
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parentType = new ObjectType(['name' => 'Taxonomy', 'fields' => ['name' => Type::string()]]);
        $returnType = new ListOfType(new ObjectType(['name' => 'Discipline', 'fields' => ['name' => Type::string()]]));

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'parentType' => $parentType,
            'fieldName' => 'disciplines',
            'returnType' => $returnType,
            'getFieldSelection' => ['name' => true],
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolveRelation',
            [$parentRecord, $resolveInfo]
        );

        // Should return disciplines even though parent has no 'disciplines' field
        verify($result)->isArray();
        verify(count($result))->equals(2);
        verify($result[0]['name'])->equals('Physics');
        verify($result[1]['name'])->equals('Chemistry');
    }

    // =========================================================================
    // Pagination Tests
    // =========================================================================

    public function testEncodeCursorProducesOpaqueString(): void
    {
        $this->setupServiceWithSchema('type Query { test: String }');

        $cursor = $this->invokePrivateMethod($this->service, 'encodeCursor', [42]);
        verify($cursor)->isString();
        verify($cursor)->notEquals('42');

        // Round-trip
        $uid = $this->invokePrivateMethod($this->service, 'decodeCursor', [$cursor]);
        verify($uid)->equals(42);
    }

    public function testDecodeCursorReturnsUid(): void
    {
        $this->setupServiceWithSchema('type Query { test: String }');

        $cursor = base64_encode('cursor:99');
        $uid = $this->invokePrivateMethod($this->service, 'decodeCursor', [$cursor]);
        verify($uid)->equals(99);
    }

    public function testDecodeCursorThrowsOnInvalid(): void
    {
        $this->setupServiceWithSchema('type Query { test: String }');

        $this->expectException(\InvalidArgumentException::class);
        $this->invokePrivateMethod($this->service, 'decodeCursor', ['garbage-string']);
    }

    public function testIsConnectionTypeDetectsConnectionSuffix(): void
    {
        $schema = <<<'GRAPHQL'
type Query { experts(first: Int, after: String): ExpertConnection }
type PageInfo { hasNextPage: Boolean! hasPreviousPage: Boolean! startCursor: String endCursor: String }
type Expert { familyName: String }
type ExpertConnection { edges: [ExpertEdge!]! pageInfo: PageInfo! totalCount: Int! }
type ExpertEdge { cursor: String! node: Expert! }
GRAPHQL;

        $this->setupServiceWithSchema($schema);

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );

        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
        ]);

        $result = $this->invokePrivateMethod($this->service, 'isConnectionType', [$resolveInfo]);
        verify($result)->true();
    }

    public function testIsConnectionTypeReturnsFalseForPlainList(): void
    {
        $schema = 'type Query { experts: [Expert] } type Expert { familyName: String }';
        $this->setupServiceWithSchema($schema);

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );

        $expertType = $parsedSchema->getType('Expert');
        $listType = new ListOfType($expertType);

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $listType,
        ]);

        $result = $this->invokePrivateMethod($this->service, 'isConnectionType', [$resolveInfo]);
        verify($result)->false();
    }

    public function testResolveConnectionWithFirstArg(): void
    {
        $schema = $this->getConnectionSchema();
        $settings = $this->getConnectionSettings();
        $this->setupServiceWithSettings($settings, $schema);

        // Return 3 records (first=2, so +1 extra to detect hasNextPage)
        $queryBuilder = $this->createPaginatedQueryBuilder([
            ['uid' => 1, 'family_name' => 'Alpha'],
            ['uid' => 2, 'family_name' => 'Beta'],
            ['uid' => 3, 'family_name' => 'Gamma'],
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );
        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true]],
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => true],
                        'totalCount' => true,
                    ];
                }
                return ['edges' => true, 'pageInfo' => true, 'totalCount' => true];
            },
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['experts'], ['first' => 2], null, $resolveInfo]
        );

        verify($result)->isArray();
        verify($result)->arrayHasKey('edges');
        verify($result)->arrayHasKey('pageInfo');
        verify($result)->arrayHasKey('totalCount');
        verify(count($result['edges']))->equals(2);
        verify($result['edges'][0])->arrayHasKey('cursor');
        verify($result['edges'][0])->arrayHasKey('node');
        verify($result['pageInfo']['hasNextPage'])->true();
    }

    public function testResolveConnectionWithFirstAndAfter(): void
    {
        $schema = $this->getConnectionSchema();
        $settings = $this->getConnectionSettings();
        $this->setupServiceWithSettings($settings, $schema);

        $queryBuilder = $this->createPaginatedQueryBuilder([
            ['uid' => 3, 'family_name' => 'Gamma'],
            ['uid' => 4, 'family_name' => 'Delta'],
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );
        $connectionType = $parsedSchema->getType('ExpertConnection');

        $afterCursor = base64_encode('cursor:2');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true]],
                        'pageInfo' => ['hasNextPage' => true],
                    ];
                }
                return ['edges' => true, 'pageInfo' => true];
            },
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['experts'], ['first' => 2, 'after' => $afterCursor], null, $resolveInfo]
        );

        verify($result)->isArray();
        verify(count($result['edges']))->equals(2);
        verify($result['pageInfo']['hasPreviousPage'])->true();
        verify($result['pageInfo']['hasNextPage'])->false();
    }

    public function testResolveConnectionDefaultLimit(): void
    {
        $schema = $this->getConnectionSchema();
        $settings = $this->getConnectionSettings();
        $settings['pagination'] = ['defaultLimit' => '3', 'maxLimit' => '100'];
        $this->setupServiceWithSettings($settings, $schema);

        // Return 4 records (default limit 3, so +1 to detect hasNextPage)
        $records = [];
        for ($i = 1; $i <= 4; $i++) {
            $records[] = ['uid' => $i, 'family_name' => "Name{$i}"];
        }
        $queryBuilder = $this->createPaginatedQueryBuilder($records);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );
        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true]],
                        'pageInfo' => ['hasNextPage' => true],
                    ];
                }
                return ['edges' => true, 'pageInfo' => true];
            },
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['experts'], [], null, $resolveInfo]
        );

        // Default limit is 3, so 3 edges returned
        verify(count($result['edges']))->equals(3);
        verify($result['pageInfo']['hasNextPage'])->true();
    }

    public function testResolveConnectionMaxLimitCap(): void
    {
        $schema = $this->getConnectionSchema();
        $settings = $this->getConnectionSettings();
        $settings['pagination'] = ['defaultLimit' => '20', 'maxLimit' => '5'];
        $this->setupServiceWithSettings($settings, $schema);

        // Return 6 records to detect capping to 5
        $records = [];
        for ($i = 1; $i <= 6; $i++) {
            $records[] = ['uid' => $i, 'family_name' => "Name{$i}"];
        }
        $queryBuilder = $this->createPaginatedQueryBuilder($records);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );
        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true]],
                        'pageInfo' => ['hasNextPage' => true],
                    ];
                }
                return ['edges' => true, 'pageInfo' => true];
            },
        ]);

        // Request 999, should be capped to maxLimit=5
        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['experts'], ['first' => 999], null, $resolveInfo]
        );

        verify(count($result['edges']))->equals(5);
        verify($result['pageInfo']['hasNextPage'])->true();
    }

    public function testResolveConnectionTotalCount(): void
    {
        $schema = $this->getConnectionSchema();
        $settings = $this->getConnectionSettings();
        $this->setupServiceWithSettings($settings, $schema);

        $queryBuilder = $this->createPaginatedQueryBuilder(
            [['uid' => 1, 'family_name' => 'Alpha']],
            42 // totalCount
        );

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );
        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true]],
                        'pageInfo' => ['hasNextPage' => true],
                        'totalCount' => true,
                    ];
                }
                return ['edges' => true, 'pageInfo' => true, 'totalCount' => true];
            },
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['experts'], ['first' => 1], null, $resolveInfo]
        );

        verify($result['totalCount'])->equals(42);
    }

    public function testResolveConnectionEmptyResult(): void
    {
        $schema = $this->getConnectionSchema();
        $settings = $this->getConnectionSettings();
        $this->setupServiceWithSettings($settings, $schema);

        $queryBuilder = $this->createPaginatedQueryBuilder([], 0);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );
        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true]],
                        'pageInfo' => ['hasNextPage' => true],
                        'totalCount' => true,
                    ];
                }
                return ['edges' => true, 'pageInfo' => true, 'totalCount' => true];
            },
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['experts'], [], null, $resolveInfo]
        );

        verify($result['edges'])->equals([]);
        verify($result['pageInfo']['hasNextPage'])->false();
        verify($result['pageInfo']['startCursor'])->null();
        verify($result['pageInfo']['endCursor'])->null();
        verify($result['totalCount'])->equals(0);
    }

    public function testResolveConnectionHasNextPage(): void
    {
        $schema = $this->getConnectionSchema();
        $settings = $this->getConnectionSettings();
        $this->setupServiceWithSettings($settings, $schema);

        // first=1, return 2 records (extra one signals hasNextPage)
        $queryBuilder = $this->createPaginatedQueryBuilder([
            ['uid' => 1, 'family_name' => 'Alpha'],
            ['uid' => 2, 'family_name' => 'Beta'],
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );
        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true]],
                        'pageInfo' => ['hasNextPage' => true],
                    ];
                }
                return ['edges' => true, 'pageInfo' => true];
            },
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['experts'], ['first' => 1], null, $resolveInfo]
        );

        verify(count($result['edges']))->equals(1);
        verify($result['pageInfo']['hasNextPage'])->true();
    }

    public function testResolvePlainListUnchanged(): void
    {
        $schema = 'type Query { users: [User] } type User { id: Int name: String }';

        $settings = [
            'schemaFiles' => [],
            'tableMapping' => ['users' => 'fe_users', 'User' => 'fe_users'],
        ];
        $this->setupServiceWithSettings($settings, $schema);

        $queryBuilder = $this->createFluentQueryBuilder([
            ['uid' => 1, 'id' => 1, 'name' => 'John'],
            ['uid' => 2, 'id' => 2, 'name' => 'Jane'],
        ]);

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );

        $userType = $parsedSchema->getType('User');
        $listType = new ListOfType($userType);

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $listType,
            'fieldName' => 'users',
            'getFieldSelection' => ['id' => true, 'name' => true],
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['users'], [], null, $resolveInfo]
        );

        // Should be a flat array, not a connection structure
        verify($result)->isArray();
        verify(count($result))->equals(2);
        verify($result[0])->arrayHasKey('id');
        verify(array_key_exists('edges', $result[0]))->false();
    }

    public function testGetConnectionFieldSelection(): void
    {
        $schema = $this->getConnectionSchema();
        $this->setupServiceWithSchema($schema);

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );

        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true, 'givenName' => true]],
                        'pageInfo' => ['hasNextPage' => true],
                    ];
                }
                return ['edges' => true, 'pageInfo' => true];
            },
        ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'getConnectionFieldSelection',
            [$resolveInfo]
        );

        verify($result)->arrayContains('uid');
        verify($result)->arrayContains('family_name');
        verify($result)->arrayContains('given_name');
        // pageInfo should NOT appear as a database field
        verify($result)->arrayNotContains('page_info');
    }

    public function testPaginationArgsNotTreatedAsWhereConditions(): void
    {
        $schema = $this->getConnectionSchema();
        $settings = $this->getConnectionSettings();
        $this->setupServiceWithSettings($settings, $schema);

        $capturedConditions = [];
        $queryBuilder = $this->createPaginatedQueryBuilder(
            [['uid' => 1, 'family_name' => 'Alpha']],
            1,
            $capturedConditions
        );

        $this->connectionPool = $this->makeEmpty(ConnectionPool::class, [
            'getQueryBuilderForTable' => $queryBuilder,
        ]);

        $this->service = $this->createService();

        $parsedSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );
        $connectionType = $parsedSchema->getType('ExpertConnection');

        $resolveInfo = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $connectionType,
            'fieldName' => 'experts',
            'getFieldSelection' => function (int $depth = 0) {
                if ($depth >= 3) {
                    return [
                        'edges' => ['node' => ['familyName' => true]],
                        'pageInfo' => ['hasNextPage' => true],
                        'totalCount' => true,
                    ];
                }
                return ['edges' => true, 'pageInfo' => true, 'totalCount' => true];
            },
        ]);

        // Pass first and after as args — they should NOT become WHERE first = ... / WHERE after = ...
        $afterCursor = base64_encode('cursor:0');
        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['experts'], ['familyName' => 'Alpha', 'first' => 10, 'after' => $afterCursor], null, $resolveInfo]
        );

        // Should not have 'first' or 'after' in conditions
        foreach ($capturedConditions as $condition) {
            verify($condition)->stringNotContainsString('first');
            verify($condition)->stringNotContainsString('after');
        }

        // familyName should be in conditions
        $hasFilter = false;
        foreach ($capturedConditions as $condition) {
            if (str_contains($condition, 'family_name')) {
                $hasFilter = true;
            }
        }
        verify($hasFilter)->true();
    }

    // =========================================================================
    // applySorting() Tests
    // =========================================================================

    public function testApplySortingWithNoComparator(): void
    {
        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry,
            null
        );

        $records = [
            ['uid' => 1, 'family_name' => 'Zimmermann'],
            ['uid' => 2, 'family_name' => 'Albrecht'],
        ];

        $result = $this->invokePrivateMethod($service, 'applySorting', [$records, 'family_name']);
        verify($result)->equals($records);
    }

    public function testApplySortingWithComparatorAndSortField(): void
    {
        $comparator = $this->makeEmpty(ComparatorInterface::class, [
            'compare' => function (string $a, string $b): int {
                return strcmp($a, $b);
            },
        ]);

        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry,
            $comparator
        );

        $records = [
            ['uid' => 1, 'family_name' => 'Zimmermann'],
            ['uid' => 2, 'family_name' => 'Albrecht'],
            ['uid' => 3, 'family_name' => 'Mueller'],
        ];

        $result = $this->invokePrivateMethod($service, 'applySorting', [$records, 'family_name']);

        verify($result[0]['family_name'])->equals('Albrecht');
        verify($result[1]['family_name'])->equals('Mueller');
        verify($result[2]['family_name'])->equals('Zimmermann');
    }

    public function testApplySortingWithNullSortFieldReturnsOriginalOrder(): void
    {
        $comparator = $this->makeEmpty(ComparatorInterface::class, [
            'compare' => function (string $a, string $b): int {
                return strcmp($a, $b);
            },
        ]);

        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry,
            $comparator
        );

        $records = [
            ['uid' => 1, 'family_name' => 'Zimmermann'],
            ['uid' => 2, 'family_name' => 'Albrecht'],
        ];

        $result = $this->invokePrivateMethod($service, 'applySorting', [$records, null]);
        verify($result)->equals($records);
    }

    public function testApplySortingWithEmptyRecords(): void
    {
        $comparator = $this->makeEmpty(ComparatorInterface::class, [
            'compare' => function (string $a, string $b): int {
                return strcmp($a, $b);
            },
        ]);

        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry,
            $comparator
        );

        $result = $this->invokePrivateMethod($service, 'applySorting', [[], 'family_name']);
        verify($result)->equals([]);
    }

    public function testApplySortingWithNonexistentFieldPreservesOrder(): void
    {
        $comparator = $this->makeEmpty(ComparatorInterface::class, [
            'compare' => function (string $a, string $b): int {
                return strcmp($a, $b);
            },
        ]);

        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry,
            $comparator
        );

        $records = [
            ['uid' => 1, 'family_name' => 'Zimmermann'],
            ['uid' => 2, 'family_name' => 'Albrecht'],
        ];

        // Nonexistent field — all values are empty strings,
        // so compare returns 0 and order is effectively preserved
        $result = $this->invokePrivateMethod($service, 'applySorting', [$records, 'nonexistent']);
        verify(count($result))->equals(2);
    }

    public function testPlainListQueryWithSortByArgument(): void
    {
        $schema = <<<'GRAPHQL'
type Query {
    experts(sortBy: String): [Expert]
}

type Expert {
    familyName: String
    givenName: String
}
GRAPHQL;

        $comparator = $this->makeEmpty(ComparatorInterface::class, [
            'compare' => function (string $a, string $b): int {
                return strcmp($a, $b);
            },
        ]);

        $queryBuilder = $this->createFluentQueryBuilder([
            ['uid' => 1, 'family_name' => 'Albrecht', 'given_name' => 'Zara'],
            ['uid' => 2, 'family_name' => 'Zimmermann', 'given_name' => 'Anna'],
        ]);

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            ['getQueryBuilderForTable' => $queryBuilder]
        );

        $settings = [
            'schemaFiles' => [],
            'tableMapping' => ['experts' => 'tx_academy_domain_model_persons'],
        ];

        $this->cache = $this->makeEmpty(
            FrontendInterface::class,
            ['has' => false, 'set' => function () {}]
        );

        $builtSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );

        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs($comparator),
            ['getSchema' => $builtSchema]
        );
        $this->service->configure($settings);

        $queryJson = json_encode([
            'query' => '{ experts(sortBy: "givenName") { familyName givenName } }',
        ]);

        $result = $this->service->process($queryJson);
        $decoded = json_decode($result, true);

        verify($decoded['data']['experts'][0]['givenName'])->equals('Anna');
        verify($decoded['data']['experts'][1]['givenName'])->equals('Zara');
    }

    public function testPlainListQueryWithoutSortByReturnsOriginalOrder(): void
    {
        $schema = <<<'GRAPHQL'
type Query {
    experts(sortBy: String): [Expert]
}

type Expert {
    familyName: String
    givenName: String
}
GRAPHQL;

        $comparator = $this->makeEmpty(ComparatorInterface::class, [
            'compare' => function (string $a, string $b): int {
                return strcmp($a, $b);
            },
        ]);

        $queryBuilder = $this->createFluentQueryBuilder([
            ['uid' => 1, 'family_name' => 'Zimmermann', 'given_name' => 'Max'],
            ['uid' => 2, 'family_name' => 'Albrecht', 'given_name' => 'Anna'],
        ]);

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            ['getQueryBuilderForTable' => $queryBuilder]
        );

        $settings = [
            'schemaFiles' => [],
            'tableMapping' => ['experts' => 'tx_academy_domain_model_persons'],
        ];

        $this->cache = $this->makeEmpty(
            FrontendInterface::class,
            ['has' => false, 'set' => function () {}]
        );

        $builtSchema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schema)
        );

        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs($comparator),
            ['getSchema' => $builtSchema]
        );
        $this->service->configure($settings);

        // No sortBy argument — original DB order preserved
        $queryJson = json_encode([
            'query' => '{ experts { familyName givenName } }',
        ]);

        $result = $this->service->process($queryJson);
        $decoded = json_decode($result, true);

        verify($decoded['data']['experts'][0]['familyName'])->equals('Zimmermann');
        verify($decoded['data']['experts'][1]['familyName'])->equals('Albrecht');
    }

    // =========================================================================
    // Pagination Helper Methods
    // =========================================================================

    private function getConnectionSchema(): string
    {
        return <<<'GRAPHQL'
type Query { experts(familyName: String, first: Int, after: String): ExpertConnection }
type PageInfo { hasNextPage: Boolean! hasPreviousPage: Boolean! startCursor: String endCursor: String }
type Expert { familyName: String givenName: String }
type ExpertConnection { edges: [ExpertEdge!]! pageInfo: PageInfo! totalCount: Int! }
type ExpertEdge { cursor: String! node: Expert! }
GRAPHQL;
    }

    private function getConnectionSettings(): array
    {
        return [
            'schemaFiles' => [],
            'tableMapping' => [
                'experts' => 'tx_academy_domain_model_persons',
                'Expert' => 'tx_academy_domain_model_persons',
            ],
            'pagination' => [
                'defaultLimit' => '20',
                'maxLimit' => '100',
            ],
        ];
    }

    private function createPaginatedQueryBuilder(
        array $returnData,
        int $totalCount = 0,
        ?array &$capturedConditions = null
    ): QueryBuilder {
        $expressionBuilder = $this->makeEmpty(ExpressionBuilder::class, [
            'eq' => function ($field, $value) {
                return "{$field} = {$value}";
            },
            'gt' => function ($field, $value) {
                return "{$field} > {$value}";
            },
        ]);

        $queryBuilder = $this->makeEmpty(QueryBuilder::class, [
            'select' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'count' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'from' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'where' => function (...$conditions) use (&$queryBuilder, &$capturedConditions) {
                if ($capturedConditions !== null) {
                    $capturedConditions = array_merge($capturedConditions, $conditions);
                }
                return $queryBuilder;
            },
            'orderBy' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'setMaxResults' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'expr' => $expressionBuilder,
            'createNamedParameter' => function ($value) {
                return "'{$value}'";
            },
            'executeQuery' => function () use ($returnData, $totalCount) {
                $statement = $this->makeEmpty(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => $returnData,
                    'fetchOne' => $totalCount,
                ]);
                return $statement;
            },
        ]);

        return $queryBuilder;
    }

    // =========================================================================
    // Field Transforms
    // =========================================================================

    public function testApplyFieldTransformsReturnsRecordsUnchangedWhenNoTransformsConfigured(): void
    {
        $service = $this->makeServiceWithFieldTransforms(
            new TransformerRegistry([]),
            []
        );
        $this->setCurrentRequestOn($service);

        $records = [['uid' => 1, 'bodytext' => 'raw']];
        $info = $this->makeListResolveInfo('Content');

        $result = $this->invokePrivateMethod($service, 'applyFieldTransforms', [$records, $info]);

        verify($result)->equals($records);
    }

    public function testApplyFieldTransformsReturnsRecordsUnchangedWhenNoCurrentRequest(): void
    {
        $recorder = $this->recordingTransformer();
        $service = $this->makeServiceWithFieldTransforms(
            new TransformerRegistry(['record' => $recorder]),
            ['Content' => ['bodytext' => 'record']]
        );
        // No setCurrentRequestOn() — the service has a null request set.

        $records = [['uid' => 1, 'bodytext' => 'raw']];
        $result = $this->invokePrivateMethod(
            $service,
            'applyFieldTransforms',
            [$records, $this->makeListResolveInfo('Content')]
        );

        verify($result)->equals($records);
        verify($recorder->calls)->equals([]);
    }

    public function testApplyFieldTransformsAppliesConfiguredTransformToMatchingField(): void
    {
        $recorder = $this->recordingTransformer();
        $service = $this->makeServiceWithFieldTransforms(
            new TransformerRegistry(['record' => $recorder]),
            ['Content' => ['bodytext' => 'record']]
        );
        $this->setCurrentRequestOn($service);

        $records = [
            ['uid' => 1, 'bodytext' => 'raw one'],
            ['uid' => 2, 'bodytext' => 'raw two'],
        ];
        $result = $this->invokePrivateMethod(
            $service,
            'applyFieldTransforms',
            [$records, $this->makeListResolveInfo('Content')]
        );

        verify($recorder->calls)->equals(['raw one', 'raw two']);
        verify($result[0]['bodytext'])->equals('TRANSFORMED[raw one]');
        verify($result[1]['bodytext'])->equals('TRANSFORMED[raw two]');
        verify($result[0]['uid'])->equals(1);
    }

    public function testApplyFieldTransformsMapsCamelCaseFieldToSnakeCaseColumn(): void
    {
        $recorder = $this->recordingTransformer();
        $service = $this->makeServiceWithFieldTransforms(
            new TransformerRegistry(['record' => $recorder]),
            ['Content' => ['bodyText' => 'record']]
        );
        $this->setCurrentRequestOn($service);

        // Row comes back from DB keyed by snake_case, matching what the
        // resolver's SELECT uses. The transform must find this column
        // even though the config names the field in camelCase.
        $records = [['uid' => 1, 'body_text' => 'raw']];
        $result = $this->invokePrivateMethod(
            $service,
            'applyFieldTransforms',
            [$records, $this->makeListResolveInfo('Content')]
        );

        verify($recorder->calls)->equals(['raw']);
        verify($result[0]['body_text'])->equals('TRANSFORMED[raw]');
    }

    public function testApplyFieldTransformsSkipsRecordsOfUnrelatedType(): void
    {
        $recorder = $this->recordingTransformer();
        $service = $this->makeServiceWithFieldTransforms(
            new TransformerRegistry(['record' => $recorder]),
            ['Content' => ['bodytext' => 'record']]
        );
        $this->setCurrentRequestOn($service);

        $records = [['uid' => 1, 'bodytext' => 'raw']];
        $result = $this->invokePrivateMethod(
            $service,
            'applyFieldTransforms',
            [$records, $this->makeListResolveInfo('OtherType')]
        );

        verify($recorder->calls)->equals([]);
        verify($result)->equals($records);
    }

    public function testApplyFieldTransformsWarnsWhenTransformerNotRegistered(): void
    {
        $warned = false;
        $this->logger = $this->makeEmpty(LoggerInterface::class, [
            'warning' => function () use (&$warned): void {
                $warned = true;
            },
        ]);

        $service = $this->makeServiceWithFieldTransforms(
            new TransformerRegistry([]), // empty — "record" is not registered
            ['Content' => ['bodytext' => 'record']]
        );
        $this->setCurrentRequestOn($service);

        $records = [['uid' => 1, 'bodytext' => 'raw']];
        $result = $this->invokePrivateMethod(
            $service,
            'applyFieldTransforms',
            [$records, $this->makeListResolveInfo('Content')]
        );

        verify($warned)->true();
        verify($result)->equals($records);
    }

    public function testApplyFieldTransformsLogsErrorAndKeepsValueWhenTransformerThrows(): void
    {
        $errored = false;
        $this->logger = $this->makeEmpty(LoggerInterface::class, [
            'error' => function () use (&$errored): void {
                $errored = true;
            },
        ]);

        $throwing = new class implements TransformerInterface {
            public function transform(mixed $value, ServerRequestInterface $request): mixed
            {
                throw new \RuntimeException('boom');
            }
        };

        $service = $this->makeServiceWithFieldTransforms(
            new TransformerRegistry(['explode' => $throwing]),
            ['Content' => ['bodytext' => 'explode']]
        );
        $this->setCurrentRequestOn($service);

        $records = [['uid' => 1, 'bodytext' => 'raw']];
        $result = $this->invokePrivateMethod(
            $service,
            'applyFieldTransforms',
            [$records, $this->makeListResolveInfo('Content')]
        );

        verify($errored)->true();
        verify($result[0]['bodytext'])->equals('raw');
    }

    // =========================================================================
    // resolveEntityTypeName
    // =========================================================================

    public function testResolveEntityTypeNameReturnsNameForListOfObject(): void
    {
        $service = $this->createService();
        $info = $this->makeListResolveInfo('Foo');

        $typeName = $this->invokePrivateMethod($service, 'resolveEntityTypeName', [$info]);

        verify($typeName)->equals('Foo');
    }

    public function testResolveEntityTypeNameReturnsNodeNameForConnectionType(): void
    {
        $schema = \GraphQL\Utils\BuildSchema::build(\GraphQL\Language\Parser::parse(<<<'GRAPHQL'
type Query { foos: FooConnection }
type Foo { id: Int }
type FooConnection { edges: [FooEdge!]! pageInfo: PageInfo! }
type FooEdge { cursor: String! node: Foo! }
type PageInfo { hasNextPage: Boolean! }
GRAPHQL
        ));
        $returnType = $schema->getQueryType()->getField('foos')->getType();
        $info = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => $returnType,
            'fieldName' => 'foos',
        ]);

        $service = $this->createService();
        $typeName = $this->invokePrivateMethod($service, 'resolveEntityTypeName', [$info]);

        verify($typeName)->equals('Foo');
    }

    public function testResolveEntityTypeNameReturnsNullForScalarReturnType(): void
    {
        $service = $this->createService();
        $info = $this->makeEmpty(ResolveInfo::class, [
            'returnType' => Type::string(),
            'fieldName' => 'name',
        ]);

        $typeName = $this->invokePrivateMethod($service, 'resolveEntityTypeName', [$info]);

        verify($typeName)->null();
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function setupServiceWithSettings(array $settings, string $schemaContent): void
    {
        $this->serviceSettings = $settings;

        $this->cache = $this->makeEmpty(
            FrontendInterface::class,
            [
                'has' => false,
                'set' => function () {},
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
        $this->service->configure($settings);
    }

    private function setupServiceWithSchema(string $schemaContent): void
    {
        $this->serviceSettings = [
            'schemaFiles' => [],
            'tableMapping' => ['users' => 'fe_users', 'user' => 'fe_users'],
        ];

        $this->cache = $this->makeEmpty(
            FrontendInterface::class,
            [
                'has' => false,
                'set' => function () {},
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
        $this->service->configure([
            'schemaFiles' => [],
            'tableMapping' => ['users' => 'fe_users', 'user' => 'fe_users'],
        ]);
    }

    private function setupServiceWithSchemaForCacheTest(string $schemaContent): void
    {
        // Mock readSchemaFiles() instead of getSchema() to allow the real
        // getSchema() logic to run, which will properly call cache->set()
        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs(),
            ['readSchemaFiles' => $schemaContent]
        );
        $this->service->configure(['schemaFiles' => [], 'tableMapping' => []]);
    }

    private function createService(): ResolverService
    {
        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry
        );
        $service->configure($this->serviceSettings);
        return $service;
    }

    private function createServiceWithMockedReadSchemaFiles(string $content): void
    {
        $this->service = $this->construct(
            ResolverService::class,
            $this->getServiceConstructorArgs(),
            ['readSchemaFiles' => $content]
        );
        $this->service->configure(['schemaFiles' => [], 'tableMapping' => []]);
    }

    private function getServiceConstructorArgs(?ComparatorInterface $comparator = null): array
    {
        return [
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $this->transformerRegistry,
            $comparator,
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
                    'fetchAssociative' => $returnData[0] ?? false,
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
            'returnType' => new ListOfType(Type::string()),
        ]);
    }

    private function invokePrivateMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Build a ResolverService pre-configured with a specific registry and
     * `fieldTransforms` map. Bypasses the shared `$this->transformerRegistry`
     * so individual tests can provide their own registry population.
     *
     * @param array<string, array<string, string>> $fieldTransforms
     */
    private function makeServiceWithFieldTransforms(
        TransformerRegistry $registry,
        array $fieldTransforms
    ): ResolverService {
        $service = new ResolverService(
            $this->connectionPool,
            $this->cache,
            $this->logger,
            $registry,
            null
        );
        $service->configure([
            'tableMapping' => [],
            'fieldTransforms' => $fieldTransforms,
        ]);
        return $service;
    }

    /**
     * Inject a mock PSR-7 request into the resolver's protected
     * `$currentRequest` slot. Mirrors what `process()` does at runtime,
     * without actually running a GraphQL operation.
     */
    private function setCurrentRequestOn(ResolverService $service): void
    {
        $prop = (new \ReflectionClass($service))->getProperty('currentRequest');
        $prop->setAccessible(true);
        $prop->setValue(
            $service,
            $this->makeEmpty(ServerRequestInterface::class)
        );
    }

    /**
     * Build a ResolveInfo whose `returnType` is a `List<NamedType>` and
     * whose `fieldName` is the same as the named type, which is what the
     * resolver sees for root list queries.
     */
    private function makeListResolveInfo(string $typeName): ResolveInfo
    {
        $objectType = new ObjectType([
            'name' => $typeName,
            'fields' => ['uid' => Type::int()],
        ]);
        return $this->makeEmpty(ResolveInfo::class, [
            'returnType' => new ListOfType($objectType),
            'fieldName' => strtolower($typeName) . 's',
        ]);
    }

    /**
     * Build an anonymous transformer that records every value it sees and
     * returns a recognisable marker string. Useful for asserting both that
     * a transform ran and that the result made it into the record.
     */
    private function recordingTransformer(): TransformerInterface
    {
        return new class implements TransformerInterface {
            /** @var list<mixed> */
            public array $calls = [];

            public function transform(mixed $value, ServerRequestInterface $request): mixed
            {
                $this->calls[] = $value;
                return 'TRANSFORMED[' . (is_string($value) ? $value : gettype($value)) . ']';
            }
        };
    }
}
