define([
  "Magento_Checkout/js/view/payment/default",
  "jquery",
  "mage/url",
  "ko",
  "Magento_Checkout/js/model/quote",
  "Magento_Checkout/js/model/totals",
  "Magento_Checkout/js/model/payment/method-list",
  "Magento_Checkout/js/model/payment/additional-validators",
], function (Component, $, urlBuilder, ko, quote, totals, paymentMethodList) {
  "use strict"
  return Component.extend({
    defaults: {
      template: "Briqpay_Payments/payment/briqpay",
    },

    initialize: function () {
      this._super()
      this.message = ko.observable("Loading...")
      this.loadMessage()
      this.qouteChange()
      // Retrieve the title from configuration
      this.title = window.checkoutConfig.payment?.briqpay?.title
        ? window.checkoutConfig.payment.briqpay.title
        : "Payment Methods"
      this.hasMultiplePaymentMethods = ko.computed(function () {
        // Check the length of active payment methods
        return paymentMethodList().length > 1
      }, this)
      return this
    },

    loadMessage: function () {
      var self = this
      var guestEmailEncoded = btoa(quote.guestEmail)
      var ajaxUrl = urlBuilder.build(
        "briqpay/index/session?hash=" + guestEmailEncoded
      )
      $.ajax({
        url: ajaxUrl,
        type: "GET",
        success: function (response) {
          self.message(response.message)
        },
        error: function () {
          self.message("Error loading message")
        },
      })
    },

    briqpayTitle: function () {
      return this.hasMultiplePaymentMethods() ? this.title : ""
    },

    qouteChange: function () {
      var initialTotalsSet = false

      // Observe changes to the shipping address
      quote.shippingAddress.subscribe(function (newAddress) {
        // Your custom logic here
      })

      // Observe changes to the billing address
      quote.billingAddress.subscribe(function (newAddress) {
        // Your custom logic here
      })

      // Observe changes to the totals object
      totals.totals.subscribe(function () {
        if (initialTotalsSet) {
          var guestEmailEncoded = btoa(quote.guestEmail)
          var ajaxUrl = urlBuilder.build(
            "briqpay/index/session?hash=" + guestEmailEncoded
          )
          //Suspend the iframe
          window._briqpay.v3.suspend()
          $.ajax({
            url: ajaxUrl,
            type: "GET",
            success: function () {
              window._briqpay.v3.resume()
            },
            error: function () {
              window._briqpay.v3.resume()
            },
          })
        } else {
          initialTotalsSet = true
        }
      })
    },
  })
})
