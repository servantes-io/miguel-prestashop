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

/**
 * Create the outbound-order tables (`miguel_outbound_order`, `miguel_outbound_order_payment`) and hook the module to
 * actionCronJob, through which the "Cron tasks manager" module sends the buffered error reports.
 *
 * The tables come from src/sql/install.php, the same `CREATE TABLE IF NOT EXISTS` statements a fresh install runs.
 *
 * @param Miguel $module
 *
 * @return bool false when a table cannot be created — the outbound-order endpoints and the order export need them
 */
function upgrade_module_1_5_0($module)
{
    if (false === include dirname(__FILE__) . '/../src/sql/install.php') {
        return false;
    }

    try {
        $module->registerHook('actionCronJob');
    } catch (Throwable $e) {
        // Best effort — reports are still sent after the next successful back-office call to Miguel.
    }

    return true;
}
