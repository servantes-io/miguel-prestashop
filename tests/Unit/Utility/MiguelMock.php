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

namespace Tests\Unit\Utility;

use Miguel;

class MiguelMock extends Miguel
{
    public $validateApiAccessReturnValue = true;
    public $readFileContentReturnValue = null;

    public function readFileContent($filename)
    {
        return $this->readFileContentReturnValue;
    }

    public function validateApiAccess()
    {
        return $this->validateApiAccessReturnValue;
    }
}
