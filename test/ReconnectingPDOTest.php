<?php

/*
 * All rights reserved © 2016 Legow Hosting Kft.
 */

namespace LegowTest\ReconnectingPDO;

use Legow\ReconnectingPDO\ReconnectingPDO;
use Legow\ReconnectingPDO\ReconnectingPDOStatement;

/**
 * Description of ReconnectingPDOTest
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class ReconnectingPDOTest extends \PHPUnit_Framework_TestCase
{

    public function testInstance()
    {
        $instance = new ReconnectingPDO('sqlite:'.__DIR__.'/test.sq3');
        $this->assertTrue($instance instanceof ReconnectingPDO);
        return $instance;
    }


    /**
     * @depends testInstance
     */
    public function testStatement($instance)
    {
        $statement = $instance->prepare('CREATE TABLE `tests` ('
                . 'id int)');
        die(get_class($statement));
        $this->assertTrue($statement instanceof ReconnectingPDOStatement);
        $this->assertTrue($statement->execute());

        $insertStatement = $instance->prepare('INSERT INTO `tests` (`id`) VALUES (1);');
        $this->assertTrue($insertStatement instanceof ReconnectingPDOStatement);
        $this->assertTrue($insertStatement->execute());
        $deleteStatement = $instance->prepare('DROP TABLE `tests`');
        $this->assertTrue($deleteStatement instanceof ReconnectingPDOTest);
        $this->assertTrue($deleteStatement->execute());
    }

}
