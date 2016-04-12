<?php

/*
 * All rights reserved © 2016 Legow Hosting Kft.
 */

namespace Legow\ReconnectingPDO;

/**
 * It covers the PDO database handler to prevent connection loss caused by non-critical
 * error (ie. the MySQL has gone away).
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 * @method PDOStatement|bool prepare(string $statement, array $driver_options = 'array()' [optional]) Prepares a statement for execution and returns a statement object
 * @method bool beginTransaction(type $paramName) Initiates a transaction
 * @method bool commit() Commits a transaction
 * @method bool rollBack() Rolls back a transaction
 * @method bool inTransaction() Checks if inside a transaction
 * @method bool setAttribute(int $attribute, mixed $value) Set an attribute
 * @method int exec(string $statement The SQL statement to prepare and execute) Execute an SQL statement and return the number of affected rows. Returns the number of rows that were modified or deleted by the SQL statement you issued.
 * @method PDOStatement|bool query(string $statement The SQL statement to prepare and execute.) Executes an SQL statement, returning a result set as a PDOStatement object
 * @method string lastInsertId(string $name = null Name of the sequence object from which the ID should be returned.) Returns the ID of the last inserted row or sequence value
 * @method mixed errorCode() Fetch the SQLSTATE associated with the last operation on the database handle. Returns an <b>SQLSTATE</b> or <b>NULL</b> if no operation has been run
 * @method array errorInfo() Fetch extended error information associated with the last operation on the database handle
 * @method mixed getAttribute(int $attribute One of the PDO::ATTR_* constants.) Retrieve a database connection attribute
 * @method string quote(string $string The string to be quoted, int $parameter_type = 'PDO::PARAM_STR') Quotes a string for use in a query.
 * @method array getAvailableDrivers() Return an array of available PDO drivers
 */
class ReconnectingPDO
{

    /**
     * @var \PDO
     */
    protected $db;

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
     * (PHP 5 &gt;= 5.1.0, PECL pdo &gt;= 0.1.0)<br/>
     * Creates a PDO instance representing a connection to a database
     * @link http://php.net/manual/en/pdo.construct.php
     * @param $dsn
     * @param $username [optional]
     * @param $passwd [optional]
     * @param $options [optional]
     * @param $maxRetry [optional]
     */
    public function __construct($dsn, $username = null, $passwd = null, $options = null, $maxRetry = 5)
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->passwd = $passwd;
        $this->options = $options;
        $this->maxReconnection = $maxRetry;
        $this->connectDb();
        $this->resetCounter();
    }

    public function __call($method, $arguments)
    {
        return $this->call($method, $arguments); // Avoid direct calling of magic method
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws \PDOException
     */
    protected function call($method, $arguments)
    {
        try {
            return call_user_func_array([$this->db, $method], $arguments);
        } catch (\PDOException $ex) {
            if (!preg_match("/General error: 2006/", $ex->getMessage())) {
                throw $ex;
            }
            if ($this->reconnectCounter < $this->maxReconnection) {
                $this->reconnectDb();
                $returnValue = $this->call($method, $arguments); // Retry
                $this->resetCounter();
                return $returnValue;
            } else {
                throw $ex;
            }
        }
    }

    protected function reconnectDb()
    {
        unset($this->db);
        $this->connectDb();
        $this->reconnectCounter++;
    }

    protected function connectDb()
    {
        $this->db = new \PDO($this->dsn, $this->username, $this->passwd, $this->options);
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

}