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

class MiguelSettings
{
    public const API_TOKEN_PRODUCTION_KEY = 'MIGUEL_API_TOKEN_PRODUCTION';
    public const API_TOKEN_STAGING_KEY = 'MIGUEL_API_TOKEN_STAGING';
    public const API_TOKEN_TEST_KEY = 'MIGUEL_API_TOKEN_TEST';
    public const API_TOKEN_OWN_KEY = 'MIGUEL_API_TOKEN_OWN';
    public const API_SERVER_KEY = 'MIGUEL_API_SERVER';
    public const API_SERVER_OWN_KEY = 'MIGUEL_API_SERVER_OWN';
    public const NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY_KEY = 'MIGUEL_NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY';
    public const NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS_KEY = 'MIGUEL_NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS';
    public const API_ENABLE_KEY = 'MIGUEL_API_ENABLE';

    public const ENV_PROD = 'prod';
    public const ENV_STAGING = 'staging';
    public const ENV_TEST = 'test';
    public const ENV_OWN = 'own';

    /**
     * @return array<string,mixed>
     */
    public static function getDefaultValues(): array
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
            \Configuration::updateValue($key, $value);
        }
    }

    public static function deleteAll()
    {
        foreach (self::getAllKeys() as $key) {
            \Configuration::deleteByName($key);
        }
    }

    public static function getAll()
    {
        $values = [];
        foreach (self::getAllKeys() as $key) {
            $values[$key] = \Configuration::get($key);
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
        \Configuration::updateValue($key, $value);
    }

    // Custom getters and setters

    /**
     * Returns whether the module is enabled or not
     */
    public static function getEnabled(): bool
    {
        return \Configuration::get(self::API_ENABLE_KEY);
    }

    public static function setEnabled($value)
    {
        \Configuration::updateValue(self::API_ENABLE_KEY, $value);
    }

    /**
     * Returns selected server environment
     *
     * @return string
     */
    public static function getServer()
    {
        return \Configuration::get(self::API_SERVER_KEY);
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
                return \Configuration::get(self::API_SERVER_OWN_KEY);
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
                return \Configuration::get(self::API_TOKEN_PRODUCTION_KEY);
            case MiguelSettings::ENV_STAGING:
                return \Configuration::get(self::API_TOKEN_STAGING_KEY);
            case MiguelSettings::ENV_TEST:
                return \Configuration::get(self::API_TOKEN_TEST_KEY);
            case MiguelSettings::ENV_OWN:
                return \Configuration::get(self::API_TOKEN_OWN_KEY);
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
            $new_state_id_raw = \Configuration::get(self::NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY_KEY);
        } else {
            $new_state_id_raw = \Configuration::get(self::NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS_KEY);
        }

        if ($new_state_id_raw === false) {
            return false;
        }

        return (int) $new_state_id_raw;
    }
}
