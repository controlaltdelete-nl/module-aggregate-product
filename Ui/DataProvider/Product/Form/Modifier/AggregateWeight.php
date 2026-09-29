<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;

/**
 * An aggregate derives its weight from its children and never stores one, so the field is disabled
 * and left empty with a note explaining where the shipped weight comes from. The attribute stays
 * applicable to aggregates so a future dynamic-weight toggle has somewhere to store a value.
 */
class AggregateWeight extends AbstractModifier
{
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
        // The "has weight" switcher cannot toggle anything: an aggregate always ships its children.
        $meta = $this->disableField(ProductAttributeInterface::CODE_HAS_WEIGHT, $meta, ['visible' => false]);

        return $this->disableField(ProductAttributeInterface::CODE_WEIGHT, $meta, [
            'notice' => __('Calculated from the child products and their configured quantities.'),
            'validation' => ['required-entry' => false],
        ]);
    }

    /**
     * @param string $attributeCode
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function disableField(string $attributeCode, array $meta, array $config): array
    {
        $path = $this->arrayManager->findPath($attributeCode, $meta, null, 'children');

        if ($path === null) {
            return $meta;
        }

        $configPath = $path . self::META_CONFIG_PATH;

        // Core's General modifier binds the weight field's `disabled` flag to the product_has_weight
        // toggle, which re-enables the field as soon as the form initialises. merge() cannot undo
        // that — array_replace_recursive keeps whatever is already there — so drop the node first.
        $meta = $this->arrayManager->remove($configPath . '/imports', $meta);

        return $this->arrayManager->merge($configPath, $meta, $config + ['disabled' => true]);
    }
}
