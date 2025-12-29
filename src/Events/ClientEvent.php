<?php

namespace RealTimePHP\Events;

use RealTimePHP\Server\Connection;

class ClientEvent extends Event
{
    private $connection;
    private $acknowledged = false;
    private $acknowledgementCallback = null;
    private $requiresAck = false;

    public function __construct(
        string $name, 
        array $data = [], 
        ?Connection $connection = null
    ) {
        parent::__construct($name, $data);
        $this->connection = $connection;
    }

    public static function fromMessage(string $message, ?Connection $connection = null): ?self
    {
        $data = json_decode($message, true);
        
        if (!$data || !isset($data['event'])) {
            return null;
        }

        $event = new self(
            $data['event'],
            $data['data'] ?? [],
            $connection
        );

        if (isset($data['meta'])) {
            if (isset($data['meta']['requiresAck'])) {
                $event->setRequiresAck($data['meta']['requiresAck']);
            }
            if (isset($data['meta']['ackId'])) {
                $event->setAckId($data['meta']['ackId']);
            }
        }

        return $event;
    }

    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    public function setConnection(Connection $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    public function hasConnection(): bool
    {
        return $this->connection !== null;
    }

    public function getClientId(): ?string
    {
        return $this->connection ? $this->connection->getId() : null;
    }

    public function getClientData(string $key = null)
    {
        if (!$this->connection) {
            return null;
        }

        if ($key === null) {
            return $this->connection->getAllData();
        }

        return $this->connection->getData($key);
    }

    public function setClientData(string $key, $value): self
    {
        if ($this->connection) {
            $this->connection->setData($key, $value);
        }
        return $this;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged;
    }

    public function acknowledge(array $responseData = []): void
    {
        $this->acknowledged = true;
        
        if ($this->acknowledgementCallback) {
            call_user_func($this->acknowledgementCallback, $responseData);
        }

        if ($this->connection && $this->requiresAck) {
            $this->connection->send([
                'event' => 'ack',
                'data' => [
                    'event' => $this->name,
                    'response' => $responseData,
                    'timestamp' => microtime(true)
                ]
            ]);
        }
    }

    public function setAcknowledgementCallback(callable $callback): self
    {
        $this->acknowledgementCallback = $callback;
        return $this;
    }

    public function requiresAcknowledgement(): bool
    {
        return $this->requiresAck;
    }

    public function setRequiresAck(bool $requiresAck): self
    {
        $this->requiresAck = $requiresAck;
        return $this;
    }

    public function setAckId(string $ackId): self
    {
        $this->addData('_ackId', $ackId);
        return $this;
    }

    public function getAckId(): ?string
    {
        return $this->data['_ackId'] ?? null;
    }

    public function respond(array $data): void
    {
        if ($this->connection) {
            $this->connection->send([
                'event' => $this->name . '_response',
                'data' => $data
            ]);
        }
    }

    public function broadcastTo(array $rooms = []): self
    {
        $this->setBroadcast(true);
        if (!empty($rooms)) {
            $this->setRooms($rooms);
        }
        return $this;
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        
        $array['meta']['clientId'] = $this->getClientId();
        $array['meta']['requiresAck'] = $this->requiresAck;
        
        if ($this->requiresAck && !isset($array['data']['_ackId'])) {
            $array['data']['_ackId'] = uniqid('ack_');
        }

        return $array;
    }
}