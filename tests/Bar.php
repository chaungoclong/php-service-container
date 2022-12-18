<?php

namespace Chaungoclong\Container\Tests;

class Bar
{
    private Foo $foo;

    public function __construct(Foo $foo)
    {
        $this->foo = $foo;
    }

    public function print(Foo $foo): void
    {
        echo get_class($foo);
    }
}