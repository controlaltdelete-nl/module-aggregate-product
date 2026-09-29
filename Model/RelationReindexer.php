<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model;

use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexProcessor;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexProcessor;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Triggers the parent stock/price reindex after an aggregate relation write (add/remove/qty
 * change); nothing native starts one because a relation change saves no stock item and
 * touches no core-subscribed table. In scheduled mode the mview subscriptions on
 * catalog_product_aggregate_link carry the reindex and the processors below no-op; in on-save
 * mode the reindexList() calls here take over. No cache work happens here: core's CacheCleaner
 * plugin on the indexer action flushes the affected FPC tags after whichever reindex runs.
 * This is also the seam MSI hooks to drop its salability memo. Every write path (repository
 * save/delete, import) funnels through here.
 */
class RelationReindexer
{
    public function __construct(
        private readonly StockIndexProcessor $stockIndexProcessor,
        private readonly PriceIndexProcessor $priceIndexProcessor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int[] $parentIds aggregate parent entity ids whose relations changed
     * @param bool $forceReindex bypass the scheduled-mode skip when mview cannot see the change
     *     (FK-cascaded link deletes never fire the subscription triggers)
     */
    public function reindex(array $parentIds, bool $forceReindex = false): void
    {
        $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds))));
        if (empty($parentIds)) {
            return;
        }

        try {
            $this->stockIndexProcessor->reindexList($parentIds, $forceReindex);
            $this->priceIndexProcessor->reindexList($parentIds, $forceReindex);
        } catch (Throwable $e) {
            $this->logger->error('Aggregate relation reindex failed', [
                'parent_ids' => $parentIds,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
