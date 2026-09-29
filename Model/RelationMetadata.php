<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model;

use Magento\Framework\Model\AbstractExtensibleModel;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataExtensionInterface;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata as ResourceModel;

class RelationMetadata extends AbstractExtensibleModel implements RelationMetadataInterface
{
    protected function _construct()
    {
        $this->_init(ResourceModel::class);
    }

    /**
     * @return int
     */
    public function getLinkId(): int
    {
        return (int)$this->getData(self::LINK_ID);
    }

    /**
     * @param int $linkId
     * @return $this
     */
    public function setLinkId(int $linkId): self
    {
        return $this->setData(self::LINK_ID, $linkId);
    }

    /**
     * @return int
     */
    public function getProductId(): int
    {
        return (int)$this->getData(self::PRODUCT_ID);
    }

    /**
     * @param int $productId
     * @return $this
     */
    public function setProductId(int $productId): self
    {
        return $this->setData(self::PRODUCT_ID, $productId);
    }

    /**
     * @return int
     */
    public function getParentId(): int
    {
        return (int)$this->getData(self::PARENT_ID);
    }

    /**
     * @param int $parentId
     * @return $this
     */
    public function setParentId(int $parentId): self
    {
        return $this->setData(self::PARENT_ID, $parentId);
    }

    /**
     * @return float
     */
    public function getQty(): float
    {
        return (float)$this->getData(self::QTY);
    }

    /**
     * @param float $qty
     * @return $this
     */
    public function setQty(float $qty): self
    {
        return $this->setData(self::QTY, $qty);
    }

    public function getExtensionAttributes(): ?RelationMetadataExtensionInterface
    {
        return $this->_getExtensionAttributes();
    }

    public function setExtensionAttributes(RelationMetadataExtensionInterface $extensionAttributes): self
    {
        return $this->_setExtensionAttributes($extensionAttributes);
    }
}
