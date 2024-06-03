<?php

namespace Pluk77\SymfonySphinxBundle\Sphinx;

use Doctrine\ORM\QueryBuilder;
use Pluk77\SymfonySphinxBundle\Logger\SphinxLogger;
use PDO;
use PDOStatement;

/**
 * Class Query
 *
 * @package Pluk77\SymfonySphinxBundle\Sphinx
 */
class Query
{
    protected const CONDITION_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'IN', 'NOT IN', 'BETWEEN'];

    const META_STATEMENT = 'SHOW META';

    /**
     * A query object is in CLEAN state when it has NO unparsed/unprocessed DQL parts.
     */
    const STATE_CLEAN  = 1;

    /**
     * A query object is in state DIRTY when it has DQL parts that have not yet been
     * parsed/processed. This is automatically defined as DIRTY when addDqlQueryPart
     * is called.
     */
    const STATE_DIRTY = 2;

    /**
     * The current state of this query.
     *
     * @var integer
     */
    private $_state = self::STATE_DIRTY;

    /**
     * @var PDO
     */
    protected $connection;

    /**
     * @var SphinxLogger
     */
    protected $logger;

    /**
     * @var string|null
     */
    protected $query;

    /**
     * @var QueryBuilder|null
     */
    protected $queryBuilder;

    /**
     * @var string|null
     */
    protected $queryBuilderAlias;

    /**
     * @var string|null
     */
    protected $queryBuilderColumn;

    /**
     * @var array
     */
    protected $select = [];

    /**
     * @var array
     */
    protected $from = [];

    /**
     * @var array
     */
    protected $where = [];

    /**
     * @var array
     */
    protected $match = [];

    /**
     * @var array
     */
    protected $rawMatch = [];

    /**
     * @var array
     */
    protected $groupBy = [];

    /**
     * @var array
     */
    protected $withinGroupOrderBy = [];

    /**
     * @var array
     */
    protected $having = [];

    /**
     * @var array
     */
    protected $orderBy = [];

    /**
     * @var integer
     */
    protected $offset = 0;

    /**
     * @var integer
     */
    protected $limit = 20;

    /**
     * @var array
     */
    protected $option = [];

    /**
     * @var array|null
     */
    protected $results;

    /**
     * @var integer
     */
    protected $numRows;

    /**
     * @var array|null
     */
    protected $metadata;

    /**
     * Query constructor.
     *
     * @param PDO          $connection
     * @param SphinxLogger $logger
     * @param string       $query
     */
    public function __construct(PDO $connection, SphinxLogger $logger, string $query = null)
    {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->query = $query;
    }

    /**
     * Use QueryBuilder.
     *
     * @param QueryBuilder $queryBuilder
     * @param string       $alias
     * @param string       $column
     *
     * @return Query
     */
    public function useQueryBuilder(QueryBuilder $queryBuilder, string $alias, string $column = 'id')
    {
        $this->_state = self::STATE_DIRTY;
        $this->queryBuilder = clone $queryBuilder;
        $this->queryBuilderAlias = $alias;
        $this->queryBuilderColumn = $column;

        if (array_search('id', $this->select) === false && array_search('*', $this->select) === false) {
            $this->select('id');
        }

        return $this;
    }

    /**
     * Sets query.
     *
     * @param string $query
     *
     * @return Query
     */
    public function setQuery(string $query)
    {
        $this->_state = self::STATE_DIRTY;
        $this->query = $query;

        return $this;
    }

    /**
     * Add SELECT clause.
     *
     * @param array ...$columns
     *
     * @return Query
     */
    public function select(...$columns)
    {
        $this->_state = self::STATE_DIRTY;
        $this->select = array_merge($this->select, $columns ?: ['*']);

        return $this;
    }

    /**
     * Add single column into SELECT clause.
     *
     * @param string $column
     *
     * @return Query
     */
    public function addSelectIfNotExists($column)
    {
        if (!in_array($column, $this->select, true)) {
            $this->_state = self::STATE_DIRTY;
            $this->select[] = $column;
        }

        return $this;
    }

    /**
     * Add FROM clause.
     *
     * @param array ...$indexes
     *
     * @return Query
     */
    public function from(...$indexes)
    {
        $this->_state = self::STATE_DIRTY;
        $this->from = array_merge($this->from, $indexes);

        return $this;
    }

    /**
     * Creates a new condition for WHERE or HAVING clause.
     *
     * @param string $column
     * @param mixed  $operator
     * @param mixed  $value
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    protected function createCondition(?string $column, $operator, $value = null, string $rawExpression = null)
    {
        $this->_state = self::STATE_DIRTY;

        if ($rawExpression) {
            $column = null;
            $operator = null;
            $value = null;
        } else {
            if (is_null($value)) {
                $value = $operator;
                $operator = is_array($value) ? 'IN' : '=';
            }

            $operator = strtoupper($operator);

            if (!in_array($operator, static::CONDITION_OPERATORS)) {
                throw new \InvalidArgumentException(sprintf('Invalid operator %s', $operator));
            }

            if ($operator === 'BETWEEN' && (!is_array($value) || count($value) != 2)) {
                throw new \InvalidArgumentException('BETWEEN operator expects an array with exactly 2 values');
            }

            if (in_array($operator, ['IN', 'NOT IN']) && !is_array($value)) {
                throw new \InvalidArgumentException('IN operator expects an array with values');
            }
        }

        return [$column, $operator, $value, $rawExpression];
    }

    /**
     * Add WHERE clause.
     *
     * @param string $column
     * @param mixed  $operator
     * @param mixed  $value
     *
     * @return Query
     */
    public function andWhere(string $column, $operator, $value = null)
    {
        $this->where[] = $this->createCondition($column, $operator, $value);

        return $this;
    }

    /**
     * Add WHERE raw expression.
     *
     * @param string $expression
     *
     * @return Query
     */
    public function andRawWhere(string $expression)
    {
        $this->where[] = $this->createCondition(null, null, null, $expression);

        return $this;
    }

    /**
     * Add AND MATCH clause without pre-processing.
     *
     * @param string $match
     *
     * @return Query
     */
    public function andRawMatch(string $match): Query
    {
        $this->_state = self::STATE_DIRTY;
        $this->rawMatch[] = [$match, '&'];

        return $this;
    }

    /**
     * Add OR MATCH clause without pre-processing.
     *
     * @param string $match
     *
     * @return Query
     */
    public function orRawMatch(string $match): Query
    {
        $this->_state = self::STATE_DIRTY;
        $this->rawMatch[] = [$match, '|'];

        return $this;
    }

    /**
     * MATCH clause without pre-processing
     *
     * @param string $match
     * @return Query
     */
    public function rawMatch(string $match): Query
    {
        $this->match = [];

        return $this->andRawMatch($match);
    }

    /**
     * WHERE clause.
     *
     * @param string $column
     * @param mixed  $operator
     * @param mixed  $value
     *
     * @return Query
     */
    public function where(string $column, $operator, $value = null)
    {
        $this->where = [];

        return $this->andWhere($column, $operator, $value);
    }

    /**
     * @param string[] $orComponents
     *
     * @return Query
     */
    public function orWhere(array $orComponents)
    {
        static $index = 0;

        if ($orComponents) {
            $alias = 'orX' . $index++;

            foreach ($orComponents as $key => $component) {
                if (strpos($component, ' AND ')) {
                    $orComponents[$key] = "($component)";
                }
            }

            $select = count($orComponents) > 1
                ? '(' . implode(' OR ', $orComponents) . ')'
                : array_pop($orComponents)
            ;

            $this->select($select . ' AS ' . $alias);
            $this->andWhere($alias, '=', 1);
        }

        return $this;
    }

    /**
     * Add MATCH clause.
     *
     * @param string|string[] $column
     * @param string          $value
     * @param boolean         $safe
     *
     * @return Query
     */
    public function andMatch($column, $value, bool $safe = false)
    {
        $this->_state = self::STATE_DIRTY;
        $this->match[] = [$column, $value, $safe];

        return $this;
    }

    /**
     * MATCH clause.
     *
     * @param string|string[] $column
     * @param string          $value
     * @param boolean         $safe
     *
     * @return Query
     */
    public function match($column, $value, bool $safe = false)
    {
        $this->match = [];

        return $this->andMatch($column, $value, $safe);
    }

    /**
     * and GROUP BY column.
     *
     * @param string $column
     *
     * @return Query
     */
    public function andGroupBy(string $column)
    {
        $this->_state = self::STATE_DIRTY;
        $this->groupBy[] = $column;

        return $this;
    }

    /**
     * GROUP BY column.
     *
     * @param string $column
     *
     * @return Query
     */
    public function groupBy(string $column)
    {
        $this->groupBy = [];

        return $this->andGroupBy($column);
    }

    /**
     * and Within group order by column.
     *
     * @param string      $column
     * @param string|null $direction
     *
     * @return Query
     */
    public function andWithinGroupOrderBy(string $column, $direction = null)
    {
        $this->_state = self::STATE_DIRTY;
        $this->withinGroupOrderBy[] = [
            $column,
            (!is_null($direction) && strtoupper($direction) === 'DESC') ? 'DESC' : 'ASC'
        ];

        return $this;
    }

    /**
     * Within group order by column.
     *
     * @param string      $column
     * @param string|null $direction
     *
     * @return Query
     */
    public function withinGroupOrderBy(string $column, $direction = null)
    {
        $this->withinGroupOrderBy = [];

        return $this->andWithinGroupOrderBy($column, $direction);
    }

    /**
     * Add HAVING clause.
     *
     * @param string $column
     * @param mixed  $operator
     * @param mixed  $value
     *
     * @return Query
     */
    public function andHaving(string $column, $operator, $value = null)
    {
        $this->having[] = $this->createCondition($column, $operator, $value);

        return $this;
    }

    /**
     * HAVING clause.
     *
     * @param string $column
     * @param mixed  $operator
     * @param mixed  $value
     *
     * @return Query
     */
    public function having(string $column, $operator, $value = null)
    {
        $this->having = [];

        return $this->andHaving($column, $operator, $value);
    }

    /**
     * And ORDER BY column.
     *
     * @param string      $column
     * @param string|null $direction
     *
     * @return Query
     */
    public function andOrderBy(string $column, $direction = null)
    {
        $this->_state = self::STATE_DIRTY;
        $this->orderBy[] = [
            $column,
            (!is_null($direction) && strtoupper($direction) === 'DESC') ? 'DESC' : 'ASC'
        ];

        return $this;
    }

    /**
     * ORDER BY column.
     *
     * @param string      $column
     * @param string|null $direction
     *
     * @return Query
     */
    public function orderBy(string $column, $direction = null)
    {
        $this->orderBy = [];

        return $this->andOrderBy($column, $direction);
    }

    /**
     * Sets the position of the first result to retrieve (the "offset").
     *
     * @param integer $firstResult The first result to return.
     *
     * @return Query This query object.
     */
    public function setFirstResult($firstResult)
    {
        $this->_state = self::STATE_DIRTY;
        $this->offset = $firstResult;

        return $this;
    }

    /**
     * Sets the maximum number of results to retrieve (the "limit").
     *
     * @param integer|null $maxResults
     *
     * @return Query This query object.
     */
    public function setMaxResults($maxResults)
    {
        $this->_state = self::STATE_DIRTY;
        $this->limit = $maxResults;

        return $this;
    }

    /**
     * Add option.
     *
     * @param string $name
     * @param string $value
     *
     * @return Query
     */
    public function addOption(string $name, string $value)
    {
        $this->_state = self::STATE_DIRTY;
        $this->option[] = [$name, $value];

        return $this;
    }

    /**
     * Set option.
     *
     * @param string $name
     * @param string $value
     *
     * @return Query
     */
    public function setOption(string $name, string $value)
    {
        $this->option = [];
        $this->addOption($name, $value);

        return $this;
    }

    /**
     * Quote value.
     *
     * @param mixed  $value
     * @param int|null $type
     *
     * @return string|integer|boolean
     */
    public function quoteValue($value, $type = null)
    {
        return is_int($value) || is_bool($value)
            ? (int) $value
            : (is_int($type) ? $this->connection->quote($value, $type) : $this->connection->quote($value))
        ;
    }

    /**
     * Quote match.
     *
     * @param string  $value
     * @param boolean $isText
     *
     * @return string
     */
    public function quoteMatch(string $value, $isText = false): string
    {
        return addcslashes($value, $isText ? '\()!@~&/^$=<>' : '\()|-!@~"&/^$=<>');
    }

    /**
     * Returns a plain SQL query.
     *
     * @return string
     */
    public function getSQL(): string
    {
        if($this->_state === self::STATE_CLEAN && $this->query) {
            return $this->query;
        }

        $this->query = $this->buildQuery();

        return $this->query;
    }

    /**
     * Build a query.
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    protected function buildQuery(): string
    {
        if (!$this->select) {
            throw new \InvalidArgumentException('You should add at least one SELECT clause');
        }

        if (!$this->from) {
            throw new \InvalidArgumentException('You should add at least one FROM clause');
        }

        $clauses = [];

        $clauses[] = 'SELECT ' . implode(', ', $this->select);

        $clauses[] = 'FROM ' . implode(', ', $this->from);

        if ($this->where) {
            $clauses[] = 'WHERE ' . $this->buildCondition($this->where);
        }

        if ($this->match || $this->rawMatch) {
            $clauses[] = ($this->where ? 'AND ' : 'WHERE ') . $this->buildMatch($this->match, $this->rawMatch);
        }

        if ($this->groupBy) {
            $clauses[] = 'GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->withinGroupOrderBy) {
            $clauses[] = 'WITHIN GROUP ORDER BY ' . $this->buildOrder($this->withinGroupOrderBy);
        }

        if ($this->having) {
            $clauses[] = 'HAVING ' . $this->buildCondition($this->having);
        }

        if ($this->orderBy) {
            $clauses[] = 'ORDER BY ' . $this->buildOrder($this->orderBy);
        }

        $clauses[] = sprintf('LIMIT %d, %d', $this->offset, $this->limit);

        if ($this->option) {
            $clauses[] = 'OPTION ' . $this->buildOption($this->option);
        }

        return trim(implode(' ', $clauses));
    }

    /**
     * Builds condition clause.
     *
     * @param array $conditions
     *
     * @return string
     */
    protected function buildCondition(array $conditions): string
    {
        $pieces = [];

        foreach ($conditions as [$column, $operator, $value, $rawExpression]) {
            if ($rawExpression) {
                $pieces[] = $rawExpression;
            } else {
                if ($operator === 'BETWEEN') {
                    $value = $this->quoteValue($value[0]) . ' AND ' . $this->quoteValue($value[1]);
                } elseif (is_array($value)) {
                    $value = '(' . implode(', ', array_map([$this, 'quoteValue'], $value)) . ')';
                } else {
                    $value = $this->quoteValue($value);
                }

                $pieces[] = $column . ' ' . $operator . ' ' . $value;
            }
        }

        return implode(' AND ', $pieces);
    }

    /**
     * Builds match clause.
     *
     * @param array $matches
     *
     * @return string
     */
    protected function buildMatch(array $matches, array $rawMatches = []): string
    {
        $pieces = [];

        foreach ($matches as [$column, $value, $safe]) {
            if (is_array($column)) {
                $column = '(' . implode(',', array_map([$this, 'quoteMatch'], $column)) . ')';
            } else {
                $column = $this->quoteMatch($column);
            }

            if (!$safe) {
                $value = $this->quoteMatch($value);
            }

            $pieces[] = sprintf('@%s %s', $column, $value);
        }

        foreach ($rawMatches as [$match, $andOr]) {
            if (count($pieces)) {
                $pieces[] = sprintf('%s %s', $andOr, $match);
            } else {
                $pieces[] = sprintf('%s', $match, true);
            }
        }

        return sprintf('MATCH(%s)', $this->quoteValue(implode(' ', $pieces)));
    }

    /**
     * Builds order clause.
     *
     * @param array $orders
     *
     * @return string
     */
    protected function buildOrder(array $orders): string
    {
        $pieces = [];

        foreach ($orders as [$column, $direction]) {
            $pieces[] = $column . ' ' . $direction;
        }

        return implode(', ', $pieces);
    }

    /**
     * Builds option clause.
     *
     * @param array $options
     *
     * @return string
     */
    protected function buildOption(array $options): string
    {
        $pieces = [];

        foreach ($options as [$name, $value]) {
            $pieces[] = $name . ' = ' . $value;
        }

        return implode(', ', $pieces);
    }

    /**
     * Returns an array of results.
     *
     * @return array
     */
    public function getResults(): array
    {
        if($this->_state === self::STATE_CLEAN) {
            return $this->results;
        }

        $this->execute();

        return $this->results;
    }

    /**
     * Executes query and returns number of affected rows.
     *
     * @return integer
     */
    public function execute()
    {
        if($this->_state === self::STATE_CLEAN) {
            return $this->numRows;
        }

        $startTime = microtime(true);

        $this->results = [];
        $this->numRows = 0;

        $stmt = $this->createStatement($this->getSQL());

        if ($stmt->execute()) {
            if ($this->select) {
                $this->results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $this->numRows = $stmt->rowCount();

            $stmt->closeCursor();

            $this->_state = self::STATE_CLEAN;

            $this->populateMetadata();
        }

        $endTime = microtime(true);

        $this->logger->logQuery($this->getSQL(), $this->getNumRows(), $endTime - $startTime);
        $this->logger->logQuery(self::META_STATEMENT, $this->getTotalFound(), $this->getTime());

        if ($this->queryBuilder) {
            $this->results = $this->applyQueryBuilder($this->results);
        }

        return $this->numRows;
    }

    /**
     * Returns number of affected rows.
     *
     * @return integer
     *
     * @throws \BadMethodCallException
     */
    public function getNumRows(): int
    {
        if ($this->_state === self::STATE_DIRTY) {
            throw new \BadMethodCallException('You must execute query before getting number of affected rows');
        }

        return $this->numRows;
    }

    /**
     * Creates a new PDO statement.
     *
     * @param string $query
     *
     * @return PDOStatement
     */
    protected function createStatement(string $query): PDOStatement
    {
        return $this->connection->prepare($query);
    }

    /**
     * Apply QueryBuilder
     *
     * @param array $results
     *
     * @return array
     */
    protected function applyQueryBuilder(array $results): array
    {
        if (count($results) == 0) {
            return [];
        }

        $ids = array_map('intval', array_column($results, 'id'));
        $results = [];

        $paramName = sprintf('%s%sids', $this->queryBuilderAlias, $this->queryBuilderColumn);

        $this->queryBuilder
            ->andWhere(sprintf('%s.%s IN (:%s)', $this->queryBuilderAlias, $this->queryBuilderColumn, $paramName))
            ->setParameter($paramName, $ids)
            ->setFirstResult(null)
            ->setMaxResults(null);

        if ($this->orderBy) {
            $this->queryBuilder->resetDQLPart('orderBy');
        }

        $entities = $this->queryBuilder->getQuery()->getResult();

        foreach ($entities as $entity) {
            $idGetter = 'get' . ucfirst($this->queryBuilderColumn);
            $id = $entity->$idGetter();
            $position = array_search($id, $ids);
            $results[$position] = $entity;
        }

        ksort($results);

        return array_values($results);
    }

    private function populateMetadata() {

        $this->metadata = [];
        $stmt = $this->createStatement(self::META_STATEMENT);

        if ($stmt->execute()) {
            $this->metadata = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stmt->closeCursor();
        }
    }

    /**
     * Returns query result metadata.
     *
     * @return array
     *
     * @throws \BadMethodCallException
     */
    public function getMetadata(): array
    {
        if (!is_null($this->metadata)) {
            return $this->metadata;
        }

        if (is_null($this->results)) {
            throw new \BadMethodCallException('You can get metadata only after executing query');
        }

        return [];
    }

    /**
     * Returns metadata value.
     *
     * @param string $name
     * @param mixed  $defaultValue
     *
     * @return mixed|null
     */
    public function getMetadataValue(string $name, $defaultValue = null)
    {
        $metadata = $this->getMetadata();

        return array_key_exists($name, $metadata) ? $metadata[$name] : $defaultValue;
    }

    /**
     * Returns total count of found rows.
     *
     * @return integer
     */
    public function getTotalFound(): int
    {
        return (int) $this->getMetadataValue('total_found', 0);
    }

    /**
     * Returns executing time.
     *
     * @return float
     */
    public function getTime(): float
    {
        return (float) $this->getMetadataValue('time', 0);
    }

    public function clearResult() {
        $this->_state = self::STATE_DIRTY;
        $this->query = null;
        $this->result = null;
        $this->metadata = null;
        $this->numRows = null;
    }

    /**
     * Clones the current object.
     */
    public function __clone()
    {
        $this->clearResult();

        if ($this->queryBuilder) {
            $this->queryBuilder = clone $this->queryBuilder;
        }
    }

    /**
     * Sleep.
     *
     * @return array
     */
    public function __sleep()
    {
        return array_diff(array_keys(get_object_vars($this)), ['connection', 'logger', 'queryBuilder', 'results']);
    }

    /**
     * Returns a string representation of the object.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getSQL();
    }
}
