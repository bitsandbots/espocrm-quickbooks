<?php

namespace Espo\Modules\QuickBooks\Entities;

use Espo\Core\ORM\Entity;

class Invoice extends Entity
{
    public const ENTITY_TYPE = 'Invoice';

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SENT = 'Sent';
    public const STATUS_PAID = 'Paid';
    public const STATUS_OVERDUE = 'Overdue';
    public const STATUS_VOIDED = 'Voided';
}
