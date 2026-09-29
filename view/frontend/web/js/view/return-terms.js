define([
    'uiComponent',
    'mage/storage',
    'mage/translate',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/resource-url-manager',
    'Magento_Catalog/js/price-utils'
], function (Component, storage, $t, quote, resourceUrlManager, priceUtils) {
    'use strict';

    function rememberDisplayed(displayed) {
        document.cookie = 'rs_return_terms=' + (displayed ? '1' : '') + '; Path=/; SameSite=Lax' +
            (displayed ? '' : '; Max-Age=0') + (location.protocol === 'https:' ? '; Secure' : '');
    }

    return Component.extend({
        defaults: {
            template: 'ReturnSentry_Connector/return-terms',
            terms: null
        },

        initObservable: function () {
            this._super().observe(['terms']);

            return this;
        },

        initialize: function () {
            this._super();
            this.refresh();
            quote.totals.subscribe(this.refresh, this);

            return this;
        },

        refresh: function () {
            var self = this,
                url = resourceUrlManager.getUrl({
                    guest: '/guest-carts/:cartId/returnsentry-return-terms',
                    customer: '/carts/mine/returnsentry-return-terms'
                }, {cartId: quote.getQuoteId()});

            storage.get(url, false).done(function (terms) {
                self.terms(terms && terms.paid === true ? terms : null);
                rememberDisplayed(self.terms() !== null);
            }).fail(function () {
                self.terms(null);
                rememberDisplayed(false);
            });
        },

        notice: function () {
            var terms = this.terms();

            if (terms && typeof terms['label_cost'] === 'number') {
                return $t('Returns for this order are at your own cost: the return label, %1, is deducted from your refund.')
                    .replace('%1', priceUtils.formatPrice(terms['label_cost'], quote.getBasePriceFormat()));
            }

            return $t('Returns for this order are at your own cost.');
        }
    });
});
