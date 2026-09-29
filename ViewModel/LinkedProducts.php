<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Service\LinkedProductFormatter;

class LinkedProducts implements ArgumentInterface
{
    public function __construct(
        private readonly LinkedProductProviderInterface $linkedProductProvider,
        private readonly LinkedProductFormatter $linkedProductFormatter
    ) {
    }

    /**
     * @param ProductInterface $product
     * @return DataObject[]
     */
    public function get(ProductInterface $product): array
    {
        if ($product->getTypeId() !== Aggregate::TYPE_CODE) {
            return [];
        }

        try {
            $linkedProducts = $this->linkedProductProvider->getForProduct(
                (int)$product->getId()
            );
        } catch (NoSuchEntityException $e) {
            return [];
        }

        $result = [];
        foreach ($linkedProducts as $linkedProduct) {
            $result[] = new DataObject([
                'name' => $linkedProduct->getProductName(),
                'qty' => $linkedProduct->getQty()
            ]);
        }

        return $result;
    }

    public function getValueHtml(float $qty, string $name): string
    {
        return $this->linkedProductFormatter->formatFromData($qty, $name);
    }
}
