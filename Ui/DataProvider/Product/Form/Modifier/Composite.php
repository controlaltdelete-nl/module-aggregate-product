<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Ui\DataProvider\Modifier\ModifierFactory;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate as AggregateProduct;

class Composite extends AbstractModifier
{
    public const string NAME = 'aggregate';
    public const string CHILDREN_PATH = 'aggregate/children';

    /** @var ModifierInterface[] */
    protected array $modifiersInstances = [];

    /**
     * @param string[] $modifiers
     */
    public function __construct(
        private readonly ModifierFactory $modifierFactory,
        private readonly LocatorInterface $locator,
        private array $modifiers = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function modifyData(array $data): array
    {
        if ($this->canShowAggregateFieldset()) {
            foreach ($this->getModifiers() as $modifier) {
                $data = $modifier->modifyData($data);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function modifyMeta(array $meta): array
    {
        if ($this->canShowAggregateFieldset()) {
            foreach ($this->getModifiers() as $modifier) {
                $meta = $modifier->modifyMeta($meta);
            }
        }

        return $meta;
    }

    protected function canShowAggregateFieldset(): bool
    {
        return $this->locator->getProduct()->getTypeId() === AggregateProduct::TYPE_CODE;
    }

    /**
     * @return ModifierInterface[]
     */
    protected function getModifiers(): array
    {
        if (empty($this->modifiersInstances)) {
            foreach ($this->modifiers as $modifierClass) {
                $this->modifiersInstances[$modifierClass] = $this->modifierFactory->create($modifierClass);
            }
        }

        return $this->modifiersInstances;
    }
}
