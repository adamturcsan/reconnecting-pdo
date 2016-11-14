<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO\Test;

use PHPUnit\Framework\TestCase;
use LegoW\ReconnectingPDO\StatementCursor;
use LegoW\ReconnectingPDO\CursorException;
/**
 * Description of StatementCursorTest
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class StatementCursorTest extends TestCase
{
    
    public function testGetPosition()
    {
        $cursor = new StatementCursor();
        
        $this->assertEquals(0, $cursor->getPosition());
        
        $reflection = new \ReflectionClass($cursor);
        $positionProperty = $reflection->getProperty('position');
        $positionProperty->setAccessible(true);
        $positionProperty->setValue($cursor, 11);
        
        $this->assertEquals(11, $cursor->getPosition());
    }
    
    /**
     * @depends testGetPosition
     */
    public function testSetPosition()
    {
        $cursor = new StatementCursor();
        
        $cursor->setPosition(1);
        $this->assertEquals(1, $cursor->getPosition());
        $cursor->setPosition(11);
        $this->assertEquals(11, $cursor->getPosition());
        $cursor->setPosition(15);
        $this->assertEquals(15, $cursor->getPosition());
        $cursor->setPosition(5);
        $this->assertEquals(5, $cursor->getPosition());
    }
    
    /**
     * @depends testGetPosition
     */
    public function testNext()
    {
        $cursor = new StatementCursor();
        
        $this->assertEquals(0, $cursor->getPosition());
        $cursor->next()
               ->next();
        $this->assertEquals(2, $cursor->getPosition());
    }
    
    /**
     * @depends testGetPosition
     * @depends testSetPosition
     */
    public function testPrevGreatenThanZero()
    {
        $cursor = new StatementCursor();
        
        $cursor->setPosition(15)
               ->prev();
        $this->assertEquals(14, $cursor->getPosition());
        $cursor->prev()
               ->prev()
               ->prev();
        $this->assertEquals(11, $cursor->getPosition());
    }
    
    /**
     * @expectedException LegoW\ReconnectingPDO\CursorException
     * @expectedExceptionMessage Position cannot be less than zero
     */
    public function testPrevLessThanZero()
    {
        $cursor = new StatementCursor();
        
        $cursor->setPosition(0)
               ->prev();
    }
}
