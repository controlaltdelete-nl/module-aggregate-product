<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Block\Product\View\Type;

use Magento\Catalog\Block\Product\View\AbstractView;

/**
 * AbstractView is deprecated but has no replacement, and core product type blocks
 * (Configurable, Bundle, Grouped) still extend it; its @api annotation should
 * guarantee backward compatibility.
 */
class Aggregate extends AbstractView
{
}