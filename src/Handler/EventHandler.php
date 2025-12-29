<?php

namespace RealTimePHP\Handler;

use RealTimePHP\Events\Event;
use RealTimePHP\Events\ClientEvent;
use RealTimePHP\Server\Connection;
use RealTimePHP\Server\ConnectionPool;

class EventHandler
{
    private $handlers = [];
    private $middlewares = [];
    private $connectionPool;
    private $roomManager;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
        $this->roomManager = new RoomManager();
    }

    public function register(string $eventName, callable $handler): self
    {
        if (!isset($this->handlers[$eventName])) {
            $this->handlers[$eventName] = [];
        }

        $this->handlers[$eventName][] = $handler;
        return $this;
    }

    public function handle(Event $event): void
    {
        // Appliquer les middlewares avant
        foreach ($this->middlewares as $middleware) {
            if ($middleware($event) === false) {
                return; // Middleware a bloqué l'événement
            }
        }

        // Gestion des événements client
        if ($event instanceof ClientEvent) {
            $this->handleClientEvent($event);
            return;
        }

        // Gestion des événements système
        $eventName = $event->getName();
        
        if (isset($this->handlers[$eventName])) {
            foreach ($this->handlers[$eventName] as $handler) {
                $handler($event);
            }
        }
    }

    private function handleClientEvent(ClientEvent $event): void
    {
        $eventName = $event->getName();
        
        if (isset($this->handlers[$eventName])) {
            foreach ($this->handlers[$eventName] as $handler) {
                try {
                    $result = $handler($event);
                    
                    // Si le handler retourne une réponse, l'envoyer
                    if ($result !== null && $event->hasConnection()) {
                        $event->respond(is_array($result) ? $result : ['result' => $result]);
                    }
                    
                    // Si l'événement nécessite un accusé de réception
                    if ($event->requiresAcknowledgement() && !$event->isAcknowledged()) {
                        $event->acknowledge();
                    }
                    
                } catch (\Exception $e) {
                    $this->handleError($event, $e);
                }
            }
        }
    }

    public function addMiddleware(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function broadcast(Event $event, array $options = []): void
    {
        if (!$event->shouldBroadcast()) {
            return;
        }

        $rooms = $options['rooms'] ?? $event->getRooms();
        $except = $options['except'] ?? $event->getExcept();
        
        $connections = $this->getConnectionsForRooms($rooms);

        foreach ($connections as $connection) {
            if (!in_array($connection->getId(), $except)) {
                $this->sendToConnection($connection, $event);
            }
        }
    }

    private function getConnectionsForRooms(array $rooms): array
    {
        if (empty($rooms)) {
            return $this->connectionPool->getAll();
        }

        $connections = [];
        foreach ($rooms as $room) {
            $roomConnections = $this->roomManager->getConnectionsInRoom($room);
            foreach ($roomConnections as $connection) {
                $connections[$connection->getId()] = $connection;
            }
        }

        return $connections;
    }

    private function sendToConnection(Connection $connection, Event $event): void
    {
        $connection->send($event->toArray());
    }

    private function handleError(ClientEvent $event, \Exception $e): void
    {
        error_log("Event handler error: " . $e->getMessage());
        
        if ($event->hasConnection()) {
            $event->respond([
                'error' => true,
                'message' => 'Internal server error',
                'code' => 500
            ]);
        }
    }

    public function emit(string $eventName, array $data = []): Event
    {
        $event = new Event($eventName, $data);
        $this->handle($event);
        return $event;
    }

    public function emitToClient(Connection $connection, string $eventName, array $data = []): void
    {
        $event = new Event($eventName, $data);
        $event->setBroadcast(false);
        $this->sendToConnection($connection, $event);
    }

    public function emitToRoom(string $room, string $eventName, array $data = []): void
    {
        $event = new Event($eventName, $data);
        $event->addRoom($room);
        $this->broadcast($event);
    }

    public function getRoomManager(): RoomManager
    {
        return $this->roomManager;
    }
}