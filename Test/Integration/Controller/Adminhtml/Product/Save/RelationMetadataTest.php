<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Controller\Adminhtml\Product\Save;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Registry;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;

#[AppArea('adminhtml')]
class RelationMetadataTest extends AbstractBackendController
{
    protected $resource = 'Magento_Catalog::products';
    protected $uri = 'backend/catalog/product/save';
    protected $httpMethod = 'POST';

    private ?ProductRepositoryInterface $productRepository = null;
    private ?DataFixtureStorage $fixtures = null;
    /** @var string[]|null Nullable so the framework's end-of-suite property cleanup can null it. */
    private ?array $postCreatedSkus = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->productRepository = $this->_objectManager->create(ProductRepositoryInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    protected function tearDown(): void
    {
        $registry = $this->_objectManager->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);

        foreach ($this->postCreatedSkus as $sku) {
            try {
                $this->productRepository->deleteById($sku);
            } catch (NoSuchEntityException $e) {
                // already deleted
            }
        }

        $registry->unregister('isSecureArea');

        parent::tearDown();
    }

    #[Config('dev/static/sign', '0', 'store', 'default')]
    public function testAclNoAccess()
    {
        parent::testAclNoAccess();
    }

    #[DataFixture(ProductFixture::class, as: 'child')]
    public function testSaveRelationMetadataOnNewAggregateProductSave(): void
    {
        $childProduct = $this->fixtures->get('child');
        $postData = $this->getPostData(
            'new-aggregate-child-links',
            null,
            [['id' => $childProduct->getId(), 'qty' => '5']]
        );
        $this->sendRequestToSaveProduct($postData);
        $this->postCreatedSkus[] = 'new-aggregate-child-links';

        $product = $this->productRepository->get('new-aggregate-child-links', false, null, true);

        $this->assertCount(1, $product->getExtensionAttributes()->getAggregateRelations());
        foreach ($product->getExtensionAttributes()->getAggregateRelations() as $relation) {
           $this->assertEquals(5, $relation->getQty());
        }
    }

    public function testEmptyRelationMetadataOnNewAggregateProductSave(): void
    {
        $postData = $this->getPostData('aggregate-no-child-links');
        $this->sendRequestToSaveProduct($postData);
        $this->postCreatedSkus[] = 'aggregate-no-child-links';

        $product = $this->productRepository->get('aggregate-no-child-links', false, null, true);

        $this->assertEmpty($product->getExtensionAttributes()->getAggregateRelations());
    }

