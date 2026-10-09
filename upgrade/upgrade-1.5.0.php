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
 * Hook the module to actionCronJob, through which the "Cron tasks manager" module sends the buffered error reports.
 *
 * @param Miguel $module
 *
 * @return bool always true — a failed registration must not block the module upgrade
 */
function upgrade_module_1_5_0($module)
{
    try {
        $module->registerHook('actionCronJob');
    } catch (Throwable $e) {
        // Best effort — reports are still sent after the next successful back-office call to Miguel.
    }

    return true;
}
