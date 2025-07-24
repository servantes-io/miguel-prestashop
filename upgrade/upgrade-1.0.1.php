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

use Miguel\Utils\MiguelSettings;

if (!defined('_PS_VERSION_')) {
    exit;
}

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
