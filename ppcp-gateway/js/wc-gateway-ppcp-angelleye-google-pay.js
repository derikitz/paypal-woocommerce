
class GooglePayCheckoutButton {
    static googlePayConfig;
    static isConfigLoading;
    static configPromise;
    static googlePayObject;
    static baseRequest = {
        apiVersion: 2,
        apiVersionMinor: 0,
    }
    constructor() {

    }

    /**
     * This function makes sure google pay config is loaded once for all the render calls.
     * @returns {Promise<*>}
     */
    async initGooglePayConfig() {
        if (GooglePayCheckoutButton.isConfigLoading) {
            if (!GooglePayCheckoutButton.configPromise) {
                GooglePayCheckoutButton.configPromise = new Promise((resolve, reject) => {
                    let configLoop = setInterval(() => {
                        if (GooglePayCheckoutButton.googlePayConfig) {
                            resolve(GooglePayCheckoutButton.googlePayConfig);
                            clearInterval(configLoop);
                        }
                    }, 100);
                });
            }
            return GooglePayCheckoutButton.configPromise;
        }
        if (GooglePayCheckoutButton.googlePayConfig) {
            return GooglePayCheckoutButton.googlePayConfig;
        }
        GooglePayCheckoutButton.isConfigLoading = true;
        GooglePayCheckoutButton.googlePayConfig = await GooglePayCheckoutButton.googlePay().config();
        return GooglePayCheckoutButton.googlePayConfig;
    }

    static googlePay() {
        if (!GooglePayCheckoutButton.googlePayObject) {
            GooglePayCheckoutButton.googlePayObject = angelleye_paypal_sdk.Googlepay();
        }
        return GooglePayCheckoutButton.googlePayObject;
    }

    render(containerSelector) {
        if (jQuery(containerSelector).length === 0) {
            return;
        }
        this.initGooglePayConfig().then(() => {
            let thisObj = this;
            let paymentsClient = this.getGooglePaymentsClient();
            let allowedPaymentMethods = GooglePayCheckoutButton.googlePayConfig.allowedPaymentMethods;
            paymentsClient.isReadyToPay(this.getGoogleIsReadyToPayRequest(allowedPaymentMethods))
                .then((response) => {
                    if (response.result) {
                        this.showGooglePayPaymentMethod();
                        this.renderButton(containerSelector);
                    }
                })
                .catch((err) => {

                    this.removeGooglePayPaymentMethod(containerSelector);
                });
        });
    }

    getGoogleIsReadyToPayRequest(allowedPaymentMethods) {
        return Object.assign({}, GooglePayCheckoutButton.baseRequest, {
            allowedPaymentMethods: allowedPaymentMethods,
        });
    }

    isShippingRequired() {
        const cartDetails = angelleyeOrder.getCartDetails();
        return !angelleyeOrder.isCheckoutPage() && cartDetails && typeof cartDetails.shippingRequired !== 'undefined' && cartDetails.shippingRequired;
    }

    getGooglePaymentsClient(data) {
        return new google.payments.api.PaymentsClient({
            environment: angelleye_ppcp_manager.sandbox_mode === '1' ? "TEST" : "PRODUCTION",
            paymentDataCallbacks: {
                onPaymentDataChanged: (this.isShippingRequired() ? this.onPaymentDataChanged.bind(null, {
                    thisObject: this
                }) : null),
                onPaymentAuthorized: this.onPaymentAuthorized.bind(null, {
                    thisObject: this
                }),
            },
        });
    }

    showGooglePayPaymentMethod() {
        if (angelleyeOrder.isCheckoutPage()) {
            jQuery('.wc_payment_method.payment_method_angelleye_ppcp_google_pay').show();
        }
    }

    removeGooglePayPaymentMethod(containerSelector) {
        if (angelleyeOrder.isCheckoutPage()) {
            jQuery('.wc_payment_method.payment_method_angelleye_ppcp_google_pay').hide();
        }
        if (jQuery(containerSelector).length) {
            jQuery(containerSelector).remove();
        }
    }

    parseErrorMessage(errorObject) {
        if (errorObject && errorObject.name === 'PayPalGooglePayError') {
            let debugID = errorObject.paypalDebugId;
            switch (errorObject.errorName) {
                case 'ERROR_VALIDATING_MERCHANT':
                    return localizedMessages.error_validating_merchant + ' [GooglePay DebugId:' + debugID + ']';
                //case 'UNPROCESSABLE_ENTITY':
                //    return JSON.stringify(errorObject);
                default:
                    return localizedMessages.general_error_message + ' [GooglePay DebugId:' + debugID + ']';
            }
        }
        return typeof errorObject === 'string' ? errorObject :
            (errorObject && typeof errorObject.message === 'string' ? errorObject.message : localizedMessages.general_error_message);
    }

    paymentError(error) {
        return {transactionState: 'ERROR', error: {
            intent: 'PAYMENT_AUTHORIZATION',
            reason: 'PAYMENT_DATA_INVALID',
            message: this.parseErrorMessage(error)
        }};
    }

