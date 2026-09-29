<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Ui\Component\Container;
use Magento\Ui\Component\DynamicRows;
use Magento\Ui\Component\Form\Element\DataType\Number;
use Magento\Ui\Component\Form\Element\DataType\Text;
use Magento\Ui\Component\Form\Element\Input;
use Magento\Ui\Component\Form\Field;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;

class AggregateProductsListing extends AbstractModifier
{
    public const string LISTING_PATH = Composite::CHILDREN_PATH . '/' . RelationMetadataInterface::LINK_TYPE;

    /** @var array<string, mixed> */
    protected array $meta = [];

    public function __construct(
        private readonly ArrayManager $arrayManager
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
        $listingConfig['arguments']['data']['config'] = [
            'additionalClasses' => 'admin__field-wide',
            'componentType' => DynamicRows::NAME,
            'label' => null,
            'renderDefaultRecord' => false,
            'template' => 'ui/dynamic-rows/templates/grid',
            'component' => 'Magento_Ui/js/dynamic-rows/dynamic-rows-grid',
            'addButton' => false,
            'itemTemplate' => 'record',
            'dataScope' => 'data',
            'index' => RelationMetadataInterface::LINK_TYPE,
            'deleteButtonLabel' => __('Remove'),
            'dataProvider' => 'aggregate_products_listing',
            'dndConfig' => [
                'enabled' => false,
            ],
            'map' => [
                // id mapping imposed by js/dynamic-rows/dynamic-rows-grid.js::identificationProperty
                'id' => 'entity_id',
                'name' => 'name',
                'sku' => 'sku',
                'price' => 'price',
                'status' => 'status_text',
                'attribute_set' => 'attribute_set_text',
                'thumbnail' => 'thumbnail_src',
            ],
            'links' => [
                'insertData' => '${ $.provider }:${ $.dataProvider }',
                '__disableTmpl' => ['insertData' => false],
            ],
            'sortOrder' => 20,
            'columnsHeader' => false,
            'columnsHeaderAfterRender' => true
        ];

        $this->meta = $this->arrayManager->set(self::LISTING_PATH, $this->meta, $listingConfig);
        $this->addItemRecordTemplate();

        return $this->meta;
    }

    private function addItemRecordTemplate(): void
    {
        $rowsPath = self::LISTING_PATH . '/children/record';
        $rowsConfig['arguments']['data']['config'] = [
            'componentType' => Container::NAME,
            'component' => 'Magento_Ui/js/dynamic-rows/record',
            'isTemplate' => true,
            'is_collection' => true,
            'dataScope' => '',
        ];

        $this->meta = $this->arrayManager->set($rowsPath, $this->meta, $rowsConfig);
        $this->addMetaColumns($rowsPath);
    }

    private function addMetaColumns(string $rowsPath): void
    {
        $metaPath = $rowsPath . '/children';
        $metaConfig = [
            'id' => $this->getTextColumn('id', true, __('ID'), 10),
            'thumbnail' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'componentType' => Field::NAME,
                            'formElement' => Input::NAME,
                            'elementTmpl' => 'ui/dynamic-rows/cells/thumbnail',
                            'dataType' => Text::NAME,
                            'dataScope' => 'thumbnail',
                            'fit' => true,
                            'label' => __('Thumbnail'),
                            'sortOrder' => 20,
                            'labelVisible' => false,
                        ],
                    ],
                ],
            ],
            'name' => $this->getTextColumn('name', false, __('Name'), 30),
            'attribute_set' => $this->getTextColumn('attribute_set', false, __('Attribute Set'), 40),
            'status' => $this->getTextColumn('status', true, __('Status'), 50),
            'sku' => $this->getTextColumn('sku', false, __('SKU'), 60),
            'price' => $this->getTextColumn('price', true, __('Price'), 70),
            'qty' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'dataType' => Number::NAME,
                            'formElement' => Input::NAME,
                            'componentType' => Field::NAME,
                            'dataScope' => 'qty',
                            'label' => __('Quantity'),
                            'fit' => true,
                            'additionalClasses' => 'admin__field-small',
                            'sortOrder' => 80,
                            'validation' => [
                                'validate-number' => true,
                            ],
                            'labelVisible' => false,
                        ],
                    ],
                ],
            ],
            'actionDelete' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'additionalClasses' => 'data-grid-actions-cell',
                            'componentType' => 'actionDelete',
                            'dataType' => Text::NAME,
                            'label' => __('Actions'),
                            'sortOrder' => 100,
                            'fit' => true,
                        ],
                    ],
                ],
            ]
        ];

        $this->meta = $this->arrayManager->set($metaPath, $this->meta, $metaConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function getTextColumn(string $dataScope, bool $fit, Phrase $label, int $sortOrder): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Field::NAME,
                        'formElement' => Input::NAME,
                        'elementTmpl' => 'ui/dynamic-rows/cells/text',
                        'dataType' => Text::NAME,
                        'dataScope' => $dataScope,
                        'fit' => $fit,
                        'label' => $label,
                        'sortOrder' => $sortOrder,
                        'labelVisible' => false,
                    ],
                ],
            ],
        ];
    }
}
