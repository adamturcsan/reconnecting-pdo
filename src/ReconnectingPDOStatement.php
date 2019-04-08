<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 *
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO;

use LegoW\ReconnectingPDO\ReconnectingPDO;
use LegoW\ReconnectingPDO\StatementCursor;
use PDOStatement;
use PDO;

/**
 * Represents a prepared statement and, after the statement is executed, an
 * associated result set.
 * If server has gone away it can recreate itself. Use it with great caution in cases of update or insert statements
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 * @method bool bindParam(mixed $parameter, mixed &$variable, int $dataType = PDO::PARAM_STR, int $length = null, $driver_options = null) Binds a parameter to the specified variable name
 * @method bool bindValue(mixed $parameter, mixed $value, int $data_type = PDO::PARAM_STR) Binds a value to a parameter
 * @method bool closeCursor() Closes the cursor, enabling the statement to be executed again.
 * @method int columnCount() Returns the number of columns in the result set
 * @method void debugDumpParams() Dump an SQL prepared command
 * @method string errorCode() Fetch the SQLSTATE associated with the last operation on the statement handle
 * @method array errorInfo() Fetch extended error information associated with the last operation on the statement handle
 * @method bool execute(array $parameters = null) Executes a prepared statement
 * @method mixed fetchColumn(int $column_number = 0) Returns a single column from the next row of a result set
 * @method mixed fetchObject(string $class_name = "stdClass", array $ctor_args = null) Fetches the next row and returns it as an object
 * @method mixed getAttribute(int $attribute) Retrieve a statement attribute
 * @method array getColumnMeta(int $column) Returns metadata for a column in a result set
 * @method bool nextRowset() Advances to the next rowset in a multi-rowset statement handle
 * @method int rowCount() Returns the number of rows affected by the last SQL statement
 * @method bool setAttribute ( int $attribute , mixed $value ) Set a statement attribute
 * @method bool setFetchMode ( int $mode [PDO::FETCH_* constants] ) Set the default fetch mode for this statement<br /><br />If $mode = PDO::FETCH_COLUMN, second argument: <b>int $colnum</b><br />If $mode = PDO::FETCH_CLASS, further arguments: <b>string $classname, array $ctorargs</b><br />If $mode = PDO::FETCH_INTO, second argument: <b>object $object</b>
 */
class ReconnectingPDOStatement
{

    /**
     * @var PDOStatement
     */
    private $statement;

    /**
     * @var ReconnectingPDO
     */
    private $connection;

    /**
     * @var string
     */
    public $queryString;

    /**
     * @var StatementCursor
     */
    protected $cursor;

    /**
     * @var array
     */
    protected $seedData = [];

    /**
     * @var bool
     */
    protected $executed = false;

    /**
     * @var bool
     */
    protected $isQuery = false;

    /**
     * @return \PDOStatement
     */
    public function getPDOStatement()
    {
        return $this->statement;
    }

    /**
     * @return bool
     */
    public function isExecuted()
    {
        return $this->executed;
    }

    /**
     *
     * @param PDOStatement $statement
     * @param ReconnectingPDO $connection
     */
    public function __construct(
        PDOStatement $statement,
        ReconnectingPDO $connection, $isQuery = false,
        StatementCursor $cursor = null
    ) {
        $this->statement = $statement;
        $this->queryString = $statement->queryString;
        $this->connection = $connection;
        $this->isQuery = $isQuery;
        if ($cursor == null) {
            $cursor = new StatementCursor();
        }
        $this->cursor = $cursor;
    }

    /**
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        if ($method == 'bindParam' || $method == 'bindValue') {
            $key = $arguments[0];
            $this->seedData[$method][$key] = $arguments;
            return $this->call($method, $this->seedData[$method][$key]);
        }
        return $this->call($method, $arguments); // Avoid direct calling of magic method
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws \PDOException
     */
    protected function call($method, &$arguments)
    {
        try {
            switch ($method) {
                //Differenct method handlers
                case 'bindParam':
                    for ($i = 0; $i < 5; $i++) {
                        ${'a' . $i} = &$arguments[$i];
                    }
                    return $this->statement->bindParam($a0, $a1, $a2, $a3, $a4);
                //Pre-call
                case 'fetch':
                case 'fetchColumn':
                case 'fetchObject':
                    $this->trackCursor();
                    break;
                case 'execute':
                    $parameters = isset($arguments[0]) ? $arguments[0] : null;
                    return $this->execute($parameters);
                    break;
                default:
                    break;
            }
            return call_user_func_array([$this->statement, $method], $arguments);
        } catch (\PDOException $ex) {
            if (!stristr($ex->getMessage(), "server has gone away") || $ex->getCode() != 'HY000') {
                throw $ex;
            }
            $this->recreateStatement();
            $returnValue = $this->call($method, $arguments); // Retry
            return $returnValue;
        }
    }

