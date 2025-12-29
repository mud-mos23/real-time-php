<?php

namespace RealTimePHP\Server;

class ConnectionPool
{
    private $connections = [];

    public function add(Connection $connection): void
    {
        $this->connections[$connection->getId()] = $connection;
    }

    public function remove(Connection $connection): void
    {
        unset($this->connections[$connection->getId()]);
    }

    public function findById(string $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    public function findByResourceId(int $resourceId): ?Connection
    {
        foreach ($this->connections as $connection) {
            if ($connection->getResourceId() === $resourceId) {
                return $connection;
            }
        }
        
        return null;
    }

    public function getAll(): array
    {
        return $this->connections;
    }

    public function count(): int
    {
        return count($this->connections);
    }
}