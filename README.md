yii-sphinx-search
=================

An integration of <a href="http://sphinxsearch.com/" target="_blank">Sphinx Search</a> with configurable search form, results view, sorting and data provider.

## Installation ##

### Install Sphinx Server ###

This is probably best installed on a linux machine available to all developers. Quick install:

```bash

  # Install dependencies
  sudo yum install postgresql-libs unixODBC
  # Download the sphinx RPM
  wget http://sphinxsearch.com/files/sphinx-2.2.1-1.rhel6.x86_64.rpm
  # Install
  sudo rpm -Uhv sphinx-2.2.1-1.rhel6.x86_64.rpm
  # Create sphinx directories
  mkdir ~/sphinx ~/sphinx/{indexes,dict,log,pid}
  # Create the config file
  vi ~/sphinx/sphinx.conf
  # Run the indexer
  sudo indexer --config ~/sphinx/sphinx.conf --all
  # Start the search service
  sudo searchd --config ~/sphinx/sphinx.conf

```

### Application Configuration ###

Add the following to the `require` object in your `composer.json`:

```json
  "require": {
    ...
    "markokeeffe/yii-sphinx-search": "dev-master"
  },
```

Update composer:

```bash
$ composer update
```

Add the 'sphinx' and 'Search' application components into your config:

```php

  // Sphinx Search Engine
  'sphinx' => array(
    'class' => 'vendor.markokeeffe.yii-sphinx-search.src.sphinx-yii.ESphinxMysqlConnection',
    'server' => array('127.0.0.1', 9306),
    'connectionTimeout' => 3, // optional, default 0 - no limit
    'queryTimeout'      => 5, // optional, default 0 - no limit
  ),

  'Search' => array(
    'class' => '\Veneficus\SphinxSearch\Search',
    'maxMatches' => 3000,
    'indexes' => 'fsw_rt',
  ),

```

To use real time indexes, you will need to add a database connection for the Sphinx server:

```php

    'dbsphinx' => array(
      'class' => 'system.db.CDbConnection',
      'connectionString' => 'mysql:host=127.0.0.1;port=9306;',
      'charset' => 'utf8',
      'username' => 'user',
      'password' => 'pass',
    ),

```

## Real Time Index Behaviour ##

Content is added to a real time index by performing an `INSERT` query on the index on its creation. This is achieved by adding the `RTSphinxBehavior` to the model you wish to index.

Add the behaviour to your model class:

```php

  /**
   * Add the 'RT Sphinx' behaviour to this model
   *
   * @return array
   */
  public function behaviors()
  {
    return array(
      'RTSphinxBehavior' => array(
        'class'             => 'vendor.markokeeffe.yii-sphinx-search.src.behaviors.RTSphinxBehavior',
        'getDataMethod'     => array($this, 'getIndexData'),
        'sphinxIndex'       => 'fsw_rt', // The index name
        'sphinxDbComponent' => 'dbsphinx', // The database connection for the sphinx server
        'allowCallbacks'    => true,
        'disabled'          => !Yii::app()->params['sphinxEnabled'], // on or off
      ),
    );
  }

```

Add a `getIndexData()` method to the model to specify the attributes to be indexed:

```php

  /**
   * Get an array of data for this model to add to the Sphinx RT index
   *
   * @return array
   */
  public function getIndexData()
  {
    return array(
      'id'            => (integer)$this->id,
      'title'         => $this->title,
      'description'   => $this->description,
      'category_id'   => $this->getDefaultCategoryId(),
      'content_id'    => (integer)$this->id,
      'type_id'       => (integer)$this->type_id,
      'is_admin_page' => (integer)$this->isAdminPage,
      'is_aff_link'   => (integer)$this->isAffLink,
      'added_time'    => strtotime($this->added_time),
      'updated_time'  => strtotime($this->updated_time),
    );
  }

```
