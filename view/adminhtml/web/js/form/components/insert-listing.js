/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */
define([
    'underscore',
    'Magento_Ui/js/form/components/insert-listing'
], function (_, InsertListing) {
    'use strict';

    /**
     * Core fetches ticked products that are not on the current page from the server, dropping the
     * grid's filters but keeping its keyword search, so a product ticked under an earlier search
     * never reaches the aggregate. Without the search, the request returns every ticked product.
     */
    return InsertListing.extend({
        updateFromServerData: function (selections, itemsType) {
            if (selections.excludeMode) {
                return this._super(selections, itemsType);
            }

            return this._super(_.extend({}, selections, {
                params: _.omit(selections.params || {}, 'search')
            }), itemsType);
        }
    });
});
