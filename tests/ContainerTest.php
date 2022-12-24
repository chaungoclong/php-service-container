<?php

namespace Chaungoclong\Container\Tests;

use Chaungoclong\Container\Container;
use Chaungoclong\Container\Exceptions\BindingResolutionException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class ContainerTest extends TestCase
{
    public Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = Container::getInstance();
    }

    /**
     * @throws ContainerExceptionInterface
     */
    public function testCanCallClassMethodWithoutCreateInstanceOfClass(): void
    {
        $className = $this->container->call(
            [MysqlConnection::class, 'test'],
            ['configuration' => $this->container->make(MysqlConfiguration::class)]
        );
        $this->assertSame(MysqlConfiguration::class, $className);

        $className = $this->container->call(
            [
                'Chaungoclong\Container\Tests\MysqlConnection',
                'testStatic'
            ],
            ['configuration' => $this->container->make(MysqlConfiguration::class)]
        );
        $this->assertSame(MysqlConfiguration::class, $className);

        $className = $this->container->call(
            [MysqlConnection::class, 'testPrivate'],
            ['configuration' => $this->container->make(MysqlConfiguration::class)]
        );
        $this->assertSame(MysqlConfiguration::class, $className);
    }

    public function testInstanceOfContainerIsSingleton(): void
    {
        $container1 = Container::getInstance();
        $container2 = Container::getInstance();
        $this->assertSame($container1, $container2);
    }

    public function testCannotCreateNewInstanceOfContainerWithConstructor(): void
    {
        $this->expectError();
        new Container();
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCanResolveInstanceOfClass(): void
    {
        $this->container->bind(MysqlConnection::class, MysqlConnection::class);
        $connection = $this->container->make(MysqlConnection::class);
        $this->assertInstanceOf(MysqlConnection::class, $connection);

        $this->container->bind(SqlConnection::class, SqlConnection::class);
        $connection = $this->container->make(SqlConnection::class, ['dsn' => 'sql']);
        $this->assertInstanceOf(SqlConnection::class, $connection);

        $connection1 = $this->container->make(MysqlConnection::class);
        $connection2 = $this->container->make(MysqlConnection::class);
        $this->assertNotSame($connection1, $connection2);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCanResolveInstanceOfClassWithoutBinding(): void
    {
        $connection1 = $this->container->make(MysqlConnection::class);
        $connection2 = $this->container->make(MysqlConnection::class);
        $this->assertInstanceOf(MysqlConnection::class, $connection1);
        $this->assertInstanceOf(MysqlConnection::class, $connection2);
        $this->assertNotSame($connection1, $connection2);

        $connection3 = $this->container->make(SqlConnection::class, ['dsn' => 'sql']);
        $connection4 = $this->container->make(SqlConnection::class, ['dsn' => 'sql']);
        $this->assertInstanceOf(SqlConnection::class, $connection3);
        $this->assertInstanceOf(SqlConnection::class, $connection4);
        $this->assertNotSame($connection1, $connection2);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCanBindInterfaceToConcreteClass(): void
    {
        $this->container->bind(Connection::class, MysqlConnection::class);
        $connection = $this->container->make(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(MysqlConnection::class, $connection);

        $this->container->bind(Connection::class, SqlConnection::class);
        $connection = $this->container->make(SqlConnection::class, ['dsn' => 'sql']);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(SqlConnection::class, $connection);

        $this->assertNotInstanceOf(MysqlConnection::class, $connection);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCanResolveInstanceRecursively(): void
    {
        $this->container->bind(Connection::class, AbstractConnection::class);
        $this->container->bind(AbstractConnection::class, MysqlConnection::class);
        $connection = $this->container->make(Connection::class);
        $this->assertInstanceOf(MysqlConnection::class, $connection);
        $this->assertInstanceOf(AbstractConnection::class, $connection);
        $this->assertInstanceOf(Connection::class, $connection);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCanUseStringAsAbstract(): void
    {
        $this->container->bind('connection', MysqlConnection::class);
        $connection = $this->container->make('connection');
        $this->assertInstanceOf(MysqlConnection::class, $connection);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCanResolveInstanceWithConcreteIsClosure(): void
    {
        $this->container->singleton(MysqlConnection::class, function () {
            return $this->container->make(MysqlConfiguration::class);
        });
        $connection1 = $this->container->make(MysqlConnection::class);
        $connection2 = $this->container->make(MysqlConnection::class);
        $this->assertNotInstanceOf(MysqlConnection::class, $connection1);
        $this->assertNotInstanceOf(MysqlConnection::class, $connection2);
        $this->assertSame($connection1, $connection2);
        $this->assertInstanceOf(MysqlConfiguration::class, $connection1);

        $this->container->bind(Connection::class, function () {
            return $this->container->make(SqlConnection::class, ['dsn' => 'sql']);
        });
        $connection3 = $this->container->make(Connection::class);
        $this->assertInstanceOf(SqlConnection::class, $connection3);
        $this->assertInstanceOf(Connection::class, $connection3);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCanResolveSingletonInstanceOfClass(): void
    {
        $this->container->singleton(MysqlConnection::class);
        $connection1 = $this->container->make(MysqlConnection::class);
        $connection2 = $this->container->make(MysqlConnection::class);
        $this->assertInstanceOf(MysqlConnection::class, $connection1);
        $this->assertInstanceOf(MysqlConnection::class, $connection2);
        $this->assertSame($connection1, $connection2);

        $this->container->singleton(Connection::class, MysqlConnection::class);
        $connection3 = $this->container->make(Connection::class);
        $connection4 = $this->container->make(Connection::class);
        $this->assertInstanceOf(MysqlConnection::class, $connection1);
        $this->assertInstanceOf(MysqlConnection::class, $connection2);
        $this->assertInstanceOf(Connection::class, $connection1);
        $this->assertInstanceOf(Connection::class, $connection2);
        $this->assertSame($connection3, $connection4);

        $this->container->instance(MysqlConnection::class, MysqlConnection::class);
        $connection5 = $this->container->make(MysqlConnection::class);
        $connection6 = $this->container->make(MysqlConnection::class);
        $this->assertInstanceOf(MysqlConnection::class, $connection5);
        $this->assertInstanceOf(MysqlConnection::class, $connection6);
        $this->assertSame($connection5, $connection6);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testCanBindASingletonByPassingTheInstance(): void
    {
        $connection = $this->container->get(MysqlConnection::class);
        $this->container->instance(MysqlConnection::class, $connection);
        $connection1 = $this->container->get(MysqlConnection::class);
        $connection2 = $this->container->get(MysqlConnection::class);
        $this->assertInstanceOf(MysqlConnection::class, $connection);
        $this->assertInstanceOf(MysqlConnection::class, $connection1);
        $this->assertInstanceOf(MysqlConnection::class, $connection2);
        $this->assertSame($connection, $connection1);
        $this->assertSame($connection1, $connection2);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testImplementPsr11(): void
    {
        $connection = $this->container->get(MysqlConnection::class);
        $this->assertInstanceOf(MysqlConnection::class, $connection);

        $this->expectException(NotFoundExceptionInterface::class);
        $this->container->get('abc');

        $this->container->bind('connection', MysqlConnection::class);
        $connection = $this->container->get('connection');
        $this->assertInstanceOf(MysqlConnection::class, $connection);
        $this->assertTrue($this->container->has('connection'));

        $this->container->bind('connection', 'abc');
        $this->assertTrue($this->container->has('abc'));
        $this->expectException(ContainerExceptionInterface::class);
        $this->container->get('abc');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->container->flush();
    }
}

interface Connection
{
    public function connect(): void;
}

abstract class AbstractConnection implements Connection
{

}

class MysqlConnection extends AbstractConnection
{
    public function __construct(MysqlConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function connect(): void
    {
        // TODO: Implement connect() method.
    }

    public function test(MysqlConfiguration $configuration): string
    {
        return get_class($configuration);
    }

    public static function testStatic(MysqlConfiguration $configuration): string
    {
        return get_class($configuration);
    }

    public static function testPrivate(MysqlConfiguration $configuration): string
    {
        return get_class($configuration);
    }
}

class SqlConnection extends AbstractConnection
{
    private string $dsn;

    public function __construct(string $dsn)
    {
        $this->dsn = $dsn;
    }

    public function connect(): void
    {
        // TODO: Implement connect() method.
    }
}


class MysqlConfiguration
{

}