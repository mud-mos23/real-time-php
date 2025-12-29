<?php

namespace RealTimePHP\Exceptions;

use RealTimePHP\Server\Connection;

class ConnectionException extends WebSocketException
{
    private $connectionId;
    private $remoteAddress;
    private $connectionData = [];

    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $errorCode = null,
        array $context = [],
        ?string $connectionId = null,
        ?string $remoteAddress = null,
        array $connectionData = []
    ) {
        parent::__construct($message, $code, $previous, $errorCode, $context);
        $this->connectionId = $connectionId;
        $this->remoteAddress = $remoteAddress;
        $this->connectionData = $connectionData;
    }

    public static function fromConnection(
        ?Connection $connection,
        int $errorCode,
        string $message = "",
        array $additionalContext = []
    ): self {
        $connectionId = $connection ? $connection->getId() : null;
        $connectionData = $connection ? $connection->getAllData() : [];
        
        // Récupérer l'adresse IP depuis le contexte Ratchet
        $remoteAddress = null;
        if ($connection && method_exists($connection, 'getRemoteAddress')) {
            $remoteAddress = $connection->getRemoteAddress();
        }

        $context = array_merge($additionalContext, [
            'connection_id' => $connectionId,
            'remote_address' => $remoteAddress,
            'timestamp' => microtime(true)
        ]);

        if (empty($message)) {
            $message = self::getConnectionErrorMessage($errorCode);
        }

        return new self(
            $message,
            0,
            null,
            $errorCode,
            $context,
            $connectionId,
            $remoteAddress,
            $connectionData
        );
    }

    public function getConnectionId(): ?string
    {
        return $this->connectionId;
    }

    public function getRemoteAddress(): ?string
    {
        return $this->remoteAddress;
    }

    public function getConnectionData(): array
    {
        return $this->connectionData;
    }

    public function getUserData(string $key = null)
    {
        if ($key === null) {
            return $this->connectionData;
        }

        return $this->connectionData[$key] ?? null;
    }

    public function isAuthenticated(): bool
    {
        return !empty($this->connectionData['authenticated']);
    }

    public function getUsername(): ?string
    {
        return $this->connectionData['username'] ?? 
               $this->connectionData['user']['name'] ?? 
               null;
    }

    public function getUserId(): ?string
    {
        return $this->connectionData['user_id'] ?? 
               $this->connectionData['user']['id'] ?? 
               null;
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        
        $array['connection'] = [
            'id' => $this->connectionId,
            'remote_address' => $this->remoteAddress,
            'authenticated' => $this->isAuthenticated(),
            'user' => $this->getUsername(),
            'user_id' => $this->getUserId()
        ];

        // Masquer les données sensibles dans les logs
        if (isset($array['context']['connection_data'])) {
            unset($array['context']['connection_data']['token']);
            unset($array['context']['connection_data']['password']);
        }

        return $array;
    }

    private static function getConnectionErrorMessage(int $errorCode): string
    {
        $messages = [
            self::AUTHENTICATION_FAILED => 'Connection authentication failed',
            self::RATE_LIMIT_EXCEEDED => 'Connection rate limit exceeded',
            self::CONNECTION_TIMEOUT => 'Connection timeout',
            self::UNAUTHORIZED_ACTION => 'Connection attempted unauthorized action',
            
            // Messages spécifiques aux connexions
            4100 => 'Connection closed unexpectedly',
            4101 => 'Connection heartbeat timeout',
            4102 => 'Connection handshake failed',
            4103 => 'Connection rejected - server full',
            4104 => 'Connection rejected - invalid origin',
            4105 => 'Connection rejected - banned IP',
            4106 => 'Connection rejected - too many connections from same IP',
            4107 => 'Connection lost - network error',
            4108 => 'Connection idle timeout',
            4109 => 'Connection buffer overflow',
            4110 => 'Connection protocol mismatch'
        ];

        return $messages[$errorCode] ?? parent::getDefaultMessage($errorCode);
    }

    public static function createHeartbeatTimeout(?Connection $connection = null): self
    {
        return self::fromConnection(
            $connection,
            4101,
            'Connection heartbeat timeout',
            ['last_heartbeat' => time() - 60]
        );
    }

    public static function createIdleTimeout(?Connection $connection = null): self
    {
        return self::fromConnection(
            $connection,
            4108,
            'Connection idle timeout',
            ['idle_time' => time() - 300]
        );
    }

    public static function createInvalidOrigin(?Connection $connection = null, string $origin = null): self
    {
        return self::fromConnection(
            $connection,
            4104,
            'Invalid origin',
            ['origin' => $origin, 'allowed_origins' => $_SERVER['HTTP_ORIGIN'] ?? null]
        );
    }

    public static function createMaxConnections(?Connection $connection = null, int $maxConnections = 0): self
    {
        return self::fromConnection(
            $connection,
            4103,
            'Maximum connections reached',
            ['max_connections' => $maxConnections]
        );
    }

    public function shouldLogAsWarning(): bool
    {
        $warningCodes = [
            self::GOING_AWAY,
            self::NORMAL_CLOSURE,
            self::CONNECTION_TIMEOUT,
            4100, // Connection closed unexpectedly
            4108  // Connection idle timeout
        ];

        return in_array($this->getErrorCode(), $warningCodes);
    }

    public function shouldLogAsError(): bool
    {
        $errorCodes = [
            self::PROTOCOL_ERROR,
            self::POLICY_VIOLATION,
            self::AUTHENTICATION_FAILED,
            self::INTERNAL_ERROR,
            4104, // Invalid origin
            4105  // Banned IP
        ];

        return in_array($this->getErrorCode(), $errorCodes);
    }

    public function getSeverity(): string
    {
        if ($this->shouldLogAsWarning()) {
            return 'WARNING';
        }
        
        if ($this->shouldLogAsError()) {
            return 'ERROR';
        }
        
        return 'INFO';
    }
}