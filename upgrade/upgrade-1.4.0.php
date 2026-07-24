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
 * Notify Miguel once, right after the module is upgraded, so it learns the new
 * module version and endpoints without waiting for an admin to open the config page.
 *
 * @param Miguel $module
 *
 * @return bool always true — a failed connect must not block the module upgrade
 */
function upgrade_module_1_4_0($module)
{
    try {
        $module->connectToMiguel(Miguel::CONNECT_TIMEOUT);
    } catch (Throwable $e) {
        // Best-effort notify — a failed connect must never block the module upgrade.
    }

    return true;
}
