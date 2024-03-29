<?php

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MiguelApiError implements \JsonSerializable
{
    /**
     * @var string
     */
    private $code;
    /**
     * @var string
     */
    private $message;

    /**
     * @var int
     */
    private $status;

    public function __construct(string $code, string $message, int $status)
    {
        $this->code = $code;
        $this->message = $message;
        $this->status = $status;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    // JsonSerializable

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
        ];
    }

    // Make functions

    public static function unauthorized(): MiguelApiError
    {
        return new self('unauthorized', 'Unauthorized', 401);
    }

    public static function apiKeyNotSet(): MiguelApiError
    {
        return new self('api_key.not_set', 'API key not set', 400);
    }

    public static function apiKeyInvalid(): MiguelApiError
    {
        return new self('api_key.invalid', 'API key invalid', 403);
    }

    public static function moduleDisabled(): MiguelApiError
    {
        return new self('module.disabled', 'Module is disabled', 400);
    }

    public static function configurationNotSet(): MiguelApiError
    {
        return new self('configuration.not_set', 'Configuration not set', 400);
    }

    public static function argumentNotSet($argument): MiguelApiError
    {
        return new self('argument.not_set', "Argument $argument not set", 400);
    }

    public static function invalidPayload($message): MiguelApiError
    {
        return new self('payload.invalid', "Invalid payload: $message", 400);
    }

    public static function unknownError(): MiguelApiError
    {
        return new self('unknown.error', 'Unknown error', 400);
    }
}
