<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiError;
use PHPUnit\Framework\TestCase;

class ApiErrorTest extends TestCase
{
    public function testResourceNotFound()
    {
        $error = MiguelApiError::resourceNotFound('widgets');

        $this->assertSame('resource.not_found', $error->getCode());
        $this->assertSame('Resource widgets not found', $error->getMessage());
    }

    public function testMethodNotAllowed()
    {
        $error = MiguelApiError::methodNotAllowed('DELETE');

        $this->assertSame('method.not_allowed', $error->getCode());
        $this->assertSame('Method DELETE not allowed', $error->getMessage());
    }

    public function testOrderNotFound()
    {
        $error = MiguelApiError::orderNotFound('XKBKNABJK');

        $this->assertSame('order.not_found', $error->getCode());
        $this->assertSame('Order XKBKNABJK not found', $error->getMessage());
    }

    public function testOutboundOrderFailureIncludesDiagnosticMessage()
    {
        $error = MiguelApiError::outboundOrderFailed('Table ps_miguel_outbound_order does not exist');

        $this->assertSame('order.create_failed', $error->getCode());
        $this->assertSame('Outbound order creation failed: Table ps_miguel_outbound_order does not exist', $error->getMessage());
    }
}
