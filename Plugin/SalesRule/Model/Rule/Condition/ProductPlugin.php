<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Plugin\SalesRule\Model\Rule\Condition;

use Magento\Framework\Model\AbstractModel;
use Magento\SalesRule\Model\Rule\Condition\Product as ProductCondition;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

/**
 * Keep hidden aggregate child rows invisible to cart price rules.
 */
class ProductPlugin
{
    /**
     * SalesRule validates a composite parent against each of its children
     * (Combine::retrieveValidateEntities), so a rule conditioned on a child SKU/category would
     * leak its discount onto the parent. Aggregate children are zero-priced dummy lines, so
     * short-circuit the match and let only the parent's own attributes count.
     *
     * @param ProductCondition $subject
     * @param callable $proceed
     * @param AbstractModel $model
     * @return bool
     */
    public function aroundValidate(
        ProductCondition $subject,
        callable $proceed,
        AbstractModel $model
    ): bool {
        $parentItem = method_exists($model, 'getParentItem') ? $model->getParentItem() : null;
        if ($parentItem && $parentItem->getProductType() === Aggregate::TYPE_CODE) {
            return false;
        }

        return $proceed($model);
    }
}