    async onPaymentAuthorized(additionalData, paymentData) {
        const thisObject = additionalData.thisObject;
        if (thisObject.paymentInFlight || thisObject.paymentStatusUncertain) {
            return thisObject.paymentError(angelleye_ppcp_manager.payment_status_unconfirmed);
        }
        thisObject.pendingRedirect = null;
        angelleyeOrder.showProcessingSpinner();
        let timeout;
        try {
            const payment = Promise.resolve().then(() => thisObject.processPayment(additionalData, paymentData))
                .finally(() => { thisObject.paymentInFlight = null; });
            thisObject.paymentInFlight = payment;
            const outcome = await Promise.race([payment, new Promise((resolve, reject) => {
                // Google requires an authorization callback response within 30s.
                timeout = setTimeout(() => {
                    thisObject.paymentStatusUncertain = true;
                    reject(new Error(angelleye_ppcp_manager.payment_status_unconfirmed));
                }, 25000);
            })]);
            if (!outcome || outcome.transactionState !== 'SUCCESS' || typeof outcome.redirect !== 'string' || outcome.redirect === '') {
                throw new Error(outcome && outcome.error ? outcome.error.message : localizedMessages.general_error_message);
            }
            thisObject.pendingRedirect = outcome.redirect;
            return {transactionState: 'SUCCESS'};
        } catch (error) {
            const result = thisObject.paymentError(error);
            angelleyeOrder.hideProcessingSpinner();
            angelleyeOrder.showError(result.error.message);
            return result;
        } finally {
            clearTimeout(timeout);
        }
    }

    onPaymentDataChanged(additionalData, intermediatePaymentData) {
        return new Promise(async function(resolve, reject) {

            let shippingAddress = intermediatePaymentData.shippingAddress;
            let paymentDataRequestUpdate = {};

            if (intermediatePaymentData.callbackTrigger == "INITIALIZE" || intermediatePaymentData.callbackTrigger == "SHIPPING_ADDRESS") {

                try {
                    let shippingDetails = {
                        administrativeArea: shippingAddress.administrativeArea,
                        countryCode: shippingAddress.countryCode,
                        locality: shippingAddress.locality,
                        postalCode: shippingAddress.postalCode
                    };
                    let response = await angelleyeOrder.shippingAddressUpdate({shippingDetails: shippingDetails}, undefined, undefined, additionalData.thisObject.containerSelector);
                    if (typeof response.totalAmount !== 'undefined') {
                        angelleyeOrder.updateCartTotalsInEnvironment(response);
                        paymentDataRequestUpdate.newTransactionInfo = additionalData.thisObject.getGoogleTransactionInfo();
                    } else {
                        throw new Error(localizedMessages.shipping_amount_update_error);
                    }
                } catch (error) {

                    angelleyeOrder.handleCreateOrderError(additionalData.thisObject.parseErrorMessage(error), additionalData.thisObject.errorLogId);
                    paymentDataRequestUpdate.error = localizedMessages.shipping_amount_pull_error;
                    reject(localizedMessages.shipping_amount_pull_error);
                }
            }

            resolve(paymentDataRequestUpdate);
        });
    }

    async processPayment(additionalData, paymentData) {
        try {
            let thisObject = additionalData.thisObject;
            angelleyeOrder.showProcessingSpinner();
            /* Create Order */
            let orderID = await angelleyeOrder.createOrder({
                angelleye_ppcp_button_selector: thisObject.containerSelector,
                errorLogId: additionalData.thisObject.errorLogId
            }).then((orderData) => {
                if (!orderData || !orderData.orderID) {
                    throw new Error(localizedMessages.general_error_message);
                }
                angelleyeOrder.updateCartTotalsInEnvironment(orderData);
                return orderData.orderID;
            });

            const { status } = await GooglePayCheckoutButton.googlePay().confirmOrder({
                orderId: orderID,
                paymentMethodData: paymentData.paymentMethodData,
            });
            if (status === "APPROVED") {
                // check if the billing address details are available
                // Do not run this service on checkout page as user already provides all details there
                if (!angelleyeOrder.isCheckoutPage()) {
                    let billingDetails;
                    let shippingDetails = paymentData.shippingAddress;
                    if (paymentData.paymentMethodData && paymentData.paymentMethodData.info && paymentData.paymentMethodData.info.billingAddress) {
                        billingDetails = paymentData.paymentMethodData.info.billingAddress;
                    }
                    if (paymentData.email) {
                        if (!billingDetails) {
                            billingDetails = {};
                        }
                        billingDetails.emailAddress = paymentData.email;
                    }
                    await angelleyeOrder.shippingAddressUpdate({shippingDetails}, {billingDetails}, additionalData.thisObject.errorLogId);
                }
                /* Capture the Order */
                const outcome = await angelleyeOrder.approveOrder({orderID, payerID: '', errorLogId: additionalData.thisObject.errorLogId, deferRedirect: true});
                if (!outcome || !['success', 'redirect'].includes(outcome.result) || typeof outcome.redirect !== 'string' || outcome.redirect === '') {
                    throw new Error(localizedMessages.general_error_message);
                }
                return {transactionState: 'SUCCESS', redirect: outcome.redirect};
            } else {
                return thisObject.paymentError(localizedMessages.general_error_message);
            }
        } catch (error) {
            if (error && error.paymentStatusUncertain) {
                additionalData.thisObject.paymentStatusUncertain = true;
                return additionalData.thisObject.paymentError(angelleye_ppcp_manager.payment_status_unconfirmed);
            }
            return additionalData.thisObject.paymentError(error);
        }
    }

