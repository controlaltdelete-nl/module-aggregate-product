# Nfourteen_AggregateProduct

Sell a group of products at fixed quantities as a single Magento 2 product, while still tracking each child's own inventory.

## How it works

Adding an aggregate to the cart adds its children as separate line items, each linked to the parent, each quantity multiplied by the configured one. A child can belong to several aggregates, which then share its inventory.

## Features

- The `aggregate` product type, with an admin UI for children and quantities
- Aggregate contents shown on the frontend and in order history
- Children as separate cart and order line items
- GraphQL via `Nfourteen_AggregateProductGraphQl`
- MSI salability and stock movements via `Nfourteen_InventoryAggregateProduct`

## Creating one

**Catalog > Products > Add Product**, product type **Aggregate Product**. Fill in the standard attributes, then add children and their quantities in the **Aggregate Products** section.

## Database

`catalog_product_aggregate_link` - `link_id` (PK), `product_id` (child), `parent_id` (aggregate), `qty` DECIMAL(12,4).

## Installation

Part of the Aggregate Product suite. Install it through [`nfourteen/aggregate-product-metapackage`](https://github.com/nfourteen/aggregate-product-metapackage), whose README covers the Composer repositories these packages need and the MSI requirement. Requires PHP >= 8.3 and Magento Open Source 2.4.x with MSI enabled.
