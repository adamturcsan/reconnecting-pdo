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
 * Description of ReconnectingPDOStatement
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
    protected $queryString;

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
            ReconnectingPDO $connection, StatementCursor $cursor = null)
    {
        $this->statement = $statement;
        $this->queryString = $statement->queryString;
        $this->connection = $connection;
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
                    return $this->statement->bindParam(...$arguments);
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
                $this->seedData['bindColumn'][$name] = $result[$name];
            }
        }
        return $result;
    }

}
