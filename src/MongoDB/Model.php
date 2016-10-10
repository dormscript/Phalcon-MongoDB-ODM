<?php
declare(strict_types = 1);  // MUAHAHAHAHAHAHAHHHH!!!!! finally..

namespace MemMaker\MongoDB;

use MongoDB\Driver\Command;
use MongoDB\Database;
use MongoDB\BSON\Javascript;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Manager;
use Phalcon\Di;
use Phalcon\Text;

// autoloading would not work for me, TODO: make it work
require_once 'Exceptions/EntryNotFoundException.php';
require_once 'Exceptions/ErrorOnInsertException.php';

class Model extends Collection
{

    protected $_id;
    protected $_attributes;
    private $modelName;
    private $hooks;

    ////// path mapping

    public static function collection(array $path)
    {
        $client = $path[0];
        if ($client == 'Master')
        {
            $dbname = 'Master';
            $collectionName = $path[1];
        }
        else
        {
            $spaceId = $path[1];
            $dbname = $client . '_' . $spaceId;
            $collectionName = $path[2];
        }
        return (new static(Di::getDefault()->getShared('mongo'), $dbname, $collectionName, ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]));
    }

    public function __construct(Manager $manager, $databaseName, $collectionName, array $options)
    {
        $this->modelName = $collectionName;
        $this->hooks = Di::getDefault()->getShared('hooks');
        parent::__construct($manager, $databaseName, $collectionName, $options);
    }

    ////// Basics

    /* Old Style CRUD Functions
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

    public function delete()
    {
        $this->event('beforeDelete');
        $this->deleteOne(['_id' => $this->getId(false)]);
        $this->event('afterDelete');
        return $this;
    }


    public function update(array $attributes)
    {
        $this->event('beforeSave');
        $this->event('beforeUpdate');
        $this->fill($attributes);
        static::collection()->updateOne(['_id' => $this->_id], ['$set' => $attributes]);
        $this->event('afterUpdate');
        $this->event('afterSave');
        return $this;
    }

    public function insert($entry)
    {
        return static::collection()->insertOne($entry);
    }

    */
    /// NOTES:
    // using static::collectin() means the the Model and thereby the Collection is chosen implicitly based upon the Classname used in the call

    public function create(array $data)
    {
        $data['_id'] = (string) (new ObjectID());
        $continueCreation = $this->hooks->beforeCreate($this->modelName, $data);
        if (!$continueCreation)
        {
            return false;
        }
        $result = $this->insertOne($data);
        $this->hooks->afterCreate($this->modelName, $data);
        return $result;
    }

    public function getById($id)
    {
        $result = $this->findOne(['_id' => $id]);
        if ($result == null)
        {
            throw new Exceptions\EntryNotFoundException(vsprintf("Entry with id '%1\$s' not found in collection '%2\$s'", [$id, $this->getCollectionName()]));
        }
        return $result;
    }

    public function replaceById(string $id, array $data)
    {
        $result = $this->replaceOne(['_id' => $id], $data);
        return $result;
    }

    public function deleteById($id)
    {
        return $this->deleteOne(['_id' => $id]);
    }

    ////// Advanced

    public function incrementById($id, $fieldName, $byValue = 1)
    {
        return $this->updateOne(['_id' => $id], ['$inc' => [$fieldName => $byValue]]);
    }

    public static function mapReduce($mapJS, $reduceJS, array $query = array())
    {
        $map = new Javascript($mapJS);
        $reduce = new Javascript($reduceJS);

        $source = static::getSource();  // TODO: remove or refactor
        $command = new Command([
            "mapreduce" => $source,
            "map" => $map,
            "reduce" => $reduce,
            "query" => new \stdClass(),
            "out" => 'results'
        ]);

        $manager = Di::getDefault()->get('mongo');
        $results = $manager->executeCommand('TheBackend', $command);

        return $results;
    }

    public static function getFullTextSearchQuery($model, $searchString, $searchLimit = 500)
    {
        $searchFields = array();
        foreach ($model['searchFields'] as $fieldname) {
            $searchFields[] = array($fieldname => new \MongoRegex('/' . $searchString . '/iu'));
        }
        return
            array(
                array('$or' => $searchFields),
                'sort' => $model['sortFieldOrder'],
                'limit' => $searchLimit
            );
    }

    public static function destroyDatabase(string $dbname)
    {
        $manager = Di::getDefault()->get('mongo');
        $db = new Database($manager, $dbname);
        $db->drop();
    }

    public static function createIndicesOnFields(string $modelName, array $fieldNames)
    {
        $keys = [];
        foreach ($fieldNames as $fieldName)
        {
            $keys[] = ['key' => [$fieldName => 1]];
        }
        static::collection($modelName)->createIndex($keys);
    }

    public static function createUniqueIndicesOnFields(string $modelName, array $fieldNames)
    {
        $keys = [];
        foreach ($fieldNames as $fieldName)
        {
            $keys[] = ['key' => [$fieldName => 1], 'unique' => true];
        }
        static::collection($modelName)->createIndex($keys);
    }


    ////// Complex (currently not integrated)


    /* Assigns all fields it can find in $data to the $this model object.
    /* Tries to parse everything correct according to fieldtype.  */
    public function assign($data)
    {
        $modelclassname = get_class($this);
        $model = $modelclassname::getModel();

        foreach ($model['fields'] as $fieldname => $fieldFlags)
        {
            if (!array_key_exists($fieldname, $data))
            {
                // no value for this fieldtype
                continue;
            }

            $fieldtype = $fieldFlags['type'];

            $this->$fieldname = array_key_exists('default', $fieldFlags) ? $fieldFlags['default'] : '';

            $valueConverter = Di::getDefault()->getShared('valueConverter');

            $convertedValue = $valueConverter->getConversion($data[$fieldname], $fieldtype);

            $this->setField($fieldname, $convertedValue);
        }
    }

    public function setField($fieldname, $value)
    {
        if ($this->$fieldname !== $value && (! in_array($fieldname, $this->changedFields)))
        {
            // only add to changedFields if the value changed and the field is not already in there
            $this->changedFields[] = $fieldname;
            $this->$fieldname = $value;
        }
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

    ////// Convenience

    public static function mongoTime()
    {
        return new UTCDateTime(round(microtime(true) * 1000) . '');
    }

    //////// Essentials

    public function __toString()
    {
        return json_encode($this->toArray());
    }

    //////// Events

    public function beforeCreate()
    {
        //$this->_id = strval(new \MongoId());
        $this->timestamp_entry_created = new UTCDatetime(time()*1000);
        $this->created_at = self::mongoTime();

        $session = Di::getDefault()->getShared('session');
        $username = $session->get('username');

        $this->entry_created_by = $username;
    }

    public function beforeSave()
    {
        $this->timestamp_entry_last_modified = new UTCDatetime(time()*1000);

        $session = Di::getDefault()->getShared('session');
        $username = $session->get('username');

        $this->entry_last_modified_by = $username;

        $this->changedFields = array();
    }

    public function beforeUpdate()
    {
        $this->updated_at = self::mongoTime();
    }
}
