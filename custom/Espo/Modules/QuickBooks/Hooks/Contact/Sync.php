<?php

namespace Espo\Modules\QuickBooks\Hooks\Contact;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\QuickBooks\Services\QuickBooksService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

use Throwable;

/**
 * @implements AfterSave<\Espo\Modules\Crm\Entities\Contact>
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

        try {
            $service = $this->injectableFactory->create(QuickBooksService::class);
            $service->upsertCustomer('Contact', $entity);
        } catch (Throwable $e) {
            $this->log->warning("QuickBooks Contact sync failed for '{$entity->getId()}': " . $e->getMessage());
        }
    }
}
