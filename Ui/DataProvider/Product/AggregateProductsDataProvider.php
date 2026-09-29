<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Ui\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Nfourteen\AggregateProduct\Model\Product\AllowedChildTypes;

class AggregateProductsDataProvider extends ProductDataProvider
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     * @param array<string, mixed> $addFieldStrategies
     * @param array<string, mixed> $addFilterStrategies
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly AllowedChildTypes $allowedChildTypes,
        private readonly StoreRepositoryInterface $storeRepository,
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = [],
        array $addFieldStrategies = [],
        array $addFilterStrategies = []
    ) {
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $collectionFactory,
            $addFieldStrategies,
            $addFilterStrategies,
            $meta,
            $data
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        /** @var Collection $collection */
        $collection = $this->getCollection();
        if (!$collection->isLoaded()) {
            $collection->addAttributeToFilter('type_id', $this->allowedChildTypes->get());
            $collection->addAttributeToFilter('required_options', ['eq' => 0]);

            if ($storeId = $this->request->getParam('current_store_id')) {
                $store = $this->storeRepository->getById($storeId);
                $collection->setStore($store);
            }
            $collection->load();
        }

        $items = $collection->toArray();
        return [
            'totalRecords' => $collection->getSize(),
            'items' => array_values($items),
        ];
    }
}

