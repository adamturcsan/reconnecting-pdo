<?php

/*
 * All rights reserved © 2016 Legow Hosting Kft.
 */

namespace LegowTest\ReconnectingPDO;

/**
 * Description of ReconnectingPDOTest
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class ReconnectingPDOTest extends \PHPUnit_Framework_TestCase
{

    public function testInstance()
    {
        $instance = $this->getMock('PDO', ['localhost']);
        $this->assertTrue($instance instanceof \PDO);
    }

}
