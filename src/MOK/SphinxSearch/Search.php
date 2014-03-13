<?php namespace MOK\SphinxSearch;
/**
 * Author:  Mark O'Keeffe
 * Date:    28/10/13
 */

/**
 * Class VSearch
 *
 * A Sphinx Search widget
 *
 * @package MOK\SphinxSearch
 */
class Search  extends \CApplicationComponent {

  /**
   * Minimum occurrences of a keyword to suggest it
   */
  const FREQ_THRESHOLD = 10;

  /**
   * Debug suggestions?
   */
  const SUGGEST_DEBUG = 0;

  /**
   * How many letters difference do we allow?
   */
  const LENGTH_THRESHOLD = 3;

  /**
   * How far apart a Levenshtein distance do we allow?
   */
  const LEVENSHTEIN_THRESHOLD = 2;

  /**
   * Maximum suggestions to find for a keyword
   */
  const TOP_COUNT = 10;

  /**
   * Suggest keywords if the number of results is below this number
   */
  const MIN_RESULT_COUNT = 10;

  /**
   * The search query
   * @var string
   */
  public $query;

  /**
   * A limit of matches to return in a sphinx search
   * @var int
   */
  public $maxMatches = 1000;

  /**
   * Names of relations for the model used to get results from the DB
   * @var array
   */
  public $relations = array();

  /**
   * List of Sphinx indexes to query
   * @var string
   */
  public $indexes = '*';

  /**
   * Other configuration options e.g. filters, sort attributes, exclude IDs
   * @var array
   */
  public $config = array();

  /**
   * Make partial matches on search terms
   * @var bool
   */
  public $partialMatch = true;

  /**
   * Execute the search query and return results
   *
   * @param string $modelName
   * @param string $query
   *
   * @return array
   */
  public function query($modelName, $query)
  {
    // Set up a new sphinx connection and escape the query string
    $con = \Yii::app()->sphinx;
    $q = $con->escape($query);

    if ($this->partialMatch) {
      $q = '*'.$q.'*';
    }

    // Create the data provider, running the query and selecting models from
    // the database to match the results
    $dataProvider = new \ESphinxDataProvider($modelName, array(
      'query'=>$q,
      'criteria'=>$this->dbCriteria(),
      'sphinxCriteria'=>$this->sphinxCriteria(),
      'indexes' => $this->indexes,
      'sort'=>$this->dbSort(),
      'pagination'=>array(
        'pageSize'=>$this->pageSize(),
      ),
    ));

    if ($dataProvider->getTotalItemCount() < self::MIN_RESULT_COUNT) {
      // Make suggestions for each word to fix any spelling mistakes
      $suggestion = $this->makeSuggestions($query);
    } else {
      $suggestion = false;
    }

    // Remove escaping slashes and asterisks from query string
    $q = preg_replace(array('/^\*/', '/\*$/'), '', stripslashes($q));

    return compact('q', 'dataProvider', 'suggestion');
  }

  /**
   * Make suggestions to fix spelling on query terms
   *
   * @param $query
   *
   * @return array|bool
   */
  public function makeSuggestions($query)
  {

    // Split the query into separate words
    $words = explode(' ', trim($query));

    // Bool to check if query words change at all
    $change = false;
    // New query words will go here
    $newWords = array();
    $suggestionText = array();
    foreach ($words as $word) {
      // Find suggested alternates for the word
      $suggestions = $this->querySuggestions($word);
      // Get the best suggested word, or false if none suitable
      $topSuggestion = $this->topSuggestion($suggestions);
      if ($topSuggestion) {
        $change = true;
        $newWords[] = $topSuggestion;
        $suggestionText[] = '<strong><i>'.$topSuggestion.'</i></strong>';
      } else {
        $newWords[] = $word;
        $suggestionText[] = $word;
      }
    }

    if (!$change) {
      return false;
    }

    $suggestions = array(
      'text' => implode(' ', $suggestionText),
      'query' => implode(' ', $newWords),
    );

    return $suggestions;
  }

  /**
   * Find the best suitable suggestion from an array of suggested keywords
   *
   * @param $suggestions
   *
   * @return bool
   */
  public function topSuggestion($suggestions)
  {
    // No suggestions? Or top suggestion matches the keyword?
    if (!count($suggestions) || $suggestions[0]['levdist'] == 0) {
      // No need to offer a suggestion
      return false;
    }

    // Loop through each suggestion and return the first one with
    // matched documents
    foreach ($suggestions as $suggestion) {
      // Does this keyword return any results in the index?
      if ($this->countDocs($suggestion['keyword'], $this->indexes)) {
        // Return the suggested keyword
        return $suggestion['keyword'];
      }
    }

    return false;
  }

