<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Nfourteen\AggregateProduct\Api\Data\LinkedProductInterface;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Api\RelationMetadataRepositoryInterface;
use Nfourteen\AggregateProduct\Model\ResourceModel\Product\Child\Collection as ChildCollection;
use Nfourteen\AggregateProduct\Model\ResourceModel\Product\Child\CollectionFactory as ChildCollectionFactory;
use Psr\Log\LoggerInterface;

class LinkedProductProvider implements LinkedProductProviderInterface
{
    public const ATTRIBUTES = ['name', 'price', 'thumbnail', 'status', 'tax_class_id', 'weight'];

    public function __construct(
        private readonly RelationMetadataRepositoryInterface $relationMetadataRepository,
        private readonly ChildCollectionFactory $childCollectionFactory,
        private readonly LinkedProductFactory $linkedProductFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getForProduct(int $aggregateProductId): array
    {
        $result = $this->getForProducts([$aggregateProductId]);

        if (!isset($result[$aggregateProductId]) || empty($result[$aggregateProductId])) {
            throw new NoSuchEntityException(
                __('Linked products not found for aggregate product with ID "%1"', $aggregateProductId)
            );
        }

        return $result[$aggregateProductId];
    }

    public function getForProducts(array $aggregateProductIds): array
    {
        if (empty($aggregateProductIds)) {
            return [];
        }

        return $this->load(array_values(array_unique($aggregateProductIds)));
    }

    public function getChildCollection(array $aggregateProductIds): ChildCollection
    {
        return $this->childCollectionFactory
            ->create()
            ->setFlag('product_children', true)
            ->setProductsFilter($aggregateProductIds);
    }

    /**
     * @param int[] $productIds
     * @return array<int, LinkedProductInterface[]>
     */
    private function load(array $productIds): array
    {
        try {
            $result = array_fill_keys($productIds, []);

            $relationsByParentId = $this->relationMetadataRepository->getList($productIds);

            $childProducts = $this->loadProducts($productIds);

            foreach ($relationsByParentId as $parentId => $relations) {
                if (empty($relations)) {
                    continue;
                }

                foreach ($relations as $relation) {
                    $childProductId = $relation->getProductId();
                    $product = $childProducts[$childProductId] ?? null;

                    if ($product === null) {
                        continue;
                    }

                    $linkedProduct = $this->linkedProductFactory->create([
                        'relationMetadata' => $relation,
                        'productId' => $childProductId,
                        'productName' => (string) $product->getName(),
                        'productSku' => (string) $product->getSku(),
                        'product' => $product,
                    ]);

                    $result[$parentId][$childProductId] = $linkedProduct;
                }
            }

            return $result;
        } catch (LocalizedException $e) {
            $this->logger->error('Error loading linked products', [
                'product_ids' => $productIds,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param int[] $aggregateProductIds
     * @return array<int, ProductInterface>
     */
    private function loadProducts(array $aggregateProductIds): array
    {
        $collection = $this->getChildCollection($aggregateProductIds);
        $collection->addAttributeToSelect(self::ATTRIBUTES);

        $products = [];
        foreach ($collection->getItems() as $product) {
            $products[(int) $product->getId()] = $product;
        }

        return $products;
    }
}
