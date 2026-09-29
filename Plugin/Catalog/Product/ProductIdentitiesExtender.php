<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Plugin\Catalog\Product;

use Magento\Catalog\Model\Product;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Nfourteen\AggregateProduct\Model\Product\AllowedChildTypes;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata;

/**
 * Appends aggregate parent cache tags to a child's identities so a child save invalidates the
 * parent's FPC immediately instead of waiting for the reindex changelog. Child deletes are not
 * covered here — the link rows are FK-cascaded away before identities are consulted — the
 * delete observers handle those.
 */
class ProductIdentitiesExtender implements ResetAfterRequestInterface
{
    /** @var array<int, int[]> */
    private array $parentIdsByChild = [];

    public function __construct(
        private readonly RelationMetadata $relationMetadataResource,
        private readonly AllowedChildTypes $allowedChildTypes
    ) {
    }

    /**
     * @param \Magento\Catalog\Model\Product $subject
     * @param string[] $identities
     * @return string[]
     */
    public function afterGetIdentities(Product $subject, array $identities): array
    {
        if (!in_array($subject->getTypeId(), $this->allowedChildTypes->get(), true)) {
            return $identities;
        }

        foreach ($this->getParentIdsByChild((int)$subject->getId()) as $parentId) {
            $identities[] = Product::CACHE_TAG . '_' . $parentId;
        }

        return $identities;
    }

    /**
     * getIdentities runs several times per request (save, cache clean, FPC tag emit); memoize
     * the lookup like the core extenders do.
     *
     * @param int $childId
     * @return int[]
     */
    private function getParentIdsByChild(int $childId): array
    {
        if (!isset($this->parentIdsByChild[$childId])) {
            $this->parentIdsByChild[$childId] = $this->relationMetadataResource->getParentIdsByChild($childId);
        }

        return $this->parentIdsByChild[$childId];
    }

    /**
     * @return void
     */
    public function _resetState(): void
    {
        $this->parentIdsByChild = [];
    }
}
