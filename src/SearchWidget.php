<?php
/**
 * Author:  Mark O'Keeffe

 * Date:    28/10/13
 *
 * [Yii Workbench] VSearchWidget.php
 */

/**
 * Class SearchWidget
 *
 * @property $owner \CController
 *
 */
class SearchWidget extends \CWidget {

  /**
   * The search class
   * @var \MOK\SphinxSearch\Search
   */
  protected $search;

  /**
   * The model class of objects we are searching e.g. 'Content'
   *
   * @var string
   */
  public $className;

  /**
   * The search form model
   * @var \SearchForm
   */
  public $formModel;

  /**
   * The search form view
   * @var string
   */
  public $formView;

  /**
   * A partial view used to render the results
   * @var string
   */
  public $resultsView;

  /**
   * Number of results per page
   * @var int
   */
  public $resultsPageSize = 20;

  /**
   * An array of relationship names to add to the query fetching results
   * @var array
   */
  public $classRelations=array();

  /**
   * The parameters to configure CSort
   * @var array
   */
  public $dbSort = array();

  /**
   * Attribute values to exclude
   * @var array
   */
  public $excludeFilters = array();

  /**
   * Initialisation
   *
   * @throws CHttpException
   */
  public function init()
  {
    if (!is_object($this->formModel)) {
      throw new CHttpException(500, 'Invalid search model.');
    }
    if (!$this->formView || !$this->resultsView) {
      throw new CHttpException(500, 'Need partial views for search form and results.');
    }

    $this->search = Yii::app()->Search;

  }

  /**
   * Display the widget
   */
  public function run()
  {

    // Get the model instance
    $model = $this->formModel;

    if (isset($_GET['q'])) {

      // Set the model attributes
      $model->attributes = $_GET;

      // Set the model relations
      $this->search->relations = $this->classRelations;

      // Apply config variables to the search class
      $this->search->config = array(
        // Results per page e.g. 20
        'pageSize' => $this->resultsPageSize,
        // Sort attributes for results table
        'dbSort' => $this->dbSort,
        // Filters e.g. 'content type = "freebie"'
        'filters' => $model->filterValues,
        // Exclude these IDs from the search
        'excludeFilters' => $this->excludeFilters,
      );

      // Execute the query
      $results = $this->search->query($this->className, $model->q);
    }

    echo CHtml::openTag('div', array('class' => 'search-container clearfix'));

    // Render the provided form view
    $this->owner->renderPartial($this->formView, compact('model'));

    if (isset($results)) {
      // Render the provided results view
      $this->owner->renderPartial($this->resultsView, compact('model', 'results'));
    }

    echo CHtml::closeTag('div');

  }

}
