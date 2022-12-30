<?php

namespace Chaungoclong\Container;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

class DI implements ContainerInterface
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
     * @param string $abstract
     * @param        $concrete
     *
     * @return void
     */
    public function singleton(string $abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
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
     * @param array  $overrideParameters
     *
     * @return object
     * @throws DependencyException
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
     * @throws DependencyException
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
            throw new DependencyException($e->getMessage(), 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new DependencyException('Cannot instant', 0);
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
     * @param ReflectionParameter[] $parameters
     * @param array                 $overrideParameters
     *
     * @return array
     * @throws DependencyException
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
                throw new DependencyException('Cannot resolve parameter: ' . $parameter->name, 0);
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
     * Inject dependencies of method and execute it
     *
     * @param array|string $instance
     * @param array        $overrideParameters
     *
     * @return mixed
     * @throws DependencyException
     */
    public function call($instance, array $overrideParameters = [])
    {
        $className = $methodName = null;

        if (is_string($instance)) {
            $defaultMethodName = method_exists($instance, '__invoke') ? '__invoke' : null;
            $segments          = explode('@', $instance);
            [$className, $methodName] = [$segments[0], $segments[1] ?? $defaultMethodName];

            if (is_null($methodName)) {
                throw new InvalidArgumentException('Method is not provided');
            }
        }

        if (is_array($instance)) {
            if (count($instance) === 0) {
                throw new InvalidArgumentException('Class is not provided');
            }

            $className         = is_string($instance[0]) ? $instance[0] : get_class($instance[0]);
            $defaultMethodName = method_exists($className, '__invoke') ? '__invoke' : null;
            $methodName        = $instance[1] ?? $defaultMethodName;

            if (is_null($methodName)) {
                throw new InvalidArgumentException('Method is not provided');
            }
        }

        if (is_null($className) || is_null($methodName)) {
            throw new InvalidArgumentException('Invalid class name or method name');
        }

        $object = $this->resolve($className);
        try {
            $method       = new ReflectionMethod($object, $methodName);
            $dependencies = $this->resolveMethodDependencies($method, $overrideParameters);

            return $method->invokeArgs($object, $dependencies);
        } catch (ReflectionException $e) {
            throw new DependencyException('Cannot call method:' . $methodName . ' on class:' . $className);
        }
    }

    /**
     * @param ReflectionMethod $method
     * @param array            $overrideParameters
     *
     * @return array
     * @throws DependencyException
     */
    private function resolveMethodDependencies(ReflectionMethod $method, array $overrideParameters = []): array
    {
        $parameters = $method->getParameters();

        return $this->resolveDependencies($parameters, $overrideParameters);
    }

    /**
     * @inheritDoc
     */
    public function get(string $id)
    {
        try {
            return $this->resolve($id);
        } catch (DependencyException $e) {
            if ($this->has($id)) {
                throw $e;
            }

            throw new DependencyException('Not found');
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