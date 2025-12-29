<?php

namespace RealTimePHP\Server;

class Connection
{
    private $conn;
    private $id;
    private $data = [];

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->id = uniqid('client_');
    }

    public function send($data): void
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }
        
        $this->conn->send($data);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getResourceId(): int
    {
        return $this->conn->resourceId;
    }

    public function setData(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function getData(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function getAllData(): array
    {
        return $this->data;
    }

    public function close(): void
    {
        $this->conn->close();
    }
}