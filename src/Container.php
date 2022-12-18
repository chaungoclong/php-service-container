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
     * @var Container|null Singleton instance of Container
     */
    private static ?Container $instance = null;

    /**
     * @var array The Container's bindings(bind abstract to concrete class)
     * structure:
     * [
     *  'abstract' => [
     *      'concrete' => Closure|string|NULL
     *      'singleton' => bool
     *  ]
     * ]
     */
    private array $bindings = [];

    /**
     * @var object[] The instances of singleton concrete class
     */
    private array $instances = [];

    /**
     * @var array Parameters used to override default dependencies
     */
    private array $overrideParameters = [];

    private function __construct()
    {
    }

    /**
     * Get singleton instance of Container
     * @return Container
     */
    public static function getInstance(): Container
    {
        if (is_null(static::$instance)) {
            static::$instance = new Container();
        }

        return static::$instance;
    }

    /**
     * Bind abstract with concrete class
     * @param string $abstract
     * @param mixed $concrete
     * @param bool $singleton
     * @return void
     */
    public function bind(string $abstract, $concrete, bool $singleton = false): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'singleton' => $singleton
        ];
    }

    /**
     * Bind abstract with singleton concrete class
     * @param string $abstract
     * @param mixed $concrete
     * @return void
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Bind abstract with instance
     * @param string $abstract
     * @param mixed $instance
     * @return void
     */
    public function instance(string $abstract, $instance): void
    {
        if (is_object($instance)) {
            $this->instances[$abstract] = $instance;
        } else {
            $this->singleton($abstract, $instance);
        }
    }

    /**
     * Get instance of concrete class of abstract
     * @param string $abstract
     * @param array $overrideParameters
     * @return mixed
     * @throws BindingResolutionException
     */
    public function resolve(string $abstract, array $overrideParameters = [])
    {
        // Return singleton instance of concrete class if it already exists
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Set override parameters
        // I don't want to pass this parameter a lot of time to multiple methods, So I make it as a class property
        // This is array of array because resolve is recurves method
        $this->overrideParameters[] = $overrideParameters;

        // Get concrete of abstract
        $concrete = $this->bindings[$abstract]['concrete'] ?? $abstract;

        // If concrete is a Closure, or it is abstract, I will try to instantiate it.
        // Otherwise, the concrete must be referencing something else,
        // So we'll recursively resolve it until we get either a singleton instance, a closure,
        // or run out of references(when the concrete is equivalent to the abstract).
        // After that, I will try to instantiate last reference.
        if ($concrete instanceof Closure || $concrete === $abstract) {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }

        // After resolve instance of concrete class of the abstract,
        // I remove the override parameter to be ready for the next call
        array_pop($this->overrideParameters);

        return $object;
    }

    /**
     * Instantiate a concrete instance of the given abstract.
     * @param mixed $concrete
     * @return mixed
     * @throws BindingResolutionException
     */
    private function build($concrete)
    {
        // If concrete is Closure then execute it to instantiate a concrete instance of the abstract
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->overrideParameters);
        }

        // Try init reflector of concrete
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new BindingResolutionException("Target class [$concrete] does not exist.", 0, $e);
        }

        // Get constructor of concrete class
        $constructor = $reflector->getConstructor();

        // If the concrete class has no constructor then return its new instance
        if (is_null($constructor)) {
            return new $concrete();
        }

        // Get parameters of constructor
        $parameters = $constructor->getParameters();
        $dependencies = $this->resolveDependencies($parameters);

        try {
            return $reflector->newInstanceArgs($dependencies);
        } catch (ReflectionException $e) {
            throw new BindingResolutionException("Cannot instantiate");
        }
    }

    /**
     * Resolve all the dependencies from parameters of constructor of concrete class.
     * @param ReflectionParameter[] $parameters
     * @return array
     * @throws BindingResolutionException
     */
    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            // If the parameter has an overridden value, use the overridden value for dependency
            if ($this->hasOverrideParameter($parameter)) {
                $dependencies[] = $this->getOverrideParameter($parameter);

                continue;
            }

            // If class name of parameter is null -> type of parameter is primitive(string, int ..)
            // -> can not resolve(can only use default value if exists or throw exception)
            // Otherwise, resolve dependency from parameter
            $dependency = is_null($this->getParameterClassName($parameter))
                ? $this->resolvePrimitive($parameter)
                : $this->resolveClass($parameter);

            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    /**
     * Get the override parameter corresponding to the recursive level of resolve method
     * @return array
     */
    private function getLastParametersOverride(): array
    {
        return count($this->overrideParameters) ? end($this->overrideParameters) : [];
    }

    /**
     * Check parameter has override value
     * @param ReflectionParameter $parameter
     * @return bool
     */
    private function hasOverrideParameter(ReflectionParameter $parameter): bool
    {
        return array_key_exists($parameter->name, $this->getLastParametersOverride());
    }

    /**
     * Get override value of parameter if exists
     * @param ReflectionParameter $parameter
     * @return mixed
     */
    private function getOverrideParameter(ReflectionParameter $parameter)
    {
        return $this->getLastParametersOverride()[$parameter->name];
    }

    /**
     * Alias of resolve
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     * @throws BindingResolutionException
     */
    public function make(string $abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     * Get class name of parameter
     * @param ReflectionParameter $parameter
     * @return string|null null if it not has a class name
     */
    private function getParameterClassName(ReflectionParameter $parameter): ?string
    {
        // Parameter's type
        $type = $parameter->getType();

        // If parameter's type is builtin type(string, int ...) or it not instanceof ReflectionNamedType return null
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        // Type's name
        $name = $type->getName();

        // If the parameter is declared in the class and the name of the type is 'self' or 'parent'
        // then get the name of the corresponding class
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
     * Resolve primitive dependency
     * @return mixed
     * @throws BindingResolutionException
     */
    private function resolvePrimitive(ReflectionParameter $parameter)
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if (!is_null($class = $parameter->getDeclaringClass())) {
            $message = "Unresolvable dependency resolving [$parameter] in class {$class->getName()}";
        } else {
            $message = "Parameter $parameter is not declared in class";
        }

        throw new BindingResolutionException($message);
    }

    /**
     * Resolve a dependency whose type is class
     * @return mixed
     * @throws BindingResolutionException
     */
    private function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($this->getParameterClassName($parameter));
        } catch (BindingResolutionException $e) {
            if ($parameter->isDefaultValueAvailable()) {
                // When call make in try $this->overrideParameters is pushed, so we need pop it if occur exception
                array_pop($this->overrideParameters);

                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }
}