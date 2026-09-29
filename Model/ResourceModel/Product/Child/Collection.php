<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\ResourceModel\Product\Child;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection as MageProductCollection;
use Zend_Db;

class Collection extends MageProductCollection
{
    protected string $_linkTable = 'catalog_product_aggregate_link';
    /** @var int[] */
    private array $productIds = [];

    protected function _construct()
    {
        parent::_construct();
        $this->_linkTable = $this->getTable('catalog_product_aggregate_link');
    }

    protected function _initSelect()
    {
        parent::_initSelect();

        // Bypass automatic stock status filtering for child collections applied in AddStockStatusToCollection plugin.
        // Aggregate products need to display ALL linked children regardless of stock status.
        $this->setFlag('has_stock_status_filter', true);

        // Status is always loaded so getSalableItems() can partition the loaded (UI) set into the
        // process-safe subset without callers re-wiring the select.
        $this->addAttributeToSelect('status');

        // Join without selecting parent_id and group by entity_id so children
        // shared across multiple parents (hot/shared cohort) appear once
        // instead of one row per (parent, child) pair.
        $this->getSelect()->join(
            ['link_table' => $this->_linkTable],
            'link_table.product_id = e.entity_id',
            []
        );

        return $this;
    }

    /**
     * @param int[] $productIds
     * @return $this
     */
    public function setProductsFilter(array $productIds): self
    {
        $this->productIds = $productIds;
        return $this;
    }

    protected function _renderFilters()
    {
        parent::_renderFilters();
        $this->getSelect()
            ->where('link_table.parent_id IN (?)', $this->productIds, Zend_Db::INT_TYPE)
            ->group('e.entity_id');

        return $this;
    }

    /**
     * getItems() returns every linked child so the UI can show disabled ones; process paths use
     * this method so a disabled child never slips through (same rule as ChildStatusResolver).
     * Stock is deliberately not checked — the stock index and MSI salability condition own that.
     *
     * @return Product[]
     */
    public function getEnabledItems(): array
    {
        return $this->getItemsByColumnValue('status', Status::STATUS_ENABLED);
    }

    /**
     * Flat catalog is deprecated; never read this collection from the flat table.
     * @return false
     */
    public function isEnabledFlat()
    {
        return false;
    }
}
