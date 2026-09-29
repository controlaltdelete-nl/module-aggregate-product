<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\Product;

use Magento\Catalog\Model\ProductTypes\ConfigInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

class AllowedChildTypes
{
    public function __construct(
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * @return string[]
     */
    public function get(): array
    {
        $configData = $this->config->getType(Aggregate::TYPE_CODE);

        return array_values($configData['allowed_selection_types'] ?? []);
    }
}
