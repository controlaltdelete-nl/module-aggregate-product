<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\Inventory;

use Magento\Catalog\Api\Data\ProductInterface as Product;
use Magento\CatalogInventory\Observer\ParentItemProcessorInterface;

class ParentItemProcessor implements ParentItemProcessorInterface
{
    public function __construct(
        private readonly ChangeParentStockStatus $changeParentStockStatus
    ) {
    }

    public function process(Product $product): void
    {
        $this->changeParentStockStatus->execute([(int)$product->getId()]);
    }
}
