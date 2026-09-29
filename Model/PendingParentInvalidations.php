<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Parent ids captured before a child delete: the link rows are FK-cascaded away with the child,
 * so they must be read pre-delete and the reindex deferred until after commit.
 */
class PendingParentInvalidations implements ResetAfterRequestInterface
{
    /** @var array<int, int[]> productId => parent ids */
    private array $pending = [];

    /**
     * @param int $productId
     * @param int[] $parentIds
     * @return void
     */
    public function set(int $productId, array $parentIds): void
    {
        $this->pending[$productId] = $parentIds;
    }

    /**
     * @param int $productId
     * @return int[]
     */
    public function take(int $productId): array
    {
        $parentIds = $this->pending[$productId] ?? [];
        unset($this->pending[$productId]);

        return $parentIds;
    }

    /**
     * @return void
     */
    public function _resetState(): void
    {
        $this->pending = [];
    }
}
