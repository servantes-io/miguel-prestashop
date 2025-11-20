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
use Miguel\Utils\MiguelSettings;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utility\ContextMocker;
use Tests\Unit\Utility\EntityCreator;

class DatabaseTestCase extends TestCase
{
    /**
     * @var ContextMocker
     */
    protected $contextMocker;

    /**
     * @var EntityCreator
     */
    protected $entityCreator;

    protected function setUp(): void
    {
        $this->contextMocker = new ContextMocker();
        $this->contextMocker->mockContext();

        $this->entityCreator = new EntityCreator();

        MiguelSettings::reset();
        Miguel::setSharedInstance(null);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->contextMocker->resetContext();

        parent::tearDown();
    }
}
