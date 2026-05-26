<?php

namespace tests\unit\Espo\Modules\QuickBooks;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\QuickBooks\Services\QuickBooksService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class QuickBooksServiceFieldMappingTest extends TestCase
{
    private QuickBooksService $service;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $config = $this->createMock(Config::class);
        $log = $this->createMock(Log::class);

        $this->service = new QuickBooksService($em, $config, $log);
    }

    public function testBuildCustomerPayloadForAccount(): void
    {
        $entity = $this->createMock(Entity::class);

        $entity->method('get')->willReturnMap([
            ['name', 'Acme Corp'],
            ['emailAddress', 'info@acme.com'],
            ['phoneNumber', '555-1234'],
            ['website', 'https://acme.com'],
            ['billingAddressStreet', '123 Main St'],
            ['billingAddressCity', 'Springfield'],
            ['billingAddressState', 'IL'],
            ['billingAddressPostalCode', '62701'],
            ['billingAddressCountry', 'US'],
        ]);

        $payload = $this->invokeMethod('buildCustomerPayload', ['Account', $entity]);

        $this->assertSame('Acme Corp', $payload['CompanyName']);
        $this->assertSame('Acme Corp', $payload['DisplayName']);
        $this->assertSame('info@acme.com', $payload['PrimaryEmailAddr']['Address']);
        $this->assertSame('555-1234', $payload['PrimaryPhone']['FreeFormNumber']);
        $this->assertSame('https://acme.com', $payload['WebAddr']['URI']);
        $this->assertSame('123 Main St', $payload['BillAddr']['Line1']);
        $this->assertSame('Springfield', $payload['BillAddr']['City']);
    }

    public function testBuildCustomerPayloadForContact(): void
    {
        $entity = $this->createMock(Entity::class);

        $entity->method('get')->willReturnMap([
            ['firstName', 'Jane'],
            ['lastName', 'Doe'],
            ['name', 'Jane Doe'],
            ['emailAddress', 'jane@example.com'],
            ['phoneNumber', null],
            ['website', null],
            ['billingAddressStreet', null],
        ]);

        $payload = $this->invokeMethod('buildCustomerPayload', ['Contact', $entity]);

        $this->assertSame('Jane', $payload['GivenName']);
        $this->assertSame('Doe', $payload['FamilyName']);
        $this->assertSame('Jane Doe', $payload['DisplayName']);
        $this->assertSame('jane@example.com', $payload['PrimaryEmailAddr']['Address']);
        $this->assertArrayNotHasKey('BillAddr', $payload);
    }

    public function testBuildLineItemsWithExplicitItems(): void
    {
        $lineItems = [
            ['description' => 'Widget A', 'quantity' => 2, 'unitPrice' => 10.0],
            ['description' => 'Widget B', 'quantity' => 1, 'unitPrice' => 25.0],
        ];

        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', $lineItems],
            ['amount', null],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice]);

        $this->assertCount(2, $lines);
        $this->assertSame(20.0, $lines[0]['Amount']);
        $this->assertSame(10.0, $lines[0]['SalesItemLineDetail']['UnitPrice']);
        $this->assertSame(2.0, $lines[0]['SalesItemLineDetail']['Qty']);
        $this->assertSame(25.0, $lines[1]['Amount']);
    }

    public function testBuildLineItemsFallsBackToAmount(): void
    {
        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', []],
            ['amount', 150.0],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice]);

        $this->assertCount(1, $lines);
        $this->assertSame(150.0, $lines[0]['Amount']);
        $this->assertSame(1.0, $lines[0]['SalesItemLineDetail']['Qty']);
        $this->assertArrayNotHasKey('ItemRef', $lines[0]['SalesItemLineDetail']);
    }

    public function testBuildLineItemsUsesPerItemQbItemId(): void
    {
        $lineItems = [
            ['description' => 'Consulting', 'quantity' => 1, 'unitPrice' => 100.0, 'qbItemId' => '42'],
        ];

        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', $lineItems],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice, '99']);

        $this->assertSame('42', $lines[0]['SalesItemLineDetail']['ItemRef']['value']);
    }

    public function testBuildLineItemsFallsBackToDefaultItemId(): void
    {
        $lineItems = [
            ['description' => 'Consulting', 'quantity' => 1, 'unitPrice' => 100.0],
        ];

        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', $lineItems],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice, '99']);

        $this->assertSame('99', $lines[0]['SalesItemLineDetail']['ItemRef']['value']);
    }

    public function testBuildLineItemsNoItemRefWhenNeitherSet(): void
    {
        $lineItems = [
            ['description' => 'Consulting', 'quantity' => 1, 'unitPrice' => 100.0],
        ];

        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', $lineItems],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice]);

        $this->assertArrayNotHasKey('ItemRef', $lines[0]['SalesItemLineDetail']);
    }

    public function testBuildLineItemsFallbackAmountUsesDefaultItemId(): void
    {
        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', []],
            ['amount', 200.0],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice, '7']);

        $this->assertCount(1, $lines);
        $this->assertSame('7', $lines[0]['SalesItemLineDetail']['ItemRef']['value']);
    }

    /**
     * @param array<mixed> $args
     * @return mixed
     */
    private function invokeMethod(string $name, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($this->service, $name);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->service, $args);
    }
}
