<?php

namespace Chaungoclong\Container\Tests;

use Chaungoclong\Container\Container;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
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

}
