<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Plugin;

use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Api\Data\OrderItemInterface;

class SetQuoteItemOptionsOnOrderItem
{
    public function afterConvert(
        ToOrderItem $subject,
        OrderItemInterface $result,
        AbstractItem $item,
        $data = []
    ): OrderItemInterface {
        if ($config = $item->getProduct()->getCustomOption('aggregate_config')) {
            $productOptions = $result->getProductOptions();
            $productOptions['aggregate_config'] = $config->getValue();
            $result->setProductOptions($productOptions);
        }

        return $result;
    }
}