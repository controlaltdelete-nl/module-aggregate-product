<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Plugin\Admin;

use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoader;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

/**
 * Keep the refund qty of an aggregate the merchant asked for in the admin credit memo form.
 *
 * Between loading the credit memo and refunding it, the admin's credit memo save replaces the qty
 * of every parent item with the sum of its children's qtys, skipping only bundles. Each aggregate
 * child carries the parent's qty, so a pack with two children refunds double, and the refund is
 * rejected as an invalid quantity. That step is private to the controller, so the qty is recorded
 * when the credit memo is loaded and put back right before the refund.
 */
class KeepAggregateRefundQty
{
    private const REQUESTED_QTY = 'aggregate_requested_refund_qty';

    /**
     * @param CreditmemoLoader $subject
     * @param Creditmemo|false $result
     * @return Creditmemo|false
     */
    public function afterLoad(CreditmemoLoader $subject, $result)
    {
        if ($result instanceof Creditmemo) {
            array_map(
                static fn (Item $item) => $item->setData(self::REQUESTED_QTY, $item->getQty()),
                $this->getAggregateItems($result)
            );
        }

        return $result;
    }

    public function beforeRefund(
        CreditmemoManagementInterface $subject,
        CreditmemoInterface $creditmemo,
        $offlineRequested = false
    ): null {
        if ($creditmemo instanceof Creditmemo) {
            array_map(
                static fn (Item $item) => $item->setQty($item->getData(self::REQUESTED_QTY) ?? $item->getQty()),
                $this->getAggregateItems($creditmemo)
            );
        }

        return null;
    }

    /**
     * @param Creditmemo $creditmemo
     * @return Item[]
     */
    private function getAggregateItems(Creditmemo $creditmemo): array
    {
        return array_filter(
            $creditmemo->getAllItems(),
            static fn (Item $item) => $item->getOrderItem()->getProductType() === Aggregate::TYPE_CODE
        );
    }
}
