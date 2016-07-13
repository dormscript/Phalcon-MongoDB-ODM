<?php

namespace MemMaker\MongoDB;

use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Phalcon\Di;
use Phalcon\Mvc\CollectionInterface;
use Phalcon\Text;

class Model extends \MongoDB\Collection implements Di\InjectionAwareInterface
{

    protected $_id;
    protected $_di;
    protected $_attributes;
    private static $collection;

    public static function init()
    {
        static::$collection = (new static(Di::getDefault()->get('mongo'), Di::getDefault()->getDI()->get('config')->mongodb->database, static::getSource()));
        return static::$collection;
    }

    public static function mongoTime()
    {
        return new UTCDateTime(round(microtime(true) * 1000) . '');
    }

    public function getId($asString = true)
    {
        return $asString ? (string)$this->_id : $this->_id;
    }

    public function save()
    {
        if ($attributes != null) {
            $this->fill($attributes);
        }
        $this->event('beforeSave');
        if (isset($this->_id)) {
            $this->event('beforeUpdate');
            $this->updateOne(['_id' => $this->_id], ['$set' => $this->_attributes]);
            $this->event('afterUpdate');
        } else {
            $this->event('beforeCreate');
            $insertResult = $this->insertOne($this->_attributes);
            $this->_id = $insertResult->getInsertedId();
            $this->event('afterCreate');
        }
        $this->event('afterSave');
        return $this;
    }

    public function update(array $attributes)
    {
        $this->event('beforeSave');
        $this->event('beforeUpdate');
        $this->fill($attributes);
        $this->updateOne(['_id' => $this->_id], ['$set' => $attributes]);
        $this->event('afterUpdate');
        $this->event('afterSave');
        return $this;
    }

    public function increment($argument, $value = 1)
    {
        $this->{$argument} += $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);
        return $this;
    }

    public function decrement($argument, $value = 1)
    {
        $this->{$argument} -= $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);
        return $this;
    }

    public function delete()
    {
        $this->event('beforeDelete');
        $this->deleteOne(['_id' => $this->getId(false)]);
        $this->event('afterDelete');
        return $this;
    }

    public function unsetField($field)
    {
        $path = explode('.', $field);
        $lastPart = end($path);
        if (count($path) > 1) {
            $ref = $this->getAttrRef($field, 1);
        } else {
            $ref = &$this->_attributes;
        }
        if ($ref != false) {
            $type = gettype($ref);
            if ($type == 'object' && isset($ref->{$lastPart})) {
                unset($ref->{$lastPart});
            } else if ($type == 'array' && isset($ref[$lastPart])) {
                unset($ref[$lastPart]);
            } else {
                return false;
            }
            $this->updateOne(['_id' => $this->_id], ['$unset' => [$field => '']]);
            return true;
        }
        return false;
    }

    public function beforeCreate()
    {
        $this->created_at = self::mongoTime();
    }

    public function beforeUpdate()
    {
        $this->updated_at = self::mongoTime();
    }

    protected function castArrayAttributes(array $data, $useMutators = false)
    {
        foreach ($data as $param => $value)
        {
            if ($useMutators)
            {
                $methodName = 'set' . Text::camelize($param);
                $data[$param] = method_exists($this, $methodName) ? $this->{$methodName}($value) : $this->castAttribute($param, $value);
            }
            else
            {
                $data[$param] = $this->castAttribute($param, $value);
            }
        }
        return $data;
    }

    public function castAttribute($param, $value)
    {
        if (isset(static::$casts[$param])) {
            $type = static::$casts[$param];
            if ($type == 'id') {
                if (!($value instanceof ObjectID)) {
                    try {
                        return new ObjectID((string)$value);
                    } catch (\Exception $e) {
                        return null;
                    }
                }
                return $value;
            } else if (in_array($type, ['integer', 'float', 'boolean', 'string', 'array', 'object'])) {
                settype($value, $type);
            }
        }
        return $value;
    }


    public function toArray($params = [])
    {
        $attributes = array_merge(['id' => (string)$this->_id], $this->_attributes);
        if (isset($params['include']) || isset($params['exclude'])) {
            $attributes = array_filter($attributes, function ($value, $key) use ($params) {
                if (isset($params['include'])) {
                    return in_array($key, $params['include']);
                }
                return !in_array($key, $params['exclude']);
            }, ARRAY_FILTER_USE_BOTH);
        }
        $attributes = array_map(function ($item) {
            if (gettype($item) == 'object') {
                if ($item instanceof ObjectID) {
                    return (string)$item;
                } elseif($item instanceof UTCDateTime){
                    return $item->toDateTime()->format(DATE_ISO8601);
                } else {
                    return (array)$item;
                }
            }
            return $item;
        }, $attributes);
        $relations = array_map(function ($item) {
            if (gettype($item) == 'object') {
                return $item->toArray();
            } else if (gettype($item) == 'array') {
                return array_map(function ($item1) {
                    return $item1->toArray();
                }, $item);
            }
            return $item;
        }, $this->_relations);
        $result = array_merge($attributes, $relations);
        return $result;
    }

    protected function event($name)
    {
        if (method_exists($this, $name)) {
            $this->{$name}();
        }
    }

    protected function getAttrRef($path, $rightOffset = 0)
    {
        $path = explode('.', $path);
        $length = count($path) - $rightOffset;
        $return = &$this->_attributes;
        for ($i = 0; $i <= $length - 1; ++$i) {
            if (isset($return->{$path[$i]})) {
                if ($i == $length - 1) {
                    return $return->{$path[$i]};
                } else {
                    $return = &$return->{$path[$i]};
                }
            } else if (isset($return[$path[$i]])) {
                if ($i == $length - 1) {
                    return $return[$path[$i]];
                } else {
                    $return = &$return[$path[$i]];
                }
            } else {
                return false;
            }
        }
        return $return;
    }

    public function __get($name)
    {
        $methodName = 'get' . Text::camelize($name);
        return isset($this->_attributes[$name]) ? (method_exists($this, $methodName) ? $this->{$methodName}($this->_attributes[$name]) : $this->_attributes[$name])
            : (isset($this->_relations[$name]) ? $this->_relations[$name]
                : (isset(static::$relations[$name]) ? $this->loadRelation($name) : null));
    }

    public function __set($name, $value)
    {
        $methodName = 'set' . Text::camelize($name);
        $this->_attributes[$name] = method_exists($this, $methodName) ? $this->{$methodName}($value) : $this->castAttribute($name, $value);
    }

    public function __toString()
    {
        return json_encode($this->toArray());
    }

    /**
     * Sets the dependency injector
     *
     * @param mixed $dependencyInjector
     */
    public function setDI(\Phalcon\DiInterface $dependencyInjector)
    {
        $this->_di = $dependencyInjector;
    }

    /**
     * Returns the internal dependency injector
     *
     * @return \Phalcon\DiInterface
     */
    public function getDI()
    {
        return $this->_di;
    }


    public static function get()
    {
        return static::init()->find();
    }
}
