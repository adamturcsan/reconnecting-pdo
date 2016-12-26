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
 * @method bool execute(array $parameters = null [optional]) Executes a prepared statement
 * @method bool bindParam(mixed $parameter, mixed &$variable, int $dataType = PDO::PARAM_STR [optional], int $length = null [optional], $driver_options = null [optional]) Binds a parameter to the specified variable name
 * @method bool bindValue(mixed $parameter, mixed $value, int $data_type = PDO::PARAM_STR [optional]) Binds a value to a parameter
 * @method int rowCount() Returns the number of rows affected by the last SQL statement
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
    public function __construct(PDOStatement $statement,
            ReconnectingPDO $connection, $isQuery = false,
            StatementCursor $cursor = null)
    {
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
                    for($i=0; $i<5; $i++) {
                        ${'a'.$i} = &$arguments[$i];
                    }
                    return $this->statement->bindParam($a0, $a1, $a2, $a3, $a4);
                //Pre-call 
                case 'fetch':
                case 'fetchAll':
                case 'fetchColumn':
                case 'fetchObject':
                    $this->trackCursor($method);
                    break;
                case 'execute':
                    $this->executed = true;
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
                    list($name,, $paramType) = $params;
                    // Value comes from the seedData array, because bindParam only takes it by reference
                    $statement->$method($name,
                            $this->seedData[$method][$key][1], $paramType);
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

    public function bindColumn($column, &$param, $type = null, $maxlen = null,
            $driverdata = null)
    {
        $this->seedData['bindColumn'][$column] = &$param;
        return $this->statement->bindColumn($column, $param, $type, $maxlen,
                        $driverdata);
    }

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
     * 
     * @param int $fetch_style
     * @param mixed $fetch_argumnet
     * @param array $ctor_args
     */
    public function fetchAll($fetch_style = \PDO::FETCH_BOTH,
            $fetch_argumnet = null, $ctor_args = [])
    {
        $args = func_get_args();
        $result = $this->call('fetchAll', $args);
        if (isset($this->seedData['bindColumn']) && count($this->seedData['bindColumn'])) {
            foreach ($this->seedData['bindColumn'] as $name => $column) {
                if (is_int($name)) {
                    $keys = array_keys($result);
                    $this->seedData['bindColumn'][$name] = $result[count($result) - 1][$keys[$name - 1]];
                } else {
                    $this->seedData['bindColumn'][$name] = $result[count($result) - 1][$name];
                }
            }
        }
        return $result;
    }

}
