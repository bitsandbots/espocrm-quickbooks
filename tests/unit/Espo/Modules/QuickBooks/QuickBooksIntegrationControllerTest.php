<?php

namespace tests\unit\Espo\Modules\QuickBooks;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Entities\Integration;
use Espo\Entities\User;
use Espo\Modules\QuickBooks\Controllers\QuickBooksIntegration;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class QuickBooksIntegrationControllerTest extends TestCase
{
    private EntityManager $em;
    private InjectableFactory $injectableFactory;
    private User $user;
    private Request $request;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->injectableFactory = $this->createMock(InjectableFactory::class);
        $this->user = $this->createMock(User::class);
        $this->request = $this->createMock(Request::class);
    }

    public function testThrowsForbiddenForNonAdmin(): void
    {
        $this->user->method('isAdmin')->willReturn(false);

        $this->expectException(Forbidden::class);

        new QuickBooksIntegration($this->em, $this->injectableFactory, $this->user);
    }

    public function testReturnsStateWithCorrectFormat(): void
    {
        $this->user->method('isAdmin')->willReturn(true);

        $integration = $this->createMock(Integration::class);

        $this->em
            ->method('getEntityById')
            ->with(Integration::ENTITY_TYPE, 'QuickBooks')
            ->willReturn($integration);

        $controller = new QuickBooksIntegration($this->em, $this->injectableFactory, $this->user);
        $result = $controller->postActionInitOAuth($this->request);

        $this->assertObjectHasProperty('state', $result);
        $this->assertIsString($result->state);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->state);
    }

    public function testStateIsPersistedToIntegration(): void
    {
        $this->user->method('isAdmin')->willReturn(true);

        $integration = $this->createMock(Integration::class);

        $this->em
            ->method('getEntityById')
            ->with(Integration::ENTITY_TYPE, 'QuickBooks')
            ->willReturn($integration);

        $integration
            ->expects($this->once())
            ->method('set')
            ->with('oauthState', $this->matchesRegularExpression('/^[0-9a-f]{32}$/'));

        $this->em
            ->expects($this->once())
            ->method('saveEntity')
            ->with($integration);

        $controller = new QuickBooksIntegration($this->em, $this->injectableFactory, $this->user);
        $controller->postActionInitOAuth($this->request);
    }

    public function testReturnedStateMatchesStoredState(): void
    {
        $this->user->method('isAdmin')->willReturn(true);

        $integration = $this->createMock(Integration::class);

        $this->em
            ->method('getEntityById')
            ->willReturn($integration);

        $storedState = null;
        $integration
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$storedState, $integration) {
                if ($key === 'oauthState') {
                    $storedState = $value;
                }
                return $integration;
            });

        $controller = new QuickBooksIntegration($this->em, $this->injectableFactory, $this->user);
        $result = $controller->postActionInitOAuth($this->request);

        $this->assertSame($storedState, $result->state);
    }

    public function testThrowsErrorWhenIntegrationNotFound(): void
    {
        $this->user->method('isAdmin')->willReturn(true);

        $this->em
            ->method('getEntityById')
            ->with(Integration::ENTITY_TYPE, 'QuickBooks')
            ->willReturn(null);

        $controller = new QuickBooksIntegration($this->em, $this->injectableFactory, $this->user);

        $this->expectException(Error::class);

        $controller->postActionInitOAuth($this->request);
    }
}
