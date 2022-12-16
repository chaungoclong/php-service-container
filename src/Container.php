<?php

namespace Chaungoclong\Container;

use Chaungoclong\Container\Exceptions\BindingResolutionException;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

class Container
{
    /**
     * @var Container|null instance of Container
     */
    private static ?Container $instance = null;

    /**
     * @var array bind abstract to concrete
     *                  [
     *                  'abstract' => [
     *                  'concrete' => 'Closure|null|string',
     *                  'shared' => 'TRUE|FALSE',
     *                  'context' => [
     *                  'abstract' => 'concrete'
     *                  ]
     *                  ]
     *                  ]
     */
    private array $bindings = [];

    /**
     * @var object[] store instance of singleton concrete
     *            structure:
     *            ['abstract' => instance of singleton concrete]
     */
    private array $instances = [];

    /**
     * @var array The parameter override stack:when resolve a class instance sometime we want to override some
     *      dependencies of this class this array store those dependencies
     */
    private array $with = [];

    /**
     * @var array The
     */
    private array $contextual = [];

    /**
     * @var array The stack of concretions currently being built.
     */
    private array $buildStack = [];

    private function __construct()
    {
    }

    /**
     * bind abstract id to concrete
     *
     * @param string              $id       abstract id of abstract(abstract class, interface, class, any string)
     * @param string|Closure|null $concrete concrete class that will be instantiated
     * @param bool                $shared   true if the concrete is singleton
     *
     * @return void
     */
    public function bind(string $id, $concrete, bool $shared = false): void
    {
        $this->bindings[$id] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
    }

    /**
     * bind abstract id to singleton concrete
     *
     * @param string              $id       abstract id of abstract(abstract class, interface, class, any string)
     * @param string|Closure|null $concrete concrete class that will be instantiated
     *
     * @return void
     */
    public function singleton(string $id, $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    /**
     * make instance of concrete of abstract id
     *
     * @param string $abstract
     * @param array  $parameters
     *
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function resolve(string $abstract, array $parameters = [])
    {
        // return instance of concrete if it already exists
        if (isset($this->instance[$abstract])) {
            return $this->instance[$abstract];
        }

        $this->with[] = $parameters;

        // get concrete class of abstract id
        $concrete = $this->bindings[$abstract] ?? $abstract;

        // if concrete is Closure or concrete equals to abstract id then build a new instance from concrete
        // else if concrete is string and concrete not equals to abstract id then build a new instance from concrete of concrete
        // (now concrete is abstract)
        if ($concrete instanceof Closure || $concrete === $abstract) {
            $instance = $this->build($concrete);
        } else {
            $instance = $this->make($concrete);
        }

        // if concrete is singleton then store it to $instances
        if (isset($this->bindings[$abstract]) && $this->bindings[$abstract]['shared']) {
            $this->instances[$abstract] = $instance;
        }

        array_pop($this->with);

        return $instance;
    }

    /**
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function makeWith($abstract, array $parameters = [])
    {
        return $this->make($abstract, $parameters);
    }

    /**
     * @param       $concrete
     * @param array $parameters
     *
     * @return mixed
     * @throws BindingResolutionException|ReflectionException
     */
    private function make($concrete, array $parameters = [])
    {
        return $this->resolve($concrete, $parameters);
    }

    /**
     * @param Closure|string $concrete
     *
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    private function build($concrete)
    {
        // if the concrete type is actually a Closure, we will just execute it
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->getLastParameterOverride());
        }

        // init reflector of concrete
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new BindingResolutionException("Target class [$concrete] does not exist.", 0, $e);
        }

        // check concrete can instantiable(Ex: interface cannot instantiable)
        if (!$reflector->isInstantiable()) {
            throw new BindingResolutionException("Target [$concrete] is not instantiable.");
        }

        $this->buildStack[] = $concrete;

        // get constructor of concrete
        $constructor = $reflector->getConstructor();
        if (is_null($constructor)) {
            return new $concrete();
        }

        // get parameters of constructor(dependencies of concrete class)
        $dependencies = $constructor->getParameters();
        $instanceArguments = $this->resolveDependencies($dependencies);

        array_pop($this->buildStack);

        return $reflector->newInstanceArgs($instanceArguments);
    }


    /**
     * @param array $dependencies
     *
     * @return array
     * @throws BindingResolutionException
     */
    private function resolveDependencies(array $dependencies): array
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            // if the dependency has an override for itself then use override value
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            $result = is_null($this->getParameterClassName($dependency))
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency);

            if ($dependency->isVariadic()) {
                $results = [...$results, $result];
            } else {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param ReflectionParameter $parameter
     *
     * @return string|null
     */
    protected function getParameterClassName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        if (!is_null($class = $parameter->getDeclaringClass())) {
            if ($name === 'self') {
                return $class->getName();
            }

            if ($name === 'parent' && $parent = $class->getParentClass()) {
                return $parent->getName();
            }
        }

        return $name;
    }

    /**
     * @param $parameter
     *
     * @return mixed
     * @throws BindingResolutionException
     */
    private function resolvePrimitive($parameter)
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new BindingResolutionException($message);
    }

    /**
     * @param ReflectionParameter $parameter
     *
     * @return mixed
     */
    private function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $parameter->isVariadic()
                ? $this->resolveVariadicClass($parameter)
                : $this->make($parameter);
        } catch (BindingResolutionException|ReflectionException $e) {
        }
    }

    private function resolveVariadicClass(ReflectionParameter $parameter)
    {
    }

    /**
     * @return array
     */
    private function getLastParameterOverride(): array
    {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * @param $dependency
     *
     * @return bool
     */
    private function hasParameterOverride($dependency): bool
    {
        return array_key_exists($dependency->name, $this->getLastParameterOverride());
    }

    /**
     * @param ReflectionParameter $parameter
     *
     * @return mixed
     */
    private function getParameterOverride(ReflectionParameter $dependency)
    {
        return $this->getLastParameterOverride()[$dependency->name];
    }
}