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
     * @var mixed
     */
    private $data;

    public function __construct(string $code, string $message, $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    // JsonSerializable

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $res = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->data) {
            $res['data'] = $this->data;
        }

        return $res;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getData()
    {
        return $this->data;
    }

    // Make functions

    /**
     * @param mixed $data
     *
     * @return MiguelApiError
     */
    public static function apiKeyNotSet($data = null): MiguelApiError
    {
        return new self('api_key.not_set', 'API key not set', $data);
    }

    public static function apiKeyInvalid(): MiguelApiError
    {
        return new self('api_key.invalid', 'API key invalid');
    }

    public static function moduleDisabled(): MiguelApiError
    {
        return new self('module.disabled', 'Module is disabled');
    }

    public static function configurationNotSet(): MiguelApiError
    {
        return new self('configuration.not_set', 'Configuration not set');
    }

    public static function argumentNotSet($argument): MiguelApiError
    {
        return new self('argument.not_set', "Argument $argument not set");
    }

    public static function invalidPayload($message): MiguelApiError
    {
        return new self('payload.invalid', "Invalid payload: $message");
    }

    public static function unknownError(): MiguelApiError
    {
        return new self('unknown.error', 'Unknown error');
    }
}
