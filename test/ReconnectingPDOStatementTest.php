<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 LegoW Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO\Test;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;
use LegoW\ReconnectingPDO\ReconnectingPDO;
use LegoW\ReconnectingPDO\ReconnectingPDOStatement;

/**
 * Description of ReconnectingPDOStatementTest
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class ReconnectingPDOStatementTest extends TestCase
{    
    /**
     * @var \PDO
     */
    protected $commonTestDB = null;
    
    protected $testDBData = [
        ['id' => 1, 'name' => 'név', 'value' => 'érték'],
        ['id' => 2, 'name' => 'name', 'value' => 'value'],
        ['id' => 3, 'name' => 'name', 'value' => 'Wert']
    ];
    
    public function setUp()
    {
        $pdo = new PDO($this->testDSN);
        $s1 = $pdo->query('CREATE TABLE test (id int(2), name varchar(32), value varchar(255))');
        $s2 = $pdo->prepare('INSERT INTO test (id, name, value) VALUES (:id, :name, :value)');
        foreach ($this->testDBData as $row) {
            $s2->execute($row);
        }
        $this->commonTestDB = $pdo;
    }

    /**
     * @var string
     */
    protected $testDSN = 'sqlite::memory:';
    
    public function testConstruct()
    {
        if(version_compare(phpversion(), '7.0', '>=')) {
            $error = null;
            try {
                $statement = new ReconnectingPDOStatement();
            } catch (\Throwable $ex) {
                $error = $ex;
            }
            $this->assertInstanceOf(\TypeError::class, $error);
        }
        $pdo = $this->commonTestDB;
        $rpdo = new ReconnectingPDO();
        $rpdo->setConnectionParameters([
            "dsn" => $this->testDSN,
            "passwd" => '',
            "username" => ''
        ]);
        $rpdo->setPDO($pdo);
        $stm = $pdo->prepare('SELECT 1');
        
        $rstm = new ReconnectingPDOStatement($stm, $rpdo);
        $this->assertInstanceOf(ReconnectingPDOStatement::class, $rstm);
        
    }
    
    /**
     */
    public function testBinding()
    {
        $pdo = new ReconnectingPDO($this->testDSN);
        $rstm = $pdo->prepare('SELECT :param, :value, 1 as column;');
        $param = 'test';
        $rstm->bindValue('value','value',PDO::PARAM_STR);
        $rstm->bindParam('param', $param, PDO::PARAM_STR);
        
        $this->assertTrue($rstm->execute());
        $this->assertEquals([$param, 'value', 1], $rstm->fetch(PDO::FETCH_NUM));
        
        $value = $id = null;
        $statement = $pdo->prepare('SELECT 11, "valuevalue" as value;');
        $statement->bindColumn('value', $value);

        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        
        $reflection = new \ReflectionClass($statement);
        $prop = $reflection->getProperty('seedData');
        $prop->setAccessible(true);
        $seedData = $prop->getValue($statement);
        $this->assertEquals('valuevalue', $value);
    }

    public function testGetPDOStatement()
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockRPDO = $this->createMock(ReconnectingPDO::class);
        
        $rstm = new ReconnectingPDOStatement($mockStatement, $mockRPDO);
        
        $this->assertInstanceOf(PDOStatement::class, $rstm->getPDOStatement());
        $this->assertEquals($mockStatement, $rstm->getPDOStatement());
    }
    
    public function testRecreation()
    {
        // Create failing statement while fecthing
        $mockStm = $this->createMock(PDOStatement::class);
        $mockStm->method('fetch')->will($this->throwException(new \PDOException('Mysql server has gone away')));
        $mockStm->method('execute')->willReturn(true);
        
        // Create reconnecting PDO
        $rPDO = new ReconnectingPDO($this->testDSN, '', '');
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->will($this->throwException(new \PDOException('Mysql server has gone away')));
        $rPDO->setPDO($pdo);
        
        $rstm = new ReconnectingPDOStatement($mockStm, $rPDO);
        
        // Set query string to have working querystring while recreation
        $reflection = new \ReflectionClass($rstm);
        $queryStringProperty = $reflection->getProperty('queryString');
        $queryStringProperty->setAccessible(true);
        $queryStringProperty->setValue($rstm, 'SELECT 1;');
        
        $this->assertFalse($rstm->isExecuted());
        $rstm->execute();
        $this->assertTrue($rstm->isExecuted());
        $this->assertEquals("1", $rstm->fetch(PDO::FETCH_COLUMN), "First fetch try which triggers reconnection");
        
        $this->assertNotEquals($mockStm, $rstm->getPDOStatement(), "Check if statement has been recreated");
        $this->assertTrue($rstm->isExecuted(), "Check if recreated");
    }
    
    public function testBindingRecreation()
    {
        // Create failing statement while fecthing
        $mockStm = $this->createMock(PDOStatement::class);
        $mockStm->method('fetch')->will($this->throwException(new \PDOException('Mysql server has gone away')));
        $mockStm->method('execute')->willReturn(true);
        
        // Create reconnecting PDO
        $rPDO = new ReconnectingPDO($this->testDSN, '', '');
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->will($this->throwException(new \PDOException('Mysql server has gone away')));
        $rPDO->setPDO($pdo);
        
        $rstm = new ReconnectingPDOStatement($mockStm, $rPDO);
        
        // Set query string to have working querystring while recreation
        $reflection = new \ReflectionClass($rstm);
        $queryStringProperty = $reflection->getProperty('queryString');
        $queryStringProperty->setAccessible(true);
        $queryStringProperty->setValue($rstm, 'SELECT :value, :param;');
        
        $value = $param = 'value';
        $rstm->bindValue('value', $value, PDO::PARAM_STR);
        $rstm->bindParam('param', $param, PDO::PARAM_STR);
        $rstm->execute();
        
        $this->assertEquals([$value, $param], $rstm->fetch(PDO::FETCH_NUM));
    }
    
    /**
     * @expectedException \PDOException
     */
    public function testExceptionUpbubbling()
    {
        $mockStm = $this->createMock(PDOStatement::class);
        $mockStm->method('fetch')->will($this->throwException(new \PDOException));
        $mockStm->method('execute')->willReturn(true);
        
        // Create reconnecting PDO
        $rPDO = new ReconnectingPDO($this->testDSN, '', '');
        $pdo = $this->createMock(PDO::class);
        $rPDO->setPDO($pdo);
        
        $rstm = new ReconnectingPDOStatement($mockStm, $rPDO);
        
        $rstm->fetch();
    }
    
    public function testCursor()
    {
        $pdo = $this->commonTestDB;
        
        $testStament = new Mock\StatementFetchCursorTest();
        $testStament->setStatement($pdo->prepare('SELECT * FROM test'));
        
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($pdo);
        $sut = new ReconnectingPDOStatement($testStament, $rpdo);
        $sut->execute();
        
        $this->assertEquals($this->testDBData[0], $sut->fetch(\PDO::FETCH_ASSOC));
        // testStatement throws exception for second call, so statement recreation is triggered
        $this->assertEquals($this->testDBData[1], $sut->fetch(\PDO::FETCH_ASSOC));
    }
}
