<?php
namespace EasyDI;


use EasyDI\Exception\UnknownIdentifierException;
use EasyDI\Exception\InvalidArgumentException;
use EasyDI\Exception\InstantiateException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class Container implements ContainerInterface
{
    /**
     * 保存 参数, 已实例化的对象
     * @var array
     */
    private $instance = [];

    private $shared = [];

    private $raw = [];

    private $params = [];

    /**
     * 保存 定义的 工厂等
     * @var array
     */
    private $binding = [];

//    private $dependenciesCirculateDetect = [];

    /**
     * 别名
     * @var array
     */
//    private $alias = [];
    public function __construct()
    {
        $this->raw(ContainerInterface::class, $this);
        $this->raw(self::class, $this);
    }


    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @throws NotFoundExceptionInterface  No entry was found for **this** identifier.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     *
     * @return mixed Entry.
     */
    public function get($id, $parameters = [])
    {
        if (!$this->has($id)) {
            throw new UnknownIdentifierException($id);
        }

        if (array_key_exists($id, $this->raw)) {
            return $this->raw[$id];
        }

        if (array_key_exists($id, $this->instance)) {
            return $this->instance[$id];
        }

        $define = $this->binding[$id];
        if ($define instanceof \Closure) {
            $instance = $this->call($define, $parameters);
        } else {
            // string
            $class = $define;
            $params = $this->params[$id] + $parameters;

            // Case: "\\xxx\\xxx"=>"abc"
            if ($id !== $class && $this->has($class)) {
                $instance = $this->get($class, $params);
            } else {
                $dependencies = $this->getClassDependencies($class, $params);
                if (is_null($dependencies) || empty($dependencies)) {
                    $instance = $this->getReflectionClass($class)->newInstanceWithoutConstructor();
                } else {
                    $instance = $this->getReflectionClass($class)->newInstanceArgs($dependencies);
                }
            }
        }

        if ($this->shared[$id]) {
            $this->instance[$id] = $instance;
        }
        return $instance;
    }

    /**
     * @param callback $function
     * @param array $parameters
     * @return mixed
     * @throws InvalidArgumentException 传入错误的参数
     * @throws InstantiateException
     */
    public function call($function, $parameters=[])
    {
        //TODO 若要实现类似 call_user_func, 则要解析 $callable. 可参考 http://php.net/manual/zh/function.call-user-func-array.php#121292

        $class = null;
        $method = null;
        $object = null;
        // Case1: function() {}
        if ($function instanceof \Closure) {
            $method = $function;
        } elseif (is_array($function) && count($function)==2) {
            // Case2: [$object, $methodName]
            if (is_object($function[0])) {
                $object = $function[0];
                $class = get_class($object);
            } elseif (is_string($function[0])) {
                // Case3: [$className, $staticMethodName]
                $class = $function[0];
            }

            if (is_string($function[1])) {
                $method = $function[1];
            }
        } elseif (is_string($function) && strpos($function, '::') !== false) {
            // Case4: "class::staticMethod"
            list($class, $method) = explode('::', $function);
        } elseif (is_scalar($function)) {
            // Case5: "functionName"
            $method = $function;
        } else {
            throw new InvalidArgumentException("Case not allowed! Invalid Data supplied!");
        }

        try {
            if (!is_null($class) && !is_null($method)) {
                $reflectionFunc = $this->getReflectionMethod($class, $method);
            } elseif (!is_null($method)) {
                $reflectionFunc = $this->getReflectionFunction($method);
            } else {
                throw new InvalidArgumentException("class:$class method:$method");
            }
        } catch (\ReflectionException $e) {
//            var_dump($e->getTraceAsString());
            throw new InvalidArgumentException("class:$class method:$method", 0, $e);
        }

        $parameters = $this->getFuncDependencies($reflectionFunc, $parameters);

        if ($reflectionFunc instanceof \ReflectionFunction) {
            return $reflectionFunc->invokeArgs($parameters);
        } elseif ($reflectionFunc->isStatic()) {
            return $reflectionFunc->invokeArgs(null, $parameters);
        } elseif (!empty($object)) {
            return $reflectionFunc->invokeArgs($object, $parameters);
        }

        throw new InvalidArgumentException("class:$class method:$method, unable to invoke.");
    }

    /**
     * @param $class
     * @param array $parameters
     * @throws \ReflectionException
     */
    protected function getClassDependencies($class, $parameters=[])
    {
        // 获取类的反射类
        $reflectionClass = $this->getReflectionClass($class);

        if (!$reflectionClass->isInstantiable()) {
            throw new InstantiateException($class);
        }

        // 获取构造函数反射类
        $reflectionMethod = $reflectionClass->getConstructor();
        if (is_null($reflectionMethod)) {
            return null;
        }

        return $this->getFuncDependencies($reflectionMethod, $parameters=[], $class);
    }

    protected function getFuncDependencies(\ReflectionFunctionAbstract $reflectionFunc, $parameters=[], $class="")
    {
        $params = [];
        // 获取构造函数参数的反射类
        $reflectionParameterArr = $reflectionFunc->getParameters();
        foreach ($reflectionParameterArr as $reflectionParameter) {
            $paramName = $reflectionParameter->getName();
            $paramPos = $reflectionParameter->getPosition();
            $paramClass = $reflectionParameter->getClass();
            $context = ['pos'=>$paramPos, 'name'=>$paramName, 'class'=>$paramClass, 'from_class'=>$class];

            // 优先考虑 $parameters
            if (isset($parameters[$paramName]) || isset($parameters[$paramPos])) {
                $tmpParam = isset($parameters[$paramName]) ? $parameters[$paramName] : $parameters[$paramPos];
                if (gettype($tmpParam) == 'object' && !is_a($tmpParam, $paramClass->getName())) {
                    throw new InstantiateException($class."::".$reflectionFunc->getName(), $parameters + ['__context'=>$context, 'tmpParam'=>get_class($tmpParam)]);
                }
                $params[] = $tmpParam;
//                $params[] = isset($parameters[$paramName]) ? $parameters[$paramName] : $parameters[$pos];
            } elseif (empty($paramClass)) {
            // 若参数不是class类型

                // 优先使用默认值, 只能用于判断用户定义的函数/方法, 对系统定义的函数/方法无效, 也同样无法获取默认值
                if ($reflectionParameter->isDefaultValueAvailable()) {
                    $params[] = $reflectionParameter->getDefaultValue();
                } elseif ($reflectionFunc->isUserDefined()) {
                    throw new InstantiateException("UserDefined. ".$class."::".$reflectionFunc->getName());
                } elseif ($reflectionParameter->isOptional()) {
                    break;
                } else {
                    throw new InstantiateException("SystemDefined.  ".$class."::".$reflectionFunc->getName());
                }
            } else {
            // 参数是类类型, 优先考虑解析
                if ($this->has($paramClass->getName())) {
                    $params[] = $this->get($paramClass->getName());
                } elseif ($reflectionParameter->allowsNull()) {
                    $params[] = null;
                } else {
                    throw new InstantiateException($class."::".$reflectionFunc->getName()."  {$paramClass->getName()} ");
                }
            }
        }
        return $params;
    }

//    protected function make($id, $parameters = [])
//    {
//        if ($this->has($id)) {
//            return $this->get($id);
//        } elseif (is_string($id)) {
//            $reflectionClass = $this->getReflectionClass($id);
//
//        }
//    }

    protected function getReflectionClass($class)
    {
        static $cache = [];
        if (isset($cache[$class])) {
            return $cache[$class];
        }

        try {
            $reflectionClass = new \ReflectionClass($class);
        } catch (\Exception $e) {
            throw new InstantiateException($class, 0, $e);
        }

        return $cache[$class] = $reflectionClass;
    }

    protected function getReflectionMethod($class, $name)
    {
        static $cache = [];

        if (is_object($class)) {
            $class = get_class($class);
        }

        if (isset($cache[$class]) && isset($cache[$class][$name])) {
            return $cache[$class][$name];
        }
        $reflectionFunc = new \ReflectionMethod($class, $name);
        return $cache[$class][$name] = $reflectionFunc;
    }

    protected function getReflectionFunction($name)
    {
        static $closureCache;
        static $cache = [];

        $isClosure = is_object($name) && $name instanceof \Closure;
        $isString = is_string($name);

        if (!$isString && !$isClosure) {
            throw new InvalidArgumentException("$name can't get reflection func.");
        }

        if ($isString && array_key_exists($cache, $name)) {
            return $cache[$name];
        }

        if ($isClosure) {
            if (is_null($closureCache)) {
                $closureCache = new \SplObjectStorage();
            }
            if ($closureCache->contains($name)) {
                return $closureCache[$name];
            }
        }

        $reflectionFunc = new \ReflectionFunction($name);

        if ($isString) {
            $cache[$name] = $reflectionFunc;
        }
        if ($isClosure) {
            $closureCache->attach($name, $reflectionFunc);
        }

        return $reflectionFunc;
    }


    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
     * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return bool
     */
    public function has($id)
    {
        return array_key_exists($id, $this->binding) || array_key_exists($id, $this->raw) || array_key_exists($id, $this->instance);
    }

    public function needResolve($id)
    {
        return !(array_key_exists($id, $this->raw) && (array_key_exists($id, $this->instance) && $this->shared[$id]));
    }

    public function keys()
    {
        return array_unique(array_merge(array_keys($this->raw), array_keys($this->binding), array_keys($this->instance)));
    }

    public function instanceKeys()
    {
        return array_unique(array_keys($this->instance));
    }

    public function unset($id)
    {
        unset($this->shared[$id], $this->binding[$id], $this->raw[$id], $this->instance[$id], $this->params[$id]);
    }

    public function singleton($id, $value, $params=[])
    {
        $this->set($id, $value, $params, true);
    }

    /**
     * 想好定义数组, 和定义普通项
     * @param $id
     * @param $value
     * @param bool $shared
     */
    public function set($id, $value, $params=[], $shared=false)
    {
        if (is_object($value) && !($value instanceof  \Closure)) {
            $this->raw($id, $value);
            return;
        } elseif ($value instanceof \Closure) {
            // no content
        } elseif (is_array($value)) {
            $value = [
                'class' => $id,
                'params' => [],
                'shared' => $shared
                ] + $value;
            if (!isset($value['class'])) {
                $value['class'] = $id;
            }
            $params = $value['params'] + $params;
            $shared = $value['shared'];
            $value = $value['class'];
        } elseif (is_string($value)) {
            // no content
        }
        $this->binding[$id] = $value;
        $this->shared[$id] = $shared;
        $this->params[$id] = $params;
    }

    public function raw($id, $value)
    {
        $this->unset($id);
        $this->raw[$id] = $value;
    }

    public function batchRaw(array $data)
    {
        foreach ($data as $key=>$value) {
            $this->raw($key, $value);
        }
    }

    public function batchSet(array $data, $shared=false)
    {
        foreach ($data as $key=>$value) {
            $this->set($key, $value, $shared);
        }
    }

}