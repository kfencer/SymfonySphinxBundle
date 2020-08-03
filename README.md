SymfonySphinxBundle
=================

Forked from javer/JaverSphinxBundle

This bundle provides integration of [Sphinx](http://sphinxsearch.com) search engine with Symfony5.

Features:
- SphinxQL Query Builder
- Integration with [doctrine/orm](https://packagist.org/packages/doctrine/orm) 
- Symfony Profiler toolbar section with number of executed queries and profiler page with detailed information about executed queries

This fork is not backwards compatible with Javer/JaverSphinxBundle

Requirements
------------

- PHP 7.1+
- pdo_mysql php extension

Installation
------------

Install the bundle using composer:
Add to composer.json
```json
{
    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/knifefencer/SymfonySphinxBundle"
        }
    ]
}
```

```sh
composer require rsemizarov/symfony-sphinx-bundle
```

Configuration
-------------

Add to your ```config/package/symfony_sphinx.yml``` the following options:
```yml
symfony_sphinx:
    host: 127.0.0.1
    port: 9306
```

Full configuration with default values:
```yml
symfony_sphinx:
    host: 127.0.0.1
    port: 9306
```

Usage
-----

Synthetic example of SELECT query which returns an array:
```php
$results = $sphinx->createQuery()
    ->select('id', 'column1', 'column2', 'WEIGHT() as weight')
    ->from('index1', 'index2')
    ->where('column3', 'value1')
    ->andWhere('column4', '>', 4)
    ->andWhere('column5', [5, '6'])
    ->andWhere('column6', 'NOT IN', [7, '8'])
    ->andWhere('column7', 'BETWEEN', [9, 10])
    ->andRawWhere('column8 > 2')
    ->match('column8', 'value2')
    ->andMatch(['column9', 'column10'], 'value3')
    ->groupBy('column11')
    ->andGroupBy('column12')
    ->withinGroupOrderBy('column13', 'desc')
    ->AndWithinGroupOrderBy('column14')
    ->having('weight', '>', 2)
    ->orderBy('column15', 'desc')
    ->andOrderBy('column16')
    ->setFirstResult(5)
    ->setMaxResults(10)
    ->setOption('agent_query_timeout', 10000)
    ->addOption('max_matches', 1000)
    ->addOption('field_weights', '(column9=10, column10=3)')
    ->getResults();
```

Entities fetched from the database using Doctrine ORM QueryBuilder by searching phrase in them using Sphinx:
```php
$queryBuilder = $this->getRepository(Patient::class)
	->createQueryBuilder('p')
	->select();

$query = $sphinx->createQuery()
	->select('id')
	->from('patient')
	->match(['last_name','first_name'], 'jo*')
	->setOption('field_weights', '(last_name=10, first_name=5)')
	->useQueryBuilder($queryBuilder, 'p', 'id');
	
$results = $query->getResults();

```
