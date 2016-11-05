<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO\Test;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;
use Legow\ReconnectingPDO\ReconnectingPDO;
use Legow\ReconnectingPDO\ReconnectingPDOStatement;

/**
 * Description of ReconnectingPDOStatementTest
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class ReconnectingPDOStatementTest extends TestCase
{

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
        $pdo = new PDO($this->testDSN);
        $stm = $pdo->prepare('SELECT 1');
        
        $rstm = new ReconnectingPDOStatement($stm, $pdo);
        $this->assertInstanceOf(ReconnectingPDOStatement::class, $rstm);
        
    }

}
