import { runMagentoPhp } from './MagentoCommand';

export interface ChildProduct {
    sku: string;
    name: string;
    linkQty: number;
}

/**
 * Creates the simple products an aggregate is built from, and removes everything the run created.
 *
 * The children are set up through Magento rather than the browser because they are not what is
 * under test; creating the aggregate itself is, so that part goes through the admin form.
 *
 * Every SKU starts with a per-run prefix, so cleanup removes exactly this run's products and
 * nothing else, even after a failure halfway through.
 */
export class CatalogFixture {
    static readonly STORE_ID = 1;

    readonly prefix: string;

    constructor() {
        this.prefix = `e2e-aggr-${Date.now()}`;
    }

    sku(suffix: string): string {
        return `${this.prefix}-${suffix}`;
    }

    createSimpleProduct(child: ChildProduct, price: number, stockQty: number): void {
        runMagentoPhp(`
            $product = $om->create(\\Magento\\Catalog\\Api\\Data\\ProductInterfaceFactory::class)->create();
            $product->setTypeId('simple')
                ->setAttributeSetId(4)
                ->setSku(${JSON.stringify(child.sku)})
                ->setName(${JSON.stringify(child.name)})
                ->setUrlKey(${JSON.stringify(child.sku)})
                ->setPrice(${price})
                ->setWeight(1)
                ->setStatus(\\Magento\\Catalog\\Model\\Product\\Attribute\\Source\\Status::STATUS_ENABLED)
                ->setVisibility(\\Magento\\Catalog\\Model\\Product\\Visibility::VISIBILITY_NOT_VISIBLE)
                ->setWebsiteIds([1])
                ->setStockData(['qty' => ${stockQty}, 'is_in_stock' => 1, 'manage_stock' => 1]);
            $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class)->save($product);
        `);
    }

    /**
     * Does what the `indexer_update_all_views` cron job does. Stores with indexers on "Update by
     * Schedule" otherwise show a new product without a price or stock status until cron runs.
     */
    processScheduledIndexes(): void {
        runMagentoPhp(`
            $om->get(\\Magento\\Indexer\\Model\\Processor::class)->updateMview();
        `);
    }

    /**
     * The storefront URL as the store itself builds it, so the suite works with or without store
     * codes in URLs and regardless of the URL suffix configuration.
     */
    productUrl(sku: string): string {
        return runMagentoPhp(`
            echo $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class)
                ->get(${JSON.stringify(sku)}, false, ${CatalogFixture.STORE_ID})
                ->getProductUrl();
        `).trim();
    }

    salableQty(sku: string): number {
        return parseFloat(runMagentoPhp(`
            echo $om->get(\\Magento\\InventorySalesApi\\Api\\GetProductSalableQtyInterface::class)
                ->execute(${JSON.stringify(sku)}, 1);
        `).trim());
    }

    removeAll(): void {
        runMagentoPhp(`
            $om->get(\\Magento\\Framework\\Registry::class)->register('isSecureArea', true);
            $collection = $om->create(\\Magento\\Catalog\\Model\\ResourceModel\\Product\\Collection::class)
                ->addAttributeToFilter('sku', ['like' => ${JSON.stringify(`${this.prefix}-%`)}]);
            $repository = $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class);
            array_map(
                static fn ($product) => $repository->delete($product),
                array_reverse($collection->getItems())
            );
        `);
    }
}
