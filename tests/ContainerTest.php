<?php

namespace Chaungoclong\Container\Tests;

use Chaungoclong\Container\Container;
use Chaungoclong\Container\Exceptions\BindingResolutionException;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    public Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = Container::getInstance();
    }


    /**
     * Test Container instance is singleton
     * @return void
     */
    public function testGetInstance(): void
    {
        $container1 = Container::getInstance();
        $container2 = Container::getInstance();

        $this->assertEquals($container1, $container2);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testBind(): void
    {
        $this->container->bind(Bar::class, Bar::class);
        $bar = $this->container->resolve(Bar::class);
        $this->assertInstanceOf(Bar::class, $bar);
    }


}
