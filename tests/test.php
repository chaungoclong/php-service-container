<?php

class Bar
{

}

class Foo extends Bar
{
    public function bar(...$variadic)
    {
    }
}

class Baz extends Foo
{
}

//
//$param = new ReflectionParameter(['Baz', 'bar'], 0);
//var_dump($param->getDefaultValue());
//echo getParameterClassName($param);

$method = new ReflectionMethod(Foo::class, 'bar');
$parameters = $method->getParameters();
var_dump($parameters);
function getParameterClassName(ReflectionParameter $parameter): ?string
{
    $type = $parameter->getType();

    if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
        return null;
    }

    $name = $type->getName();

    if (!is_null($class = $parameter->getDeclaringClass())) {
        echo $class->getName();
        if ($name === 'self') {
            echo 'here';
            return $class->getName();
        }

        if ($name === 'parent' && $parent = $class->getParentClass()) {
            echo 'here 2';
            return $parent->getName();
        }
    }

    return $name;
}

$arr = [
    'abstract' => [
        'concrete' => 'Closure|null|string',
        'shared' => 'TRUE|FALSE',
        'context' => [
            'abstract' => 'concrete'
        ]
    ]
];