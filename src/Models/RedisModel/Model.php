<?php

namespace limx\Models\RedisModel;

use limx\Models\RedisModel\Exception;
use limx\Models\RedisModel\Commands\Command;
use limx\Models\RedisModel\Commands\Factory;
use limx\Models\RedisModel\FactoryInterface;
use Predis\Client as RedisClient;

abstract class Model
{
    const TYPE_STRING = 'string';
    const TYPE_SET = 'set';
    const TYPE_SORTED_SET = 'zset';
    const TYPE_LIST = 'list';
    const TYPE_HASH = 'hash';

    /**
     * Redis data type
     * @var string
     * Could be string, list, set, zset, hash
     */
    protected $type;

    /**
     * Redis key representation.
     * users:{id}:phone e.g.
     * @var string
     */
    protected $key;

    /**
     * @var string
     */
    protected $delimiter = ':';

    /**
     * Primary key name like database
     * @var string
     */
    protected $primaryFieldName = 'id';

    /**
     * @var string
     */
    protected $fieldWrapper = '{}';

    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var FactoryInterface
     */
    protected $commandFactory;

    /**
     * @var array
     */
    protected $orderBys = [];

    /**
     * offset for pagination
     * @var int
     */
    protected $offset;

    /**
     * limit for pagination
     * @var int
     */
    protected $limit;

    /**
     * Push method for list type
     * @var string
     */
    protected $listPushMethod = 'rpush';

    /**
     * @var RedisClient
     */
    protected $redClient;

    /**
     * if set to true, the subclass must override method compare()
     * @var bool
     */
    protected $sortable = false;

    /**
     * @var array
     */
    private $orderByFieldIndices = [];

    public function __construct($parameters = null, $options = null)
    {
        $this->initRedisClient($parameters, $options);
        $this->newQuery();
        $this->setCommandFactory();
    }

    /**
     * Refresh query builder
     * @return $this
     */
    public function newQuery()
    {
        $this->orderBys = [];

        $this->limit = null;

        $this->offset = null;

        return $this->freshQueryBuilder();
    }

    /**
     * @param $factory FactoryInterface
     */
    public function setCommandFactory($factory = null)
    {
        $this->commandFactory = $factory ?: new Factory();
    }

    /**
     * @return FactoryInterface
     */
    public function getCommandFactory()
    {
        return $this->commandFactory;
    }

