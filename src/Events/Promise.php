<?php

namespace RealTimePHP\Client;

class Promise
{
    private $state = 'pending'; // pending, fulfilled, rejected
    private $value = null;
    private $reason = null;
    private $onFulfilled = [];
    private $onRejected = [];
    private $onFinally = [];

    public function __construct(callable $executor)
    {
        try {
            $executor(
                [$this, 'resolve'],
                [$this, 'reject']
            );
        } catch (\Throwable $e) {
            $this->reject($e);
        }
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $promise = new self(function() {});
        
        if ($onFulfilled) {
            $this->onFulfilled[] = function($value) use ($onFulfilled, $promise) {
                try {
                    $result = $onFulfilled($value);
                    $promise->resolve($result);
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            };
        }
        
        if ($onRejected) {
            $this->onRejected[] = function($reason) use ($onRejected, $promise) {
                try {
                    $result = $onRejected($reason);
                    $promise->resolve($result);
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            };
        } else {
            // Propagation automatique des erreurs
            $this->onRejected[] = function($reason) use ($promise) {
                $promise->reject($reason);
            };
        }
        
        $this->processQueue();
        return $promise;
    }

    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    public function finally(callable $onFinally): self
    {
        $this->onFinally[] = $onFinally;
        return $this;
    }

    public function resolve($value = null): void
    {
        if ($this->state !== 'pending') {
            return;
        }
        
        $this->state = 'fulfilled';
        $this->value = $value;
        $this->processQueue();
    }

    public function reject($reason = null): void
    {
        if ($this->state !== 'pending') {
            return;
        }
        
        $this->state = 'rejected';
        $this->reason = $reason;
        $this->processQueue();
    }

    private function processQueue(): void
    {
        if ($this->state === 'pending') {
            return;
        }
        
        $callbacks = $this->state === 'fulfilled' ? $this->onFulfilled : $this->onRejected;
        $value = $this->state === 'fulfilled' ? $this->value : $this->reason;
        
        // Exécuter les callbacks
        foreach ($callbacks as $callback) {
            try {
                $callback($value);
            } catch (\Throwable $e) {
                // Ignorer les erreurs dans les callbacks
            }
        }
        
        // Exécuter les callbacks finally
        foreach ($this->onFinally as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                // Ignorer les erreurs dans finally
            }
        }
        
        // Nettoyer
        $this->onFulfilled = [];
        $this->onRejected = [];
        $this->onFinally = [];
    }

    public function getState(): string
    {
        return $this->state;
    }

    public static function all(array $promises): self
    {
        return new self(function($resolve, $reject) use ($promises) {
            $results = [];
            $remaining = count($promises);
            
            if ($remaining === 0) {
                $resolve($results);
                return;
            }
            
            foreach ($promises as $index => $promise) {
                $promise->then(
                    function($value) use ($index, &$results, &$remaining, $resolve) {
                        $results[$index] = $value;
                        $remaining--;
                        
                        if ($remaining === 0) {
                            ksort($results);
                            $resolve(array_values($results));
                        }
                    },
                    $reject
                );
            }
        });
    }

    public static function race(array $promises): self
    {
        return new self(function($resolve, $reject) use ($promises) {
            foreach ($promises as $promise) {
                $promise->then($resolve, $reject);
            }
        });
    }

    public static function resolveStatic($value = null): self
    {
        return new self(function($resolve) use ($value) {
            $resolve($value);
        });
    }

    public static function rejectStatic($reason = null): self
    {
        return new self(function($resolve, $reject) use ($reason) {
            $reject($reason);
        });
    }
}