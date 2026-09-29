<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Unit\Ui\DataProvider\Product\Form\Modifier;

use Magento\Framework\Stdlib\ArrayManager;
use Nfourteen\AggregateProduct\Ui\DataProvider\Product\Form\Modifier\AggregateWeight;
use PHPUnit\Framework\TestCase;

class AggregateWeightTest extends TestCase
{
    private AggregateWeight $modifier;

    protected function setUp(): void
    {
        $this->modifier = new AggregateWeight(new ArrayManager());
    }

    /**
     * The operator must not be able to type a weight the type would discard on save.
     */
    public function testWeightFieldIsDisabled(): void
    {
        $config = $this->configOf($this->modifier->modifyMeta($this->meta()), 'weight');

        $this->assertTrue($config['disabled']);
        $this->assertArrayHasKey('notice', $config);
        $this->assertFalse($config['validation']['required-entry']);
        $this->assertSame('lbs', $config['addafter'], 'unrelated field config is preserved');
    }

    /**
     * Core binds the weight field's disabled flag to the product_has_weight toggle, which re-enables
     * it in the browser; merging over that binding leaves it in place, so it has to be dropped.
     */
    public function testRuntimeDisabledBindingIsDropped(): void
    {
        $config = $this->configOf($this->modifier->modifyMeta($this->meta()), 'weight');

        $this->assertArrayNotHasKey('imports', $config);
    }

    /**
     * An aggregate always ships its children, so the switcher has nothing to toggle.
     */
    public function testHasWeightSwitcherIsHidden(): void
    {
        $config = $this->configOf($this->modifier->modifyMeta($this->meta()), 'product_has_weight');

        $this->assertTrue($config['disabled']);
        $this->assertFalse($config['visible']);
        $this->assertSame(1, $config['value'], 'the switcher keeps its value, it is only hidden');
    }

    /**
     * The field stays empty: the attribute holds nothing, and showing a derived number in a
     * disabled input reads as a stored value the admin could edit.
     */
    public function testNoWeightValueIsInjected(): void
    {
        $data = [42 => ['product' => ['sku' => 'agg-1']]];

        $this->assertSame($data, $this->modifier->modifyData($data));
    }

    /**
     * Meta shaped the way Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\General leaves it.
     *
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'product-details' => [
                'children' => [
                    'container_weight' => [
                        'children' => [
                            'weight' => [
                                'arguments' => ['data' => ['config' => [
                                    'addafter' => 'lbs',
                                    'imports' => [
                                        'disabled' => '!${$.provider}:data.product.product_has_weight:value',
                                        '__disableTmpl' => ['disabled' => false],
                                    ],
                                ]]],
                            ],
                            'product_has_weight' => [
                                'arguments' => ['data' => ['config' => [
                                    'dataScope' => 'product_has_weight',
                                    'value' => 1,
                                    'disabled' => false,
                                ]]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @param string $field
     * @return array<string, mixed>
     */
    private function configOf(array $meta, string $field): array
    {
        return $meta['product-details']['children']['container_weight']['children'][$field]
            ['arguments']['data']['config'];
    }
}
