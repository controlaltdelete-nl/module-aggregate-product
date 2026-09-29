<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model;

use Exception;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Nfourteen\AggregateProduct\Api\RelationMetadataRepositoryInterface;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata as RelationMetadataResource;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata\CollectionFactory as RelationMetadataCollectionFactory;
use Psr\Log\LoggerInterface;

class RelationMetadataRepository implements RelationMetadataRepositoryInterface
{
    public function __construct(
        private readonly RelationMetadataResource $relationMetadataResource,
        private readonly RelationMetadataCollectionFactory $relationMetadataCollectionFactory,
        private readonly RelationReindexer $relationReindexer,
        private readonly RelationMetadataValidator $relationMetadataValidator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getByParentId(int $parentProductId): array
    {
        try {
            $collection = $this->relationMetadataCollectionFactory->create();
            $collection->addParentProductIdsFilter([$parentProductId]);
        } catch (Exception $e) {
            $this->logger->error(
                'Error loading relations for parent product',
                [
                    'parent_id' => $parentProductId,
                    'error' => $e->getMessage()
                ]
            );

            throw new LocalizedException(
                __('Unable to load relations for parent product ID %1', $parentProductId),
                $e
            );
        }

        return $collection->getItems();
    }

    public function getList(array $parentProductIds): array
    {
        if (empty($parentProductIds)) {
            return [];
        }

        $result = [];
        try {
            $collection = $this->relationMetadataCollectionFactory->create();
            $collection->addParentProductIdsFilter($parentProductIds);

            foreach ($collection->getItems() as $relation) {
                $parentId = $relation->getParentId();
                $result[$parentId][] = $relation;
            }
        } catch (Exception $e) {
            $this->logger->error(
                'Error loading relations for multiple parent products',
                [
                    'parent_ids' => $parentProductIds,
                    'error' => $e->getMessage()
                ]
            );

            throw new LocalizedException(
                __('Unable to load relations for the requested parent product IDs'),
                $e
            );
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function save(array $relationMetadata): array
    {
        $this->relationMetadataValidator->validate($relationMetadata);

        $connection = $this->relationMetadataResource->getConnection();

        try {
            $connection->beginTransaction();
            $this->relationMetadataResource->saveAggregateLinks($relationMetadata);
            $connection->commit();

            $this->relationReindexer->reindex($this->extractParentIds($relationMetadata));

            return $relationMetadata;
        } catch (Exception $e) {
            $connection->rollBack();
            $this->logger->error(
                'Error saving relations',
                [
                    'error' => $e->getMessage()
                ]
            );

            throw new CouldNotSaveException(__('Could not save relations'));
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(array $links): void
    {
        if (empty($links)) {
            return;
        }

        $connection = $this->relationMetadataResource->getConnection();

        try {
            $connection->beginTransaction();
            $this->relationMetadataResource->deleteAggregateLinks($links);
            $connection->commit();

            $this->relationReindexer->reindex($this->extractParentIds($links));
        } catch (Exception $e) {
            $connection->rollBack();
            $parentIds = array_reduce($links, function (array $carry, $relation) {
                $carry[] = $relation->getParentId();
                return $carry;
            }, []);

            $this->logger->error(
                'Error deleting relations for products',
                [
                    'parent_ids' => $parentIds,
                    'error' => $e->getMessage()
                ]
            );
            throw new CouldNotDeleteException(__('Could not delete relations for the specified parent product IDs'));
        }
    }

    /**
     * @param RelationMetadataInterface[] $relationMetadata
     * @return int[]
     */
    private function extractParentIds(array $relationMetadata): array
    {
        $parentIds = [];
        foreach ($relationMetadata as $relation) {
            $parentIds[(int)$relation->getParentId()] = (int)$relation->getParentId();
        }

        return array_values($parentIds);
    }
}
