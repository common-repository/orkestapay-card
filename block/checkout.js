const orkestapayCardSettings = window.wc.wcSettings.getSetting('orkestapay-card_data', {});
const orkestapayCardLabel = window.wp.htmlEntities.decodeEntities(orkestapayCardSettings.title) || window.wp.i18n.__('Orkestapay', 'orkestapay-card');

var orkestapay = initOrkestaPay({
    merchant_id: orkestapayCardSettings.merchant_id,
    public_key: orkestapayCardSettings.public_key,
    is_sandbox: orkestapayCardSettings.is_sandbox,
});
console.log('orkestapay.js is ready!', orkestapay);

const cardExpiryValue = (value) => {
    var month, prefix, year, _ref;
    value = value.replace(/\s/g, '');
    (_ref = value.split('/', 2)), (month = _ref[0]), (year = _ref[1]);
    if ((year != null ? year.length : void 0) === 2 && /^\d+$/.test(year)) {
        prefix = new Date().getFullYear();
        prefix = prefix.toString().slice(0, 2);
        year = prefix + year;
    }

    return {
        month: month,
        year: year,
    };
};

const createPayment = async (paymentMethodId, deviceSessionId, billingAddress, shippingAddress) => {
    // Realizar la llamada AJAX
    const response = await fetch(orkestapayCardSettings.orkestapay_create_payment_blocks_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            payment_method_id: paymentMethodId,
            device_session_id: deviceSessionId,
            shipping_address: shippingAddress,
            billing_address: billingAddress,
        }),
    });

    return response.ok ? await response.json() : { success: false, data: { message: response.statusText } };
};

const complete3DSPayment = async (data) => {
    const response = await fetch(orkestapayCardSettings.orkestapay_complete_3ds_payment_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    });

    return response.ok ? await response.json() : { success: false, data: { message: response.statusText } };
};

const OrkestapayCardContent = (props) => {
    const { eventRegistration, emitResponse, billing, shippingData } = props;
    const { onPaymentProcessing } = eventRegistration;

    wp.element.useEffect(() => {
        jQuery('.wc-credit-card-form-card-number').payment('formatCardNumber');
        jQuery('.wc-credit-card-form-card-expiry').payment('formatCardExpiry');
        jQuery('.wc-credit-card-form-card-cvc').payment('formatCardCVC');
    }, []);

    wp.element.useEffect(() => {
        const unsubscribe = onPaymentProcessing(async () => {
            try {
                const deviceSessionid = document.getElementById('orkestapay_device_session_id');
                const cardNumber = document.getElementById('orkestapay-card-number');
                const holderName = document.getElementById('orkestapay-holder-name');
                const verificationCode = document.getElementById('orkestapay-card-cvc');
                const expirationDate = document.getElementById('orkestapay-card-expiry');
                const expiry = cardExpiryValue(expirationDate.value);

                const card = {
                    card_number: cardNumber.value,
                    expiration_date: { expiration_month: expiry['month'], expiration_year: expiry['year'] },
                    verification_code: verificationCode.value,
                    holder_name: holderName.value,
                };

                const orkestapayCard = await orkestapay.createCard();
                const paymentMethodData = {
                    card,
                    one_time_use: true,
                    billing_address: {
                        first_name: billing.billingAddress.first_name,
                        last_name: billing.billingAddress.first_name,
                        email: billing.billingAddress.email,
                        line_1: billing.billingAddress.address_1,
                        line_2: billing.billingAddress.address_2,
                        city: billing.billingAddress.city,
                        state: billing.billingAddress.state,
                        country: billing.billingAddress.country,
                        zip_code: billing.billingAddress.postcode,
                    },
                };

                const paymentMethod = await orkestapayCard.createToken(paymentMethodData);

                // Asigna un valor al campo de texto "orkestapay_payment_method_id"
                const paymentMethodInput = document.getElementById('orkestapay_payment_method_id');
                paymentMethodInput.value = paymentMethod.payment_method_id;

                // Realiza la petición de pago
                const paymentResponse = await createPayment(paymentMethod.payment_method_id, deviceSessionid.value, billing.billingAddress, shippingData.shippingAddress);

                const { data, success } = paymentResponse;

                // Verificar si la respuesta fue exitosa
                if (!success) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: data.message,
                    };
                }

                // Asigna un valor al campo de texto "orkestapay_payment_id"
                const paymentIdInput = document.getElementById('orkestapay_payment_id');
                paymentIdInput.value = data.payment_id;

                // Asigna un valor al campo de texto "orkestapay_order_id"
                const orderIdInput = document.getElementById('orkestapay_order_id');
                orderIdInput.value = data.order_id;

                // If the payment is completed, submit the form
                if (data.status === 'COMPLETED') {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                orkestapay_payment_method_id: paymentMethod.payment_method_id,
                                orkestapay_device_session_id: deviceSessionid.value,
                                orkestapay_order_id: data.order_id,
                                orkestapay_payment_id: data.payment_id,
                            },
                        },
                    };
                }

                // If the payment is failed or rejected, display the error message
                if (data.status === 'FAILED' || data.status === 'REJECTED') {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: data.message,
                    };
                }

                // Start the 3DSecure Global
                if (data.status === 'PAYMENT_ACTION_REQUIRED' && data.user_action_required.type === 'THREE_D_SECURE_AUTHENTICATION') {
                    console.log('startModal3DSecure', data.user_action_required.three_d_secure_authentication.merchant_provider_id);
                    const result = await orkestapay.startModal3DSecure({
                        merchant_provider_id: data.user_action_required.three_d_secure_authentication.merchant_provider_id,
                        payment_id: data.payment_id,
                        order_id: data.order_id,
                    });

                    console.log('startModal3DSecure', result);

                    if (result) {
                        const response3DS = await complete3DSPayment({ orkestapay_payment_id: data.payment_id });
                        if (response3DS.data.status !== 'SUCCESS') {
                            return {
                                type: emitResponse.responseTypes.ERROR,
                                message: response3DS.data.message,
                            };
                        }
                    }
                }

                // Redirect to the 3DSecure page
                if (data.status === 'PAYMENT_ACTION_REQUIRED' && data.user_action_required.type === 'THREE_D_SECURE_SPECIFIC') {
                    window.location.href = data.user_action_required.three_d_secure_specific.three_ds_redirect_url;
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: '',
                    };
                }
            } catch (error) {
                console.error('Error:', error.message);
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: error.message,
                };
            }
        });
        // Unsubscribes when this component is unmounted.
        return () => {
            unsubscribe();
        };
    }, [emitResponse.responseTypes.ERROR, emitResponse.responseTypes.SUCCESS, onPaymentProcessing, billing.billingAddress]);

    return wp.element.RawHTML({
        children: orkestapayCardSettings.orkestapay_form,
    });
};

const Orkestapay_Card_Block_Gateway = {
    name: 'orkestapay-card',
    label: orkestapayCardLabel,
    content: window.wp.element.createElement(OrkestapayCardContent, null),
    edit: window.wp.element.createElement(OrkestapayCardContent, null),
    canMakePayment: () => true,
    ariaLabel: orkestapayCardLabel,
    supports: {
        features: orkestapayCardSettings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(Orkestapay_Card_Block_Gateway);
