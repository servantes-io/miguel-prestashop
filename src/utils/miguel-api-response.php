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

class MiguelApiResponse implements JsonSerializable
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
    public function __construct($result, $data, $data_key)
    {
        $this->result = $result;
        $this->data = $data;
        $this->data_key = $data_key;
    }

    /**
     * @return bool
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function getDataKey()
    {
        return $this->data_key;
    }

    // JsonSerializable

    public function jsonSerialize()
    {
        return (object) [
            'result' => $this->result,
            'debug' => '', // backward compatibility
            $this->data_key => $this->data,
        ];
    }

    // Make functions

    /**
     * @param mixed $data
     * @param string $data_key
     *
     * @return MiguelApiResponse
     */
    public static function success($data, $data_key)
    {
        return new self(true, $data, $data_key);
    }

    /**
     * @param MiguelApiError $error
     *
     * @return MiguelApiResponse
     */
    public static function error($error)
    {
        return new self(false, $error, 'error');
    }
}
