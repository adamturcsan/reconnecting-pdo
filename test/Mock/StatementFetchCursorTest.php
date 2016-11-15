<?php

/*
 * LegoW\ReconnectingPDO (https://github.com/adamturcsan/reconnecting-pdo)
 * 
 * @copyright Copyright (c) 2014-2016 Legow Hosting Kft. (http://www.legow.hu)
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace LegoW\ReconnectingPDO\Test\Mock;

/**
 * Should throw a \PDOException at second fetch call
 *
 * @author Turcsán Ádám <turcsan.adam@legow.hu>
 */
class StatementFetchCursorTest extends \PDOStatement
{

    protected $statement;
    public $queryString = 'SELECT * FROM test';
    
    protected $fetchCalls = 0;

    public function fetch($fetch_style = null,
            $cursor_orientation = \PDO::FETCH_ORI_NEXT, $cursor_offset = 0)
    {
        if(++$this->fetchCalls == 2) {
            throw new \PDOException('Mysql server has gone away');
        }
        return $this->statement->fetch($fetch_style, $cursor_orientation, $cursor_offset);
    }
    
    public function execute($input_params = null)
    {
        return $this->statement->execute($input_params);
    }

    public function setStatement(\PDOStatement $stm)
    {
        $this->statement = $stm;
    }
}
