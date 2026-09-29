<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\ViewModel\Sales\Order\Items;

use InvalidArgumentException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Nfourteen\AggregateProduct\Service\LinkedProductFormatter;

class Renderer implements ArgumentInterface
{
    public function __construct(
        private readonly LinkedProductFormatter $linkedProductFormatter,
        private readonly SerializerInterface $json
    ) {
    }

    public function getOrderItemRowClassName(OrderItemInterface $item): string
    {
        return $item->getParentItem() ? 'item-options-container' : 'item-parent';
    }

    /**
     * Qty of a child to display against a document row: the document item's qty scaled by the
     * per-parent child qty captured in the snapshot. Guards the null/absent snapshot so templates
     * never dereference a missing 'qty'.
     */
    public function getChildDisplayQty($parentItem, $item): float
    {
        $config = $this->getConfigurationData($item);
        $childQty = is_array($config) ? (float)($config['qty'] ?? 0) : 0.0;

        return (float)$parentItem->getQty() * $childQty;
    }

    public function getValueHtml($item): string
    {
        $config = $this->getConfigurationData($item);
        if (is_array($config) && isset($config['qty'])) {
            return $this->linkedProductFormatter->formatFromData(
                (float)$config['qty'],
                $config['name'] ?? $item->getName()
            );
        }

        return $item->getName();
    }

    public function getConfigurationData($item): ?array
    {
        $options = $item instanceof OrderItemInterface
            ? $item->getProductOptions()
            : $item->getOrderItem()->getProductOptions();

        if (!isset($options['aggregate_config'])) {
            return null;
        }

        // Per-child items store aggregate_config as a serialized JSON string ({qty, name}); the
        // parent order item stores it as an already-deserialized array ([{option_id, label,
        // value}]). Accept either rather than TypeError-ing on the array form.
        $config = $options['aggregate_config'];
        if (is_string($config)) {
            try {
                $config = $this->json->unserialize($config);
            } catch (InvalidArgumentException $e) {
                return null;
            }
        }

        return is_array($config) ? $config : null;
    }
}