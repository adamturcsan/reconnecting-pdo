<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 LegoW Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO\Test;

use PDO;
use PDOStatement;
use LegoW\ReconnectingPDO\ReconnectingPDO;
use LegoW\ReconnectingPDO\ReconnectingPDOStatement;
use LegoW\ReconnectingPDO\Test\Mock\FetchObjectTestClass;

/**
 * Description of ReconnectingPDOStatementTest
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class ReconnectingPDOStatementTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var string
     */
    protected $testDSN = 'sqlite::memory:';

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

    public function testConstruct()
    {
        if (version_compare(phpversion(), '7.0', '>=')) {
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
        $rstm->bindParam('param', $param, PDO::PARAM_STR);
        $rstm->bindValue('value', 'value', PDO::PARAM_STR);

        $this->assertTrue($rstm->execute());
        $this->assertEquals([$param, 'value', 1], $rstm->fetch(PDO::FETCH_NUM));
    }

    public function testBindColumn()
    {
        $pdo = new ReconnectingPDO($this->testDSN);
        $value = $id = null;
        $statement = $pdo->prepare('SELECT 11, "valuevalue" as value;');
        $statement->execute();

        $statement->bindColumn(1, $id);
        $statement->bindColumn('value', $value);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        $reflection = new \ReflectionClass($statement);
        $prop = $reflection->getProperty('seedData');
        $prop->setAccessible(true);
        $seedData = $prop->getValue($statement);
        $this->assertEquals('valuevalue', $value);
        $this->assertEquals(11, $id);
    }

    public function testBindColumnWithFetchAll()
    {

        $newPDO = new ReconnectingPDO('', '', '');
        $newPDO->setPDO($this->commonTestDB);
        $stm = $newPDO->prepare('SELECT * FROM test');

        $stm->bindColumn('id', $newId);
        $stm->bindColumn(2, $newValue);

        $stm->execute();
        $stm->fetchAll();
        $lastIndex = (count($this->testDBData) - 1);

        $this->assertEquals($this->testDBData[$lastIndex]['id'], $newId);
        $this->assertEquals($this->testDBData[$lastIndex]['name'], $newValue);
    }

    public function testGetPDOStatement()
    {
        $mockStatement = $this->getMockBuilder(PDOStatement::class)->getMock();
        $mockRPDO = $this->getMockBuilder(ReconnectingPDO::class)->getMock();

        $rstm = new ReconnectingPDOStatement($mockStatement, $mockRPDO);

        $this->assertInstanceOf(PDOStatement::class, $rstm->getPDOStatement());
        $this->assertEquals($mockStatement, $rstm->getPDOStatement());
    }

    public function testPreparedRecreation()
    {
        // Create failing statement while fecthing
        $mockStm = $this->getMockBuilder(PDOStatement::class)->getMock();
        $mockStm->method('fetch')->will($this->throwException(new \PDOException('Mysql server has gone away')));
        $mockStm->method('execute')->willReturn(true);

        // Create reconnecting PDO
        $rPDO = new ReconnectingPDO($this->testDSN, '', '');
        $pdo = $this->getMockBuilder(PDO::class)
                    ->setConstructorArgs([$this->testDSN])
                    ->getMock();
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
        $this->assertEquals("1", $rstm->fetch(PDO::FETCH_COLUMN),
                "First fetch try which triggers reconnection");

        $this->assertNotEquals($mockStm, $rstm->getPDOStatement(),
                "Check if statement has been recreated");
        $this->assertTrue($rstm->isExecuted(), "Check if recreated");
    }

    public function testQueryRecreation()
    {

        // Create failing statement while fecthing
        $mockStm = $this->getMockBuilder(PDOStatement::class)->getMock();
        $mockStm->method('fetch')->will($this->throwException(new \PDOException('Mysql server has gone away')));
        $mockStm->method('execute')->willReturn(true);

        // Create reconnecting PDO
        $rPDO = new ReconnectingPDO($this->testDSN, '', '');
        $pdo = $this->getMockBuilder(PDO::class)
                    ->setConstructorArgs([$this->testDSN])
                    ->getMock();
        $pdo->method('query')->will($this->throwException(new \PDOException('Mysql server has gone away')));
        $rPDO->setPDO($pdo);

        $rstm = new ReconnectingPDOStatement($mockStm, $rPDO);

        // Set query string to have working querystring while recreation
        $reflection = new \ReflectionClass($rstm);
        $queryStringProperty = $reflection->getProperty('queryString');
        $queryStringProperty->setAccessible(true);
        $queryStringProperty->setValue($rstm, 'SELECT 1;');
        $isQueryProperty = $reflection->getProperty('isQuery');
        $isQueryProperty->setAccessible(true);
        $isQueryProperty->setValue($rstm, true);

        $this->assertEquals([1], $rstm->fetch(\PDO::FETCH_NUM));
    }

    public function testBindingRecreation()
    {
        // Create failing statement while fecthing
        $mockStm = $this->getMockBuilder(PDOStatement::class)->getMock();
        $mockStm->method('fetch')->will($this->throwException(new \PDOException('Mysql server has gone away')));
        $mockStm->method('execute')->willReturn(true);

        // Create reconnecting PDO
        $rPDO = new ReconnectingPDO($this->testDSN, '', '');
        $pdo = $this->getMockBuilder(PDO::class)
                    ->setConstructorArgs([$this->testDSN])
                    ->getMock();
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
        $mockStm = $this->getMockBuilder(PDOStatement::class)->getMock();
        $mockStm->method('fetch')->will($this->throwException(new \PDOException));
        $mockStm->method('execute')->willReturn(true);

        // Create reconnecting PDO
        $rPDO = new ReconnectingPDO($this->testDSN, '', '');
        $pdo = $this->getMockBuilder(PDO::class)
                    ->setConstructorArgs([$this->testDSN])
                    ->getMock();
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

    public function testCursorAfterFetchAll()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        /* @var $stm ReconnectingPDOStatement */
        $stm = $rpdo->query('SELECT * FROM test');
        $result = $stm->fetchAll();
        $stmCursor = new \LegoW\ReconnectingPDO\StatementCursor();
        $stmCursor->setPosition(count($result));

        $this->assertAttributeEquals($stmCursor, 'cursor', $stm);
    }

    public function testCloseCursor()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        /* @var $stm ReconnectingPDOStatement */
        $stm = $rpdo->prepare('SELECT * FROM test');

        $stm->execute();
        $this->assertEquals($stm->fetch(\PDO::FETCH_ASSOC), $this->testDBData[0]);
        $stm->closeCursor();
        $this->assertFalse($stm->fetch(\PDO::FETCH_ASSOC));
        //Reexecuting starts everything over
        $stm->execute();
        $this->assertEquals($stm->fetch(\PDO::FETCH_ASSOC), $this->testDBData[0]);
    }

    public function testColumnCount()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        $stm = $rpdo->prepare('SELECT * FROM test');

        $this->assertSame(0, $stm->columnCount());
        $stm->execute();
        $this->assertSame(3, $stm->columnCount());
    }

    public function testDebugDumpParams()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        $query = 'SELECT * FROM test';
        /* @var $stm ReconnectingPDOStatement */
        $stm = $rpdo->prepare($query);

        $debugParams = 'SQL: [' . strlen($query) . '] ' . $query . "\nParams:  0\n";

        ob_start();
        $stm->debugDumpParams();
        $output = ob_get_contents();
        ob_end_clean();
        $this->assertSame($debugParams, $output);
    }

    public function testErrorCode()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        $stm = $rpdo->prepare('SELECT * FROM test');
        $stm->execute();
        $this->assertSame('00000', $stm->errorCode());
    }

    public function testErrorInfo()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        $stm = $rpdo->prepare('SELECT * FROM test');
        $stm->execute();
        $emptyErrorInfo = [
            '00000',
            null,
            null
        ];
        $this->assertSame($emptyErrorInfo, $stm->errorInfo());
    }

    public function testFetchColumn()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        $stm = $rpdo->prepare('SELECT * FROM test');
        $stm->execute();

        $this->assertEquals($this->testDBData[0]['name'], $stm->fetchColumn(1));
        $this->assertEquals($this->testDBData[1]['id'], $stm->fetchColumn());
    }

    public function testFetchObject()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        $stm = $rpdo->prepare('SELECT * FROM test');
        $stm->execute();
        $testObject = new FetchObjectTestClass();
        $testObject->id = 1;
        $testObject->name = 'név';
        $testObject->value = 'érték';
        $this->assertEquals($testObject,
                $stm->fetchObject(FetchObjectTestClass::class));
        $this->assertInstanceOf(get_class($testObject),
                $stm->fetchObject(FetchObjectTestClass::class));
    }

    public function testGetColumnMeta()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        $stm = $rpdo->prepare('SELECT id FROM test');
        $this->assertFalse($stm->getColumnMeta(0));
        $stm->execute();
        $this->assertArraySubset(['name' => 'id', 'table' => 'test', 'native_type' => 'integer'],
                $stm->getColumnMeta(0));
    }

    public function testRowCount()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);
        $rpdo->beginTransaction();
        $stm = $rpdo->prepare('DELETE FROM test');
        $this->assertSame(0, $stm->rowCount());
        $stm->execute();
        $this->assertSame(count($this->testDBData), $stm->rowCount());
        $rpdo->rollBack();
    }

    public function testSetFetchMode()
    {
        $rpdo = new ReconnectingPDO($this->testDSN, '', '');
        $rpdo->setPDO($this->commonTestDB);

        $stm = $rpdo->prepare('SELECT * FROM test');
        $stm->setFetchMode(\PDO::FETCH_ASSOC);
        $stm->execute();
        $this->assertEquals($this->testDBData[0], $stm->fetch());
        $stm->setFetchMode(\PDO::FETCH_NUM);
        $this->assertEquals(array_values($this->testDBData[1]), $stm->fetch());
    }
    
    public function testExecuteWithParameters()
    {        
        // Create failing statement while execute
        $mockStm = $this->getMockBuilder(PDOStatement::class)->getMock();
        $mockStm->method('execute')->will($this->throwException(new \PDOException('Mysql server has gone away')));

        // Create reconnecting PDO
        $rPDO = new ReconnectingPDO($this->testDSN, '', '');

        $rstm = new ReconnectingPDOStatement($mockStm, $rPDO);

        // Set query string to have working querystring while recreation
        $reflection = new \ReflectionClass($rstm);
        $queryStringProperty = $reflection->getProperty('queryString');
        $queryStringProperty->setAccessible(true);
        $queryStringProperty->setValue($rstm, 'SELECT :value, :param;');

        $value = $param = 'value';
        $rstm->execute([
            'value' => $value,
            'param' => $param
        ]);

        $this->assertEquals([$value, $param], $rstm->fetch(PDO::FETCH_NUM));
    }

}
