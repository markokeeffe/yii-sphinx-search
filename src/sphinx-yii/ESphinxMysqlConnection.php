<?php


class ESphinxMysqlConnection extends ESphinxBaseConnection
{
  /**
   * @var string
   */
  private $server;

  /**
   * @var int
   */
  private $port;

  /**
   * @var CDbConnection
   */
  private $db;
  private $connectionTimeout;
  private $queryTimeout = 0;


  public function init()
  {
    $this->openConnection();
  }

  /**
   * Set Sphinx server connection parameters.
   *
   * @param array $parameters list of params, where first item is host, second is port
   *
   * @example 'localhost'
   * @example 'localhost:3314'
   * @example array("localhost", 3386)
   * @link    http://sphinxsearch.com/docs/current.html#api-func-setserver
   */
  public function setServer($parameters = null)
  {
    $server = self::DEFAULT_SERVER;
    $port = self::DEFAULT_PORT;
    if (is_string($parameters)) {
      $parameters = explode(':', $parameters);
    }

    if (isset($parameters[0])) {
      $server = $parameters[0];
    }
    if (isset($parameters[1])) {
      $port = $parameters[1];
    }

    $this->server = $server;
    $this->port = $port;
  }

  /**
   * Open Sphinx persistent connection.
   *
   * @throws ESphinxException if client is already connected.
   * @throws ESphinxException if client has connection error.
   * @link http://sphinxsearch.com/docs/current.html#api-func-open
   */
  public function openConnection()
  {
    $dsn = "mysql:host={$this->server};port={$this->port};";
    $this->db = new ESphinxQlDbConnection($dsn);
    $this->db->setAttribute(PDO::ATTR_TIMEOUT, $this->connectionTimeout);
    $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

    $this->db->setActive(true);
  }

  /**
   * Close Sphinx persistent connection.
   *
   * @throws ESphinxException if client is not connected.
   * @link http://sphinxsearch.com/docs/current.html#api-func-close
   */
  public function closeConnection()
  {
    $this->getDbConnection()->setActive(false);
  }

  /**
   * Check is client has connection
   *
   * @return boolean
   */
  public function getIsConnected()
  {
    return $this->db && $this->db->getActive();
  }

  /**
   * Sets the time allowed to spend connecting to the server before giving up.
   * Under some circumstances, the server can be delayed in responding, either due to network delays,
   * or a query backlog. In either instance, this allows the client application programmer some degree
   * of control over how their program interacts with searchd when not available, and can ensure
   * that the client application does not fail due to exceeding the script execution limits (especially in PHP).
   * In the event of a failure to connect, an appropriate error code should be returned back to the application
   * in order for application-level error handling to advise the user.
   *
   * @param integer $timeout
   *
   * @link http://sphinxsearch.com/docs/current.html#api-func-setconnecttimeout
   */
  function setConnectionTimeout($timeout)
  {
    $this->connectionTimeout = (int)$timeout;
  }

  /**
   * Sets maximum search query time, in milliseconds.
   * Parameter must be a non-negative integer. Default valus is 0 which means "do not limit".
   * Similar to $cutoff setting from {@link SetLimits}, but limits elapsed query time instead of processed matches count.
   * Local search queries will be stopped once that much time has elapsed. Note that if you're performing a search
   * which queries several local indexes, this limit applies to each index separately.
   *
   * @param integer $timeout
   *
   * @link http://sphinxsearch.com/docs/current.html#api-func-setmaxquerytime
   */
  public function setQueryTimeout($timeout)
  {
    $this->queryTimeout = (int)$timeout;
  }

  /**
   * @return CDbConnection
   * @throws ESphinxException
   */
  public function getDbConnection()
  {
    if ($this->getIsConnected()) {
      throw new ESphinxException('Connection is not opened');
    }

    return $this->db;
  }

