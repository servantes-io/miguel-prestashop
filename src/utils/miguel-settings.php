<?php
/**
 * 2023 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Pavel Vejnar <vejnar.p@gmail.com>
 *  @copyright  2022 - 2023 Servantes
 *  @license LICENSE.txt
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MiguelSettings
{
    const API_TOKEN_PRODUCTION_KEY = 'MIGUEL_API_TOKEN_PRODUCTION';
    const API_TOKEN_STAGING_KEY = 'MIGUEL_API_TOKEN_STAGING';
    const API_TOKEN_TEST_KEY = 'MIGUEL_API_TOKEN_TEST';
    const API_TOKEN_OWN_KEY = 'MIGUEL_API_TOKEN_OWN';
    const API_SERVER_KEY = 'MIGUEL_API_SERVER';
    const API_SERVER_OWN_KEY = 'MIGUEL_API_SERVER_OWN';
    const NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY_KEY = 'MIGUEL_NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY';
    const NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS_KEY = 'MIGUEL_NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS';
    const API_ENABLE_KEY = 'MIGUEL_API_ENABLE';

    const ENV_PROD = 'prod';
    const ENV_STAGING = 'staging';
    const ENV_TEST = 'test';
    const ENV_OWN = 'own';

    /**
     * @return array<string,mixed>
     */
    public static function getDefaultValues()
    {
        return [
            self::API_TOKEN_PRODUCTION_KEY => '',
            self::API_TOKEN_STAGING_KEY => '',
            self::API_TOKEN_TEST_KEY => '',
            self::API_TOKEN_OWN_KEY => '',
            self::API_SERVER_KEY => self::ENV_PROD,
            self::API_SERVER_OWN_KEY => '',
            self::NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY_KEY => 0,
            self::NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS_KEY => 0,
            self::API_ENABLE_KEY => false,
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function getAllKeys()
    {
        return [
            self::API_TOKEN_PRODUCTION_KEY,
            self::API_TOKEN_STAGING_KEY,
            self::API_TOKEN_TEST_KEY,
            self::API_TOKEN_OWN_KEY,
            self::API_SERVER_KEY,
            self::API_SERVER_OWN_KEY,
            self::NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY_KEY,
            self::NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS_KEY,
            self::API_ENABLE_KEY,
        ];
    }

    public static function reset()
    {
        foreach (self::getDefaultValues() as $key => $value) {
            Configuration::updateValue($key, $value);
        }
    }

    public static function deleteAll()
    {
        foreach (self::getAllKeys() as $key) {
            Configuration::deleteByName($key);
        }
    }

    public static function getAll()
    {
        $values = [];
        foreach (self::getAllKeys() as $key) {
            $values[$key] = Configuration::get($key);
        }

        return $values;
    }

    /**
     * Save value to configuration
     *
     * @param string $key use one of the constants
     * @param mixed $value
     */
    public static function save($key, $value)
    {
        Configuration::updateValue($key, $value);
    }

    // Custom getters and setters

    /**
     * Returns whether the module is enabled or not
     *
     * @return bool
     */
    public static function getEnabled()
    {
        return Configuration::get(self::API_ENABLE_KEY);
    }

    public static function setEnabled($value)
    {
        Configuration::updateValue(self::API_ENABLE_KEY, $value);
    }

    /**
     * Returns selected server environment
     *
     * @return string
     */
    public static function getServer()
    {
        return Configuration::get(self::API_SERVER_KEY);
    }

    /**
     * Return server URL for given environment
     *
     * @return string|false
     */
    public static function getServerUrl($env)
    {
        switch ($env) {
            case MiguelSettings::ENV_PROD:
                return 'https://miguel.servantes.cz';
            case MiguelSettings::ENV_STAGING:
                return 'https://miguel-staging.servantes.cz';
            case MiguelSettings::ENV_TEST:
                return 'https://miguel-test.servantes.cz';
            case MiguelSettings::ENV_OWN:
                return Configuration::get(self::API_SERVER_OWN_KEY);
        }

        return false;
    }

    /**
     * Return server token for given environment
     *
     * @return string|false
     */
    public static function getServerToken($env)
    {
        switch ($env) {
            case MiguelSettings::ENV_PROD:
                return Configuration::get(self::API_TOKEN_PRODUCTION_KEY);
            case MiguelSettings::ENV_STAGING:
                return Configuration::get(self::API_TOKEN_STAGING_KEY);
            case MiguelSettings::ENV_TEST:
                return Configuration::get(self::API_TOKEN_TEST_KEY);
            case MiguelSettings::ENV_OWN:
                return Configuration::get(self::API_TOKEN_OWN_KEY);
        }

        return false;
    }

    /**
     * @return int|false
     */
    public static function getNewStateAutoChange($miguel_only)
    {
        $new_state_id_raw = false;
        if ($miguel_only) {
            $new_state_id_raw = Configuration::get(self::NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY_KEY);
        } else {
            $new_state_id_raw = Configuration::get(self::NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS_KEY);
        }

        if ($new_state_id_raw === false) {
            return false;
        }

        return (int) $new_state_id_raw;
    }
}
