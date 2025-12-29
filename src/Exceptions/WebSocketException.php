<?php

namespace RealTimePHP\Exceptions;

class WebSocketException extends \RuntimeException
{
    private $errorCode;
    private $context = [];
    
    // Codes d'erreur standard WebSocket (RFC 6455)
    public const NORMAL_CLOSURE = 1000;
    public const GOING_AWAY = 1001;
    public const PROTOCOL_ERROR = 1002;
    public const UNSUPPORTED_DATA = 1003;
    public const RESERVED = 1004;
    public const NO_STATUS_RECEIVED = 1005;
    public const ABNORMAL_CLOSURE = 1006;
    public const INVALID_FRAME_PAYLOAD_DATA = 1007;
    public const POLICY_VIOLATION = 1008;
    public const MESSAGE_TOO_BIG = 1009;
    public const MISSING_EXTENSION = 1010;
    public const INTERNAL_ERROR = 1011;
    public const SERVICE_RESTART = 1012;
    public const TRY_AGAIN_LATER = 1013;
    public const BAD_GATEWAY = 1014;
    
    // Codes d'erreur personnalisés
    public const AUTHENTICATION_FAILED = 4001;
    public const RATE_LIMIT_EXCEEDED = 4002;
    public const INVALID_MESSAGE_FORMAT = 4003;
    public const UNAUTHORIZED_ACTION = 4004;
    public const ROOM_NOT_FOUND = 4005;
    public const CONNECTION_TIMEOUT = 4006;
    public const HANDSHAKE_FAILED = 4007;
    public const INVALID_PROTOCOL = 4008;

    public function __construct(
        string $message = "", 
        int $code = 0, 
        ?\Throwable $previous = null,
        ?int $errorCode = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode ?? self::INTERNAL_ERROR;
        $this->context = $context;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function addContext(string $key, $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'error' => true,
            'code' => $this->getErrorCode(),
            'message' => $this->getMessage(),
            'context' => $this->getContext(),
            'trace' => $this->getTraceAsString()
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public static function create(
        int $errorCode, 
        string $message = "", 
        array $context = []
    ): self {
        if (empty($message)) {
            $message = self::getDefaultMessage($errorCode);
        }
        
        return new self($message, 0, null, $errorCode, $context);
    }

    public static function fromException(\Throwable $e, ?int $errorCode = null): self
    {
        $errorCode = $errorCode ?? self::INTERNAL_ERROR;
        
        return new self(
            $e->getMessage(),
            $e->getCode(),
            $e,
            $errorCode,
            ['original_exception' => get_class($e)]
        );
    }

    private static function getDefaultMessage(int $errorCode): string
    {
        $messages = [
            self::NORMAL_CLOSURE => 'Normal closure',
            self::GOING_AWAY => 'Going away',
            self::PROTOCOL_ERROR => 'Protocol error',
            self::UNSUPPORTED_DATA => 'Unsupported data',
            self::NO_STATUS_RECEIVED => 'No status received',
            self::ABNORMAL_CLOSURE => 'Abnormal closure',
            self::INVALID_FRAME_PAYLOAD_DATA => 'Invalid frame payload data',
            self::POLICY_VIOLATION => 'Policy violation',
            self::MESSAGE_TOO_BIG => 'Message too big',
            self::MISSING_EXTENSION => 'Missing extension',
            self::INTERNAL_ERROR => 'Internal server error',
            self::SERVICE_RESTART => 'Service restart',
            self::TRY_AGAIN_LATER => 'Try again later',
            self::BAD_GATEWAY => 'Bad gateway',
            self::AUTHENTICATION_FAILED => 'Authentication failed',
            self::RATE_LIMIT_EXCEEDED => 'Rate limit exceeded',
            self::INVALID_MESSAGE_FORMAT => 'Invalid message format',
            self::UNAUTHORIZED_ACTION => 'Unauthorized action',
            self::ROOM_NOT_FOUND => 'Room not found',
            self::CONNECTION_TIMEOUT => 'Connection timeout',
            self::HANDSHAKE_FAILED => 'WebSocket handshake failed',
            self::INVALID_PROTOCOL => 'Invalid WebSocket protocol'
        ];

        return $messages[$errorCode] ?? 'Unknown error';
    }

    public static function isFatalError(int $errorCode): bool
    {
        $fatalErrors = [
            self::PROTOCOL_ERROR,
            self::INVALID_FRAME_PAYLOAD_DATA,
            self::POLICY_VIOLATION,
            self::MESSAGE_TOO_BIG,
            self::INTERNAL_ERROR,
            self::INVALID_PROTOCOL
        ];

        return in_array($errorCode, $fatalErrors);
    }

    public function shouldCloseConnection(): bool
    {
        return $this->errorCode >= 1000 && $this->errorCode <= 1015;
    }

    public function getCloseFrame(): string
    {
        $frame = pack('n', $this->errorCode);
        
        if (!empty($this->message)) {
            $frame .= $this->message;
        }
        
        return $frame;
    }
}