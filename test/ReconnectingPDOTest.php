<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO\Test;

use PHPUnit\Framework\TestCase;
use Legow\ReconnectingPDO\ReconnectingPDO;
use Legow\ReconnectingPDO\ReconnectingPDOException;
use Legow\ReconnectingPDO\ConnectionParametersMissingException;
use Legow\ReconnectingPDO\ExceededMaxReconnectionException;

/**
 * Description of ReconnectingPDOTest
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class ReconnectingPDOTest extends TestCase
{

    public function testConstruct()
    {
        $rpdo = new ReconnectingPDO();
        $this->assertInstanceOf(ReconnectingPDO::class, $rpdo);
        $this->assertAttributeEquals(5, 'maxReconnection', $rpdo);

        unset($rpdo);
        $rpdo = new ReconnectingPDO('sqlite::memory:', 'test', 'test', [], 3);
        $this->assertAttributeEquals('sqlite::memory:', 'dsn', $rpdo);
        $this->assertAttributeEquals('test', 'username', $rpdo);
        $this->assertAttributeEquals('test', 'passwd', $rpdo);
        $this->assertAttributeEquals([], 'options', $rpdo);
        $this->assertAttributeEquals(3, 'maxReconnection', $rpdo);
        $this->assertAttributeInstanceOf(\PDO::class, 'db', $rpdo);
    }

    public function testSetters()
    {
        $dsn = 'sqlite::memory:';
        $username = '';
        $passwd = '';
        $rpdo = new ReconnectingPDO();

        $exception = null;
        try {
            $rpdo->setPDO(new \PDO($dsn));
        } catch (\Exception $ex) {
            $exception = $ex;
        }
        $this->assertInstanceOf(ConnectionParametersMissingException::class,
                $exception);
        
        $rpdo->setConnectionParameters([
            'dsn' => $dsn,
            'passwd' => $passwd,
            'username' => $username
        ]);
        $this->assertAttributeEquals($dsn, 'dsn', $rpdo);
        $this->assertAttributeEquals($username, 'username', $rpdo);
        $this->assertAttributeEquals($passwd, 'passwd', $rpdo);
        
        $exception = null;
        try {
            $rpdo->setPDO(new \PDO($dsn));
        } catch (\Exception $ex) {
            $exception = $ex;
        }
        $this->assertNull($exception);
        
        unset($rpdo);
        $rpdo = new ReconnectingPDO();
        $rpdo->setConnectionParameters([
            'dsn' => $dsn,
            'passwd' => $passwd,
            'username' => $username
        ], true);
        
        $this->assertAttributeInstanceOf(\PDO::class, 'db', $rpdo);
        
    }
    
    public function testReconnection()
    {
        $dsn = 'sqlite::memory:';
        $username = '';
        $passwd = '';
        $mockPDO = $this->createMock(\PDO::class);
        
        $mockPDO->method('prepare')
                ->will(
                        $this->throwException(
                                new \PDOException('Mysql server has gone away')
                                )
                        );
        $rpdo = new ReconnectingPDO($dsn, $username, $passwd);
        $rpdo->setPDO($mockPDO);
        
        $this->assertAttributeEquals($mockPDO, 'db', $rpdo);
        
        $exception = null;
        try {
            $stm = $rpdo->prepare('SELECT 1');
            $stm->execute();
            $this->assertEquals('1', $stm->fetchColumn());
        } catch (\Exception $ex) {
            $exception = $ex;
        }
        $this->assertNull($exception);
        
        $rpdo->setMaxReconnection(0);
        $rpdo->setPDO($mockPDO);
        
        $exception = null;
        try {
            $rpdo->prepare('SELECT 1');
        } catch (\Exception $ex) {
            $exception = $ex;
        }
        $this->assertInstanceOf(ExceededMaxReconnectionException::class, $exception);
    }
    
    public function testExceptionThrowUp()
    {
        $mockPDO = $this->createMock(\PDO::class);
        
        $mockPDO->method('prepare')
                ->will($this->throwException(new \PDOException('Test exception')));
        
        $rpdo = new ReconnectingPDO('sqlite::memory:', '', '');
        $rpdo->setPDO($mockPDO);
        
        $exception = null;
        try {
            $rpdo->prepare('SELECT 1');
        } catch (\Exception $ex) {
            $exception = $ex;
        }
        $this->assertInstanceOf(\PDOException::class, $exception);
        $this->assertContains('Test exception', $exception->getMessage());
    }
    
    public function testCallProtection()
    {
        $rpdo = new ReconnectingPDO();
        
        $exception = null;
        try {
            $rpdo->prepare('SELECT 1');
        } catch (\Exception $ex) {
            $exception = $ex;
        }
        $this->assertInstanceOf(ReconnectingPDOException::class, $exception);
        $this->assertContains('No PDO connection', $exception->getMessage());
    }
    
    

}
