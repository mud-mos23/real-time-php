<?php

namespace RealTimePHP\Client;

use RealTimePHP\Events\Event;
use RealTimePHP\Events\ClientEvent;
use RealTimePHP\Exceptions\WebSocketException;

class EventDispatcher
{
    private $listeners = [];
    private $onceListeners = [];
    private $middlewares = [];
    private $globalListeners = [];
    private $pendingAcks = [];
    private $defaultTimeout = 30; // secondes
    private $maxListeners = 50;
    private $listenerCount = 0;

    public function __construct(?int $maxListeners = null)
    {
        if ($maxListeners !== null) {
            $this->maxListeners = $maxListeners;
        }
    }

    public function on(string $event, callable $listener, int $priority = 0): string
    {
        $this->checkMaxListeners();
        
        $listenerId = uniqid('listener_');
        
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = [
            'id' => $listenerId,
            'callback' => $listener,
            'priority' => $priority
        ];

        // Trier par priorité (ordre décroissant)
        usort($this->listeners[$event], function($a, $b) {
            return $b['priority'] - $a['priority'];
        });

        $this->listenerCount++;
        return $listenerId;
    }

    public function once(string $event, callable $listener, int $priority = 0): string
    {
        $listenerId = $this->on($event, function(...$args) use ($listener, $event, $listenerId) {
            $this->removeListener($event, $listenerId);
            return $listener(...$args);
        }, $priority);

        $this->onceListeners[$listenerId] = true;
        return $listenerId;
    }

    public function off(string $event, ?string $listenerId = null): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        if ($listenerId === null) {
            // Supprimer tous les listeners pour cet événement
            $this->listenerCount -= count($this->listeners[$event]);
            unset($this->listeners[$event]);
            return;
        }

        // Supprimer un listener spécifique
        foreach ($this->listeners[$event] as $index => $listener) {
            if ($listener['id'] === $listenerId) {
                unset($this->listeners[$event][$index]);
                $this->listeners[$event] = array_values($this->listeners[$event]);
                $this->listenerCount--;
                
                if (isset($this->onceListeners[$listenerId])) {
                    unset($this->onceListeners[$listenerId]);
                }
                break;
            }
        }

