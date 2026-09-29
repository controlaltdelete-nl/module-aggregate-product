<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Product\Child;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Child\ChildStatusResolver;
use PHPUnit\Framework\TestCase;

class ChildStatusResolverTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;
    private ?ChildStatusResolver $resolver = null;

    protected function setUp(): void
    {
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->resolver = Bootstrap::getObjectManager()->get(ChildStatusResolver::class);
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'enabled_a'], as: 'enabledA'),
        DataFixture(ProductFixture::class, ['sku' => 'enabled_b'], as: 'enabledB'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'disabled_a', 'status' => Status::STATUS_DISABLED],
            as: 'disabledA'
        )
    ]
    public function testGetEnabledChildIdsReturnsOnlyEnabled(): void
    {
        $enabledA = (int)$this->fixtures->get('enabledA')->getId();
        $enabledB = (int)$this->fixtures->get('enabledB')->getId();
        $disabledA = (int)$this->fixtures->get('disabledA')->getId();

        $result = $this->resolver->getEnabledChildIds([$enabledA, $enabledB, $disabledA]);

        sort($result);
        $expected = [$enabledA, $enabledB];
        sort($expected);

        $this->assertSame($expected, $result);
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'enabled_a'], as: 'enabledA'),
        DataFixture(ProductFixture::class, ['sku' => 'enabled_b'], as: 'enabledB'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'disabled_a', 'status' => Status::STATUS_DISABLED],
            as: 'disabledA'
        )
    ]
    public function testAllEnabled(): void
    {
        $enabledA = (int)$this->fixtures->get('enabledA')->getId();
        $enabledB = (int)$this->fixtures->get('enabledB')->getId();
        $disabledA = (int)$this->fixtures->get('disabledA')->getId();

        $this->assertTrue($this->resolver->allEnabled([$enabledA, $enabledB]));
        $this->assertFalse($this->resolver->allEnabled([$enabledA, $disabledA]));
        $this->assertFalse($this->resolver->allEnabled([]));
    }
}
