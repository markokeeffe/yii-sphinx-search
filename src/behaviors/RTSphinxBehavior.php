<?php

/**
 * RTSphinxBehavior
 *
 * @author mlapko
 */
class RTSphinxBehavior extends CActiveRecordBehavior
{
  /**
   * Method for getting index data
   *
   * @var mixed
   */
  public $getDataMethod;

  /**
   * Sphinx table
   *
   * @var string
   */
  public $sphinxIndex = 'rt';

  /**
   * Sphinx db component name or component instance
   *
   * @var mixed
   */
  public $sphinxDbComponent = 'dbsphinx';

  /**
   * Enable or disable callbacks
   *
   * @var boolean
   */
  public $allowCallbacks = true;

  /**
   * Enable or disable behavior
   *
   * @var boolean
   */
  public $disabled = false;

  /**
   *
   * @var SphinxDbCommand
   */
  protected $_command;

  /**
   * @param null $sql
   *
   * @return SphinxDbCommand
   */
  public function getCommand($sql = null)
  {
    if ($this->_command === null) {
      $conn = is_object($this->sphinxDbComponent) ? $this->sphinxDbComponent : Yii::app()->{$this->sphinxDbComponent};
      $conn->setActive(true);
      $this->_command = new SphinxDbCommand($conn);
    }

    return $this->_command->setText($sql);
  }

  /**
   * Insert index into sphinx
   *
   * @param mixed $data
   *
   * @param bool  $throwEx
   *
   * @throws Exception
   * @return boolean
   */
  public function insertIndex($data = null, $throwEx=false)
  {
    if ($this->disabled) {
      Yii::log('Sphinx behaviour disabled.');
      return true;
    }
    if ($data === null) {
      $data = call_user_func($this->getDataMethod);
    }
    Yii::log('Inserting to Sphinx index: '.$data['id']);

    try {
      $success = $this->getCommand()->insert($this->sphinxIndex, $data);
    } catch (Exception $e) {
      if ($throwEx && get_class($e) == $throwEx) {
        throw $e;
      }
      Yii::log('Failed to insert into index: '.$e->getMessage(), CLogger::LEVEL_ERROR);
      return false;
    }

    return $success;

  }

  /**
   * Update sphinx index
   *
   * @param mixed $data
   *
   * @return boolean
   */
  public function updateIndex($data = null)
  {
    if ($this->disabled) {
      Yii::log('Sphinx behaviour disabled.');
      return true;
    }
    if ($data === null) {
      $data = call_user_func($this->getDataMethod);
    }
    Yii::log('Updating Sphinx index: '.$data['id']);

    try {
      $success = $this->getCommand()->replace($this->sphinxIndex, $data);
    } catch (Exception $e) {
      Yii::log('Failed to update index: '.$e->getMessage(), CLogger::LEVEL_ERROR);
      return false;
    }

    return $success;

  }

  /**
   * Delete index from sphinx
   *
   * @param null|array $ids
   *
   * @throws CException
   * @return integer
   */
  public function deleteIndex($ids = null)
  {
    if ($this->disabled) {
      return true;
    }
    if ($ids === null) {
      $ids = array($this->getOwner()->getPrimaryKey());
    } elseif (!is_array($ids)) {
      throw new CException('The param "ids" should be array.');
    }

    if (count($ids) > 0) {
      Yii::log('Deleting from Sphinx index: (' . implode(',', $ids) . ')');
      return $this->getCommand()->delete($this->sphinxIndex, 'id IN (' . implode(',', $ids) . ')');
    }

    return 0;
  }

  /**
   * Responds to {@link CModel::onAfterSave} event.
   * Inserted or updated sphinx index
   *
   * @param CModelEvent $event event parameter
   *
   * @return bool|void
   */
  public function afterSave($event)
  {
    if (!$this->allowCallbacks) {
      return true;
    }

    if ($this->getOwner()->getIsNewRecord()) {
      try {
        $this->insertIndex(null, 'CDbException');
      } catch (CDbException $e) {
        if (str_contains($e->getMessage(), 'duplicate id')) {
          $this->updateIndex();
        }
      }
    } else {
      $this->updateIndex();
    }
    return parent::afterSave($event);
  }

  /**
   * Responds to {@link CModel::onAfterDelete} event.
   * Inserted or updated sphinx index
   *
   * @param CModelEvent $event event parameter
   *
   * @return bool|void
   */
  public function afterDelete($event)
  {
    if (!$this->allowCallbacks) {
      return true;
    }
    $this->deleteIndex();
    return parent::afterDelete($event);
  }

}