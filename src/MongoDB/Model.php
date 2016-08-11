<?php
declare(strict_types = 1);  // MUAHAHAHAHAHAHAHHHH!!!!! finally..

namespace MemMaker\MongoDB;

use MongoDB\Driver\Command;
use MongoDB\Database;
use MongoDB\BSON\Javascript;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Model\BSONDocument;
use Phalcon\Di;
use Phalcon\Mvc\CollectionInterface;
use Phalcon\Text;

// autoloading would not work for me, TODO: make it work
require_once 'Exceptions/EntryNotFoundException.php';
require_once 'Exceptions/ErrorOnInsertException.php';

class Model extends \MongoDB\Collection
{

    protected $_id;
    protected $_attributes;

    ////// Bootstrapping

    public static function collection($collectionName = '')
    {
        $client = Di::getDefault()->getShared('dispatcher')->getParam('client');
        $config = Di::getDefault()->getShared('config');
        $dbname = $config->mongodb->database;
        if (!in_array($client, ['Master', '']))
        {
            $dbname = $client;
        }
        if ($collectionName == '')
        {
            $collectionName = static::getSource();
        }
        return (new static(Di::getDefault()->getShared('mongo'), $dbname, $collectionName));
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

    public static function get(array $filter = [], array $options = [])
    {
        return static::collection()->find($filter, $options);
    }

    public static function create(array $data)
    {
        $hooks = Di::getDefault()->getShared('hooks');

        $data['_id'] = (string) (new ObjectID());
        $hooks->beforeCreate($data);
        $result = static::collection()->insertOne($data);
        $hooks->afterCreate($result);
        return $result;
    }

    public static function getById($id)
    {
        $collection = static::collection();
        $result = $collection->findOne(['_id' => $id]);
        if ($result == null)
        {
            throw new \MemMaker\MongoDB\Exceptions\EntryNotFoundException(vsprintf("Entry with id '%1\$s' not found in collection '%2\$s'", [$id, $collection->getCollectionName()]));
        }
        return $result;
    }

    public static function replaceById(string $id, array $data)
    {
        $result = static::collection()->replaceOne(['_id' => $id], $data);
        return $result;
    }

    public static function deleteById($id)
    {
        return static::collection()->deleteOne(['_id' => $id]);
    }

    ///// Explicit Model Methods (needed for Entries Class)

    public static function getByModel(string $model, array $filter = [], array $options = [])
    {
        return static::collection($model)->find($filter, $options);
    }

    public static function getOneByModel(string $model, array $filter = [], array $options = [])
    {
        return static::collection($model)->findOne($filter, $options);
    }

    public static function createForModel(string $modelName, array $entry = [])
    {
        $entry['_id'] = (string) (new ObjectID());
        $modelObject = static::collection($modelName);
        $modelObject->insertOne($entry);
    }

    public static function getByModelAndId(string $modelName, string $entryid)
    {
        $modelObject = static::collection($modelName);
        return $modelObject->findOne(['_id' => $entryid]);
    }

    public static function replaceByModelId(string $modelName, string $entryid, array $inputdata)
    {
        $modelObject = static::collection($modelName);
        return $modelObject->replaceOne(['_id' => $entryid], $inputdata);
    }

    public static function deleteByModelId(string $modelName, string $entryid)
    {
        $modelObject = static::collection($modelName);
        return $modelObject->deleteOne(['_id' => $entryid]);
    }


    ////// Advanced

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

    public static function mapReduce($mapJS, $reduceJS, array $query = array())
    {
        $map = new Javascript($mapJS);
        $reduce = new Javascript($reduceJS);

        $source = static::getSource();
        $command = new Command([
            "mapreduce" => $source,
            "map" => $map,
            "reduce" => $reduce,
            "query" => new \stdClass(), //new BSONDocument($query),
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

    public static function getWithReferences(array $model, array $filter = [], array $options = [])
    {
        $modelName = $model['name'];

        $collection = static::collection($modelName);
        if (!$collection->hasRelations($model))
        {
            return self::getByModel($modelName, $filter, $options);
        }
        $relations = $collection->getRelations($model);
        $pipeline = [];
        if (count($filter) > 0)
        {
            $pipeline[] = static::getMatchPipeline($filter);
        }

        foreach ($relations as $relation)
        {
            $pipeline[] = static::getLookUpPipeline($relation['name'], $relation['referencedModel'], 'local_'.$relation['name']);
        }
        return $collection->aggregate($pipeline);
    }

    protected static function getMatchPipeline($query)
    {
        return ['$match' => $query];
    }

    protected static function getLookUpPipeline($localFieldname, $refCollectionName, $asLocalFieldname)
    {
        return ['$lookup' => [
            'from' => $refCollectionName,
            'localField' => $localFieldname,
            'foreignField' => "_id",
            'as' => $asLocalFieldname
        ]];
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
