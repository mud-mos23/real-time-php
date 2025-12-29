<?php

namespace RealTimePHP\Client;

class WebSocketClient
{
    private $client;
    private $url;
    private $callbacks = [];

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function connect(): void
    {
        $this->client = new \WebSocket\Client($this->url);
    }

    public function on(string $event, callable $callback): void
    {
        $this->callbacks[$event] = $callback;
    }

    public function emit(string $event, $data = []): void
    {
        $message = json_encode(['event' => $event, 'data' => $data]);
        $this->client->send($message);
    }

    public function listen(): void
    {
        try {
            while (true) {
                $message = $this->client->receive();
                $data = json_decode($message, true);
                
                if (isset($data['event']) && isset($this->callbacks[$data['event']])) {
                    call_user_func($this->callbacks[$data['event']], $data['data']);
                }
            }
        } catch (\Exception $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }

    public function close(): void
    {
        $this->client->close();
    }
}