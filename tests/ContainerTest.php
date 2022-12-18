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
     * @throws NotFoundExceptionInterface
     */
    public function testBindWithZeroConfiguration(): void
    {
        $bar = $this->container->get(Bar::class);
        $this->assertInstanceOf(Bar::class, $bar);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->container = Container::getInstance();
    }
}

interface FooInterface
{
    public function fooMethod(): void;
}

class Foo implements FooInterface
{
    private Zoo $zoo;

    public function __construct(Zoo $zoo)
    {
        $this->zoo = $zoo;
    }

    public function fooMethod(): void
    {
        // TODO: Implement fooMethod() method.
    }
}

class Zoo
{

}

class Bar
{
    private Foo $foo;

    public function __construct(Foo $foo)
    {
        $this->foo = $foo;
    }
}