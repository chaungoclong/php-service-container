<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Chaungoclong\Container\Container;
use Chaungoclong\Container\Tests\Bar;


$container = Container::getInstance();
$container->bind(Bar::class, Bar::class);
$bar = $container->resolve(Bar::class, ['foo' => new \Chaungoclong\Container\Tests\SubFoo()]);

var_dump($bar);