  /**
   * Suggest an alternative search keyword
   *
   * @param $keyword
   *
   * @return array
   */
  public function querySuggestions($keyword)
  {
    // Split keyword into trigrams
    $trigrams = $this->trigrams($keyword);

    $query = "\"$trigrams\"/1";
    $len = strlen($keyword);

    $delta = self::LENGTH_THRESHOLD;

    $c  = new \ESphinxSearchCriteria(array(
      'matchMode' => \ESphinxMatch::EXTENDED2,
      'rankingMode' => SPH_RANK_WORDCOUNT,
      'sortMode' => SPH_SORT_EXTENDED,
      'limit' => self::TOP_COUNT,
      'select' => "*, WEIGHT() + $delta - abs(len-$len) AS myrank",
    ));

    $c->setOrders(array(
      'myrank' => 'DESC',
      'freq' => 'DESC',
    ));

    $c->setRangeFilters(array(
      array('len', 'min' => $len - $delta, 'max' => $len + $delta),
    ));

    $res = \Yii::app()->sphinx->executeQuery(
      new \ESphinxQuery($query, 'suggest', $c)
    );

    $suggestions = array();

    if (!$res) {
      return $suggestions;
    }

    // further restrict trigram matches with a sane Levenshtein distance limit
    foreach ($res as $match) {
      if (levenshtein($keyword, $match->keyword) <= self::LEVENSHTEIN_THRESHOLD) {
        $levdist = levenshtein($keyword, $match->keyword);
        $suggestion = array(
          'keyword' => $match->keyword,
          'myrank' => $match->myrank,
          'levdist' => $levdist,
        );
        if (self::SUGGEST_DEBUG) {
          echo '<pre>'.print_r($suggestion, true).'</pre>';
        }
        $suggestions[] = $suggestion;
      }
    }

    return $suggestions;
  }

  /**
   * Count the number of documents returned for a keyword
   *
   * @param $keyword
   * @param $indexes
   *
   * @return mixed
   */
  public function countDocs($keyword, $indexes)
  {
    $c  = new \ESphinxSearchCriteria(array(
      'matchMode' => \ESphinxMatch::EXTENDED,
      'select' => "*",
    ));

    $res = \Yii::app()->sphinx->executeQuery(
      new \ESphinxQuery($keyword, $indexes, $c)
    );

    return $res->getFoundTotal();
  }

  /**
   * Create trigrams from a keyword
   *
   * @param $keyword
   *
   * @return string
   */
  public function trigrams($keyword)
  {
    $t = "__" . $keyword . "__";

    $trigrams = "";
    for ( $i=0; $i<mb_strlen($t)-2; $i++ )
      $trigrams .= mb_substr ( $t, $i, 3 ) . " ";

    return $trigrams;
  }

  /**
   * Get the page size from config
   *
   * @return int
   */
  protected function pageSize()
  {
    return (isset($this->config['pageSize']) ? $this->config['pageSize'] : 20);
  }

  /**
   * Build the database query criteria
   *
   * @return \CDbCriteria
   */
  protected function dbCriteria()
  {
    // Set up DB criteria for the data provider
    $criteria = new \CDbCriteria();
    if (count($this->relations)) {
      $criteria->with = $this->relations;
    }
    return $criteria;
  }

  /**
   * Build a sort for the database query/results
   *
   * @return \CSort
   */
  protected function dbSort()
  {
    $sort = new \CSort();
    if (isset($this->config['dbSort']) && count($this->config['dbSort'])) {
      foreach ($this->config['dbSort'] as $key => $val) {
        $sort->{$key} = $val;
      }
    }
    return $sort;
  }

  /**
   * Build the Sphinx search criteria
   *
   * @return \ESphinxSearchCriteria
   */
  protected function sphinxCriteria()
  {
    // Create a sphinx search criteria and add filters from the form
    $sphinxCriteria = new \ESphinxSearchCriteria(array(
      'matchMode' => \ESphinxMatch::EXTENDED,
      'maxMatches' => $this->maxMatches,
    ));

    // Add any filters to the criteria
    $this->addFilters($sphinxCriteria);

    // Set an 'order by' on the criteria
    $this->addSort($sphinxCriteria);

    return $sphinxCriteria;
  }

  /**
   * Find any configured filters in the config array and apply them
   * to the search criteria
   *
   * @param \ESphinxSearchCriteria $sphinxCriteria
   */
  protected function addFilters(&$sphinxCriteria)
  {
    $filters = array(
      'filters' =>  false,
      'excludeFilters' => true,
    );

    foreach ($filters as $filter => $exclude) {
      // Are there any filters to add to the search query?
      if (isset($this->config[$filter]) && count($this->config[$filter])) {
        // Add each include filter if values are set
        foreach ($this->config[$filter] as $key => $val) {
          if ($val != null || $val === 0) {
            if (is_numeric($val)) {
              $val = (int) $val;
            } elseif (is_array($val)) {
              foreach ($val as $i => $v) {
                if (is_numeric($v)) {
                  $val[$i] = (int) $v;
                }
              }
            }
            $sphinxCriteria->addFilter($key, $val, $exclude);
          }
        }
      }
    }

  }

  /**
   * Add an order by clause to the search criteria. Sort should be
   * defined as array:
   *   array(
   *     'attribute' => 'date_added',
   *     'mode' => 'DESC',
   *   )
   *
   * @param \ESphinxSearchCriteria $sphinxCriteria
   */
  protected function addSort(&$sphinxCriteria)
  {
    if (isset($this->config['sort'])) {
      $sort = $this->config['sort'];
      if (isset($sort['attribute'])) {

        if (isset($sort['mode']) && strtolower($sort['mode']) === 'desc') {
          $sphinxCriteria->sortMode = SPH_SORT_ATTR_DESC;
        } else {
          $sphinxCriteria->sortMode = SPH_SORT_ATTR_ASC;
        }

        $sphinxCriteria->setSortBy($sort['attribute']);
      }
    }
  }

}
