<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO;

use LegoW\ReconnectingPDO\CursorException;
/**
 * Description of StatementCursor
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class StatementCursor
{
    /**
     * Current cursor position
     * @var int
     */
    private $position = 0;
    
    public function getPosition()
    {
        return $this->position;
    }
    
    /**
     * @return $this
     */
    public function next()
    {
        $this->position++;
        return $this;
    }
    /**
     * 
     * @return $this
     * @throws CursorException
     */    
    public function prev()
    {
        if($this->getPosition() == 0) {
            throw new CursorException("Position cannot be less than zero");
        }
        $this->position--;
        return $this;
    }
    
    public function setPosition($position)
    {
        $direction = $position < $this->position ? -1 : 1;
        while($this->position != $position) {
            $this->position += $direction;
        }
        return $this;
    }
}
