<?php

namespace RealTimePHP\Handler;

use RealTimePHP\Events\ClientEvent;
use RealTimePHP\Server\Connection;
use RealTimePHP\Server\ConnectionPool;

class MessageHandler
{
    private $connectionPool;
    private $eventHandler;
    private $messageFormat = 'json';
    private $validators = [];
    private $serializers = [];

    public function __construct(
        ConnectionPool $connectionPool,
        EventHandler $eventHandler
    ) {
        $this->connectionPool = $connectionPool;
        $this->eventHandler = $eventHandler;
        
        $this->initializeDefaults();
    }

    private function initializeDefaults(): void
    {
        // Serializer JSON par défaut
        $this->registerSerializer('json', function($data) {
            return json_encode($data);
        }, function($message) {
            return json_decode($message, true);
        });

        // Validateur par défaut
        $this->registerValidator('default', function($message) {
            $data = json_decode($message, true);
            return $data !== null && isset($data['event']);
        });
    }

    public function handleMessage(string $message, Connection $connection): void
    {
        // Valider le format du message
        if (!$this->validateMessage($message)) {
            $this->sendError($connection, 'Invalid message format');
            return;
        }

        // Désérialiser le message
        $data = $this->deserialize($message);
        if ($data === null) {
            $this->sendError($connection, 'Failed to deserialize message');
            return;
        }

        // Créer l'événement client
        $event = ClientEvent::fromMessage($message, $connection);
        if ($event === null) {
            $this->sendError($connection, 'Invalid event structure');
            return;
        }

        // Appliquer les validateurs spécifiques à l'événement
        if (!$this->validateEventData($event)) {
            $this->sendError($connection, 'Invalid event data');
            return;
        }

        // Traiter l'événement
        $this->processEvent($event);
    }

    private function validateMessage(string $message): bool
    {
        foreach ($this->validators as $validator) {
            if (!$validator($message)) {
                return false;
            }
        }
        return true;
    }

    private function validateEventData(ClientEvent $event): bool
    {
        $eventName = $event->getName();
        
        // Validation basique pour les événements système
        switch ($eventName) {
            case 'ping':
                return true; // Toujours valide
                
            case 'subscribe':
            case 'unsubscribe':
                $data = $event->getData();
                return isset($data['room']) && is_string($data['room']);
                
            case 'authenticate':
                $data = $event->getData();
                return isset($data['token']) || isset($data['credentials']);
                
            default:
                // Par défaut, accepter tous les événements
                return true;
        }
    }

    private function processEvent(ClientEvent $event): void
    {
        $eventName = $event->getName();
        
        // Gestion des événements système
        switch ($eventName) {
            case 'ping':
                $this->handlePing($event);
                break;
                
            case 'subscribe':
                $this->handleSubscribe($event);
                break;
                
            case 'unsubscribe':
                $this->handleUnsubscribe($event);
                break;
                
            case 'authenticate':
                $this->handleAuthentication($event);
                break;
                
            default:
                // Événement personnalisé - passer à l'EventHandler
                $this->eventHandler->handle($event);
                break;
        }
    }

    private function handlePing(ClientEvent $event): void
    {
        $event->respond(['pong' => microtime(true)]);
        $event->acknowledge();
    }

    private function handleSubscribe(ClientEvent $event): void
    {
        $data = $event->getData();
        $room = $data['room'] ?? null;
        
        if ($room && $event->hasConnection()) {
            $roomManager = $this->eventHandler->getRoomManager();
            $roomManager->addToRoom($event->getConnection(), $room);
            
            $event->respond([
                'subscribed' => true,
                'room' => $room
            ]);
        } else {
            $event->respond([
                'error' => 'Room name required'
            ]);
        }
    }

    private function handleUnsubscribe(ClientEvent $event): void
    {
        $data = $event->getData();
        $room = $data['room'] ?? null;
        
        if ($room && $event->hasConnection()) {
            $roomManager = $this->eventHandler->getRoomManager();
            $roomManager->removeFromRoom($event->getConnection(), $room);
            
            $event->respond([
                'unsubscribed' => true,
                'room' => $room
            ]);
        } else {
            $event->respond([
                'error' => 'Room name required'
            ]);
        }
    }

    private function handleAuthentication(ClientEvent $event): void
    {
        $data = $event->getData();
        
        // Logique d'authentification basique
        // À étendre selon vos besoins
        if (isset($data['token']) && $this->validateToken($data['token'])) {
            $user = $this->extractUserFromToken($data['token']);
            
            if ($event->hasConnection()) {
                $event->setClientData('authenticated', true);
                $event->setClientData('user', $user);
                
                $event->respond([
                    'authenticated' => true,
                    'user' => $user
                ]);
            }
        } else {
            $event->respond([
                'authenticated' => false,
                'error' => 'Invalid credentials'
            ]);
        }
    }

    private function validateToken(string $token): bool
    {
        // Implémentation basique - à remplacer par votre logique
        return !empty($token);
    }

    private function extractUserFromToken(string $token): array
    {
        // Implémentation basique - à remplacer par votre logique
        return ['id' => 1, 'name' => 'User'];
    }

    public function registerSerializer(
        string $name, 
        callable $serialize, 
        callable $deserialize
    ): self {
        $this->serializers[$name] = [
            'serialize' => $serialize,
            'deserialize' => $deserialize
        ];
        return $this;
    }

    public function registerValidator(string $name, callable $validator): self
    {
        $this->validators[$name] = $validator;
        return $this;
    }

    public function setMessageFormat(string $format): self
    {
        if (isset($this->serializers[$format])) {
            $this->messageFormat = $format;
        }
        return $this;
    }

    private function serialize(array $data): string
    {
        if (isset($this->serializers[$this->messageFormat])) {
            return $this->serializers[$this->messageFormat]['serialize']($data);
        }
        
        return json_encode($data);
    }

    private function deserialize(string $message): ?array
    {
        if (isset($this->serializers[$this->messageFormat])) {
            return $this->serializers[$this->messageFormat]['deserialize']($message);
        }
        
        return json_decode($message, true);
    }

    private function sendError(Connection $connection, string $message): void
    {
        $connection->send([
            'event' => 'error',
            'data' => [
                'message' => $message,
                'timestamp' => microtime(true)
            ]
        ]);
    }

    public function formatMessage(string $event, array $data = []): string
    {
        return $this->serialize([
            'event' => $event,
            'data' => $data,
            'timestamp' => microtime(true)
        ]);
    }
}