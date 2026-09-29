<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Nfourteen\AggregateProduct\Model\PendingParentInvalidations;
use Nfourteen\AggregateProduct\Model\RelationReindexer;

/**
 * Runs after commit so the reindex reads the post-delete link state. Reindex is forced because
 * the mview subscriptions never see FK-cascaded link deletes, leaving scheduled mode stale.
 */
class InvalidateParentsOnProductDeleteCommit implements ObserverInterface
{
    public function __construct(
        private readonly PendingParentInvalidations $pendingParentInvalidations,
        private readonly RelationReindexer $relationReindexer
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $productId = (int)$observer->getEvent()->getProduct()->getId();
        if (!$productId) {
            return;
        }

        $parentIds = $this->pendingParentInvalidations->take($productId);
        if (!empty($parentIds)) {
            $this->relationReindexer->reindex($parentIds, true);
        }
    }
}
