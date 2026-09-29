<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Model\RelationMetadata as Model;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata as ResourceModel;

/**
 * @method RelationMetadataInterface[] getItems()
 */
class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }

    /**
     * @param int[] $parentProductIds
     * @return $this
     */
    public function addParentProductIdsFilter(array $parentProductIds): self
    {
        if (empty($parentProductIds)) {
            return $this;
        }

        // Stable link_id order so the derived option-array order is deterministic across saves.
        $this->getSelect()
            ->where("main_table.parent_id IN (?)", $parentProductIds)
            ->order('main_table.link_id ASC');
        return $this;
    }
}
