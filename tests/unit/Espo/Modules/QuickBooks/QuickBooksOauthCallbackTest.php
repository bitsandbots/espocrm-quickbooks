<?php

namespace tests\unit\Espo\Modules\QuickBooks;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\QuickBooks\EntryPoints\QuickBooksOauthCallback;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class QuickBooksOauthCallbackTest extends TestCase
{
    private EntityManager $em;
    private Config $config;
    private Log $log;
    private Request $request;
    private Response $response;

    private const FAKE_TOKENS = [
        'access_token' => 'access-tok-abc',
        'refresh_token' => 'refresh-tok-xyz',
        'expires_in' => 3600,
    ];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->config = $this->createMock(Config::class);
        $this->log = $this->createMock(Log::class);
        $this->request = $this->createMock(Request::class);
        $this->response = $this->createMock(Response::class);

        $this->config->method('get')->with('siteUrl')->willReturn('https://crm.example.com');
        $this->response->method('writeBody')->willReturnSelf();
    }

    // -------------------------------------------------------------------------
    // Rejection paths — all resolve before any HTTP call
    // -------------------------------------------------------------------------

    public function testRejectsOnErrorQueryParam(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', 'access_denied'],
        ]);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsWhenCodeMissing(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', null],
            ['realmId', 'realm-123'],
            ['state', 'some-state'],
        ]);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsWhenRealmIdMissing(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['realmId', null],
            ['state', 'some-state'],
        ]);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsWhenIntegrationNotFound(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['realmId', 'realm-123'],
            ['state', 'some-state'],
        ]);

        $this->em
            ->method('getEntityById')
            ->with(Integration::ENTITY_TYPE, 'QuickBooks')
            ->willReturn(null);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsWhenOauthStateNotStored(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['realmId', 'realm-123'],
            ['state', 'callback-state'],
        ]);

        $integration = $this->makeIntegration(storedState: null);

        $this->em->method('getEntityById')->willReturn($integration);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsOnStateMismatch(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['realmId', 'realm-123'],
            ['state', 'callback-state-WRONG'],
        ]);

        $integration = $this->makeIntegration(storedState: 'stored-state-ABC');

        $this->em->method('getEntityById')->willReturn($integration);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    // -------------------------------------------------------------------------
    // Success path — partial mock skips curl
    // -------------------------------------------------------------------------

    public function testSuccessWhenStateMatches(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['realmId', 'realm-123'],
            ['state', 'matching-state'],
        ]);

        $integration = $this->makeIntegration(storedState: 'matching-state');

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('saveEntity');

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'success'"));

        $this->makeCallbackWithFakeExchange()->run($this->request, $this->response);
    }

    public function testClearsOauthStateOnSuccess(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['realmId', 'realm-123'],
            ['state', 'matching-state'],
        ]);

        $integration = $this->makeIntegration(storedState: 'matching-state');

        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration
            ->method('set')
            ->willReturnCallback(function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            });

        $this->makeCallbackWithFakeExchange()->run($this->request, $this->response);

        $this->assertArrayHasKey('oauthState', $setCalls);
        $this->assertNull($setCalls['oauthState']);
    }

    public function testStoresTokenFieldsOnSuccess(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['realmId', 'realm-456'],
            ['state', 'matching-state'],
        ]);

        $integration = $this->makeIntegration(storedState: 'matching-state');

        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration
            ->method('set')
            ->willReturnCallback(function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            });

        $this->makeCallbackWithFakeExchange()->run($this->request, $this->response);

        $this->assertSame(self::FAKE_TOKENS['access_token'], $setCalls['accessToken'] ?? null);
        $this->assertSame(self::FAKE_TOKENS['refresh_token'], $setCalls['refreshToken'] ?? null);
        $this->assertSame('realm-456', $setCalls['realmId'] ?? null);
        $this->assertArrayHasKey('connectedAt', $setCalls);
        $this->assertNotEmpty($setCalls['connectedAt']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCallback(): QuickBooksOauthCallback
    {
        return new QuickBooksOauthCallback($this->em, $this->config, $this->log);
    }

    private function makeCallbackWithFakeExchange(): QuickBooksOauthCallback
    {
        $callback = $this->getMockBuilder(QuickBooksOauthCallback::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['exchangeCodeForTokens'])
            ->getMock();

        $callback
            ->method('exchangeCodeForTokens')
            ->willReturn(self::FAKE_TOKENS);

        return $callback;
    }

    private function makeIntegration(?string $storedState): Integration
    {
        $integration = $this->createMock(Integration::class);

        $integration
            ->method('get')
            ->willReturnMap([
                ['oauthState', $storedState],
            ]);

        return $integration;
    }
}
