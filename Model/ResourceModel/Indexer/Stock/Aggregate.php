<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\ResourceModel\Indexer\Stock;

use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher;
use Magento\CatalogInventory\Model\Configuration;
use Magento\CatalogInventory\Model\Indexer\Stock\Action\Full;
use Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\DefaultStock;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\Table\StrategyInterface;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Store\Model\ScopeInterface;
use Zend_Db;
use Zend_Db_Expr;

/**
 * Stock indexer for aggregate product type
 *
 * Aggregate products are in stock only when ALL children are in stock.
 */
class Aggregate extends DefaultStock
{
    private ActiveTableSwitcher $activeTableSwitcher;

    public function __construct(
        Context $context,
        StrategyInterface $tableStrategy,
        Config $eavConfig,
        ScopeConfigInterface $scopeConfig,
        ActiveTableSwitcher $activeTableSwitcher,
        $connectionName = null
    ) {
        parent::__construct($context, $tableStrategy, $eavConfig, $scopeConfig, $connectionName);
        $this->activeTableSwitcher = $activeTableSwitcher;
    }

    /**
     * Parent is in stock only when every child is enabled, in stock, and holds the link qty.
     *
     * An aggregate with no children indexes as out of stock.
     *
     * @param int|array|null $entityIds
     * @param bool $usePrimaryTable use primary or temporary index table
     * @return Select
     */
    protected function _getStockStatusSelect($entityIds = null, $usePrimaryTable = false)
    {
        $connection = $this->getConnection();
        $table = $this->getActionType() === Full::ACTION_TYPE
            ? $this->activeTableSwitcher->getAdditionalTableName($this->getMainTable())
            : $this->getMainTable();
        $idxTable = $usePrimaryTable ? $table : $this->getIdxTable();

        // Start with parent select from DefaultStock
        $select = parent::_getStockStatusSelect($entityIds, $usePrimaryTable);

        $select->reset(Select::COLUMNS);
        $select->columns(['e.entity_id', 'cis.website_id', 'cis.stock_id']);

        $select->joinLeft(
            ['agg_link' => $this->getTable('catalog_product_aggregate_link')],
            'e.entity_id = agg_link.parent_id',
            []
        );

        // Exclude children with required custom options; they are not eligible aggregate members.
        $select->joinLeft(
            ['child_entity' => $this->getTable('catalog_product_entity')],
            'child_entity.entity_id = agg_link.product_id AND child_entity.required_options = 0',
            []
        );

        $statusAttributeId = $this->_getAttribute('status')->getId();
        $select->joinLeft(
            ['child_status' => $this->getTable('catalog_product_entity_int')],
            'child_entity.entity_id = child_status.entity_id'
            . ' AND child_status.attribute_id = ' . (int)$statusAttributeId
            . ' AND child_status.store_id = 0',
            []
        );

        $select->joinLeft(
            ['child_stock' => $idxTable],
            'child_stock.product_id = agg_link.product_id'
            . ' AND child_stock.website_id = cis.website_id'
            . ' AND child_stock.stock_id = cis.stock_id',
            []
        );

        // Child's own stock item: needed to honor manage_stock / backorders the same way
        // DefaultStock does, so a non-managed or backordered child is not forced OOS purely
        // because its indexed qty is 0 (manage_stock=0 indexes qty 0) or negative (backorders).
        $select->joinLeft(
            ['child_cisi' => $this->getTable('cataloginventory_stock_item')],
            'child_cisi.product_id = agg_link.product_id AND child_cisi.stock_id = cis.stock_id',
            []
        );

        // Aggregates have no quantity of their own.
        $select->columns(['qty' => new Zend_Db_Expr('0')]);

        // Effective manage_stock / backorders for the child: per-item override when
        // use_config_* = 0, else the global cataloginventory default. NULL (no stock item row)
        // falls through to the global default.
        $globalManageStock = (int)$this->_scopeConfig->isSetFlag(
            Configuration::XML_PATH_MANAGE_STOCK,
            ScopeInterface::SCOPE_STORE
        );
        $globalBackorders = (int)$this->_scopeConfig->getValue(
            Configuration::XML_PATH_BACKORDERS,
            ScopeInterface::SCOPE_STORE
        );
        $effManageStock = "IF(child_cisi.use_config_manage_stock = 0, child_cisi.manage_stock, {$globalManageStock})";
        $effBackorders = "IF(child_cisi.use_config_backorders = 0, child_cisi.backorders, {$globalBackorders})";

        // Child is considered in stock only if:
        // 1. Child status is enabled
        // 2. Child stock status is in stock
        // 3. The configured qty is available — but skip that qty gate entirely when the child
        //    doesn't manage stock or allows backorders (it can satisfy any required qty).
        $childStockExpr = $connection->getCheckSql(
            'child_status.value = ' . ProductStatus::STATUS_ENABLED
            . ' AND COALESCE(child_stock.stock_status, 0) = 1'
            . ' AND (' . $effManageStock . ' = 0'
            . ' OR ' . $effBackorders . ' > 0'
            . ' OR COALESCE(child_stock.qty, 0) >= agg_link.qty)',
            '1',
            '0'
        );

        // Honor an explicit admin out-of-stock override on the parent's own stock item.
        // An aggregate has no real quantity of its own, so we must NOT apply DefaultStock's
        // qty-vs-min_qty gate to the parent (that would force every aggregate OOS since its
        // cisi.qty is always 0). Only the parent's is_in_stock flag is authoritative; cisi is
        // inner-joined by the parent select so the column is always present.
        $parentOwnStatus = 'COALESCE(cisi.is_in_stock, 1)';

        // Aggregate is in stock only when ALL children are in stock AND the parent's own stock
        // status allows it. MIN returns 0 if any child is out of stock; LEAST ANDs the parent's
        // own status. Aggregates with no children evaluate to out of stock.
        $statusExpr = $connection->getCheckSql(
            'COUNT(agg_link.product_id) > 0',
            "LEAST(MIN({$childStockExpr}), {$parentOwnStatus})",
            '0'
        );

        $select->columns(['status' => $statusExpr]);

        if ($entityIds !== null) {
            $select->where('e.entity_id IN(?)', $entityIds, Zend_Db::INT_TYPE);
        }

        return $select;
    }
}
