<?php

namespace Espo\Modules\QuickBooks\Tools;

use Espo\ORM\Entity;

/**
 * Determines which side wins a bidirectional sync conflict.
 *
 * Strategy: last-modified-wins. If QB was updated after our last sync,
 * QB wins. Otherwise EspoCRM wins (push to QB).
 */
class ConflictResolver
{
    public const WINNER_QB = 'qb';
    public const WINNER_ESPO = 'espo';
    public const WINNER_NONE = 'none';

    /**
     * @param string|null $qbLastUpdated ISO datetime from QB MetaData.LastUpdatedTime
     * @param string|null $espoSyncedAt  EspoCRM qbSyncedAt field
     */
    public function resolve(?string $qbLastUpdated, ?string $espoSyncedAt): string
    {
        if (!$qbLastUpdated && !$espoSyncedAt) {
            return self::WINNER_NONE;
        }

        if (!$qbLastUpdated) {
            return self::WINNER_ESPO;
        }

        if (!$espoSyncedAt) {
            return self::WINNER_QB;
        }

        $qbTs = strtotime($qbLastUpdated);
        $espoTs = strtotime($espoSyncedAt);

        if ($qbTs === false || $espoTs === false) {
            return self::WINNER_NONE;
        }

        return $qbTs > $espoTs ? self::WINNER_QB : self::WINNER_ESPO;
    }

    /**
     * Returns true if QB is newer than the last EspoCRM sync.
     */
    public function isQbNewer(?string $qbLastUpdated, ?string $espoSyncedAt): bool
    {
        return $this->resolve($qbLastUpdated, $espoSyncedAt) === self::WINNER_QB;
    }
}
