<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Framework\Currency\Exception\CurrencyException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\CurrencyInterface;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Ui\Component\Container;
use Magento\Ui\Component\Form\Fieldset;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Model\RelationMetadata;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate as AggregateProduct;

class AggregatePanel extends AbstractModifier
{
    public const string BUTTON_SET_PATH = Composite::CHILDREN_PATH . '/aggregate_products_button_set';

    /** @var array<string, mixed> */
    protected array $meta = [];

    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly ArrayManager $arrayManager,
        private readonly ImageHelper $imageHelper,
        private readonly Status $status,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly CurrencyInterface $localeCurrency,
        private readonly LinkedProductProviderInterface $linkedProductProvider
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int|string, mixed>
     * @throws CurrencyException
     * @throws NoSuchEntityException|LocalizedException
     */
    public function modifyData(array $data): array
    {
        /** @var Product $product */
        $product = $this->locator->getProduct();

        if (!$product->getTypeInstance() instanceof AggregateProduct) {
            throw new LocalizedException(__('Unsupported product type instance provided.'));
        }

        $productEntityId = $product->getId();
        if ($productEntityId !== null) {
            $storeId = $this->locator->getStore()->getId();
            $data[$productEntityId][RelationMetadataInterface::LINK_TYPE] = [];

            try {
                $linkedProducts = $this->linkedProductProvider->getForProduct(
                    (int)$productEntityId
                );
            } catch (NoSuchEntityException) {
                return $data;
            }

            foreach ($linkedProducts as $linkedProduct) {
                $data[$productEntityId][RelationMetadataInterface::LINK_TYPE][] = $this->fillData(
                    $linkedProduct->getProduct(),
                    $linkedProduct->getRelationMetadata()
                );
            }
            $data[$productEntityId][self::DATA_SOURCE_DEFAULT]['current_store_id'] = $storeId;
        }

        return $data;
    }

    /**
     * @param ProductInterface $childProduct
     * @param RelationMetadataInterface $relationMetadata
     * @return array<string, mixed>
     * @throws CurrencyException
     * @throws NoSuchEntityException
     */
    protected function fillData(
        ProductInterface $childProduct,
        RelationMetadataInterface $relationMetadata
    ): array {
        $currency = $this->localeCurrency->getCurrency($this->locator->getBaseCurrencyCode());

        /** @var Product $childProduct */
        /** @var RelationMetadata $relationMetadata */
        return [
            // id mapping imposed by js/dynamic-rows/dynamic-rows-grid.js::identificationProperty
            'id' => $childProduct->getId(),
            'name' => $childProduct->getName(),
            'sku' => $childProduct->getSku(),
            'price' => $currency->toCurrency($childProduct->getPrice()),
            'qty' => $relationMetadata->getQty(),
            'thumbnail' => $this->imageHelper
                ->init($childProduct, 'product_listing_thumbnail')
                ->setImageFile($childProduct->getThumbnail())
                ->getUrl(),
            'type_id' => $childProduct->getTypeId(),
            'status' => $this->status->getOptionText((string)$childProduct->getStatus()),
            'attribute_set' => $this->attributeSetRepository
                ->get($childProduct->getAttributeSetId())
                ->getAttributeSetName(),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function modifyMeta(array $meta): array
    {
        $this->meta = $meta;

        $panelConfig['arguments']['data']['config'] = [
            'componentType' => Fieldset::NAME,
            'label' => __('Aggregate Products'),
            'collapsible' => true,
            'opened' => $this->locator->getProduct()->getTypeId() === AggregateProduct::TYPE_CODE,
            'sortOrder' => '5',
            'dataScope' => ''
        ];

        $this->meta = $this->arrayManager->set(Composite::NAME, $this->meta, $panelConfig);
        $this->addButtonSet();

        return $this->meta;
    }

    private function addButtonSet(): void
    {
        $buttonSetPathConfig['arguments']['data']['config'] = [
            'componentType' => Container::NAME,
            'formElement' => Container::NAME,
            'template' => 'ui/form/components/complex',
            'label' => false,
            'content' => __('Aggregate products are sold as a single product, but inventory is managed at the level' .
                ' of the individual products that make up the aggregate product.'
            )
        ];

        $childButtonPath = self::BUTTON_SET_PATH . '/children/aggregate_products_button';
        $childButtonConfig['arguments']['data']['config'] = [
            'formElement' => Container::NAME,
            'componentType' => Container::NAME,
            'component' => 'Magento_Ui/js/form/components/button',
            'title' => __('Add Products to Aggregate'),
            'actions' => [
                [
                    'targetName' => 'product_form.product_form.aggregate.aggregate_products_modal',
                    'actionName' => 'openModal'
                ],
                [
                    'targetName' => 'product_form.product_form.aggregate.aggregate_products_modal.aggregate_products_listing',
                    'actionName' => 'render'
                ]
            ],
            'provider' => null
        ];

        $this->meta = $this->arrayManager->set(self::BUTTON_SET_PATH, $this->meta, $buttonSetPathConfig);
        $this->meta = $this->arrayManager->set($childButtonPath, $this->meta, $childButtonConfig);
    }
}