        // Supprimer l'événement s'il n'a plus de listeners
        if (empty($this->listeners[$event])) {
            unset($this->listeners[$event]);
        }
    }

    public function emit(string $event, $data = null, bool $async = false): array
    {
        $results = [];
        
        // Appliquer les middlewares globaux
        foreach ($this->middlewares as $middleware) {
            if ($middleware($event, $data) === false) {
                return $results; // Middleware a bloqué l'événement
            }
        }

        // Appliquer les middlewares spécifiques à l'événement
        $eventMiddlewares = $this->globalListeners[$event . ':middleware'] ?? [];
        foreach ($eventMiddlewares as $middleware) {
            if ($middleware($event, $data) === false) {
                return $results;
            }
        }

        if ($async) {
            // Exécution asynchrone
            $this->emitAsync($event, $data);
            return [];
        }

        // Exécution synchrone
        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $listener) {
                try {
                    $result = $listener['callback']($data, $event);
                    if ($result !== null) {
                        $results[] = $result;
                    }
                } catch (\Throwable $e) {
                    $this->handleListenerError($e, $event, $listener['id']);
                }
            }
        }

        // Listeners globaux (tous les événements)
        if (isset($this->globalListeners['*'])) {
            foreach ($this->globalListeners['*'] as $listener) {
                try {
                    $result = $listener['callback']($data, $event);
                    if ($result !== null) {
                        $results[] = $result;
                    }
                } catch (\Throwable $e) {
                    $this->handleListenerError($e, $event, $listener['id']);
                }
            }
        }

        return $results;
    }

    private function emitAsync(string $event, $data = null): void
    {
        if (extension_loaded('pcntl') && extension_loaded('posix')) {
            // Fork pour l'exécution asynchrone
            $pid = pcntl_fork();
            
            if ($pid == -1) {
                // Échec du fork
                $this->emit($event, $data, false);
            } elseif ($pid) {
                // Processus parent
                pcntl_wait($status, WNOHANG);
            } else {
                // Processus enfant
                $this->emit($event, $data, false);
                exit(0);
            }
        } else {
            // Fallback: exécution dans un thread séparé via un pool
            if (class_exists(\Pool::class)) {
                // Implémentation avec pool de threads
                // (à adapter selon vos besoins)
            } else {
                // Fallback final: exécution synchrone
                $this->emit($event, $data, false);
            }
        }
    }

    public function addMiddleware(callable $middleware, ?string $event = null): self
    {
        if ($event === null) {
            $this->middlewares[] = $middleware;
        } else {
            if (!isset($this->globalListeners[$event . ':middleware'])) {
                $this->globalListeners[$event . ':middleware'] = [];
            }
            $this->globalListeners[$event . ':middleware'][] = $middleware;
        }
        
        return $this;
    }

    public function addGlobalListener(callable $listener, int $priority = 0): string
    {
        return $this->on('*', $listener, $priority);
    }

    public function waitFor(string $event, int $timeout = null, callable $condition = null): Promise
    {
        $timeout = $timeout ?? $this->defaultTimeout;
        
        return new Promise(function($resolve, $reject) use ($event, $timeout, $condition) {
            $timeoutId = null;
            $listenerId = null;
            
            $cleanup = function() use (&$timeoutId, &$listenerId) {
                if ($timeoutId !== null) {
                    \React\EventLoop\Loop::cancelTimer($timeoutId);
                }
                if ($listenerId !== null) {
                    $this->off($event, $listenerId);
                }
            };
            
            // Configuration du timeout
            $timeoutId = \React\EventLoop\Loop::addTimer($timeout, function() use ($reject, $cleanup) {
                $cleanup();
                $reject(new WebSocketException::create(
                    WebSocketException::CONNECTION_TIMEOUT,
                    "Timeout waiting for event"
                ));
            });
            
            // Écoute de l'événement
            $listenerId = $this->once($event, function($data) use ($resolve, $cleanup, $condition) {
                if ($condition === null || $condition($data)) {
                    $cleanup();
                    $resolve($data);
                }
            });
        });
    }

    public function emitWithAck(string $event, $data = null, int $timeout = null): Promise
    {
        $timeout = $timeout ?? $this->defaultTimeout;
        $ackId = uniqid('ack_');
        
        // Ajouter l'ID d'ACK aux données
        $dataWithAck = is_array($data) ? $data : ['payload' => $data];
        $dataWithAck['_ackId'] = $ackId;
        
        return new Promise(function($resolve, $reject) use ($event, $ackId, $timeout, $dataWithAck) {
            $timeoutId = null;
            
            // Configuration du timeout
            $timeoutId = \React\EventLoop\Loop::addTimer($timeout, function() use ($ackId, $reject) {
                unset($this->pendingAcks[$ackId]);
                $reject(new WebSocketException::create(
                    WebSocketException::CONNECTION_TIMEOUT,
                    "Timeout waiting for acknowledgement"
                ));
            });
            
            // Stocker la promesse en attente
            $this->pendingAcks[$ackId] = [
                'resolve' => $resolve,
                'reject' => $reject,
                'timeoutId' => $timeoutId,
                'event' => $event,
                'timestamp' => microtime(true)
            ];
            
            // Émettre l'événement
            $this->emit($event, $dataWithAck);
        });
    }

    public function handleAck(string $ackId, $responseData): void
    {
        if (isset($this->pendingAcks[$ackId])) {
            $pendingAck = $this->pendingAcks[$ackId];
            
            // Annuler le timeout
            \React\EventLoop\Loop::cancelTimer($pendingAck['timeoutId']);
            
            // Résoudre la promesse
            $pendingAck['resolve']($responseData);
            
            // Nettoyer
            unset($this->pendingAcks[$ackId]);
        }
    }

    public function removeAllListeners(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
            $this->onceListeners = [];
            $this->globalListeners = [];
            $this->listenerCount = 0;
        } else {
            if (isset($this->listeners[$event])) {
                $this->listenerCount -= count($this->listeners[$event]);
                unset($this->listeners[$event]);
            }
            
            // Supprimer aussi les middlewares spécifiques
            if (isset($this->globalListeners[$event . ':middleware'])) {
                unset($this->globalListeners[$event . ':middleware']);
            }
        }
    }

    public function getListeners(string $event = null): array
    {
        if ($event === null) {
            return array_merge($this->listeners, $this->globalListeners);
        }
        
        $listeners = $this->listeners[$event] ?? [];
        $globalListeners = $this->globalListeners[$event] ?? [];
        
        return array_merge($listeners, $globalListeners);
    }

    public function getListenerCount(string $event = null): int
    {
        if ($event === null) {
            return $this->listenerCount;
        }
        
        $count = isset($this->listeners[$event]) ? count($this->listeners[$event]) : 0;
        $count += isset($this->globalListeners[$event]) ? count($this->globalListeners[$event]) : 0;
        
        return $count;
    }

    public function setMaxListeners(int $maxListeners): self
    {
        $this->maxListeners = $maxListeners;
        return $this;
    }

    private function checkMaxListeners(): void
    {
        if ($this->listenerCount >= $this->maxListeners) {
            throw new WebSocketException::create(
                WebSocketException::INTERNAL_ERROR,
                "Maximum listeners exceeded ({$this->maxListeners})"
            );
        }
    }

    private function handleListenerError(\Throwable $e, string $event, string $listenerId): void
    {
        // Logger l'erreur
        error_log(sprintf(
            "Listener error for event '%s' (listener: %s): %s",
            $event,
            $listenerId,
            $e->getMessage()
        ));
        
        // Émettre un événement d'erreur
        $this->emit('listener_error', [
            'event' => $event,
            'listenerId' => $listenerId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    public function dispatchEvent(Event $event): array
    {
        if ($event instanceof ClientEvent) {
            return $this->dispatchClientEvent($event);
        }
        
        return $this->emit($event->getName(), $event->getData());
    }

    private function dispatchClientEvent(ClientEvent $event): array
    {
        $results = $this->emit($event->getName(), $event);
        
        // Si l'événement nécessite un accusé de réception
        if ($event->requiresAcknowledgement() && !$event->isAcknowledged()) {
            $event->acknowledge($results);
        }
        
        return $results;
    }

    public function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) || 
               isset($this->globalListeners[$event]) ||
               isset($this->globalListeners['*']);
    }

    public function pipeTo(EventDispatcher $target, array $events = [], bool $bidirectional = false): void
    {
        if (empty($events)) {
            // Tous les événements
            $this->addGlobalListener(function($data, $event) use ($target) {
                $target->emit($event, $data);
            });
        } else {
            // Événements spécifiques
            foreach ($events as $event) {
                $this->on($event, function($data) use ($target, $event) {
                    $target->emit($event, $data);
                });
            }
        }
        
        if ($bidirectional) {
            $target->pipeTo($this, $events, false);
        }
    }
}