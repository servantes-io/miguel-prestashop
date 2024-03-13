<?php
/**
 * 2024 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2024 Servantes
 *  @license LICENSE.txt
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MiguelApiError implements JsonSerializable
{
    private $code;
    private $message;

    public function __construct($code, $message)
    {
        $this->code = $code;
        $this->message = $message;
    }

    // JsonSerializable

    public function jsonSerialize()
    {
        return (object) [
            'code' => $this->code,
            'message' => $this->message,
        ];
    }

    public function getCode()
    {
        return $this->code;
    }

    public function getMessage()
    {
        return $this->message;
    }

    // Make functions

    public static function apiKeyNotSet()
    {
        return new self('api_key.not_set', 'API key not set');
    }

    public static function apiKeyInvalid()
    {
        return new self('api_key.invalid', 'API key invalid');
    }

    public static function moduleDisabled()
    {
        return new self('module.disabled', 'Module is disabled');
    }

    public static function configurationNotSet()
    {
        return new self('configuration.not_set', 'Configuration not set');
    }

    public static function argumentNotSet($argument)
    {
        return new self('argument.not_set', 'Argument $argument not set');
    }

    public static function invalidPayload($message)
    {
        return new self('payload.invalid', 'Invalid payload: $message');
    }

    public static function unknownError()
    {
        return new self('unknown.error', 'Unknown error');
    }
}