    renderButton(containerSelector) {
        this.containerSelector = containerSelector;
        let container = jQuery(containerSelector);
        container.html('');

        let buttonColor = 'default';
        let buttonType = 'plain';
        let containerStyle = '';
        if (typeof angelleye_ppcp_manager.google_pay_button_props !== 'undefined') {
            buttonColor = angelleye_ppcp_manager.google_pay_button_props.buttonColor;
            buttonType = angelleye_ppcp_manager.google_pay_button_props.buttonType;
            let height = angelleye_ppcp_manager.google_pay_button_props.height;
            height = height !== '' ? 'height: ' + height + 'px;' : '';
            containerStyle = height;
        }
        let googlePayContainer = jQuery('<div class="google-pay-container" style="'+(containerStyle != '' ? containerStyle  : '')+'"></div>');
        let thisObject = this;
        const paymentsClient = this.getGooglePaymentsClient();
        const button = paymentsClient.createButton({
            buttonColor: buttonColor,
            buttonType: buttonType,
            buttonSizeMode: 'fill',
            onClick: async (event) => {
                await thisObject.handleClickEvent(event, thisObject);
            }
        });
        googlePayContainer.append(button);

        // Remove the separator
        // if (!angelleyeOrder.isCheckoutPage()) {
        //     let separator = jQuery('<div class="angelleye_ppcp-proceed-to-checkout-button-separator">&mdash; OR &mdash;</div><br>');
        //     container.html(separator);
        // }
        container.append(googlePayContainer);
    }

    getGoogleTransactionInfo() {
        let displayItems = [];
        const cartDetails = angelleyeOrder.getCartDetails();
        for (let i = 0; i < (cartDetails.lineItems).length; i++) {
            let type = "LINE_ITEM";
            let prodLabel = cartDetails.lineItems[i].label;
            if (prodLabel.toLowerCase() === 'tax') {
                type = 'TAX';
            }
            displayItems.push({
                'label': cartDetails.lineItems[i].label,
                'price': cartDetails.lineItems[i].amount,
                'type': type,
            })
        }

        return {
            displayItems: displayItems,
            currencyCode: cartDetails.currencyCode,
            totalPriceStatus: "FINAL",
            totalPrice: cartDetails.totalAmount,
            totalPriceLabel: "Total",
        };
    }

    getGooglePaymentDataRequest() {
        const paymentDataRequest = Object.assign({}, GooglePayCheckoutButton.baseRequest);
        paymentDataRequest.allowedPaymentMethods = GooglePayCheckoutButton.googlePayConfig.allowedPaymentMethods;
        paymentDataRequest.transactionInfo = this.getGoogleTransactionInfo();
        paymentDataRequest.merchantInfo = GooglePayCheckoutButton.googlePayConfig.merchantInfo;
        paymentDataRequest.emailRequired = true;
        paymentDataRequest.callbackIntents = ["PAYMENT_AUTHORIZATION"];
        if (this.isShippingRequired()) {
            paymentDataRequest.callbackIntents.push("SHIPPING_ADDRESS");
            paymentDataRequest.shippingAddressRequired = true;
        }
        return paymentDataRequest;
    }

    async handleClickEvent(event, thisObject) {
        if (thisObject.paymentInFlight || thisObject.paymentStatusUncertain) {
            angelleyeOrder.showError(angelleye_ppcp_manager.payment_status_unconfirmed);
            return;
        }

        thisObject.errorLogId = angelleyeJsErrorLogger.generateErrorId();
        const cartDetails = angelleyeOrder.getCartDetails();
        if (cartDetails.totalAmount <= 0) {
            angelleyeOrder.showError(localizedMessages.empty_cart_message);
            return;
        }
        angelleyeJsErrorLogger.addToLog(thisObject.errorLogId, 'Google Pay Payment Started');
        angelleyeOrder.setPaymentMethodSelector('google_pay');
        const paymentDataRequest = thisObject.getGooglePaymentDataRequest();
        const paymentsClient = thisObject.getGooglePaymentsClient({});

        thisObject.pendingRedirect = null;
        return paymentsClient.loadPaymentData(paymentDataRequest).then(() => {
            if (thisObject.pendingRedirect) {
                window.location.href = thisObject.pendingRedirect;
            }
        }, (e) => {
            thisObject.pendingRedirect = null;
            angelleyeOrder.triggerPaymentCancelEvent();
            angelleyeOrder.hideProcessingSpinner(thisObject.containerSelector);
            angelleyeOrder.hideProcessingSpinner();

        });

    }
}
