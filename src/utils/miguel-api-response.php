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

class MiguelApiResponse implements \JsonSerializable
{
    /**
     * True when everything is ok, otherwise false.
     *
     * @var bool
     */
    private $result;

    /**
     * @var mixed
     */
    private $data;

    /**
     * @var string
     */
    private $data_key;

    /**
     * @param bool $result
     * @param mixed $data
     * @param string $data_key
     */
    public function __construct(bool $result, $data, string $data_key)
    {
        $this->result = $result;
        $this->data = $data;
        $this->data_key = $data_key;
    }

    public function getResult(): bool
    {
        return $this->result;
    }

    /**
     * @return mixed (MiguelApiError or mixed data, PHP 7.X does not allow to convert class to mixed type)
     */
    public function getData()
    {
        return $this->data;
    }

    public function getDataKey(): string
    {
        return $this->data_key;
    }

    // JsonSerializable

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'result' => $this->result,
            'debug' => '', // backward compatibility
            $this->data_key => $this->data,
        ];
    }

    // Make functions

    public static function success($data, string $data_key): MiguelApiResponse
    {
        return new self(true, $data, $data_key);
    }

    public static function error(MiguelApiError $error): MiguelApiResponse
    {
        return new self(false, $error, 'error');
    }
}