  /**
   * Prototype: function BuildExcerpts ( $docs, $index, $words, $opts=array() )
   * Excerpts (snippets) builder function. Connects to searchd, asks it to generate excerpts (snippets) from given documents, and returns the results.
   * $docs is a plain array of strings that carry the documents' contents. $index is an index name string.
   * Different settings (such as charset, morphology, wordforms) from given index will be used.
   * $words is a string that contains the keywords to highlight. They will be processed with respect to index settings.
   * For instance, if English stemming is enabled in the index, "shoes" will be highlighted even if keyword is "shoe".
   * Starting with version 0.9.9-rc1, keywords can contain wildcards, that work similarly to star-syntax available in queries.
   * $opts is a hash which contains additional optional highlighting parameters:
   * <ul>
   *   <li>"before_match": A string to insert before a keyword match. Default is "&ltb&gt".</li>
   *   <li>"after_match": A string to insert after a keyword match. Default is "&l/tb&gt".</li>
   *   <li>"chunk_separator": A string to insert between snippet chunks (passages). Default is " ... ".</li>
   *   <li>"limit": Maximum snippet size, in symbols (codepoints). Integer, default is 256.</li>
   *   <li>"around": How much words to pick around each matching keywords block. Integer, default is 5.</li>
   *   <li>"exact_phrase": Whether to highlight exact query phrase matches only instead of individual keywords. Boolean, default is false.</li>
   *   <li>"single_passage": Whether to extract single best passage only. Boolean, default is false.</li>
   *   <li>"weight_order": Whether to sort the extracted passages in order of relevance (decreasing weight), or in order of appearance in the document (increasing position). Boolean, default is false.</li>
   * </ul>
   *
   * @param array  $docs
   * @param string $index
   * @param string $words
   * @param array  $opts
   *
   * @return array
   * @link http://sphinxsearch.com/docs/current.html#api-func-buildexcerpts
   */
  public function createExcerpts(array $docs, $index, $words, array $opts = array())
  {
    $excerpts = array();
    $options = "";
    $optionParams = array();
    foreach ($opts as $name => $value) {
      $options .= ", :{$name} as {$name}";
      $optionParams[":{$name}"] = $value;
    }

    foreach ($docs as $data) {
      $query = $this->db->createCommand("CALL SNIPPETS(:data, :index, :words {$options} )");
      $query->params = $optionParams + array(
          ":data", $data,
          ":index", $index,
          ":words", $words,
        );
      $excerpts[] = $query->queryAll();
    }

    return $excerpts;
  }

  /**
   * Extracts keywords from query using tokenizer settings for given index, optionally with per-keyword
   * occurrence statistics. Returns an array of hashes with per-keyword information.
   * $query is a query to extract keywords from. $index is a name of the index to get tokenizing settings and
   * keyword occurrence statistics from. $hits is a boolean flag that indicates whether keyword occurrence
   * statistics are required.
   *
   * @param string  $query
   * @param string  $index
   * @param boolean $hits
   *
   * @return array
   * @link http://sphinxsearch.com/docs/current.html#api-func-buildkeywords
   */
  public function createKeywords($query, $index, $hits = false)
  {
    $command = $this->db->createCommand('CALL KEYWORDS(:query, :index, :hits)');
    $command->params = array(
      ':query' => $query,
      ':index' => $index,
      ':hits'  => $hits,
    );

    return $command->queryAll();
  }

  /**
   * Escapes characters that are treated as special operators by the query language parser. Returns an escaped string.
   * This function might seem redundant because it's trivial to implement in any calling application.
   * However, as the set of special characters might change over time, it makes sense to have an API call that is
   * guaranteed to escape all such characters at all times.
   *
   * @param string $string
   *
   * @return string
   * @link http://sphinxsearch.com/docs/current.html#api-func-escapestring
   */
  public function escape($string)
  {
    // Is the string a URL?
    if (preg_match('/^http/', $string) && $parts = parse_url($string)) {
      // Is there a query on the url?
      if (isset($parts['query']) && $parts['query']) {
        // Strip it off
        $string = preg_replace('/(\?.*)/', '', $string);
      }
    }

    $replace = array(
      '\\' => '\\\\',
      '(' => '\(',
      ')' => '\)',
      '|' => '\|',
      '-' => '\-',
      '!' => '\!',
      '@' => '\@',
      '~' => '\~',
      '"' => '\"',
      '&' => '\&',
      '/' => '\/',
      '^' => '\^',
      '$' => '\$',
      '=' => '\=',
    );

    return str_replace ( array_keys($replace), array_values($replace), $string );
  }

  /**
   * Execute single query.
   *
   * @example
   * <code>
   *   $result = $connection->execute(new ESphinxQuery("hello world search"));
   *   var_dump($result); // printed ESphinxResult var dump
   * </code>
   *
   * @param ESphinxQuery $query
   *
   * @return ESphinxResult
   * @see ESphinxQuery
   * @see ESphinxCriteria
   */
  public function executeQuery(ESphinxQuery $query)
  {
    /** @var ESphinxQlCommandBuilder $cb */
    $cb = $this->db->getCommandBuilder();
    // Create the find query
    $findCommand = $cb->createFindCommand(
      $query->getIndexes(),
      $this->createDbCriteria($query)
    );
    // Creat the meta query
    $metaCommand = $cb->createMetaCommand();

    // Execute the queries
    $matches = $findCommand->query();
    $meta = $metaCommand->query();

    // Create a result object from the queries
    return $this->createResult($matches, $meta);
  }


