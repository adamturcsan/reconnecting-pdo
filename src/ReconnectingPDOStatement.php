<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace Legow\ReconnectingPDO;

use Legow\ReconnectingPDO\ReconnectingPDO;
use \PDOStatement;

/**
 * Description of ReconnectingPDOStatement
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 * @method bool execute(array $parameters = null [optional]) Executes a prepared statement
 * @method mixed fetch(int $fetchType = null [optional], int $cursor_orientation = PDO::FETCH_ORI_NEXT [optional], int $cursor_offset = 0 [optional]) Fetches the next row from a result set
 * @method bool bindParam(mixed $parameter, mixed &$variable, int $dataType = PDO::PARAM_STR [optional], int $length = null [optional], $driver_options = null [optional]) Binds a parameter to the specified variable name
 * @method bool bindColumn(mixed $column, mixed &$param, int $type = null [optional], int $maxlen = null [optional], $driverdata = null [optional]) Bind a column to a PHP variable
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
     * @var array
     */
    protected $seedData = [];
    
    /**
     * @var bool
     */
    protected $executed = false;

    /**
     *
     * @param PDOStatement $statement
     * @param ReconnectingPDO $connection
     */
    public function __construct(PDOStatement $statement, ReconnectingPDO $connection)
    {
        $this->statement = $statement;
        $this->queryString = $statement->queryString;
        $this->connection = $connection;
    }

    /**
     * 
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        if(substr($method, 0, 4) == 'bind' && $method != 'bindColumn') {
            $key = $arguments[0];
            $this->seedData[$method][$key] = $arguments;
            return $this->call($method, $this->seedData[$method][$key]);
        } elseif( $method == 'execute' ) {
            $this->executed = true;
        }
        return $this->call($method, $arguments); // Avoid direct calling of magic method
    }
    
    public function bindColumn($column, &$param, $type = null, $maxlen = null, $driverdata = null)
    {
        $this->seedData['bindColumn'][$column] = &$param;
        return $this->statement->bindColumn($column, $param, $type, $maxlen, $driverdata);
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
            if($method == 'bindParam'){
                return $this->statement->bindParam(...$arguments);
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

    protected function recreateStatement() {
        $shouldBeExecuted = $this->executed;
        /* @var $reconnectingstatement ReconnectingPDOStatement */
        $reconnectingstatement = $this->connection->prepare($this->queryString);
        $this->executed = false;
        $statement = $reconnectingstatement->getPDOStatement();
        if(!empty($this->seedData)) {
            /* @var $method string bindParam, bindColumn or bindValue*/
            foreach($this->seedData as $method => $arguments) {
                /* @var $key string Parameter name */
                foreach($arguments as $key => $params) {
                    list($name, , $paramType) = $params; // Value comes from the seedData array, because it only takes it by reference
                    $statement->$key($name, $this->seedData[$method][$key][1], $paramType);
                }
            }
        }
        $this->statement = $statement;
        if($shouldBeExecuted) {
            $this->execute();
        }
    }
    
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
    
    public function fetch()
    {
        $args = func_get_args();
        $result = $this->call('fetch', $args);
        if(isset($this->seedData['bindColumn']) && count($this->seedData['bindColumn'])) {
            foreach($this->seedData['bindColumn'] as $name => &$column) {
                $this->seedData['bindColumn'][$name] = $result[$name];
            }
        }
        return $result;
    }
}
