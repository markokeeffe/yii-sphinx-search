<?php
/**
 * Author:  Mark O'Keeffe

 * Date:    19/11/13
 *
 * [Yii Workbench] BuildSuggestions.php
 */

class BuildSuggestionsCommand extends CConsoleCommand
{

  /**
   * The search class
   *
   * @var \MOK\SphinxSearch\Search
   */
  protected $search;

  public function init()
  {
    $this->search = Yii::app()->Search;
  }

  /**
   * Save search term suggestions to a database table from STDin:
   *
   * type "C:\sphinx\wordlists\english-freqs.txt" | php "C:\www\Sites\Yii Workbench\console\yiic" buildss | mysql -h 192.168.0.2 -u test_user -ptest yii_workbench
   *
   * cat /home/vadmin/sphinx/english-freqs.txt | php /home/vadmin/v2/console/yiic buildss | mysql -u root -p{pass} freestuffworld_v2
   *
   * indextool --config /home/vadmin/sphinx/sphinx.conf --dumpdict fsw_content | php /home/vadmin/v2/console/yiic buildss "0,2" "," 4 | mysql -u root -p{pass} freestuffworld_v2
   */
  public function run($args)
  {
    $indexes = (isset($args[0]) ? $args[0] : '0,2');
    $delimiter = (isset($args[1]) ? $args[1] : "\t");
    $lineCount = (isset($args[2]) ? $args[2] : 3);
    $in = fopen("php://stdin", "r");
    $out = fopen("php://stdout", "w+");
    $this->BuildDictionarySQL($out, $in, $indexes, $delimiter, $lineCount);
  }

  /// create SQL dump of the dictionary from Sphinx stopwords file
/// expects open files as parameters
  function BuildDictionarySQL($out, $in, $indexes, $delimiter, $lineCount)
  {
    fwrite($out, "DROP TABLE IF EXISTS suggest;

      CREATE TABLE suggest (
        id			INTEGER PRIMARY KEY AUTO_INCREMENT NOT NULL,
        keyword		VARCHAR(255) NOT NULL,
        trigrams	VARCHAR(255) NOT NULL,
        freq		INTEGER NOT NULL,
        UNIQUE(keyword)
      );
      "
    );

    list($iKeyword, $iFreq) = explode(',', $indexes);

    $n = 0;
    $m = 0;
    while ($line = fgets($in, 1024)) {
      $line = explode($delimiter, trim($line));
      if (count($line) != $lineCount) {
        continue;
      }

      $keyword = $line[$iKeyword];
      $freq = $line[$iFreq];

      $keyword = str_replace(array(
        "'", '*', '_',
      ), array(
        '', '', ' ',
      ), $keyword);

      if ($freq < 10)
        continue;

      $trigrams = $this->search->trigrams($keyword);

      if (!$m) {
        fwrite($out, "INSERT IGNORE INTO suggest VALUES\n( 0, '$keyword', '$trigrams', $freq )");
      } else {
        fwrite($out, ",\n( 0, '$keyword', '$trigrams', $freq )");
      }

      $n++;
      $m++;
      if (($m % 10000) == 0) {
        print ";\n";
        $m = 0;
      }
    }

    if ($m)
      fwrite($out, ";\n");
  }

}
