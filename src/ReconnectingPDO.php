<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO;

use PDO;
use LegoW\ReconnectingPDO\ReconnectingPDOStatement;
use LegoW\ReconnectingPDO\ReconnectingPDOException;

/**
 * It covers the PDO database handler to prevent connection loss caused by non-critical
 * error (ie. the MySQL has gone away).
 * 
 * The default error handling is set to Exception instead \PDO::ERRMODE_SILENT.
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class ReconnectingPDO extends PDO
{

    /**
     * @var PDO
     */
    protected $connection;

    /**
     * @var string DSN
     */
    protected $dsn;

    /**
     * @var string Username
     */
    protected $username;

    /**
     * @var string Password
     */
    protected $passwd;

    /**
     * @var int[] PDO Options <br />
     * Use original <b>PDO</b> constants
     */
    protected $options;

    /**
     * @var int Reconnect counter
     */
    protected $reconnectCounter;

    /**
     * @var int Maximum reconnection for one function call
     */
    protected $maxReconnection;

    /**
     * (PHP 5 &gt;= 5.1.3, PHP 7, PECL pdo &gt;= 1.0.3)<br/>
     * Return an array of available PDO drivers
     * @link http://php.net/manual/en/pdo.getavailabledrivers.php
     * @return array <b>PDO::getAvailableDrivers</b> returns an array of PDO driver names. If
     * no drivers are available, it returns an empty array.
     */
    public static function getAvailableDrivers()
    {
        return \PDO::getAvailableDrivers();
    }

    /**
     * (PHP 5 &gt;= 5.1.0, PECL pdo &gt;= 0.1.0)<br/>
     * Creates a ReconnectingPDO instance representing a connection to a database
     * @link http://php.net/manual/en/pdo.construct.php
     * @param $dsn
     * @param $username [optional]
     * @param $passwd [optional]
     * @param $options [optional]
     * @param $maxRetry [optional]
     */
    public function __construct($dsn = null, $username = null, $passwd = null,
            $options = null, $maxRetry = 5)
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->passwd = $passwd;
        $this->options = $options;
        $this->maxReconnection = $maxRetry;
        if ($this->dsn) {
            $this->connectDb();
        }
        $this->resetCounter();
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws \PDOException
     */
    protected function call($method, $arguments)
    {
        if (!($this->connection instanceof PDO)) {
            throw new ReconnectingPDOException('No PDO connection is set');
        }
        try {
            $this->ping($method);
            $returnValue = call_user_func_array([$this->connection, $method],
                    $arguments);
        } catch (\PDOException $ex) {
            if (!stristr($ex->getMessage(), "server has gone away") || $ex->getCode() != 'HY000') {
                throw $ex;
            }
            if ($this->reconnectCounter >= $this->maxReconnection) {
                throw new ExceededMaxReconnectionException('ReconnectingPDO has exceeded max reconnection limit',
                $ex->getCode(), $ex);
            }
            $this->reconnectDb();
            $returnValue = $this->call($method, $arguments); // Retry
            $this->resetCounter();
        }
        if ($returnValue instanceof \PDOStatement) {
            return new ReconnectingPDOStatement($returnValue, $this,
                    $method === 'query');
        }
        return $returnValue;
    }

    protected function reconnectDb()
    {
        unset($this->connection);
        $this->connectDb();
        $this->reconnectCounter++;
    }

    protected function connectDb()
    {
        $this->connection = new PDO($this->dsn, $this->username, $this->passwd,
                $this->options);
        $this->connection->setAttribute(PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION);
    }

    /**
     * If a function call didn't throw exception, reconnection counter can be reseted
     */
    protected function resetCounter()
    {
        $this->reconnectCounter = 0;
    }

    /**
     *
     * @param int $max
     */
    public function setMaxReconnection($max)
    {
        $this->maxReconnection = $max;
    }

    /**
     * 
     * Parameters can be <b>(string)dsn</b>, <b>(string)username</b>,
     * (string)<b>passwd</b> and <b>(array)options</b>
     * 
     * @param array $parameters
     * @param bool $autoconnect
     */
    public function setConnectionParameters($parameters)
    {
        foreach ($parameters as $key => $param) {
            if (property_exists($this, $key)) {
                $this->$key = $param;
            }
        }
    }

    /**
     * Prepares a statement for execution and returns a statement object
     * @param string $statement
     * @param array $driver_options [optional]
     * @return ReconnectingPDOStatement|bool
     */
    public function prepare($statement, $driver_options = [])
    {
        return $this->call('prepare', [$statement, $driver_options]);
    }

    /**
     * {@inheritDoc}
     */
    public function exec($statement)
    {
        return $this->call('exec', [$statement]);
    }

    /**
     * {@inheritDoc}
     */
    public function quote($string, $parameter_type = null)
    {
        return $this->call('quote', [$string, $parameter_type]);
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        return $this->call('beginTransaction', []);
    }

    /**
     * {@inheritDoc}
     */
    public function rollBack()
    {
        return $this->call('rollBack', []);
    }

    /**
     * {@inheritDoc}
     */
    public function commit()
    {
        return $this->call('commit', []);
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode()
    {
        return $this->call('errorCode', []);
    }

    /**
     * {@inheritDoc}
     */
    public function errorInfo()
    {
        return $this->call('errorInfo', []);
    }

    /**
     * {@inheritDoc}
     */
    public function lastInsertId($name = null)
    {
        return $this->call('lastInsertId', [$name]);
    }

    /**
     * {@inheritDoc}
     */
    public function getAttribute($attribute)
    {
        return $this->call('getAttribute', [$attribute]);
    }

    /**
     * {@inheritDoc}
     */
    public function setAttribute($attribute, $value)
    {
        return $this->call('setAttribute', [$attribute, $value]);
    }

    /**
     * {@inheritDoc}
     */
    public function inTransaction()
    {
        return $this->call('inTransaction', []);
    }

    /**
     * Executes an SQL statement, returning a result set as a PDOStatement object
     * @param string $statement The SQL statement to prepare and execute.
     * @return ReconnectingPDOStatement|bool
     */
    public function query($statement)
    {
        return $this->call('query', [$statement]);
    }

    public function setPDO(PDO $pdoObject)
    {
        if (!$this->connectionParametersAreSet()) {
            throw new ConnectionParametersMissingException();
        }
        $this->connection = $pdoObject;
    }

    /**
     * {@inheritDoc}
     */
    public function sqliteCreateFunction($functionName, callable $callback, $num_args = -1)
    {
        return $this->call('sqliteCreateFunction', [$functionName, $callback, $num_args]);
    }

    protected function connectionParametersAreSet()
    {
        if ($this->dsn !== null && $this->username !== null && $this->passwd !== null) {
            return true;
        }
        return false;
    }

    /**
     * 
     * @param string $methodName
     */
    protected function ping($methodName)
    {
        switch ($methodName) {
            case 'lastInsertId':
                //noop
                break;
            default:
                $this->connection->query('SELECT 1');
                break;
        }
    }

}
