(function () {
    'use strict';
    function initializePayPal() {

        angelleyeOrder.addPaymentMethodAdvancedCreditCard();
    }
    angelleyeLoadPayPalScript({
        url: angelleye_ppcp_manager.paypal_sdk_url,
        script_attributes: angelleye_ppcp_manager.paypal_sdk_attributes
    }, initializePayPal);
})(jQuery);
