<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Modal;

class AggregateModal extends AbstractModifier
{
    public const string MODAL_PATH = Composite::CHILDREN_PATH . '/aggregate_products_modal';

    /** @var array<string, mixed> */
    protected array $meta = [];

    public function __construct(
        private readonly ArrayManager $arrayManager,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function modifyData(array $data): array
    {
        return $data;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function modifyMeta(array $meta): array
    {
        $this->meta = $meta;

        $modalPathConfig['arguments']['data']['config'] = [
            'componentType' => Modal::NAME,
            'dataScope' => '',
            'provider' => 'product_form.product_form_data_source',
            'options' => [
                'title' => __('Add Products to Aggregate'),
                'buttons' => [
                    [
                        'text' => __('Cancel'),
                        'actions' => ['closeModal']
                    ],
                    [
                        'text' => __('Add Selected Products'),
                        'class' => 'action-primary',
                        'actions' => [
                            [
                                'targetName' => 'index = aggregate_products_listing',
                                'actionName' => 'save'
                            ],
                            'closeModal'
                        ],
                    ],
                ],
            ],
        ];

        $this->meta = $this->arrayManager->set(self::MODAL_PATH, $this->meta, $modalPathConfig);
        $this->addModalInsertListing();

        return $this->meta;
    }

    private function addModalInsertListing(): void
    {
        $listingPath = self::MODAL_PATH . '/children/aggregate_products_listing';

        $listingConfig['arguments']['data']['config'] = [
            'autoRender' => false,
            'componentType' => 'insertListing',
            'dataScope' => 'aggregate_products_listing',
            'externalProvider' => 'aggregate_products_listing.aggregate_products_listing_data_source',
            'selectionsProvider' => 'aggregate_products_listing.aggregate_products_listing.product_columns.ids',
            'ns' => 'aggregate_products_listing',
            'render_url' => $this->urlBuilder->getUrl('mui/index/render'),
            'realTimeLink' => true,
            'provider' => 'product_form.product_form_data_source',
            'dataLinks' => ['imports' => false, 'exports' => true],
            'behaviourType' => 'simple',
            'externalFilterMode' => true,
            'imports' => [
                'storeId' => '${ $.provider }:data.product.current_store_id',
                '__disableTmpl' => ['storeId' => false],
            ],
            'exports' => [
                'storeId' => '${ $.externalProvider }:params.current_store_id',
                '__disableTmpl' => ['storeId' => false],
            ],
        ];

        $this->meta = $this->arrayManager->set($listingPath, $this->meta, $listingConfig);
    }
}
