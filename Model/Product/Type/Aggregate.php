<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\Product\Type;

use InvalidArgumentException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product as MageProduct;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Eav\Model\Config;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\File\UploaderFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Nfourteen\AggregateProduct\Api\Data\LinkedProductInterface;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Model\RelationReindexer;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata as RelationMetadataResource;
use Psr\Log\LoggerInterface;

class Aggregate extends AbstractType implements ResetAfterRequestInterface
{
    public const TYPE_CODE = 'aggregate';

    /**
     * Parent item custom-option code carrying the purchase-time child snapshot (see
     * _prepareProduct). Public so cart/order GraphQL resolvers read the same snapshot the order
     * options are built from, rather than re-deriving from live relations, which could change
     * after purchase.
     */
    public const SNAPSHOT_OPTION = 'aggregate_children_snapshot';

    protected $_isComposite = true;
    protected $_canConfigure = false;

    /** @var array<int, array<string, bool>> */
    private array $isSaleableBySku = [];

    public function _resetState(): void
    {
        $this->isSaleableBySku = [];
    }

    public function __construct(
        Option $catalogProductOption,
        Config $eavConfig,
        Type $catalogProductType,
        ManagerInterface $eventManager,
        Database $fileStorageDb,
        Filesystem $filesystem,
        Registry $coreRegistry,
        LoggerInterface $logger,
        ProductRepositoryInterface $productRepository,
        private readonly RelationMetadataResource $relationMetadataResource,
        private readonly LinkedProductProviderInterface $linkedProductProvider,
        private readonly RelationReindexer $relationReindexer,
        Json $serializer = null,
        UploaderFactory $uploaderFactory = null
    ) {
        parent::__construct(
            $catalogProductOption,
            $eavConfig,
            $catalogProductType,
            $eventManager,
            $fileStorageDb,
            $filesystem,
            $coreRegistry,
            $logger,
            $productRepository,
            $serializer,
            $uploaderFactory
        );
    }

    public function getRelationInfo(): DataObject
    {
        $info = new DataObject();
        $info->setData('table', 'catalog_product_aggregate_link');
        $info->setData('parent_field_name', 'parent_id');
        $info->setData('child_field_name', 'product_id');
        return $info;
    }

    /**
     * Children ids in core's grouped shape: [groupId => [childId, ...]] — core consumers
     * array_merge() the outer groups and TypeError on a flat int[]. Every aggregate child is
     * required, so $required does not change membership.
     *
     * @param int $parentId
     * @param bool $required
     * @return array<int, int[]>
     */
    public function getChildrenIds($parentId, $required = true): array
    {
        return [$this->relationMetadataResource->getChildrenIds((int)$parentId)];
    }

    /**
     * @param int $childId
     * @return int[]
     */
    public function getParentIdsByChild($childId): array
    {
        return $this->relationMetadataResource->getParentIdsByChild((int)$childId);
    }

    /**
     * @param ProductInterface $product
     * @return ProductInterface[]
     */
    public function getAggregateProducts(ProductInterface $product): array
    {
        if (!$product instanceof MageProduct) {
            throw new LocalizedException(__('Unsupported product instance provided.'));
        }

        return array_map(
            static fn (LinkedProductInterface $linkedProduct): ProductInterface => $linkedProduct->getProduct(),
            $this->getLinkedChildren($product)
        );
    }

    /**
     * Aggregate weight is always derived from what actually ships — the children — never from a
     * stored weight attribute, which would silently drift as children or their configured
     * quantities change. Mirrors core Bundle's dynamic-weight branch.
     *
     * @param MageProduct $product
     * @return float
     */
    public function getWeight($product)
    {
        $weight = 0.0;

        foreach ($this->getLinkedChildren($product) as $linkedProduct) {
            // Prefer the qty _prepareProduct() pinned on the parent at add-to-cart time, so an item
            // already in a cart keeps the weight it was quoted.
            $qtyOption = $product->getCustomOption('product_qty_' . $linkedProduct->getProductId());
            $qty = $qtyOption !== null && $qtyOption->getValue() !== null
                ? (float)$qtyOption->getValue()
                : $linkedProduct->getQty();

            $weight += (float)$linkedProduct->getProduct()->getWeight() * $qty;
        }

        return $weight;
    }

    /**
     * Weight is always derived, so the attribute must never hold a value: the repository round-trips
     * a product through the ProductInterface getters on save, which would otherwise persist whatever
     * getWeight() happened to return and leave a stale number in the catalog.
     *
     * @param MageProduct $product
     * @return $this
     */
    public function beforeSave($product)
    {
        parent::beforeSave($product);

        if ($product->hasData('weight')) {
            $product->setData('weight', false);
        }

        return $this;
    }

    /**
     * @param MageProduct $product
     * @return bool
     */
    public function isSalable($product)
    {
        $storeId = $product->getStoreId();

        $salable = parent::isSalable($product);
        if (!$salable) {
            return false;
        }

        $sku = $product->getSku();
        if (isset($this->isSaleableBySku[$storeId][$sku])) {
            return $this->isSaleableBySku[$storeId][$sku];
        }

        $collection = $this->linkedProductProvider->getChildCollection([$product->getId()]);
        $collection->addStoreFilter($storeId);

        $enabledChildIds = array_map(
            static fn (MageProduct $child): int => (int)$child->getId(),
            $collection->getEnabledItems()
        );

        // The stock index already requires every child to be enabled and in stock, but it can
        // be stale (e.g. a child disabled moments ago). Re-check live: every configured child
        // must still exist and be enabled. A deleted or disabled child simply won't appear in
        // the enabled set. getChildrenIds() returns core's grouped shape, so use the flat
        // resource list instead.
        $configuredChildIds = $this->relationMetadataResource->getChildrenIds((int)$product->getId());
        $salable = empty(array_diff($configuredChildIds, $enabledChildIds));

        $this->isSaleableBySku[$storeId][$sku] = $salable;
        return $this->isSaleableBySku[$storeId][$sku];
    }

