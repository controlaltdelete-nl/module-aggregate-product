<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;

/**
 * @api
 */
interface RelationMetadataInterface extends ExtensibleDataInterface
{
    public const string LINK_ID = 'link_id';
    public const string PRODUCT_ID = 'product_id';
    public const string PARENT_ID = 'parent_id';
    public const string QTY = 'qty';

    public const string LINK_TYPE = 'aggregate_relations';
    /**
     * @return int
     */
    public function getLinkId(): int;

    /**
     * @param int $linkId
     * @return $this
     */
    public function setLinkId(int $linkId): self;

    /**
     * @return int
     */
    public function getProductId(): int;

    /**
     * @param int $productId
     * @return $this
     */
    public function setProductId(int $productId): self;

    /**
     * @return int
     */
    public function getParentId(): int;

    /**
     * @param int $parentId
     * @return $this
     */
    public function setParentId(int $parentId): self;

    /**
     * @return float
     */
    public function getQty(): float;

    /**
     * @param float $qty
     * @return $this
     */
    public function setQty(float $qty): self;

    /**
     * @return \Nfourteen\AggregateProduct\Api\Data\RelationMetadataExtensionInterface|null
     */
    public function getExtensionAttributes(): ?RelationMetadataExtensionInterface;

    /**
     * @param \Nfourteen\AggregateProduct\Api\Data\RelationMetadataExtensionInterface $extensionAttributes
     * @return $this
     */
    public function setExtensionAttributes(RelationMetadataExtensionInterface $extensionAttributes): self;
}
