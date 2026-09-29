<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model;

use Magento\Framework\Exception\InvalidArgumentException;
use Magento\Framework\Exception\LocalizedException;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Model\Product\AllowedChildTypes;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata as RelationMetadataResource;

class RelationMetadataValidator
{
    public function __construct(
        private readonly RelationMetadataResource $relationMetadataResource,
        private readonly AllowedChildTypes $allowedChildTypes
    ) {
    }

    /**
     * @param RelationMetadataInterface[] $relationMetadata
     * @return void
     * @throws InvalidArgumentException for programming errors (wrong type passed)
     * @throws LocalizedException for data the merchant can correct (surfaced on save)
     */
    public function validate(array $relationMetadata): void
    {
        $childIds = [];
        foreach ($relationMetadata as $relation) {
            if (!$relation instanceof RelationMetadataInterface) {
                throw new InvalidArgumentException(
                    __('RelationMetadata must be an instance of ' . RelationMetadataInterface::class)
                );
            }

            $parentId = (int)$relation->getParentId();
            $productId = (int)$relation->getProductId();

            if ($parentId === 0 || $productId === 0) {
                throw new LocalizedException(
                    __('Invalid relation data for aggregate product: parent and child IDs are required.')
                );
            }

            if ($parentId === $productId) {
                throw new LocalizedException(
                    __('An aggregate product cannot contain itself (product ID %1).', $productId)
                );
            }

            if ((float)$relation->getQty() < 1) {
                throw new LocalizedException(
                    __('Quantity for child product ID %1 must be at least 1.', $productId)
                );
            }

            $childIds[$productId] = $productId;
        }

        if (empty($childIds)) {
            return;
        }

        $allowedTypes = $this->allowedChildTypes->get();
        $types = $this->relationMetadataResource->getProductTypesByIds(array_values($childIds));
        foreach ($childIds as $productId) {
            if (!isset($types[$productId])) {
                throw new LocalizedException(
                    __('Child product ID %1 does not exist.', $productId)
                );
            }

            if (!in_array($types[$productId], $allowedTypes, true)) {
                throw new LocalizedException(
                    __(
                        'Child product ID %1 is of type "%2"; only %3 products are allowed.',
                        $productId,
                        $types[$productId],
                        implode(', ', $allowedTypes)
                    )
                );
            }
        }
    }
}
