<?php

namespace Espo\Modules\QuickBooks\Hooks\Invoice;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\QuickBooks\Services\QuickBooksService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

use Throwable;

/**
 * @implements AfterSave<\Espo\Modules\QuickBooks\Entities\Invoice>
 */
class Sync implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private InjectableFactory $injectableFactory,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('skipQuickBooksSync')) {
            return;
        }

        $service = $this->injectableFactory->create(QuickBooksService::class);

        if ($entity->get('status') === 'Voided') {
            if (!$entity->get('qbInvoiceId')) {
                return;
            }

            try {
                $service->voidInvoice($entity);
            } catch (Throwable $e) {
                $this->log->warning(
                    "QuickBooks Invoice void failed for '{$entity->getId()}': " . $e->getMessage()
                );
            }

            return;
        }

        try {
            $service->upsertInvoice($entity);
        } catch (Throwable $e) {
            $this->log->warning(
                "QuickBooks Invoice sync failed for '{$entity->getId()}': " . $e->getMessage()
            );
        }
    }
}