  /**
   * Execute query collection
   *
   * @example
   * <code>
   *   $queries = array(
   *      new ESphinxQuery("hello"),
   *      new ESphinxQuery("world"),
   *   );
   *   $results = $connection->executeQueries($queries);
   *   foreach($results as $result)
   *      var_dump($result); // print ESphinxResult
   * </code>
   *
   * @param ESphinxQuery[] $queries
   *
   * @return ESphinxResult[]
   */
  public function executeQueries(array $queries)
  {
    /** @var ESphinxQlCommandBuilder $cb */
    $cb = $this->db->getCommandBuilder();

    // prepearing
    $tables = array();
    $criterias = array();
    foreach ($queries as $query) {
      $tables[] = $query->getIndexes();
      $criterias[] = $this->createDbCriteria($query);
    }

    // querying all queries
    $command = $cb->createMultiFindCommand($tables, $criterias);
    $reader = $command->query();

    // processing result
    $result = array();
    foreach ($queries as $_query) {
      $matches = $reader->readAll();
      $reader->nextResult();
      $metaInfo = $reader->readAll();
      $reader->nextResult();

      $result[] = $this->createResult($matches, $metaInfo);
    }

    return $result;
  }

  /**
   *
   *
   * @param CDbDataReader $matches
   * @param CDbDataReader $metaInfo
   *
   * @return ESphinxResult
   */
  private function createResult($matches, $metaInfo)
  {
    $meta = array();
    foreach ($metaInfo as $item) {
      switch ($item['Variable_name']) {
        case 'total' :
        case 'total_found' :
          $meta[$item['Variable_name']] = $item['Value'];
      }
    }
    $meta['matches'] = $matches->readAll();

    return new ESphinxResult($meta);
  }


  /**
   * @param  $query
   *
   * @return ESphinxQlCriteria
   */
  private function createDbCriteria(ESphinxQuery $query)
  {
    $criteria = new ESphinxQlCriteria;

    // create select
    $criteria->select = $query->getCriteria()->select.', WEIGHT() AS `weight`';

    // process conditions
    $queryCriteria = $query->getCriteria();

    if (strlen($query->getText())) {
      $criteria->addCondition('MATCH(' . ESphinxQlCriteria::PARAM_PREFIX . ESphinxQlCriteria::$paramCount . ')');
      $criteria->params[ESphinxQlCriteria::PARAM_PREFIX . ESphinxQlCriteria::$paramCount++] = $query->getText();
    }

    $this->applyIdLimits($criteria, $queryCriteria);

    $this->applyFilters($queryCriteria->getFilters(), $criteria);
    $this->applyRanges($queryCriteria->getRangeFilters(), $criteria);

    $this->applyGroup($criteria, $queryCriteria);
    $this->applyOrder($criteria, $queryCriteria);

    $this->applyOptions($criteria, $queryCriteria);

    // limit
    $criteria->limit = $queryCriteria->limit;
    $criteria->offset = $queryCriteria->offset;

    return $criteria;
  }

  private function applyIdLimits(ESphinxQlCriteria $criteria, ESphinxSearchCriteria $queryCriteria)
  {
    if ($maxId = $queryCriteria->getMaxId()) {
      $criteria->addCondition('id <= ' . ESphinxQlCriteria::PARAM_PREFIX . ESphinxQlCriteria::$paramCount);
      $criteria->params[ESphinxQlCriteria::PARAM_PREFIX . ESphinxQlCriteria::$paramCount++] = $maxId;
    }
    if ($minId = $queryCriteria->getMinId()) {
      $criteria->addCondition('id >= ' . ESphinxQlCriteria::PARAM_PREFIX . ESphinxQlCriteria::$paramCount);
      $criteria->params[ESphinxQlCriteria::PARAM_PREFIX . ESphinxQlCriteria::$paramCount++] = $minId;
    }
  }

  private function applyGroup(ESphinxQlCriteria $criteria, ESphinxSearchCriteria $queryCriteria)
  {
    if (!$queryCriteria->groupBy) {
      return;
    }

    switch ($queryCriteria->groupByFunc) {
      case ESphinxGroup::BY_ATTR:
        $criteria->group = $queryCriteria->groupBy;
        break;
      case ESphinxGroup::BY_DAY:
        $criteria->group = 'DAY(' . $queryCriteria->groupBy . ')';
        break;
      case ESphinxGroup::BY_WEEK:
        $criteria->group = 'WEEK(' . $queryCriteria->groupBy . ')';
        break;
      case ESphinxGroup::BY_MONTH:
        $criteria->group = 'MONTH(' . $queryCriteria->groupBy . ')';
        break;
      case ESphinxGroup::BY_YEAR:
        $criteria->group = 'YEAR(' . $queryCriteria->groupBy . ')';
        break;
      default:
        throw new ESphinxException('Unknow group function');
    }

    if ($queryCriteria->groupDistinct) {
      $criteria->select .= ', COUNT(DISTINCT ' . $queryCriteria->groupDistinct . ') AS @distinct';
    }

    $criteria->order = $queryCriteria->groupBySort;
  }

