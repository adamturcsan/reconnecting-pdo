<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO\Test;

use PHPUnit\Framework\TestCase;
use LegoW\ReconnectingPDO\ReconnectingPDO;
use LegoW\ReconnectingPDO\ReconnectingPDOStatement;
use LegoW\ReconnectingPDO\ReconnectingPDOException;
use LegoW\ReconnectingPDO\ConnectionParametersMissingException;
use LegoW\ReconnectingPDO\ExceededMaxReconnectionException;

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
        $this->assertInstanceOf(ExceededMaxReconnectionException::class,
                $exception);
    }

    /**
     * @expectedException \PDOException
     * @expectedExceptionMessage Test exception
     */
    public function testExceptionThrowUp()
    {
        $mockPDO = $this->createMock(\PDO::class);

        $mockPDO->method('prepare')
                ->will($this->throwException(new \PDOException('Test exception')));

        $rpdo = new ReconnectingPDO('sqlite::memory:', '', '');
        $rpdo->setPDO($mockPDO);
        //Should throw exception
        $rpdo->prepare('SELECT 1');
    }

    /**
     * @expectedException \LegoW\ReconnectingPDO\ReconnectingPDOException
     * @expectedExceptionMessage No PDO connection
     */
    public function testCallProtection()
    {
        $rpdo = new ReconnectingPDO();

        //Should throw exception
        $rpdo->prepare('SELECT 1');
    }

    public function testQuery()
    {
        $rpdo = new ReconnectingPDO('sqlite::memory:', '', '');

        $statement = $rpdo->query('SELECT 1;');
        $this->assertInstanceOf(\LegoW\ReconnectingPDO\ReconnectingPDOStatement::class,
                $statement);
        $this->assertAttributeEquals(true, 'isQuery', $statement);
    }

    public function testExec()
    {
        $rpdo = new ReconnectingPDO('sqlite::memory:', '', '');

        $res = $rpdo->exec('CREATE TABLE test (id int(2), name varchar(64), value varchar(255))');
        $this->assertSame(0, $res);
        $res2 = $rpdo->exec('INSERT INTO test VALUES (1, "név", "érték"), (2, "name", "value"), (3, "Name", "Wert")');
        $this->assertSame(3, $res2);
        $res3 = $rpdo->exec('DELETE FROM test WHERE id = 4');
        $this->assertSame(0, $res3);
    }

    public function testPrepare()
    {
        $createTableString = 'CREATE TABLE test (id int(2), name varchar(64), value varchar(255))';
        $rpdo = new ReconnectingPDO('sqlite::memory:');

        $stm = $rpdo->prepare($createTableString);
        $this->assertInstanceOf(ReconnectingPDOStatement::class, $stm);
        $this->assertEquals($createTableString, $stm->queryString);
    }

    public function testQuote()
    {
        $rpdo = new ReconnectingPDO('sqlite::memory:');

        $simpleUnquoted = 'Simple string';
        $this->assertEquals('\'' . $simpleUnquoted . '\'',
                $rpdo->quote($simpleUnquoted));
        $complexUnquoted = '"Complex" string\'s unquoted form';
        $this->assertEquals('\'"Complex" string\'\'s unquoted form\'',
                $rpdo->quote($complexUnquoted));
    }

    /**
     * @expectedException \PDOException
     */
    public function testTransaction()
    {
        $rpdo = new ReconnectingPDO('sqlite::memory:');

        $rpdo->beginTransaction();
        $rpdo->exec('CREATE TABLE test (id int(2), name varchar(64), value varchar(255))');
        $this->assertSame(0, $rpdo->exec('SELECT * FROM test'));
        $rpdo->rollBack();
        $rpdo->exec('SELECT * FROM test');
    }

    public function testLastInsertId()
    {
        $rpdo = new ReconnectingPDO('sqlite::memory:');

        $rpdo->exec('CREATE TABLE `test` ( `id` INT NOT NULL, `value` VARCHAR(64), PRIMARY KEY (`id`));');
        $rpdo->exec('INSERT INTO test (id, value) VALUES (1, "new value")');
        $this->assertEquals(1, $rpdo->lastInsertId());
        $rpdo->exec('INSERT INTO test (id, value) VALUES (5, "new value")');
        $this->assertEquals(2, $rpdo->lastInsertId());
    }

}
