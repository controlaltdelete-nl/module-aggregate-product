<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Fixture;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\DataObject;
use Magento\Indexer\Model\IndexerFactory;
use Magento\TestFramework\Fixture\Api\DataMerger;
use Magento\TestFramework\Fixture\Api\ServiceFactory;
use Magento\TestFramework\Fixture\Data\ProcessorInterface;
use Magento\TestFramework\Fixture\RevertibleDataFixtureInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Model\RelationMetadataFactory;
use Nfourteen\AggregateProduct\Model\RelationMetadataReconciler;

class AggregateProduct implements RevertibleDataFixtureInterface
{
    private const DEFAULT_DATA = [
        'type_id' => Aggregate::TYPE_CODE,
        'attribute_set_id' => 4,
        'name' => 'Aggregate Product%uniqid%',
        'sku' => 'aggregate-product%uniqid%',
        'price' => 10,
        'visibility' => Visibility::VISIBILITY_BOTH,
        'status' => Status::STATUS_ENABLED,
        'custom_attributes' => [
            'tax_class_id' => '2',
        ],
        'extension_attributes' => [
            'website_ids' => [1],
            'stock_item' => [
                'use_config_manage_stock' => false,
                'manage_stock' => true,
                'is_in_stock' => true,
            ],
        ],
        '_children' => [],
    ];

    public function __construct(
        private readonly ServiceFactory $serviceFactory,
        private readonly ProcessorInterface $dataProcessor,
        private readonly DataMerger $dataMerger,
        private readonly RelationMetadataFactory $relationMetadataFactory,
        private readonly RelationMetadataReconciler $reconciler,
        private readonly IndexerFactory $indexerFactory
    ) {
    }

    public function apply(array $data = []): ?DataObject
    {
        $data = $this->dataMerger->merge(self::DEFAULT_DATA, $data);
        $children = $data['_children'];
        unset($data['_children']);

        $data = $this->dataProcessor->process($this, $data);

        $service = $this->serviceFactory->create(ProductRepositoryInterface::class, 'save');
        $product = $service->execute(['product' => $data]);

        if (!empty($children)) {
            $this->createRelationLinks((int)$product->getId(), $children);
        }

        $this->indexerFactory->create()->load('cataloginventory_stock')->reindexRow((int)$product->getId());

        return $product;
    }

    public function revert(DataObject $data): void
    {
        $service = $this->serviceFactory->create(ProductRepositoryInterface::class, 'deleteById');
        $service->execute(['sku' => $data->getSku()]);
    }

    private function createRelationLinks(int $parentId, array $children): void
    {
        $links = [];
        foreach ($children as $child) {
            $metadata = $this->relationMetadataFactory->create();
            $metadata->setData([
                'parent_id' => $parentId,
                'product_id' => $child['product_id'],
                'qty' => $child['qty'] ?? 1,
            ]);
            $links[] = $metadata;
        }

        $this->reconciler->reconcile($parentId, $links);
    }
}
