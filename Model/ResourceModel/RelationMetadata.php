<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product\Relation as CatalogRelation;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Model\RelationMetadata as Model;

class RelationMetadata extends AbstractDb implements ResetAfterRequestInterface
{
    /** @var array<int, array<int, float>> parentId => [childId => qty] */
    private array $childrenIdsWithQtyCache = [];

    public function __construct(
        Context $context,
        private readonly CatalogRelation $catalogRelation,
        $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    protected function _construct()
    {
        $this->_init('catalog_product_aggregate_link', 'link_id');
    }

    /**
     * Invalidate the per-request memoization after writes so child membership reflects the new state.
     */
    public function clearCache(): void
    {
        $this->childrenIdsWithQtyCache = [];
    }

    public function _resetState(): void
    {
        $this->clearCache();
    }

    /**
     * @param int $parentId
     * @return int[]
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getChildrenIds(int $parentId): array
    {
        $connection = $this->getConnection();
        if (!$connection) {
            throw new RuntimeException(__('No connection is defined.'));
        }

        $select = $connection
            ->select()
            ->from(['link' => $this->getMainTable()], ['product_id'])
            ->join(
                ['cpe' => $this->getTable('catalog_product_entity')],
                'cpe.entity_id = link.product_id AND cpe.required_options = 0',
                []
            )
            ->where('link.parent_id = ?', $parentId)
            ->order('link.link_id ASC');

        return array_map(
            static function ($row) {
                return (int)$row;
            },
            $connection->fetchCol($select)
        );
    }

    /**
     * @param int $parentId
     * @return array<int, float> product_id => qty
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getChildrenIdsWithQty(int $parentId): array
    {
        if (isset($this->childrenIdsWithQtyCache[$parentId])) {
            return $this->childrenIdsWithQtyCache[$parentId];
        }

        $connection = $this->getConnection();
        if (!$connection) {
            throw new RuntimeException(__('No connection is defined.'));
        }

        $select = $connection
            ->select()
            ->from(['link' => $this->getMainTable()], ['product_id', 'qty'])
            ->join(
                ['cpe' => $this->getTable('catalog_product_entity')],
                'cpe.entity_id = link.product_id AND cpe.required_options = 0',
                []
            )
            ->where('link.parent_id = ?', $parentId)
            ->order('link.link_id ASC');

        $result = [];
        foreach ($connection->fetchPairs($select) as $productId => $qty) {
            $result[(int)$productId] = (float)$qty;
        }

        return $this->childrenIdsWithQtyCache[$parentId] = $result;
    }

    /**
     * @param int $childId
     * @return int[]
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getParentIdsByChild(int $childId): array
    {
        $connection = $this->getConnection();
        if (!$connection) {
            throw new RuntimeException(__('No connection is defined.'));
        }

        $select = $connection
            ->select()
            ->from(['link' => $this->getMainTable()], ['parent_id'])
            ->where('link.product_id IN (?)', $childId);

        return array_map(
            static function ($row) {
                return (int)$row;
            },
            $connection->fetchCol($select)
        );
    }

    /**
     * Distinct parent ids bundling any of the given children. Batch form of getParentIdsByChild
     * so a shared-child stock flip across many parents costs one query, not one per child.
     *
     * @param int[] $childIds
     * @return int[]
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getParentIdsByChildren(array $childIds): array
    {
        $childIds = array_values(array_unique(array_map('intval', $childIds)));
        if (empty($childIds)) {
            return [];
        }

        $connection = $this->getConnection();
        if (!$connection) {
            throw new RuntimeException(__('No connection is defined.'));
        }

        $select = $connection
            ->select()
            ->distinct()
            ->from(['link' => $this->getMainTable()], ['parent_id'])
            ->where('link.product_id IN (?)', $childIds);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Children-with-qty for many parents in one query (batch form of getChildrenIdsWithQty).
     * Warms the per-parent memoization so later single lookups don't re-query.
     *
     * @param int[] $parentIds
     * @return array<int, array<int, float>> parentId => [childId => qty]
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getChildrenIdsWithQtyForParents(array $parentIds): array
    {
        $parentIds = array_values(array_unique(array_map('intval', $parentIds)));
        if (empty($parentIds)) {
            return [];
        }

        $connection = $this->getConnection();
        if (!$connection) {
            throw new RuntimeException(__('No connection is defined.'));
        }

        $select = $connection
            ->select()
            ->from(['link' => $this->getMainTable()], ['parent_id', 'product_id', 'qty'])
            ->join(
                ['cpe' => $this->getTable('catalog_product_entity')],
                'cpe.entity_id = link.product_id AND cpe.required_options = 0',
                []
            )
            ->where('link.parent_id IN (?)', $parentIds)
            ->order('link.link_id ASC');

        $result = array_fill_keys($parentIds, []);
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['parent_id']][(int)$row['product_id']] = (float)$row['qty'];
        }

        foreach ($result as $parentId => $children) {
            $this->childrenIdsWithQtyCache[$parentId] = $children;
        }

        return $result;
    }

    /**
     * @param RelationMetadataInterface[] $relationMetadata
     */
    public function saveAggregateLinks(array $relationMetadata): void
    {
        $connection = $this->getConnection();
        if (!$connection) {
            throw new RuntimeException(__('No connection is defined.'));
        }

        $insertData = [];
        $childIdsByParent = [];
        /** @var Model $metadata */
        foreach ($relationMetadata as $metadata) {
            // Intentionally omit link_id: upsert on the (product_id, parent_id) unique key so a new
            // row gets a fresh identity while an existing pair keeps its link_id (only qty changes).
            // Including link_id here would let callers that didn't carry it churn the stored id.
            $insertData[] = [
                RelationMetadataInterface::PRODUCT_ID => $metadata->getProductId(),
                RelationMetadataInterface::PARENT_ID => $metadata->getParentId(),
                RelationMetadataInterface::QTY => (float)$metadata->getQty()
            ];
            $childIdsByParent[(int)$metadata->getParentId()][] = (int)$metadata->getProductId();
        }

        if (empty($insertData)) {
            return;
        }

        $connection->insertOnDuplicate($this->getMainTable(), $insertData, [RelationMetadataInterface::QTY]);
        $this->syncCatalogRelations($childIdsByParent, false);
        $this->clearCache();
    }

    /**
     * @param RelationMetadataInterface[] $relationMetadata
     */
    public function deleteAggregateLinks(array $relationMetadata): void
    {
        $connection = $this->getConnection();
        if (!$connection) {
            throw new RuntimeException(__('No connection is defined.'));
        }

        $conditions = [];
        $childIdsByParent = [];
        foreach ($relationMetadata as $metadata) {
            $childCondition = $connection->quoteInto(
                RelationMetadataInterface::PRODUCT_ID . ' = ?',
                $metadata->getProductId()
            );
            $parentCondition = $connection->quoteInto(
                RelationMetadataInterface::PARENT_ID . ' = ?',
                $metadata->getParentId()
            );

            $conditions[] = '(' . $childCondition . ' AND ' . $parentCondition . ')';
            $childIdsByParent[(int)$metadata->getParentId()][] = (int)$metadata->getProductId();
        }

        // Guard against an empty WHERE because delete() with an empty condition truncates the whole table.
        if (empty($conditions)) {
            return;
        }

        $connection->delete($this->getMainTable(), implode(' OR ', $conditions));
        $this->syncCatalogRelations($childIdsByParent, true);
        $this->clearCache();
    }

    /**
     * Remove every aggregate link for a parent plus its mirrored catalog_product_relation rows.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteByParentId(int $parentId): void
    {
        $connection = $this->getConnection();
        if (!$connection) {
            throw new RuntimeException(__('No connection is defined.'));
        }

        $childIds = array_map(
            'intval',
            $connection->fetchCol(
                $connection->select()
                    ->from($this->getMainTable(), ['product_id'])
                    ->where('parent_id = ?', $parentId)
            )
        );

        $connection->delete(
            $this->getMainTable(),
            $connection->quoteInto('parent_id = ?', $parentId)
        );

        if (!empty($childIds)) {
            $this->syncCatalogRelations([$parentId => $childIds], true);
        }

        $this->clearCache();
    }

    /**
     * @param int[] $productIds
     * @return array<int, string> entity_id => type_id
     */
    public function getProductTypesByIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (empty($productIds)) {
            return [];
        }

        $connection = $this->getConnection();
        $select = $connection
            ->select()
            ->from(['cpe' => $this->getTable('catalog_product_entity')], ['entity_id', 'type_id'])
            ->where('cpe.entity_id IN (?)', $productIds);

        $result = [];
        foreach ($connection->fetchPairs($select) as $id => $type) {
            $result[(int)$id] = (string)$type;
        }

        return $result;
    }

    /**
     * Mirror aggregate links into the native catalog_product_relation table so that
     * aggregate parents are included in core's partial reindex + cache-invalidation paths
     *
     * @param array<int, int[]> $childIdsByParent parentEntityId => childEntityIds
     */
    private function syncCatalogRelations(array $childIdsByParent, bool $remove): void
    {
        foreach ($childIdsByParent as $parentEntityId => $childIds) {
            if ($remove) {
                $this->catalogRelation->removeRelations((int)$parentEntityId, $childIds);
                continue;
            }

            foreach ($childIds as $childId) {
                $this->catalogRelation->addRelation((int)$parentEntityId, (int)$childId);
            }
        }
    }
}
