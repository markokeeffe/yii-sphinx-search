<?php
/**
 * Author:  Mark O'Keeffe

 * Date:    26/11/13
 *
 * [Free Stuff World] SphinxDbCommand.php
 */

class SphinxDbCommand extends CDbCommand
{
  const INSERT_COMMAND = 'INSERT';
  const REPLACE_COMMAND = 'REPLACE';

  /**
   * Creates and executes an INSERT SQL statement.
   * The method will properly escape the column names, and bind the values to be inserted.
   *
   * @param string $table   the table that new rows will be inserted into.
   * @param array  $columns the column data (name=>value) to be inserted into the table.
   *
   * @return integer number of rows affected by the execution.
   */
  public function insert($table, $columns)
  {
    return $this->_intoCommand($table, $columns, self::INSERT_COMMAND);
  }

  /**
   * Creates and executes an REPLACE SQL statement.
   * The method will properly escape the column names, and bind the values to be replaced.
   *
   * @param string $table   the table that new rows will be inserted into.
   * @param array  $columns the column data (name=>value) to be inserted into the table.
   *
   * @return integer number of rows affected by the execution.
   */
  public function replace($table, $columns)
  {
    return $this->_intoCommand($table, $columns, self::REPLACE_COMMAND);
  }

  protected function _intoCommand($table, $columns, $type = self::INSERT_COMMAND)
  {
    $params = array();
    $names = array();
    $placeholders = array();
    foreach ($columns as $name => $value) {
      $names[] = $name === 'id' ? $name : $this->getConnection()->quoteColumnName($name);
      if ($value instanceof CDbExpression) {
        $placeholders[] = $value->expression;
        foreach ($value->params as $n => $v)
          $params[$n] = $v;
      } else {
        $placeholders[] = ':' . $name;
        $params[':' . $name] = $value;
      }
    }
    $sql = $type . ' INTO ' . $this->getConnection()->quoteTableName($table)
      . ' (' . implode(', ', $names) . ') VALUES ('
      . implode(', ', $placeholders) . ')';

    return $this->setText($sql)->execute($params);
  }
}
