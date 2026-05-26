<?php

namespace Espo\Modules\QuickBooks\Jobs;

use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\QuickBooks\Services\QuickBooksService;
use Espo\ORM\EntityManager;

use DateTime;
use Throwable;

/**
 * Pulls updated Customers and Payments from QuickBooks into EspoCRM.
 * Scheduled daily (or configurable interval).
 */
class SyncFromQuickBooks implements JobDataLess
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function run(): void
    {
        /** @var ?Integration $integration */
        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'QuickBooks');

        if (!$integration || !$integration->isEnabled()) {
            return;
        }

        $service = $this->injectableFactory->create(QuickBooksService::class);

        $lastSyncAt = $integration->get('lastSyncAt');
        $sinceDate = $lastSyncAt
            ? (new DateTime($lastSyncAt))->format('Y-m-d')
            : (new DateTime())->modify('-7 days')->format('Y-m-d');

        $errors = [];

        try {
            $service->pullCustomersSince($sinceDate);
        } catch (Throwable $e) {
            $this->log->error("QuickBooks SyncFromQuickBooks (customers): " . $e->getMessage());
            $errors[] = "Customers: " . $e->getMessage();
        }

        try {
            $service->pullPaymentsSince($sinceDate);
        } catch (Throwable $e) {
            $this->log->error("QuickBooks SyncFromQuickBooks (payments): " . $e->getMessage());
            $errors[] = "Payments: " . $e->getMessage();
        }

        $now = (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);
        $integration->set('lastSyncAt', $now);
        $integration->set('lastSyncError', empty($errors) ? null : implode('; ', $errors));

        $this->entityManager->saveEntity($integration);

        $this->log->info("QuickBooks SyncFromQuickBooks completed (since $sinceDate).");
    }
}
