<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Miguel\Utils\MiguelSettings;

function upgrade_module_1_0_1($module)
{
    // migrate settings keys
    foreach (MiguelSettings::getAllKeys() as $key) {
        $old_key = preg_replace('/^MIGUEL_/', '', $key);

        // copy value to new key
        Configuration::updateValue($key, Configuration::get($old_key));

        // delete old key
        Configuration::deleteByName($old_key);
    }

    // migrate env name
    $server = Configuration::get(MiguelSettings::API_SERVER_KEY);
    if ($server === 'API_TOKEN_PRODUCTION') {
        Configuration::updateValue(MiguelSettings::API_SERVER_KEY, MiguelSettings::ENV_PROD);
    }

    return true; // Return true if success.
}