    /**
     * @param DataObject $buyRequest
     * @param $product
     * @param $processMode
     * @return MageProduct[]|string
     */
    protected function _prepareProduct(DataObject $buyRequest, $product, $processMode)
    {
        $result = parent::_prepareProduct($buyRequest, $product, $processMode);
        $childIds = [];
        $childrenSnapshot = [];
        if (is_array($result)) {
            $linkedProducts = $this->linkedProductProvider->getForProduct(
                (int)$product->getId()
            );

            foreach ($linkedProducts as $linkedProduct) {
                /** @var MageProduct $childProduct */
                $childProduct = $linkedProduct->getProduct();
                $configuredAggregateQty = $linkedProduct->getQty();

                $aggregateConfig = [
                    'qty' => $configuredAggregateQty,
                    'name' => $linkedProduct->getProductName()
                ];

                $productLinkFieldId = (int)$product->getId();
                $childProduct->setData('parent_product_id', $productLinkFieldId)
                    ->addCustomOption('parent_product_id', $productLinkFieldId)
                    ->addCustomOption('aggregate_config', $this->serializer->serialize($aggregateConfig));

                $product->addCustomOption(
                    'product_qty_' . $childProduct->getId(),
                    $configuredAggregateQty,
                    $childProduct
                );

                $_result = $childProduct->getTypeInstance()->_prepareProduct(
                    $buyRequest,
                    $childProduct,
                    $processMode
                );

                if (!is_array($_result) || !isset($_result[0])) {
                    return $this->getItemErrorMessage()->render();
                }

                $_result[0]->setCartQty($configuredAggregateQty);

                $result[] = $_result[0];
                $childIds[] = (int)$childProduct->getId();
                $childrenSnapshot[] = [
                    'id' => (int)$childProduct->getId(),
                    'sku' => $linkedProduct->getProductSku(),
                    'name' => $linkedProduct->getProductName(),
                    'type' => $childProduct->getTypeId(),
                    'qty' => $configuredAggregateQty
                ];
            }

            $product->addCustomOption('aggregate_product_ids', $this->serializer->serialize($childIds), $product);
            $product->addCustomOption(self::SNAPSHOT_OPTION, $this->serializer->serialize($childrenSnapshot), $product);

            return $result;
        } elseif (is_string($result)) {
            return __($result)->render();
        }

        return $this->getItemErrorMessage()->render();
    }

    public function getOrderOptions($product)
    {
        $optionArr = parent::getOrderOptions($product);

        $aggregateConfig = $this->buildAggregateConfigFromSnapshot($product);

        if (!empty($aggregateConfig)) {
            $optionArr['aggregate_config'] = [$aggregateConfig];
        }

        return $optionArr;
    }

    /**
     * Build the order-time "Includes" option purely from the purchase-time snapshot persisted
     * on the parent item. This is authoritative once an item is added to cart: it never touches
     * live relations, so a later relation edit/delete cannot throw or null-deref here.
     *
     * @return array<string, mixed>|null null when no snapshot is present
     */
    private function buildAggregateConfigFromSnapshot($product): ?array
    {
        if (!$product->hasCustomOptions()) {
            return null;
        }

        $snapshotOption = $product->getCustomOption(self::SNAPSHOT_OPTION);
        if (!$snapshotOption || $snapshotOption->getValue() === null || $snapshotOption->getValue() === '') {
            return null;
        }

        try {
            $snapshot = $this->serializer->unserialize($snapshotOption->getValue());
        } catch (InvalidArgumentException $e) {
            return null;
        }

        if (!is_array($snapshot) || empty($snapshot)) {
            return null;
        }

        $value = [];
        foreach ($snapshot as $child) {
            $value[] = [
                'name' => $child['name'] ?? '',
                'qty' => $child['qty'] ?? 1
            ];
        }

        return [
            'option_id' => 0,
            'label' => (string)__('Includes'),
            'value' => $value
        ];
    }

    /**
     * The configured children, product and qty together, loaded at most once per product.
     *
     * Cached on the product so the entry dies with the instance; a cache on this singleton type
     * would hold stale relations for the rest of the request. getForProduct() throws when an
     * aggregate has no relations: no children, nothing to sum or list.
     *
     * @param MageProduct $product
     * @return LinkedProductInterface[]
     */
    private function getLinkedChildren(MageProduct $product): array
    {
        if (!$product->hasData('aggregate_products')) {
            try {
                $children = $this->linkedProductProvider->getForProduct((int)$product->getId());
            } catch (NoSuchEntityException $e) {
                $children = [];
            }
            $product->setData('aggregate_products', $children);
        }

        return $product->getData('aggregate_products');
    }

    private function getItemErrorMessage(): Phrase
    {
        return __('The item cannot be added to the shopping cart.');
    }

    public function deleteTypeSpecificData(MageProduct $product)
    {
        $parentId = (int)$product->getId();
        if ($parentId === 0) {
            return;
        }

        $this->relationMetadataResource->deleteByParentId($parentId);
        $this->relationReindexer->reindex([$parentId]);
    }
}
