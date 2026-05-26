<?php

namespace tests\unit\Espo\Modules\QuickBooks;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\QuickBooks\Services\QuickBooksService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for QuickBooksService HTTP-facing methods.
 * The protected `request()` method is stubbed out so no real HTTP calls are made.
 */
class QuickBooksServiceHttpTest extends TestCase
{
    private EntityManager $em;
    private Config $config;
    private Log $log;
    private Integration $integration;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->config = $this->createMock(Config::class);
        $this->log = $this->createMock(Log::class);

        $this->integration = $this->createMock(Integration::class);
        $this->integration->method('isEnabled')->willReturn(true);
        $this->integration->method('get')->willReturnMap([
            ['realmId', 'realm-123'],
            ['accessToken', 'fake-access-token'],
            ['accessTokenExpiresAt', null],
        ]);

        // Each test configures getEntityById as needed for its scenario.
    }

    // -------------------------------------------------------------------------
    // upsertCustomer
    // -------------------------------------------------------------------------

    public function testUpsertCustomerSetsQbFieldsOnNewCustomer(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'QuickBooks', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name' => 'Acme Corp',
            'emailAddress' => 'info@acme.com',
            'phoneNumber' => null,
            'website' => null,
            'billingAddressStreet' => null,
            'qbCustomerId' => null,
            'qbCustomerSyncToken' => null,
        ]);

        $setCalls = [];
        $this->captureSet($entity, $setCalls);

        $service = $this->makeService([
            'Customer' => ['Id' => '42', 'SyncToken' => '3'],
        ]);

        $this->em->expects($this->once())->method('saveEntity')
            ->with($entity, ['skipQuickBooksSync' => true, 'silent' => true]);

        $service->upsertCustomer('Account', $entity);

        $this->assertSame('42', $setCalls['qbCustomerId']);
        $this->assertSame('3', $setCalls['qbCustomerSyncToken']);
        $this->assertArrayHasKey('qbSyncedAt', $setCalls);
    }

    public function testUpsertCustomerIncludesSparseUpdateFieldsForExistingCustomer(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'QuickBooks', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name' => 'Acme Corp',
            'emailAddress' => null,
            'phoneNumber' => null,
            'website' => null,
            'billingAddressStreet' => null,
            'qbCustomerId' => '42',
            'qbCustomerSyncToken' => '5',
        ]);

        $capturedPayload = null;
        $service = $this->makeService(
            ['Customer' => ['Id' => '42', 'SyncToken' => '6']],
            function (string $method, string $url, ?array $body) use (&$capturedPayload) {
                $capturedPayload = $body;
                return ['Customer' => ['Id' => '42', 'SyncToken' => '6']];
            }
        );

        $service->upsertCustomer('Account', $entity);

        $this->assertSame('42', $capturedPayload['Customer']['Id']);
        $this->assertSame('5', $capturedPayload['Customer']['SyncToken']);
        $this->assertTrue($capturedPayload['Customer']['sparse']);
    }

    public function testUpsertCustomerThrowsWhenResponseMissingCustomer(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'QuickBooks', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name' => 'Acme Corp',
            'emailAddress' => null,
            'phoneNumber' => null,
            'website' => null,
            'billingAddressStreet' => null,
            'qbCustomerId' => null,
            'qbCustomerSyncToken' => null,
        ]);

        $service = $this->makeService([]);

        $this->expectException(Error::class);

        $service->upsertCustomer('Account', $entity);
    }

    // -------------------------------------------------------------------------
    // upsertInvoice
    // -------------------------------------------------------------------------

    public function testUpsertInvoiceSetsQbFieldsOnNewInvoice(): void
    {
        $account = $this->makeEntity(['qbCustomerId' => 'cust-7']);
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'QuickBooks', $this->integration],
            ['Account', 'acc-1', $account],
        ]);

        $invoice = $this->makeEntity([
            'accountId' => 'acc-1',
            'dueDate' => '2026-06-01',
            'lineItems' => null,
            'amount' => 500.0,
            'qbInvoiceId' => null,
            'qbInvoiceSyncToken' => null,
            'defaultItemId' => null,
        ]);

        $setCalls = [];
        $this->captureSet($invoice, $setCalls);

        $service = $this->makeService([
            'Invoice' => ['Id' => '99', 'SyncToken' => '0'],
        ]);

        $this->em->expects($this->once())->method('saveEntity')
            ->with($invoice, ['skipQuickBooksSync' => true, 'silent' => true]);

        $service->upsertInvoice($invoice);

        $this->assertSame('99', $setCalls['qbInvoiceId']);
        $this->assertSame('0', $setCalls['qbInvoiceSyncToken']);
        $this->assertArrayHasKey('qbSyncedAt', $setCalls);
    }

    public function testUpsertInvoiceThrowsWhenAccountHasNoQbCustomerId(): void
    {
        $account = $this->makeEntity(['qbCustomerId' => null]);
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'QuickBooks', $this->integration],
            ['Account', 'acc-1', $account],
        ]);

        $invoice = $this->makeEntity([
            'accountId' => 'acc-1',
            'dueDate' => null,
            'lineItems' => null,
            'amount' => 100.0,
            'qbInvoiceId' => null,
            'qbInvoiceSyncToken' => null,
        ]);

        $service = $this->makeService([]);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('QB Customer ID');

        $service->upsertInvoice($invoice);
    }

    // -------------------------------------------------------------------------
    // voidInvoice
    // -------------------------------------------------------------------------

    public function testVoidInvoiceCallsVoidEndpoint(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'QuickBooks', $this->integration],
        ]);

        $invoice = $this->makeEntity([
            'qbInvoiceId' => '77',
            'qbInvoiceSyncToken' => '2',
        ]);

        $capturedArgs = null;
        $service = $this->makeService(
            ['Invoice' => ['Id' => '77', 'SyncToken' => '2']],
            function (string $method, string $url, ?array $body) use (&$capturedArgs) {
                $capturedArgs = ['method' => $method, 'url' => $url, 'body' => $body];
                return [];
            }
        );

        $service->voidInvoice($invoice);

        $this->assertSame('POST', $capturedArgs['method']);
        $this->assertStringContainsString('operation=void', $capturedArgs['url']);
        $this->assertSame('77', $capturedArgs['body']['Invoice']['Id']);
        $this->assertSame('2', $capturedArgs['body']['Invoice']['SyncToken']);
    }

    public function testVoidInvoiceNoOpsWhenNotPreviouslySynced(): void
    {
        $invoice = $this->makeEntity([
            'qbInvoiceId' => null,
            'qbInvoiceSyncToken' => null,
        ]);

        $service = $this->getMockBuilder(QuickBooksService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        $service->expects($this->never())->method('request');

        $service->voidInvoice($invoice);
    }

    // -------------------------------------------------------------------------
    // Pull methods — verify request() call arguments
    // -------------------------------------------------------------------------

    public function testPullPaymentsSinceCallsRequestWithDateQuery(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'QuickBooks', $this->integration],
        ]);

        $capturedUrl = null;
        $service = $this->makeService(
            ['QueryResponse' => []],
            function (string $method, string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return ['QueryResponse' => []];
            }
        );

        $service->pullPaymentsSince('2026-01-01');

        $this->assertStringContainsString('query', $capturedUrl);
        $this->assertStringContainsString('Payment', rawurldecode($capturedUrl));
        $this->assertStringContainsString('2026-01-01', rawurldecode($capturedUrl));
    }

    public function testPullCustomersSinceCallsRequestWithDateQuery(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'QuickBooks', $this->integration],
        ]);

        $capturedUrl = null;
        $service = $this->makeService(
            ['QueryResponse' => []],
            function (string $method, string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return ['QueryResponse' => []];
            }
        );

        $service->pullCustomersSince('2026-01-01');

        $this->assertStringContainsString('query', $capturedUrl);
        $this->assertStringContainsString('Customer', rawurldecode($capturedUrl));
        $this->assertStringContainsString('2026-01-01', rawurldecode($capturedUrl));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $defaultResponse
     */
    private function makeService(array $defaultResponse, ?callable $callback = null): QuickBooksService
    {
        $service = $this->getMockBuilder(QuickBooksService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        if ($callback !== null) {
            $service->method('request')->willReturnCallback($callback);
        } else {
            $service->method('request')->willReturn($defaultResponse);
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function makeEntity(array $fields): Entity
    {
        $entity = $this->createMock(Entity::class);
        $map = array_map(fn($k, $v) => [$k, $v], array_keys($fields), array_values($fields));
        $entity->method('get')->willReturnMap($map);
        return $entity;
    }

    /**
     * Wires entity mock to capture set() calls into the provided array.
     *
     * @param array<string, mixed> $calls
     */
    private function captureSet(Entity $entity, array &$calls): void
    {
        $entity->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$calls, $entity) {
                $calls[$k] = $v;
                return $entity;
            }
        );
    }
}
