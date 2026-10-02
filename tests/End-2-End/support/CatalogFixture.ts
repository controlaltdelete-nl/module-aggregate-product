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
     * Creates an aggregate without the admin form, for specs where building the pack is setup and
     * not what is under test. Links go through the module's own reconciler, the same path the admin
     * save takes.
     */
    createAggregateProduct(sku: string, name: string, price: number, children: ChildProduct[]): void {
        const links = children.map((child) => ({ sku: child.sku, qty: child.linkQty }));
        runMagentoPhp(`
            $repository = $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class);
            $product = $om->create(\\Magento\\Catalog\\Api\\Data\\ProductInterfaceFactory::class)->create();
            $product->setTypeId(\\Nfourteen\\AggregateProduct\\Model\\Product\\Type\\Aggregate::TYPE_CODE)
                ->setAttributeSetId(4)
                ->setSku(${JSON.stringify(sku)})
                ->setName(${JSON.stringify(name)})
                ->setUrlKey(${JSON.stringify(sku)})
                ->setPrice(${price})
                ->setStatus(\\Magento\\Catalog\\Model\\Product\\Attribute\\Source\\Status::STATUS_ENABLED)
                ->setVisibility(\\Magento\\Catalog\\Model\\Product\\Visibility::VISIBILITY_BOTH)
                ->setWebsiteIds([1])
                ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 1, 'is_in_stock' => 1]);
            $parentId = (int) $repository->save($product)->getId();
            $links = array_map(
                static fn (array $link) => $om->create(\\Nfourteen\\AggregateProduct\\Model\\RelationMetadataFactory::class)
                    ->create()
                    ->setData([
                        'parent_id' => $parentId,
                        'product_id' => (int) $repository->get($link['sku'])->getId(),
                        'qty' => $link['qty'],
                    ]),
                json_decode(${JSON.stringify(JSON.stringify(links))}, true)
            );
            $om->get(\\Nfourteen\\AggregateProduct\\Model\\RelationMetadataReconciler::class)->reconcile($parentId, $links);
        `);
    }

    /**
     * Turns an existing simple product into a configurable one with a single in-stock variant, the
     * way a merchant does with "Create Configurations" on a product that is already in a pack. The
     * pack stays salable, because the configurable has a variant in stock.
     *
     * The variant attribute is created for this run and removed again by {@see removeAll()}.
     */
    convertToConfigurable(sku: string, variantSku: string, variantName: string, stockQty: number): void {
        runMagentoPhp(`
            $attributeRepository = $om->get(\\Magento\\Catalog\\Api\\ProductAttributeRepositoryInterface::class);
            $attribute = $om->create(\\Magento\\Catalog\\Api\\Data\\ProductAttributeInterfaceFactory::class)->create()
                ->setAttributeCode(${JSON.stringify(this.attributeCode())})
                ->setDefaultFrontendLabel('E2E Size')
                ->setFrontendInput('select')
                ->setIsGlobal(\\Magento\\Catalog\\Model\\ResourceModel\\Eav\\Attribute::SCOPE_GLOBAL)
                ->setIsUserDefined(true)
                ->setOptions([
                    $om->create(\\Magento\\Eav\\Api\\Data\\AttributeOptionInterfaceFactory::class)->create()->setLabel('One size'),
                ]);
            $attribute = $attributeRepository->save($attribute);
            $om->get(\\Magento\\Catalog\\Api\\ProductAttributeManagementInterface::class)
                ->assign(4, (int) $om->get(\\Magento\\Eav\\Model\\Entity\\Attribute\\SetFactory::class)->create()->load(4)->getDefaultGroupId(), $attribute->getAttributeCode(), 999);
            $optionId = (int) array_values(array_filter(
                $attributeRepository->get($attribute->getAttributeCode())->getOptions(),
                static fn ($option) => $option->getValue() !== ''
            ))[0]->getValue();

            $repository = $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class);
            $variant = $om->create(\\Magento\\Catalog\\Api\\Data\\ProductInterfaceFactory::class)->create()
                ->setTypeId('simple')
                ->setAttributeSetId(4)
                ->setSku(${JSON.stringify(variantSku)})
                ->setName(${JSON.stringify(variantName)})
                ->setUrlKey(${JSON.stringify(variantSku)})
                ->setPrice(5)
                ->setWeight(1)
                ->setStatus(\\Magento\\Catalog\\Model\\Product\\Attribute\\Source\\Status::STATUS_ENABLED)
                ->setVisibility(\\Magento\\Catalog\\Model\\Product\\Visibility::VISIBILITY_NOT_VISIBLE)
                ->setWebsiteIds([1])
                ->setData($attribute->getAttributeCode(), $optionId)
                ->setStockData(['qty' => ${stockQty}, 'is_in_stock' => 1, 'manage_stock' => 1]);
            $variantId = (int) $repository->save($variant)->getId();

            $product = $repository->get(${JSON.stringify(sku)}, true, 0, true);
            $configurableOptions = $om->get(\\Magento\\ConfigurableProduct\\Helper\\Product\\Options\\Factory::class)->create([[
                'attribute_id' => $attribute->getAttributeId(),
                'code' => $attribute->getAttributeCode(),
                'label' => 'E2E Size',
                'position' => 0,
                'values' => [['value_index' => $optionId]],
            ]]);
            $extension = $product->getExtensionAttributes();
            $extension->setConfigurableProductOptions($configurableOptions);
            $extension->setConfigurableProductLinks([$variantId]);
            $product->setTypeId(\\Magento\\ConfigurableProduct\\Model\\Product\\Type\\Configurable::TYPE_CODE)
                ->setExtensionAttributes($extension)
                ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);
            $repository->save($product);
        `);
    }

    private attributeCode(): string {
        return this.prefix.replace(/-/g, '_');
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

    isModuleEnabled(moduleName: string): boolean {
        return runMagentoPhp(`
            echo $om->get(\\Magento\\Framework\\Module\\Manager::class)
                ->isEnabled(${JSON.stringify(moduleName)}) ? 'yes' : 'no';
        `).trim() === 'yes';
    }

    isStockIndexScheduled(): boolean {
        return runMagentoPhp(`
            echo $om->get(\\Magento\\Framework\\Indexer\\IndexerRegistry::class)
                ->get('cataloginventory_stock')
                ->isScheduled() ? 'yes' : 'no';
        `).trim() === 'yes';
    }

    cartUrl(): string {
        return runMagentoPhp(`
            echo $om->get(\\Magento\\Store\\Model\\StoreManagerInterface::class)
                ->getStore(${CatalogFixture.STORE_ID})
                ->getUrl('checkout/cart');
        `).trim();
    }

    /**
     * What is left to sell. With MSI that is the salable quantity, stock minus open reservations.
     * Without MSI, legacy CatalogInventory deducts the stock item itself when the order is placed.
     */
    salableQty(sku: string): number {
        return parseFloat(runMagentoPhp(`
            echo $om->get(\\Magento\\Framework\\Module\\Manager::class)->isEnabled('Magento_InventorySalesApi')
                ? $om->get(\\Magento\\InventorySalesApi\\Api\\GetProductSalableQtyInterface::class)
                    ->execute(${JSON.stringify(sku)}, 1)
                : $om->get(\\Magento\\CatalogInventory\\Api\\StockRegistryInterface::class)
                    ->getStockItemBySku(${JSON.stringify(sku)})
                    ->getQty();
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
            $attribute = $om->get(\\Magento\\Eav\\Model\\Config::class)
                ->getAttribute('catalog_product', ${JSON.stringify(this.attributeCode())});
            if ($attribute->getId()) {
                $om->get(\\Magento\\Catalog\\Api\\ProductAttributeRepositoryInterface::class)->delete($attribute);
            }
        `);
    }
}
