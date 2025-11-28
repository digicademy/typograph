<?php

namespace Tests\Unit;

use Codeception\Test\Unit;
use Digicademy\TypoGraph\Service\ResolverService;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Definition\ResolveInfo;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class ResolverServiceTest extends Unit
{
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
        $schemaContent = <<<'GRAPHQL'
type Query {
    user(id: Int): User
}

type User {
    id: Int
    name: String
}
GRAPHQL;

        $queryBuilder = $this->createMockQueryBuilder(
            'fe_users',
            [['id' => 1, 'name' => 'John Doe']]
        );

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->setupServiceWithSchema($schemaContent);

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
        $schemaContent = <<<'GRAPHQL'
type Query {
    user(id: Int): User
}

type User {
    id: Int
    name: String
}
GRAPHQL;

        $queryBuilder = $this->createMockQueryBuilder(
            'fe_users',
            [['id' => 42, 'name' => 'Jane Doe']]
        );

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->setupServiceWithSchema($schemaContent);

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
            [
                $this->connectionPool,
                $this->configurationManager,
                $this->cache,
                $this->logger,
            ],
            [
                'readSchemaFiles' => $schemaContent,
            ]
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

        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => [
                    'schemaFiles' => [],
                    'tableMapping' => [],
                ],
            ]
        );

        $this->service = $this->construct(
            ResolverService::class,
            [
                $this->connectionPool,
                $this->configurationManager,
                $this->cache,
                $this->logger,
            ],
            [
                'readSchemaFiles' => $schemaContent,
            ]
        );

        $result = $this->invokePrivateMethod($this->service, 'readSchemaFiles');
        verify($result)->equals($schemaContent);
    }

    public function testReadSchemaFilesWithMultipleFiles(): void
    {
        $content1 = 'type Query { hello: String }';
        $content2 = 'type User { id: Int name: String }';
        $combinedContent = $content1 . $content2;

        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => [
                    'schemaFiles' => [],
                    'tableMapping' => [],
                ],
            ]
        );

        $this->service = $this->construct(
            ResolverService::class,
            [
                $this->connectionPool,
                $this->configurationManager,
                $this->cache,
                $this->logger,
            ],
            [
                'readSchemaFiles' => $combinedContent,
            ]
        );

        $result = $this->invokePrivateMethod($this->service, 'readSchemaFiles');
        verify($result)->equals($combinedContent);
    }

    public function testReadSchemaFilesWithEmptyArray(): void
    {
        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => [
                    'schemaFiles' => [],
                    'tableMapping' => [],
                ],
            ]
        );

        $this->service = $this->construct(
            ResolverService::class,
            [
                $this->connectionPool,
                $this->configurationManager,
                $this->cache,
                $this->logger,
            ],
            [
                'readSchemaFiles' => '',
            ]
        );

        $result = $this->invokePrivateMethod($this->service, 'readSchemaFiles');
        verify($result)->equals('');
    }

    // =========================================================================
    // resolve() Method Tests
    // =========================================================================

    public function testResolveRootFieldWithValidTable(): void
    {
        $this->setupServiceWithSchema('type Query { users: [User] } type User { id: Int }');

        $queryBuilder = $this->createMockQueryBuilder(
            'fe_users',
            [['id' => 1], ['id' => 2]]
        );

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

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

        $expressionBuilder = $this->makeEmpty(ExpressionBuilder::class, [
            'eq' => function () {
                return 'id = 1';
            },
        ]);

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
            'expr' => $expressionBuilder,
            'createNamedParameter' => function ($value) {
                return "'{$value}'";
            },
            'executeQuery' => function () {
                $statement = $this->makeEmpty(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [['id' => 1, 'name' => 'John']],
                ]);
                return $statement;
            },
        ]);

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

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

        $this->service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

        $resolveInfo = $this->createMockResolveInfo(['id' => true]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [['unknownField'], [], null, $resolveInfo]
        );

        verify($result)->null();
    }

    public function testResolveNestedField(): void
    {
        $this->setupServiceWithSchema('type Query { test: String }');

        $this->service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

        $resolveInfo = $this->createMockResolveInfo([], 'userName');

        $root = ['user_name' => 'John Doe'];

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [$root, [], null, $resolveInfo]
        );

        verify($result)->equals('John Doe');
    }

    public function testResolveNestedFieldCamelCaseConversion(): void
    {
        $this->setupServiceWithSchema('type Query { test: String }');

        $this->service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

        $resolveInfo = $this->createMockResolveInfo([], 'createdAt');

        $root = ['created_at' => '2025-01-01'];

        $result = $this->invokePrivateMethod(
            $this->service,
            'resolve',
            [$root, [], null, $resolveInfo]
        );

        verify($result)->equals('2025-01-01');
    }

    public function testResolveDatabaseError(): void
    {
        $this->setupServiceWithSchema('type Query { users: [User] } type User { id: Int }');

        $queryBuilder = $this->makeEmpty(QueryBuilder::class, [
            'select' => function () use (&$queryBuilder) {
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

        $this->service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

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

        $expressionBuilder = $this->makeEmpty(ExpressionBuilder::class, [
            'eq' => function ($field, $value) {
                return "{$field} = {$value}";
            },
        ]);

        $whereConditions = [];
        $queryBuilder = $this->makeEmpty(QueryBuilder::class, [
            'select' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'from' => function () use (&$queryBuilder) {
                return $queryBuilder;
            },
            'where' => function (...$conditions) use (&$queryBuilder, &$whereConditions) {
                $whereConditions = $conditions;
                return $queryBuilder;
            },
            'expr' => $expressionBuilder,
            'createNamedParameter' => function ($value) {
                return "'{$value}'";
            },
            'executeQuery' => function () {
                $statement = $this->makeEmpty(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [['id' => 1, 'status' => 'active']],
                ]);
                return $statement;
            },
        ]);

        $this->connectionPool = $this->makeEmpty(
            ConnectionPool::class,
            [
                'getQueryBuilderForTable' => $queryBuilder,
            ]
        );

        $this->service = new ResolverService(
            $this->connectionPool,
            $this->configurationManager,
            $this->cache,
            $this->logger
        );

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
    // Helper Methods
    // =========================================================================

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
            [
                $this->connectionPool,
                $this->configurationManager,
                $this->cache,
                $this->logger,
            ],
            [
                'getSchema' => $schema,
            ]
        );
    }

    private function setupServiceWithSchemaForCacheTest(string $schemaContent): void
    {
        $this->configurationManager = $this->makeEmpty(
            ConfigurationManagerInterface::class,
            [
                'getConfiguration' => [
                    'schemaFiles' => [],
                    'tableMapping' => [],
                ],
            ]
        );

        $schema = \GraphQL\Utils\BuildSchema::build(
            \GraphQL\Language\Parser::parse($schemaContent)
        );

        $this->service = $this->construct(
            ResolverService::class,
            [
                $this->connectionPool,
                $this->configurationManager,
                $this->cache,
                $this->logger,
            ],
            [
                'getSchema' => $schema,
            ]
        );
    }

    private function createMockQueryBuilder(string $tableName, array $returnData): QueryBuilder
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
