<?php

namespace RealTimePHP\Server;

use React\Socket\Server as ReactSocket;
use React\EventLoop\Factory as LoopFactory;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class WebSocketServer
{
    private $server;
    private $port;
    private $host;
    private $loop;
    private $connectionPool;
    private $eventHandlers = [];

    public function __construct(string $host = '0.0.0.0', int $port = 8080)
    {
        $this->host = $host;
        $this->port = $port;
        $this->connectionPool = new ConnectionPool();
        $this->loop = LoopFactory::create();
    }

    public function start(): void
    {
        $socket = new ReactSocket("{$this->host}:{$this->port}", $this->loop);
        
        $wsServer = new WsServer(
            new class($this->connectionPool, $this->eventHandlers) implements \Ratchet\MessageComponentInterface {
                private $connectionPool;
                private $eventHandlers;

                public function __construct($connectionPool, &$eventHandlers)
                {
                    $this->connectionPool = $connectionPool;
                    $this->eventHandlers = &$eventHandlers;
                }

                public function onOpen(\Ratchet\ConnectionInterface $conn)
                {
                    $connection = new Connection($conn);
                    $this->connectionPool->add($connection);
                    
                    if (isset($this->eventHandlers['connect'])) {
                        call_user_func($this->eventHandlers['connect'], $connection);
                    }
                }

                public function onMessage(\Ratchet\ConnectionInterface $from, $msg)
                {
                    $connection = $this->connectionPool->findByResourceId($from->resourceId);
                    
                    if ($connection) {
                        $data = json_decode($msg, true);
                        
                        if (isset($data['event']) && isset($this->eventHandlers[$data['event']])) {
                            call_user_func($this->eventHandlers[$data['event']], $connection, $data['data'] ?? []);
                        }
                    }
                }

                public function onClose(\Ratchet\ConnectionInterface $conn)
                {
                    $connection = $this->connectionPool->findByResourceId($conn->resourceId);
                    
                    if ($connection) {
                        if (isset($this->eventHandlers['disconnect'])) {
                            call_user_func($this->eventHandlers['disconnect'], $connection);
                        }
                        
                        $this->connectionPool->remove($connection);
                    }
                }

                public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e)
                {
                    echo "Error: {$e->getMessage()}\n";
                    $conn->close();
                }
            }
        );

        $server = new IoServer(
            new HttpServer($wsServer),
            $socket,
            $this->loop
        );

        echo "Serveur WebSocket démarré sur ws://{$this->host}:{$this->port}\n";
        $this->loop->run();
    }

    public function on(string $event, callable $handler): self
    {
        $this->eventHandlers[$event] = $handler;
        return $this;
    }

    public function broadcast(string $event, $data, ?array $except = []): void
    {
        $message = json_encode(['event' => $event, 'data' => $data]);
        
        foreach ($this->connectionPool->getAll() as $connection) {
            if (!in_array($connection->getId(), $except)) {
                $connection->send($message);
            }
        }
    }

    public function stop(): void
    {
        $this->loop->stop();
    }
}