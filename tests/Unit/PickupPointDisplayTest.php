<?php
namespace Tests\Unit;

use Miguel\Utils\MiguelOrderPickupPoint;
use Tests\Unit\Utility\DatabaseTestCase;

require_once dirname(__DIR__, 2) . '/src/utils/miguel-order-pickup-point.php';

class PickupPointDisplayTest extends DatabaseTestCase
{
    public function testCarriersAndInvalidNotes()
    {
        foreach (['Packeta', 'Balikovna', 'Gls'] as $carrier) {
            $point = MiguelOrderPickupPoint::fromNote("Pickup point ($carrier): 123\nBranch\nAddress");
            $this->assertSame($carrier, $point['carrier']);
            $this->assertSame('123', $point['point_id']);
            $this->assertSame('Branch', $point['name']);
            $this->assertSame('Address', $point['address']);
        }
        $this->assertNull(MiguelOrderPickupPoint::fromNote('Ordinary note'));
        $this->assertNull(MiguelOrderPickupPoint::fromNote(''));
    }

    public function testAdminHookUsesNoteThenPreservesOriginalMessageAndEscapesHtml()
    {
        $order = $this->entityCreator->createOrder();
        $order->note = "Pickup point (Packeta): 123\n<script>Branch</script>\nStreet & City";
        $order->save();
        $message = null;
        try {
            $module = new \Miguel();
            $html = $module->hookDisplayAdminOrderMainBottom(['id_order' => $order->id]);
            $this->assertStringContainsString('&lt;script&gt;Branch&lt;/script&gt;', $html);
            $this->assertStringContainsString('Street &amp; City', $html);
            $this->assertStringNotContainsString('<script>Branch', $html);
            $message = new \Message();
            $message->id_order = $order->id;
            $message->message = "Pickup point (Packeta): 123\nOriginal branch\nOriginal address";
            $message->private = true;
            $message->add();
            $order->note = 'Merchant edited the note';
            $order->save();
            $this->assertStringContainsString('ID: 123', $module->hookDisplayAdminOrderMainBottom(['id_order' => $order->id]));
            $message->delete();
            $message = null;
            $this->assertSame('', $module->hookDisplayAdminOrderMainBottom(['id_order' => $order->id]));
        } finally {
            if ($message) { $message->delete(); }
            $order->delete();
        }
    }
}
