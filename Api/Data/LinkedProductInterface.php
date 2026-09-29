<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Api\Data;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Represents a linked child product within an aggregate product.
 *
 * This is a read-only value object combining:
 * - The child product data
 * - The relation metadata (qty, link_id, etc.)
 *
 * @api
 */
interface LinkedProductInterface
{
    /**
     * Get the relation metadata for this linked product
     */
    public function getRelationMetadata(): RelationMetadataInterface;

    /**
     * Get the linked product ID
     */
    public function getProductId(): int;

    /**
     * Get the linked product name
     */
    public function getProductName(): string;

    /**
     * Get the linked product SKU
     */
    public function getProductSku(): string;

    /**
     * Get the configured quantity of this product within the aggregate
     *
     * Convenience method - equivalent to getRelationMetadata()->getQty()
     */
    public function getQty(): float;

    /**
     * Get the full product object
     */
    public function getProduct(): ProductInterface;
}
