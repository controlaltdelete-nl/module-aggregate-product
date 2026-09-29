<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Helper\Product;

use Magento\Catalog\Helper\Product\Configuration\ConfigurationInterface;
use Magento\Catalog\Model\Product\Configuration\Item\ItemInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Nfourteen\AggregateProduct\Api\Data\LinkedProductInterface;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Service\LinkedProductFormatter;

class Configuration extends AbstractHelper implements ConfigurationInterface
{
    public function __construct(
        Context $context,
        private readonly LinkedProductProviderInterface $linkedProductProvider,
        private readonly LinkedProductFormatter $linkedProductFormatter,
        private readonly SerializerInterface $json
    ) {
        parent::__construct($context);
    }

    public function getOptions(ItemInterface $item)
    {
        $aggregateProduct = $item->getProduct();
        $productOptions = [];

        try {
            $linkedProducts = $this->linkedProductProvider->getForProduct(
                (int)$aggregateProduct->getId()
            );

            if (!empty($linkedProducts)) {
                $productOptions[] = ['label' => __('Includes'), 'value' => []];
                foreach ($linkedProducts as $linkedProduct) {
                    $productOptions[0]['value'][] = $this->formatValue($linkedProduct);
                }

                $item->addOption(
                    new DataObject(
                        [
                            'product' => $item->getProduct(),
                            'code' => 'additional_options',
                            'value' => $this->json->serialize($productOptions)
                        ]
                    )
                );
            }
        } catch (NoSuchEntityException $e) {
            // A missing relation set is not an error here; the item simply renders no "Includes" list.
        }

        return $productOptions;
    }

    private function formatValue(LinkedProductInterface $linkedProduct): string
    {
        return $this->linkedProductFormatter->format($linkedProduct);
    }
}
