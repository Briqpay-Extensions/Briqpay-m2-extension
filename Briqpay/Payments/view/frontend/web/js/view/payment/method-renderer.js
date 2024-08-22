define([
  "uiComponent",
  "Magento_Checkout/js/model/payment/renderer-list"
], function (Component, rendererList) {
  "use strict";
  rendererList.push({
      type: "briqpay",
      component: "Briqpay_Payments/js/view/payment/briqpay"
  });
  return Component.extend({});
});
