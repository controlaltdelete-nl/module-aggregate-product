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
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata;

/**
 * The FK cascade that removes a deleted child's link rows never fires the mview triggers, so
 * affected parent ids must be captured while the links still exist; the post-commit observer
 * performs the actual invalidation.
 */
class CaptureParentIdsOnProductDelete implements ObserverInterface
{
    public function __construct(
        private readonly RelationMetadata $relationMetadataResource,
        private readonly PendingParentInvalidations $pendingParentInvalidations
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

        $parentIds = $this->relationMetadataResource->getParentIdsByChildren([$productId]);
        if (!empty($parentIds)) {
            $this->pendingParentInvalidations->set($productId, $parentIds);
        }
    }
}
