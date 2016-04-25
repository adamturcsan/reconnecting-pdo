<?php

namespace Legow\ReconnectingPDO;

/*
 * All rights reserved © 2016 Legow Hosting Kft.
 */

use \PDO;
use \PDOStatement;

/**
 * Description of ReconnectingPDOStatement
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class ReconnectingPDOStatement
{
    /**
     * @var PDOStatement
     */
    private $statement;

    /**
     * @var PDO
     */
    private $connection;

    /**
     * @var int Reconnect counter
     */
    protected $reconnectCounter;

    /**
     * @var int Maximum reconnection for one function call
     */
    protected $maxReconnection;

    /**
     * @var string
     */
    protected $queryString;

    /**
     * @var array
     */
    protected $seedData;

    /**
     *
     * @param PDOStatement $statement
     * @param int $maxRetry [optional]
     */
    public function __construct(PDOStatement $statement, PDO $connection, $maxRetry = 5)
    {
        $this->statement = $statement;
        $this->connection = $connection;
        $this->maxReconnection = $maxRetry;
    }

    public function __call($method, $arguments)
    {
        if(substr($method, 0, 4) == "bind") {
            $this->seedData[$method] = $arguments; 
        }
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
            return call_user_func_array([$this->statement, $method], $arguments);
        } catch (\PDOException $ex) {
            if (!stristr($ex->getMessage(), "server has gone away") || $ex->getCode() != 'HY000') {
                throw $ex;
            }
            if ($this->reconnectCounter < $this->maxReconnection) {
                $this->recreateStatement();
                $returnValue = $this->call($method, $arguments); // Retry
                $this->resetCounter();
                return $returnValue;
            } else {
                throw $ex;
            }
        }
    }

    protected function recreateStatement() {
        $statement = $this->connection->prepare($this->queryString);
        if(!empty($this->seedData)) {
            foreach($this->seedData as $key => $value) {
                $statement->$key($value);
            }
        }
        $this->statement = $statement;
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
