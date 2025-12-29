<?php

namespace RealTimePHP\Handler;

use RealTimePHP\Server\Connection;

class RoomManager
{
    private $rooms = [];
    private $connectionRooms = [];

    public function addToRoom(Connection $connection, string $room): void
    {
        $connectionId = $connection->getId();
        
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }
        
        if (!in_array($connectionId, $this->rooms[$room])) {
            $this->rooms[$room][] = $connectionId;
        }
        
        if (!isset($this->connectionRooms[$connectionId])) {
            $this->connectionRooms[$connectionId] = [];
        }
        
        if (!in_array($room, $this->connectionRooms[$connectionId])) {
            $this->connectionRooms[$connectionId][] = $room;
        }
    }

    public function removeFromRoom(Connection $connection, string $room): void
    {
        $connectionId = $connection->getId();
        
        if (isset($this->rooms[$room])) {
            $key = array_search($connectionId, $this->rooms[$room]);
            if ($key !== false) {
                unset($this->rooms[$room][$key]);
                $this->rooms[$room] = array_values($this->rooms[$room]);
            }
        }
        
        if (isset($this->connectionRooms[$connectionId])) {
            $key = array_search($room, $this->connectionRooms[$connectionId]);
            if ($key !== false) {
                unset($this->connectionRooms[$connectionId][$key]);
                $this->connectionRooms[$connectionId] = array_values($this->connectionRooms[$connectionId]);
            }
        }
    }

    public function removeConnectionFromAllRooms(Connection $connection): void
    {
        $connectionId = $connection->getId();
        
        if (isset($this->connectionRooms[$connectionId])) {
            foreach ($this->connectionRooms[$connectionId] as $room) {
                if (isset($this->rooms[$room])) {
                    $key = array_search($connectionId, $this->rooms[$room]);
                    if ($key !== false) {
                        unset($this->rooms[$room][$key]);
                        $this->rooms[$room] = array_values($this->rooms[$room]);
                    }
                }
            }
            
            unset($this->connectionRooms[$connectionId]);
        }
    }

    public function getConnectionsInRoom(string $room): array
    {
        if (!isset($this->rooms[$room])) {
            return [];
        }
        
        $connections = [];
        // Note: Vous aurez besoin d'accéder au ConnectionPool pour obtenir les objets Connection
        // Cette méthode devra être adaptée selon votre implémentation
        
        return $connections;
    }

    public function getRoomsForConnection(Connection $connection): array
    {
        $connectionId = $connection->getId();
        return $this->connectionRooms[$connectionId] ?? [];
    }

    public function getAllRooms(): array
    {
        return array_keys($this->rooms);
    }

    public function getRoomCount(string $room): int
    {
        return isset($this->rooms[$room]) ? count($this->rooms[$room]) : 0;
    }

    public function isInRoom(Connection $connection, string $room): bool
    {
        $connectionId = $connection->getId();
        return isset($this->rooms[$room]) && in_array($connectionId, $this->rooms[$room]);
    }
}