    #[DataFixture(ProductFixture::class, as: 'child1')]
    #[DataFixture(ProductFixture::class, as: 'child2')]
    #[DataFixture(AggregateProductFixture::class, [
        '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 1],
            ['product_id' => '$child2.id$', 'qty' => 5],
        ],
    ], as: 'aggregate')]
    public function testRelationMetadataIsDeleted(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->fixtures->get('aggregate');

        // A present-but-empty relations param is authoritative: it explicitly clears every child.
        $postData = $this->getPostData($aggregateProduct->getSku(), (int)$aggregateProduct->getId(), []);
        $this->sendRequestToSaveProduct($postData);

        $aggregateProduct = $this->productRepository->get($aggregateProduct->getSku(), false, null, true);

        $this->assertEmpty($aggregateProduct->getExtensionAttributes()->getAggregateRelations());
    }

    #[DataFixture(ProductFixture::class, as: 'child1')]
    #[DataFixture(ProductFixture::class, as: 'child2')]
    #[DataFixture(AggregateProductFixture::class, [
        '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 1],
            ['product_id' => '$child2.id$', 'qty' => 5],
        ],
    ], as: 'aggregate')]
    public function testAbsentRelationParamPreservesRelations(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->fixtures->get('aggregate');

        // Saving the product without the relations grid in the submission (e.g. an attribute-only
        // update) must NOT touch existing children — an absent param is a no-op, not a wipe.
        $postData = $this->getPostData(
            $aggregateProduct->getSku(),
            (int)$aggregateProduct->getId(),
            [],
            false
        );
        $this->sendRequestToSaveProduct($postData);

        $aggregateProduct = $this->productRepository->get($aggregateProduct->getSku(), false, null, true);

        $this->assertCount(2, $aggregateProduct->getExtensionAttributes()->getAggregateRelations());
    }

    #[DbIsolation(false)]
    #[DataFixture(ProductFixture::class, as: 'child')]
    public function testMissingQtyIsRejected(): void
    {
        $childProduct = $this->fixtures->get('child');
        $postData = $this->getPostData('aggregate-default-qty', null, [['id' => $childProduct->getId(), 'qty' => '']]);
        $this->sendRequestToSaveProduct($postData);
        $this->postCreatedSkus[] = 'aggregate-default-qty';

        $this->assertSaveRejectedWithError('must be at least 1');

        // Relations persist inside the product save, so a rejected relation set rolls back the
        // whole save — the product must not exist at all.
        $this->expectException(NoSuchEntityException::class);
        $this->productRepository->get('aggregate-default-qty', false, null, true);
    }

    #[DataFixture(ProductFixture::class, as: 'child')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-update-child-link-qty',
        '_children' => [
            ['product_id' => '$child.id$', 'qty' => 1],
        ],
    ], as: 'aggregate')]
    public function testUpdateExistingRelationMetadata(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->fixtures->get('aggregate');

        $initialAggregate = $this->productRepository->get('aggregate-update-child-link-qty', false, null, true);
        $initialChildId = null;
        $initialLinkId = null;
        foreach ($initialAggregate->getExtensionAttributes()->getAggregateRelations() as $relation) {
            $initialChildId = $relation->getProductId();
            $initialLinkId = $relation->getLinkId();
        }

        // Update the quantity of the existing relation
        $postData = $this->getPostData(
            $aggregateProduct->getSku(),
            (int)$aggregateProduct->getId(),
            [['id' => $initialChildId, 'qty' => '10']]
        );
        $this->sendRequestToSaveProduct($postData);

        $updatedProduct = $this->productRepository->get('aggregate-update-child-link-qty', false, null, true);

        $this->assertCount(1, $updatedProduct->getExtensionAttributes()->getAggregateRelations());
        foreach ($updatedProduct->getExtensionAttributes()->getAggregateRelations() as $relation) {
            $this->assertEquals($initialLinkId, $relation->getLinkId());
            $this->assertEquals($initialChildId, $relation->getProductId());
            $this->assertEquals(10, $relation->getQty());
        }
    }

    #[DataFixture(ProductFixture::class, as: 'child1')]
    #[DataFixture(ProductFixture::class, as: 'child2')]
    #[DataFixture(ProductFixture::class, as: 'child3')]
    public function testAddMultipleRelationsToExistingProduct(): void
    {
        $firstChildProduct = $this->fixtures->get('child1');
        $secondChildProduct = $this->fixtures->get('child2');
        $thirdChildProduct = $this->fixtures->get('child3');

        // Create an aggregate product with no relations first, where admin forgot to add relations
        $postData = $this->getPostData('aggregate-add-multiple-child-links');
        $this->sendRequestToSaveProduct($postData);
        $this->postCreatedSkus[] = 'aggregate-add-multiple-child-links';

        $initialAggregate = $this->productRepository->get('aggregate-add-multiple-child-links', false, null, true);
        $initialRelations = $initialAggregate->getExtensionAttributes()->getAggregateRelations();
        $this->assertEmpty($initialRelations);

        // Then update aggregate with multiple relations
        $postData = $this->getPostData(
            'aggregate-add-multiple-child-links',
            (int)$initialAggregate->getId(),
            [
                ['id' => $firstChildProduct->getId(), 'qty' => '2'],
                ['id' => $secondChildProduct->getId(), 'qty' => '3'],
                ['id' => $thirdChildProduct->getId(), 'qty' => '4']
            ]
        );
        $this->sendRequestToSaveProduct($postData);

        $updatedAggregate = $this->productRepository->get('aggregate-add-multiple-child-links', false, null, true);

        $relations = $updatedAggregate->getExtensionAttributes()->getAggregateRelations();
        $this->assertCount(3, $relations);

        $expectedQtyMap = [
            $firstChildProduct->getId() => 2,
            $secondChildProduct->getId() => 3,
            $thirdChildProduct->getId() => 4
        ];

        foreach ($relations as $relation) {
            $this->assertArrayHasKey($relation->getProductId(), $expectedQtyMap);
            $this->assertEquals(
                $expectedQtyMap[$relation->getProductId()],
                $relation->getQty()
            );
        }
    }

    #[DbIsolation(false)]
    #[DataFixture(ProductFixture::class, as: 'child')]
    public function testInvalidRelationQtyHandling(): void
    {
        $childProduct = $this->fixtures->get('child');

        // Create the aggregate without children; each invalid update below must reject atomically
        // and leave it untouched.
        $postData = $this->getPostData('aggregate-invalid-qty');
        $this->sendRequestToSaveProduct($postData);
        $this->postCreatedSkus[] = 'aggregate-invalid-qty';

        $product = $this->productRepository->get('aggregate-invalid-qty', false, null, true);
        $productId = (int)$product->getId();

        $postData = $this->getPostData(
            'aggregate-invalid-qty',
            $productId,
            [['id' => $childProduct->getId(), 'qty' => '-5']]
        );
        $this->sendRequestToSaveProduct($postData);

        $this->assertSaveRejectedWithError('must be at least 1');

        $product = $this->productRepository->get('aggregate-invalid-qty', false, null, true);
        $this->assertEmpty(
            $product->getExtensionAttributes()->getAggregateRelations(),
            'Negative quantity must not persist a relation'
        );

        $postData = $this->getPostData(
            'aggregate-invalid-qty',
            $productId,
            [['id' => $childProduct->getId(), 'qty' => 'abc']]
        );
        $this->sendRequestToSaveProduct($postData);

        $this->assertSaveRejectedWithError('must be at least 1');

        $product = $this->productRepository->get('aggregate-invalid-qty', false, null, true);
        $this->assertEmpty(
            $product->getExtensionAttributes()->getAggregateRelations(),
            'Non-numeric quantity must not persist a relation'
        );
    }

    #[DataFixture(ProductFixture::class, as: 'oldChild1')]
    #[DataFixture(ProductFixture::class, as: 'oldChild2')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-replace-child-links',
        '_children' => [
            ['product_id' => '$oldChild1.id$', 'qty' => 2],
            ['product_id' => '$oldChild2.id$', 'qty' => 3],
        ],
    ], as: 'aggregate')]
    #[DataFixture(ProductFixture::class, as: 'newChild1')]
    #[DataFixture(ProductFixture::class, as: 'newChild2')]
    public function testReplaceAllRelationsWithNewSet(): void
    {
        $newChild1 = $this->fixtures->get('newChild1');
        $newChild2 = $this->fixtures->get('newChild2');

        // Verify initial state
        $initialProduct = $this->productRepository->get('aggregate-replace-child-links', false, null, true);
        $this->assertCount(2, $initialProduct->getExtensionAttributes()->getAggregateRelations());

        $originalChildIds = [];
        foreach ($initialProduct->getExtensionAttributes()->getAggregateRelations() as $relation) {
            $originalChildIds[] = $relation->getProductId();
        }

        // Replace with completely new set of relations
        $postData = $this->getPostData(
            'aggregate-replace-child-links',
            (int)$initialProduct->getId(),
            [
                ['id' => $newChild1->getId(), 'qty' => '5'],
                ['id' => $newChild2->getId(), 'qty' => '6']
            ]
        );
        $this->sendRequestToSaveProduct($postData);

        $updatedProduct = $this->productRepository->get('aggregate-replace-child-links', false, null, true);
        $relations = $updatedProduct->getExtensionAttributes()->getAggregateRelations();

        $this->assertCount(2, $relations);

        $newChildIds = [(int)$newChild1->getId(), (int)$newChild2->getId()];

        foreach ($relations as $relation) {
            $this->assertContains((int)$relation->getProductId(), $newChildIds, 'Relation should be from the new set');
            $this->assertNotContains((int)$relation->getProductId(), $originalChildIds, 'Old relations should be removed');
        }

        // Verify the quantities of new links
        $expectedQtyMap = [
            $newChild1->getId() => 5,
            $newChild2->getId() => 6
        ];

        foreach ($relations as $relation) {
            $this->assertEquals(
                $expectedQtyMap[$relation->getProductId()],
                $relation->getQty()
            );
        }
    }

    /**
     * @param array<string, mixed> $postData
     */
    private function sendRequestToSaveProduct(array $postData): void
    {
        /** @var Request $request */
        $request = $this->getRequest();
        $request->setMethod(HttpRequest::METHOD_POST);
        $request->setPostValue($postData);

        $this->dispatch('backend/catalog/product/save');
    }

    /**
     * Assert the last product save surfaced a validation error containing $needle, proving the
     * relation write failed loudly rather than silently coercing bad data.
     */
    private function assertSaveRejectedWithError(string $needle): void
    {
        $this->assertSessionMessages(
            $this->callback(static function ($messages) use ($needle): bool {
                foreach ($messages as $message) {
                    if (str_contains((string)$message, $needle)) {
                        return true;
                    }
                }

                return false;
            }),
            MessageInterface::TYPE_ERROR
        );
    }

    /**
     * @param string $aggregateSku
     * @param array<int, array<string, int|string|null>> $childProductData [['id' => 'product_id', 'qty' => '1']]
     * @param bool $includeRelations When false, omit the relations key entirely to simulate a
     *        product save where the aggregate grid was not part of the submission (a no-op).
     *        When true (default), a present value — even an empty array — is authoritative and
     *        an empty array means "remove every child".
     * @return array<string, mixed>
     */
    private function getPostData(
        string $aggregateSku,
        ?int $productId = null,
        array $childProductData = [],
        bool $includeRelations = true
    ): array {
        $parentPostData = [
            'type' => Aggregate::TYPE_CODE,
            'set' => '4',
            'product' => [
                'status' => '1',
                'name' => 'Aggregate Product',
                'sku' => $aggregateSku
            ]
        ];

        if ($productId) {
            $parentPostData['id'] = $productId;
        }

        if ($includeRelations) {
            $parentPostData[RelationMetadataInterface::LINK_TYPE] = $childProductData;
        }

        return $parentPostData;
    }
}