    /**
     * @return QueryBuilder
     */
    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }

    /**
     * @return string
     */
    public function getPrimaryFieldName()
    {
        return $this->primaryFieldName;
    }

    /**
     * Query like database
     * The {$bindingKey} part in the key representation would be replace by $value
     * @param $field string
     * @param $value string
     * @return $this
     */
    public function where($field, $value)
    {
        $this->queryBuilder->whereEqual($field, $value);

        return $this;
    }

    /**
     * @param $field
     * @param array $values
     * @return $this
     */
    public function whereIn($field, array $values)
    {
        $this->queryBuilder->whereIn($field, $values);

        return $this;
    }

    /**
     * @param $field
     * @param string $order
     * @return $this
     */
    public function orderBy($field, $order = 'asc')
    {
        $this->orderBys[$field] = $order;

        return $this;
    }

    /**
     * @param int $offset
     * @return $this
     */
    public function skip($offset)
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function take($limit)
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Get all items
     * @return array
     */
    public function all()
    {
        $this->newQuery();

        return $this->get();
    }

    /**
     * Retrieve items
     * @return array
     */
    public function get()
    {
        $data = $this->getProxy();

        return $data;
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->prepareKeys());
    }

    /**
     * @param string $order asc|desc
     * @return array
     * @throws Exception
     */
    public function sort($order = 'asc')
    {
        $this->checkSortable();

        $values = $this->get();

        if (!$values) {
            return [];
        }

        if ($order == 'asc') {
            usort($values, [$this, 'compare']);
        } else {
            usort($values, [$this, 'revcompare']);
        }

        return $values;
    }

    /**
     * @return mixed
     * @throws Exception
     */
    public function max()
    {
        $this->checkSortable();

        $values = $this->get();

        if (!$values) {
            return null;
        }

        $max = array_pop($values);

        foreach ($values as $v) {
            if ($this->compare($v, $max) === 1) {
                $max = $v;
            }
        }

        return $max;
    }

    /**
     * @return mixed
     * @throws Exception
     */
    public function min()
    {
        $this->checkSortable();

        $values = $this->get();

        if (!$values) {
            return null;
        }

        $min = array_pop($values);

        foreach ($values as $v) {
            if ($this->compare($v, $min) === -1) {
                $min = $v;
            }
        }

        return $min;
    }

    /**
     * @return array
     */
    public function getKeys()
    {
        return $this->prepareKeys();
    }

    /**
     * @return array
     */
    public function getCompleteKeys()
    {
        return $this->prepareCompleteKeys();
    }

    /**
     * @return number
     */
    public function sum()
    {
        $values = $this->get();

        return array_sum($values);
    }

    /**
     * Retrieve first item
     * @return mixed|null
     */
    public function first()
    {
        $items = $this->take(1)->get();

        return $items ? array_shift($items) : null;
    }

    /**
     * Create an item
     * @param $id int|string Primary key
     * @param $value mixed
     * @param int $ttl
     * @param bool $force if true the exists item would be replaced
     * @return bool
     */
    public function create($id, $value, $ttl = null, $force = true)
    {
        $this->newQuery();

        $queryKey = $this->queryBuilder->whereEqual($this->primaryFieldName, $id)->firstQueryKey();

        if (!$this->isCompleteKey($queryKey)) {
            return false;
        }

        if ($force === false && $this->redClient->exists($queryKey)) {
            return false;
        }

        return $this->insertProxy($queryKey, $value, $ttl);
    }

    /**
     * @param array $bindings
     * @param $value
     * @param int $ttl
     * @param bool $force
     * @return mixed
     */
    public function insert(array $bindings, $value, $ttl = null, $force = true)
    {
        $this->newQuery();

        foreach ($bindings as $k => $v) {
            $this->queryBuilder->whereEqual($k, $v);
        }

        $queryKey = $this->queryBuilder->firstQueryKey();

        if (!$this->isCompleteKey($queryKey)) {
            return false;
        }

        if ($force === false && $this->redClient->exists($queryKey)) {
            return false;
        }

        return $this->insertProxy($queryKey, $value, $ttl);
    }

    /**
     * find an item
     * @param $id int|string Primary key
     * @return mixed
     */
    public function find($id)
    {
        $this->newQuery();

        $this->queryBuilder->whereEqual($this->primaryFieldName, $id);

        $queryKey = $this->queryBuilder->firstQueryKey();

        if (!$this->isCompleteKey($queryKey)) {
            return null;
        }

        list($method, $parameters) = $this->getFindMethodAndParameters();

        array_unshift($parameters, $queryKey);
        $value = call_user_func_array([$this->redClient, $method], $parameters);

        return $value;
    }

    /**
     * Update items, need to use where() first
     * @param $value
     * @param int $ttl ttl in second
     * @return mixed
     */
    public function update($value, $ttl = null)
    {
        $queryKeys = $this->prepareKeys(false);

        if (count($queryKeys)) {
            return $this->updateBatchProxy($queryKeys, $value, $ttl);
        }

        return false;
    }

    /**
     * Delete items
     * @return bool|int
     */
    public function delete()
    {
        $queryKeys = $this->prepareKeys(false);

        if (count($queryKeys) > 0) {
            return $this->redClient->del($queryKeys);
        }

        return false;
    }

    /**
     * Destroy item
     * @param string $id primary key
     * @return bool
     * @throws Exception
     */
    public function destroy($id)
    {
        $this->newQuery();

        $queryKey = $this->queryBuilder->whereEqual($this->primaryFieldName, $id)->firstQueryKey();

        if (!$this->isCompleteKey($queryKey)) {
            return false;
        }

        return (bool)$this->redClient->del([$queryKey]);
    }

    /**
     * @param array $ids primary keys
     * @return array
     * @throws Exception
     */
    public function findBatch(array $ids)
    {
        $primaryKeys = [];

        foreach ($ids as $id) {
            $primaryKeys[$id] = $this->getPrimaryKey($id);
        }

        $this->newQuery()->whereIn($this->getPrimaryFieldName(), $ids);

        $queryKeys = $this->prepareCompleteKeys();

        if (!$queryKeys) {
            return [];
        }

        $data = $this->getProxy($queryKeys);

        $list = [];

        foreach ($data as $k => $v) {
            $id = array_search($k, $primaryKeys);

            if ($id) {
                $list[$id] = $v;
            }
        }

        return $list;
    }

    /**
     * @param array $ids primary keys
     * @return int
     */
    public function destroyBatch(array $ids)
    {
        $this->newQuery()->whereIn($this->getPrimaryFieldName(), $ids);

        $queryKeys = $this->prepareCompleteKeys();

        if (!$queryKeys) {
            return false;
        }

        return $this->redClient->del($queryKeys);
    }

    /**
     * @param array $ids primary keys
     * @param $value
     * @param int|null $ttl
     * @return mixed
     */
    public function updateBatch(array $ids, $value, $ttl = null)
    {
        $this->newQuery()->whereIn($this->getPrimaryFieldName(), $ids);

        $queryKeys = $this->prepareCompleteKeys();

        if (!$queryKeys) {
            return false;
        }

        return $this->updateBatchProxy($queryKeys, $value, $ttl);
    }

    /**
     * Call Predis function
     * @param $method
     * @param array $parameters
     * @return mixed
     * @throws \Exception
     */
    public function __call($method, $parameters = [])
    {
        $keys = $this->queryBuilder->getQueryKeys();

        if (count($keys) > 1) {
            throw new Exception('More than one key had been built and redis built-in method "' . $method . '" dont support batch operation.');
        } elseif (count($keys) === 0) {
            throw new Exception('No query keys had been built, need to use where() first.');
        }

        array_unshift($parameters, $keys[0]);
        return call_user_func_array([$this->redClient, $method], $parameters);
    }

    /**
     * Compare items to sort
     * @param $a
     * @param $b
     * @return int 1.a>b 0.a=b -1.a<b
     */
    protected function compare($a, $b)
    {
        //
    }

    protected function revcompare($a, $b)
    {
        return -$this->compare($a, $b);
    }

    protected function initRedisClient($parameters, $options)
    {
        $this->redClient = new RedisClient($parameters, $options);
    }

    /**
     * Prepare query keys
     * @param bool $forGet
     * @return array
     */
    protected function prepareKeys($forGet = true)
    {
        $queryKeys = $this->queryBuilder->getQueryKeys();

        // Caution! Would get all items.
        if (!$queryKeys) {
            $queryKeys = [$this->key];
        }

        $existKeys = $this->getExistKeys($queryKeys);

        if ($forGet) {
            $this->setOrderByFieldIndices();

            if ($this->orderByFieldIndices) {
                uasort($existKeys, [$this, 'sortByFields']);
            }

            if ($this->offset || $this->limit) {
                $existKeys = array_slice($existKeys, (int)$this->offset, $this->limit);
            }
        }

        return $existKeys;
    }

    /**
     * @return array
     */
    protected function prepareCompleteKeys()
    {
        $keys = $this->queryBuilder->getQueryKeys();

        if (!$keys) {
            return [];
        }

        return array_filter($keys, [$this, 'isCompleteKey']);
    }

    /**
     * @param $key
     * @return bool
     */
    protected function isCompleteKey($key)
    {
        return !$this->hasUnboundField($key);
    }

    /**
     * @param $key
     * @param $value
     * @param null $ttl
     * @return bool
     */
    protected function insertProxy($key, $value, $ttl = null)
    {
        $method = $this->getUpdateMethod();

        if (!$method) {
            return false;
        }

        $value = $this->castValueForUpdate($value);

        $command = $this->commandFactory->getCommand($method, [$key], $value);

        if ($ttl) {
            $command->setTtl($ttl);
        }

        $response = $this->executeCommand($command);

        return (bool)$response[$key];
    }

    /**
     * @param $keys
     * @param $value
     * @param int $ttl ttl in second
     * @return bool
     */
    protected function updateBatchProxy($keys, $value, $ttl = null)
    {
        $method = $this->getUpdateMethod();

        if (empty($method)) {
            return false;
        }

        $value = $this->castValueForUpdate($value);

        $command = $this->commandFactory->getCommand($method, $keys, $value);

        if ($ttl) {
            $command->setTtl($ttl);
        }

        return $this->executeCommand($command);
    }

    /**
     * @param $queryKeys
     * @return array
     */
    protected function getProxy($queryKeys = null)
    {
        $data = [];

        if ($queryKeys === null) {
            $queryKeys = $this->prepareKeys();
        }

        if ($queryKeys) {
            list($method, $params) = $this->getFindMethodAndParameters();

            $command = $this->commandFactory->getCommand($method, $queryKeys);

            $data = $this->executeCommand($command);
        }

        if ($data && $this->type == static::TYPE_HASH) {
            $data = $this->rawHashToAssocArray($data);
        }

        return $data;
    }

    /**
     * @return $this
     */
    protected function freshQueryBuilder()
    {
        $this->queryBuilder = new QueryBuilder($this->key);

        $keyParts = $this->explodeKey($this->key);

        foreach ($keyParts as $part) {
            if ($this->isUnboundField($part)) {
                $this->queryBuilder->setFieldNeedle($this->trimWrapper($part), $part);
            }
        }

        return $this;
    }

    protected function getUpdateMethod()
    {
        $method = '';
        switch ($this->type) {
            case 'string':
                $method = 'set';
                break;
            case 'list':
                $method = $this->listPushMethod;
                break;
            case 'set':
                $method = 'sadd';
                break;
            case 'zset':
                $method = 'zadd';
                break;
            case 'hash':
                $method = 'hmset';
                break;
            default:
                break;
        }

        return $method;
    }

    /**
     * @param $value
     * @return array
     */
    protected function castValueForUpdate($value)
    {
        switch ($this->type) {
            case 'string':
                $value = [(string)$value];
                break;
            case 'list':
            case 'set':
                $value = (array)$value;
                break;
            case 'zset':
                $casted = [];
                foreach ($value as $k => $v) {
                    $casted[] = $v;
                    $casted[] = $k;
                }
                $value = $casted;
                break;
            case 'hash':
                $casted = [];
                foreach ($value as $k => $v) {
                    $casted[] = $k;
                    $casted[] = $v;
                }
                $value = $casted;
                break;
            default:
                break;
        }

        return $value;
    }

    /**
     * Get find method and default parameters according to redis data type.
     * @return array
     */
    protected function getFindMethodAndParameters()
    {
        $method = '';
        $parameters = [];

        switch ($this->type) {
            case 'string':
                $method = 'get';
                break;
            case 'list':
                $method = 'lrange';
                $parameters = [0, -1];
                break;
            case 'set':
                $method = 'smembers';
                break;
            case 'zset':
                $method = 'zrange';
                $parameters = [0, -1];
                break;
            case 'hash':
                $method = 'hgetall';
                break;
            default:
                break;
        }

        return [$method, $parameters];
    }

    protected function getExistKeys($queryKeys)
    {
        $keys = $this->markUnboundFields($queryKeys);

        $exist = [];

        if ($keys) {
            $command = $this->commandFactory->getCommand('keys', $keys);

            $exist = $this->executeCommand($command);

            $exist = array_unique($exist);
        }

        return $exist;
    }

    /**
     * @param Command $command
     * @return mixed
     */
    protected function executeCommand($command)
    {
        $evalArgs = $command->getArguments();
        array_unshift($evalArgs, $command->getKeysCount());
        array_unshift($evalArgs, $command->getScript());

        $data = call_user_func_array([$this->redClient, 'eval'], $evalArgs);

        $data = $command->parseResponse($data);

        return $data;
    }

    protected function hasUnboundField($key)
    {
        $parts = $this->explodeKey($key);

        foreach ($parts as $part) {
            if ($this->isUnboundField($part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $part key particle
     * @return bool|string
     */
    protected function getFieldName($part)
    {
        if ($this->isUnboundField($part)) {
            return substr($part, 1, -1);
        }

        return false;
    }

    protected function markUnboundFields($keys)
    {
        $marked = [];

        foreach ($keys as $key) {
            $parts = $this->explodeKey($key);

            foreach ($parts as &$part) {
                if ($this->isUnboundField($part)) {
                    $part = '*';
                }
            }

            $marked[] = $this->joinToKey($parts);
        }

        return $marked;
    }

    protected function sortByFields($key1, $key2)
    {
        $key1Parts = $this->explodeKey($key1);
        $key2Parts = $this->explodeKey($key2);

        $flag = 0;

        foreach ($this->orderByFieldIndices as $index => $order) {
            if ($flag !== 0) {
                break;
            }

            if ($key1Parts[$index] > $key2Parts[$index]) {
                $flag = $order == 'asc' ? 1 : -1;
            } elseif ($key1Parts[$index] < $key2Parts[$index]) {
                $flag = $order == 'asc' ? -1 : 1;
            } else {
                $flag = 0;
            }
        }

        return $flag;
    }

    /**
     * @param string $field
     * @return string
     */
    protected function getFieldNeedle($field)
    {
        return $this->fieldWrapper[0] . $field . $this->fieldWrapper[1];
    }

    /**
     * @param $id
     * @return mixed
     */
    protected function getPrimaryKey($id)
    {
        return str_replace($this->getFieldNeedle($this->getPrimaryFieldName()), $id, $this->key);
    }

    private function checkSortable()
    {
        if (!$this->sortable) {
            throw new Exception(get_class($this) . ' is not sortable.');
        }
    }

    private function setOrderByFieldIndices()
    {
        $keyParts = $this->explodeKey($this->key);

        foreach ($this->orderBys as $field => $order) {
            $needle = $this->fieldWrapper[0] . $field . $this->fieldWrapper[1];
            $this->orderByFieldIndices[array_search($needle, $keyParts)] = $order;
        }
    }

    private function explodeKey($key)
    {
        return explode($this->delimiter, $key);
    }

    private function joinToKey($parts)
    {
        return join($this->delimiter, $parts);
    }

    private function isUnboundField($part)
    {
        return $this->fieldWrapper[0] === $part[0]
        && $this->fieldWrapper[1] === $part[strlen($part) - 1];
    }

    private function trimWrapper($part)
    {
        return substr($part, 1, -1);
    }

    /**
     * raw hash data to associate array
     * @param array $hashes
     * @return array
     */
    private function rawHashToAssocArray(array $hashes)
    {
        $assoc = [];

        foreach ($hashes as $k => $hash) {
            $item = [];
            for ($i = 0; $i < count($hash); $i = $i + 2) {
                $item[$hash[$i]] = $hash[$i + 1];
            }
            if ($item) {
                $assoc[$k] = $item;
            }
        }

        return $assoc;
    }
}