  private function applyOrder(ESphinxQlCriteria $criteria, ESphinxSearchCriteria $queryCriteria)
  {
    $order = null;
    if ($queryCriteria->sortMode == ESphinxSort::EXTENDED) {
      if ($orderArray = $queryCriteria->getOrders()) {
        $order = $this->implodeKV($orderArray);
      }
    } else if ($queryCriteria->getSortBy()) {
      $order = $queryCriteria->getSortBy() . ' ';
      switch ($queryCriteria->sortMode) {
        case ESphinxSort::ATTR_ASC:
          $order .= 'ASC';
          break;
        case ESphinxSort::ATTR_DESC:
          $order .= 'DESC';
          break;
        case ESphinxSort::RELEVANCE:
          $order = '@weight DESC';
          break;
        default:
          throw new ESphinxException('Not implemented for Sphinx Ql connection');
      }
    }

    if ($order) {
      if ($queryCriteria->groupBy) {
        $criteria->withinGroupOrder = $order;
      } else {
        $criteria->order = $order;
      }
    }
  }

  private function applyOptions(ESphinxQlCriteria $criteria, ESphinxSearchCriteria $queryCriteria)
  {
    $options = array();

    if ($queryCriteria->maxMatches !== null) {
      $options['max_matches'] = $queryCriteria->maxMatches;
    }

    if ($queryCriteria->cutOff !== null) {
      $options['cutoff'] = $queryCriteria->cutOff;
    }

    if ($idxWeights = $queryCriteria->getIndexWeights()) {
      $options['index_weights'] = '(' . $this->implodeKV($idxWeights, '=') . ')';
    }

    if ($fieldsWeights = $queryCriteria->getFieldWeights()) {
      $options['field_weights'] = '(' . $this->implodeKV($fieldsWeights, '=') . ')';
    }

    if ($queryCriteria->comment) {
      $options['comment'] = $queryCriteria->comment;
    }

    if ($queryCriteria->booleanSimplify !== null) {
      $options['boolean_simplify'] = $queryCriteria->booleanSimplify;
    }

    if (($revScan = $queryCriteria->getReverseScan()) !== null) {
      $options['reverse_scan'] = $revScan ? 1 : 0;
    }

    if (($sortMode = $queryCriteria->getSortMethod()) !== null) {
      $options['sort_method'] = $sortMode;
    }

    if ($queryCriteria->globalIdf !== null) {
      $options['global_idf'] = $queryCriteria->globalIdf;
    }

    if (($idf = $queryCriteria->getIdf()) !== null) {
      $options['idf'] = $idf;
    }

    $options['max_query_time'] = $queryCriteria->queryTimeout !== null ? $queryCriteria->queryTimeout : $this->queryTimeout;

    // ranking mode
    if ($queryCriteria->rankingMode) {
      $ranker = ESphinxRank::item($queryCriteria->rankingMode);
      if ($queryCriteria->rankingMode != ESphinxRank::EXPR) {
        $options['ranker'] = $ranker;
      } else {
        $options['ranker'] = $ranker . "('" . $queryCriteria->rankingExpression . "')";
      }
    }

    $criteria->option = $this->implodeKV($options, '=');
  }


  protected function applyFilters(array $conditions, CDbCriteria $criteria)
  {
    foreach ($conditions as $filter) {
      if (!$filter['exclude']) {
        $criteria->addInCondition($filter['attribute'], $filter['value']);
      } else {
        $criteria->addNotInCondition($filter['attribute'], $filter['value']);
      }
    }
  }

  protected function applyRanges(array $ranges, ESphinxQlCriteria $criteria)
  {
    foreach ($ranges as $rangeFilter) {
      $attr = $rangeFilter['attribute'];
      if ($rangeFilter['exclude']) {
        $attr .= ' NOT';
      }
      $criteria->addBetweenCondition($attr, $rangeFilter['min'], $rangeFilter['max']);
    }
  }


  private function implodeKV($array, $separator = ' ')
  {
    $fields = array();
    foreach ($array as $k => $v) {
      $fields[] = $k . $separator . $v;
    }

    return implode(', ', $fields);
  }
}