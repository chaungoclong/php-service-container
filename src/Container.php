<?php

namespace Chaungoclong\Container;

use Chaungoclong\Container\Exceptions\DependencyResolutionException;
use Chaungoclong\Container\Exceptions\EntryNotFoundException;
use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

class Container implements ContainerInterface
{
    /**
     * @var array The dependency's bindings.
     */
    protected array $bindings = [];

    /**
     * @var object[] Singleton instances of dependencies
     */
    protected array $instances = [];

    /**
     * @var object[] The dependencies of concrete class which was resolved.
     */
    protected array $dependenciesResolved = [];

    /**
     * @param array $bindings
     */
    public function __construct(array $bindings = [])
    {
        $this->bindings = $bindings;
    }

    /**
     * Bind abstract with instance
     *
     * @param string $abstract
     * @param mixed  $instance
     *
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
     * @param string $abstract
     * @param        $concrete
     *
     * @return void
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * @param string              $abstract
     * @param Closure|string|null $concrete
     * @param bool                $singleton
     *
     * @return void
     */
    public function bind(string $abstract, $concrete = null, bool $singleton = false): void
    {
        if (is_string($concrete)) {
            unset($this->dependenciesResolved[$concrete]);
        }

        $this->bindings[$abstract] = [
            'concrete'  => $concrete,
            'singleton' => $singleton,
        ];
    }

    /**
     * Inject dependencies of method and execute it
     *
     * @param array|string $callback
     * @param array        $overrideParameters
     *
     * @return mixed
     * @throws DependencyResolutionException
     */
    public function call($callback, array $overrideParameters = [])
    {
        $className         = $methodName = null;
        $defaultMethodName = '__invoke';

        if (is_string($callback)) {
            $segments  = explode('@', $callback);
            $className = $segments[0];
            if (count($segments) === 1 && !method_exists($className, $defaultMethodName)) {
                throw new InvalidArgumentException("Method not provided");
            }
            $methodName = $segments[1] ?? $defaultMethodName;
        }

        if (is_array($callback)) {
            $countCallback = count($callback);

            if ($countCallback === 0) {
                throw new InvalidArgumentException("Class not provided");
            }

            if (!is_string($callback[0]) && !is_object($callback[0])) {
                throw new InvalidArgumentException("Class must be string or object");
            }

            $className = is_object($callback[0]) ? get_class((object)$callback[0]) : $callback[0];

            if ($countCallback === 1 && !method_exists($className, $defaultMethodName)) {
                throw new InvalidArgumentException("Method not provided");
            }

            if (isset($callback[1]) && !is_string($callback[1])) {
                throw new InvalidArgumentException("Method must be string");
            }

            $methodName = $callback[1] ?? $defaultMethodName;
        }

        if (is_null($className) && is_null($methodName)) {
            throw new InvalidArgumentException("Class or method not provided");
        }

        $object = $this->resolve($className);

        try {
            $method       = new ReflectionMethod($object, $methodName);
            $dependencies = $this->resolveMethodDependencies($method, $overrideParameters);
            return $method->invokeArgs($object, $dependencies);
        } catch (ReflectionException $e) {
            throw new DependencyResolutionException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param string $abstract
     * @param array  $overrideParameters
     *
     * @return object
     * @throws DependencyResolutionException
     */
    public function resolve(string $abstract, array $overrideParameters = []): object
    {
        // Return singleton instance of dependency if exists
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->bindings[$abstract]['concrete'] ?? $abstract;

        // If concrete is a Closure, or it is abstract, I will try to instantiate it.
        // Otherwise, the concrete must be referencing something else,
        // So we'll recursively resolve it until we get either a singleton instance, a closure,
        // or run out of references(when the concrete is equivalent to the abstract).
        // After that, I will try to instantiate last reference.
        if ($concrete instanceof Closure || $concrete === $abstract) {
            $object = $this->build($concrete, $overrideParameters);
        } else {
            $object = $this->resolve($concrete, $overrideParameters);
        }

        // If the abstract is registered as a singleton,
        // I will store its instance into the instances array so that I can return it without creating a new instance next time
        if (isset($this->instances[$abstract])
            || (isset($this->bindings[$abstract]['singleton']) && $this->bindings[$abstract]['singleton'] === true)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * @param Closure|string $concrete
     * @param array          $overrideParameters
     *
     * @return object
     * @throws DependencyResolutionException
     */
    protected function build($concrete, array $overrideParameters = []): object
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $overrideParameters);
        }

        if (isset($this->dependenciesResolved[$concrete])) {
            return new $concrete(...$this->dependenciesResolved[$concrete]);
        }

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new DependencyResolutionException("Target class [$concrete] does not exist", 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new DependencyResolutionException("Target class [$concrete] is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = $this->resolveMethodDependencies($constructor, $overrideParameters);

        $this->dependenciesResolved[$concrete] = $dependencies;

        return new $concrete(...$dependencies);
    }

    /**
     * @param ReflectionMethod $method
     * @param array            $overrideParameters
     *
     * @return array
     * @throws DependencyResolutionException
     */
    private function resolveMethodDependencies(ReflectionMethod $method, array $overrideParameters = []): array
    {
        $parameters = $method->getParameters();

        return $this->resolveDependencies($parameters, $overrideParameters);
    }

    /**
     * @param ReflectionParameter[] $parameters
     * @param array                 $overrideParameters
     *
     * @return array
     * @throws DependencyResolutionException
     */
    protected function resolveDependencies(array $parameters, array $overrideParameters = []): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            if (array_key_exists($parameter->name, $overrideParameters)) {
                $dependencies[] = $overrideParameters[$parameter->name];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }

            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                $message = "Unresolvable dependency resolving [$parameter]";
                if ($parameter->getDeclaringClass() !== null) {
                    $message .= " in class {$parameter->getDeclaringClass()->getName()}";
                }
                throw new DependencyResolutionException($message);
            }

            $className = $type->getName();
            if (($class = $parameter->getDeclaringClass()) !== null) {
                if ($className === 'self') {
                    $className = $class->getName();
                } elseif ($className === 'parent' && ($parent = $class->getParentClass()) !== null) {
                    $className = $parent->getName();
                }
            }
            $dependencies[] = $this->resolve($className);
        }

        return $dependencies;
    }

    /**
     * @inheritDoc
     */
    public function get(string $id)
    {
        try {
            return $this->resolve($id);
        } catch (DependencyResolutionException $e) {
            if ($this->has($id)) {
                throw $e;
            }

            throw new EntryNotFoundException($id, is_int($e->getCode()) ? $e->getCode() : 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }
}