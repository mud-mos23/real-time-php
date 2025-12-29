<?php

namespace RealTimePHP\Events;

abstract class Event
{
    protected $name;
    protected $data;
    protected $timestamp;
    protected $broadcast = true;
    protected $rooms = [];
    protected $except = [];

    public function __construct(string $name, array $data = [])
    {
        $this->name = $name;
        $this->data = $data;
        $this->timestamp = microtime(true);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function addData(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function shouldBroadcast(): bool
    {
        return $this->broadcast;
    }

    public function setBroadcast(bool $broadcast): self
    {
        $this->broadcast = $broadcast;
        return $this;
    }

    public function getRooms(): array
    {
        return $this->rooms;
    }

    public function setRooms(array $rooms): self
    {
        $this->rooms = $rooms;
        return $this;
    }

    public function addRoom(string $room): self
    {
        if (!in_array($room, $this->rooms)) {
            $this->rooms[] = $room;
        }
        return $this;
    }

    public function getExcept(): array
    {
        return $this->except;
    }

    public function setExcept(array $except): self
    {
        $this->except = $except;
        return $this;
    }

    public function addException(string $clientId): self
    {
        if (!in_array($clientId, $this->except)) {
            $this->except[] = $clientId;
        }
        return $this;
    }

    public function toArray(): array
    {
        return [
            'event' => $this->name,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
            'meta' => [
                'broadcast' => $this->broadcast,
                'rooms' => $this->rooms,
                'except' => $this->except
            ]
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public static function create(string $name, array $data = []): self
    {
        return new static($name, $data);
    }
}