    protected function recreateStatement()
    {
        if ($this->isQuery) {
            return $this->recreateQuery();
        }
        return $this->recreatePreparedStatement();
    }

    protected function recreateQuery()
    {
        //Recreate only if it is a fetchable result set and the error occured during longrun fetching
        if ($this->cursor->getPosition()) {
            /* @var $reconnectingStatement ReconnectingPDOStatement */
            $reconnectingStatement = $this->connection->query($this->queryString);
            $this->statement = $reconnectingStatement->getPDOStatement();
            $this->searchPosition();
        }
    }

    protected function recreatePreparedStatement()
    {
        $shouldBeExecuted = $this->executed;
        $reconnectingstatement = $this->connection->prepare($this->queryString);
        $this->executed = false;
        $statement = $reconnectingstatement->getPDOStatement();
        if (!empty($this->seedData)) {
            /* @var $method string bindParam, bindColumn or bindValue */
            foreach ($this->seedData as $method => $arguments) {
                /* @var $key string Parameter name */
                foreach ($arguments as $key => $params) {
                    $paramType = isset($params[2]) ? $params[2] : null;
                    $bindParams = [
                        'name' => $params[0],
                        // Value comes from the seedData array, because bindParam only takes it by reference
                        'parameter' => $this->seedData[$method][$key][1],
                    ];
                    // Parameter type is an optional argument
                    if ($paramType !== null) {
                        $bindParams['type'] = $paramType;
                    }
                    call_user_method_array($method, $statement, $bindParams);
                }
            }
        }
        $this->statement = $statement;
        if ($shouldBeExecuted) {
            $this->execute();
            $this->searchPosition();
        }
    }

    protected function trackCursor()
    {
        $this->cursor->next();
    }

    protected function searchPosition()
    {
        $position = $this->cursor->getPosition();
        while ($this->cursor->prev()->getPosition() && $this->statement->fetch()) {
            //Nothing to do here.
        }
        $this->cursor->setPosition($position);
    }

    /**
     *
     * @param int $steps
     */
    protected function forwardCursor($steps)
    {
        $position = $this->cursor->getPosition() + $steps;
        while ($this->cursor->next()->getPosition() < $position) {
            //Nothin to do here.
        }
    }

    /**
     * Bind a column to a PHP variable
     *
     * @param mixed $column
     * @param mixed $param
     * @param int $type [optional]
     * @param int $maxlen [optional]
     * @param mixed $driverdata [optional]
     * @return bool
     */
    public function bindColumn($column, &$param, $type = null, $maxlen = null,
            $driverdata = null)
    {
        $this->seedData['bindColumn'][$column] = &$param;
        return $this->statement->bindColumn($column, $param, $type, $maxlen,
                        $driverdata);
    }
    /**
     * Executes a prepared statement
     * @param array $parameters [optional]
     */
    protected function execute(array $parameters = null)
    {
        $success = call_user_func([$this->statement, 'execute'], $parameters);
        $this->executed = true;
        return $success;
    }

    /**
     * Fetches the next row from a result set
     *
     * @param int $fetchType [optional]
     * @param int $cursor_orientation = PDO::FETCH_ORI_NEXT
     * @param int $cursor_offset = 0
     * @return mixed
     */
    public function fetch($fetchType = null,
            $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 0)
    {
        $args = func_get_args();
        $result = $this->call('fetch', $args);
        if (isset($this->seedData['bindColumn']) && count($this->seedData['bindColumn'])) {
            foreach ($this->seedData['bindColumn'] as $name => $column) {
                if (is_int($name)) {
                    $keys = array_keys($result);
                    $this->seedData['bindColumn'][$name] = $result[$keys[$name - 1]];
                } else {
                    $this->seedData['bindColumn'][$name] = $result[$name];
                }
            }
        }
        return $result;
    }

    /**
     * Returns an array containing all of the result set rows
     *
     * @param int $fetch_style = \PDO::FETCH_BOTH
     * @param mixed $fetch_argument [optional]
     * @param array $ctor_args = []
     */
    public function fetchAll($fetch_style = \PDO::FETCH_BOTH,
            $fetch_argument = null, $ctor_args = [])
    {
        $args = func_get_args();
        $result = $this->call('fetchAll', $args);
        if (isset($this->seedData['bindColumn']) && count($this->seedData['bindColumn'])) {
            foreach (array_keys($this->seedData['bindColumn']) as $name) {
                if (is_int($name)) {
                    $keys = array_keys($result);
                    $this->seedData['bindColumn'][$name] = $result[count($result) - 1][$keys[$name - 1]];
                } else {
                    $this->seedData['bindColumn'][$name] = $result[count($result) - 1][$name];
                }
            }
        }
        if (is_array($result)) {
            $this->forwardCursor(count($result));
        }
        return $result;
    }

}
