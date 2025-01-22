<?php

namespace Pluk77\SymfonySphinxBundle\Sphinx;

use Pluk77\SymfonySphinxBundle\Logger\SphinxLogger;
use PDO;
use Pluk77\SymfonySphinxBundle\Throttler\ThrottlerFabricInterface;

/**
 * Class Manager
 *
 * @package Pluk77\SymfonySphinxBundle\Sphinx
 */
class Manager
{
    /**
     * @var SphinxLogger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var string
     */
    protected $port;

    /**
     * @var ThrottlerFabricInterface|null
     */
    protected $throttlerFabric;

    /**
     * @var PDO
     */
    protected $connection;

    /**
     * Connection constructor.
     *
     * @param SphinxLogger $logger
     * @param string       $host
     * @param string       $port
     */
    public function __construct(SphinxLogger $logger, string $host, string $port, ?ThrottlerFabricInterface $throttlerFabric = null)
    {
        $this->logger = $logger;
        $this->host = $host;
        $this->port = $port;
        $this->throttlerFabric = $throttlerFabric;
    }

    /**
     * Returns an established connection to Sphinx server.
     *
     * @return PDO
     */
    protected function getConnection(): PDO
    {
        if (is_null($this->connection)) {
            $this->connection = new PDO(sprintf('mysql:host=%s;port=%d', $this->host, $this->port));

            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->connection;
    }

    /**
     * Creates a new query.
     *
     * @return Query
     */
    public function createQuery(): Query
    {
        return new Query($this->getConnection(), $this->logger, null, $this->throttlerFabric);
    }

    /**
     * Closes the current connection.
     */
    public function closeConnection()
    {
        $this->connection = null;
    }
}
