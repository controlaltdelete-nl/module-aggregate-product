<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Nfourteen\AggregateProduct\Api\Data\LinkedProductInterface;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;

class LinkedProduct implements LinkedProductInterface
{
    public function __construct(
        private readonly RelationMetadataInterface $relationMetadata,
        private readonly int $productId,
        private readonly string $productName,
        private readonly string $productSku,
        private readonly ProductInterface $product
    ) {
    }

    public function getRelationMetadata(): RelationMetadataInterface
    {
        return $this->relationMetadata;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getProductSku(): string
    {
        return $this->productSku;
    }

    public function getQty(): float
    {
        return $this->relationMetadata->getQty();
    }

    public function getProduct(): ProductInterface
    {
        return $this->product;
    }
}
