<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * OrkestaPayCard_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

if (!defined('ABSPATH')) {
    exit();
}

class OrkestaPayCard_Gateway extends WC_Payment_Gateway
{
    protected $test_mode = true;
    protected $merchant_id;
    protected $client_id;
    protected $client_secret;
    protected $public_key;
    protected $plugin_version = '1.0.1';

    const PAYMENT_ACTION_REQUIRED = 'PAYMENT_ACTION_REQUIRED';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_SUCCESS = 'SUCCESS';

    public function __construct()
    {
        $this->id = 'orkestapay-card'; // Payment gateway plugin ID
        $this->method_title = __('OrkestaPay Card', 'orkestapay-card');
        $this->method_description = __('Orchestrate multiple payment gateways for a frictionless, reliable, and secure checkout experience.', 'orkestapay-card');
        $this->has_fields = true;
        $this->supports = ['products', 'refunds', 'tokenization', 'add_payment_method'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];

        $this->enabled = $this->settings['enabled'];
        $this->test_mode = strcmp($this->settings['test_mode'], 'yes') == 0;
        $this->merchant_id = $this->settings['merchant_id'];
        $this->client_id = $this->settings['client_id'];
        $this->client_secret = $this->settings['client_secret'];
        $this->public_key = $this->settings['public_key'];

        OrkestaPayCard_API::set_client_id($this->client_id);
        OrkestaPayCard_API::set_client_secret($this->client_secret);

        if ($this->test_mode) {
            $this->description .= __('TEST MODE ENABLED. In test mode, you can use the card number 4242424242424242 with any CVC and a valid expiration date.', 'orkestapay-card');
        }

        add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('woocommerce_api_orkestapay_card_create_payment', [$this, 'orkestapay_card_create_payment']);
        add_action('woocommerce_api_orkestapay_card_return_url', [$this, 'orkestapay_card_return_url']);
        add_action('woocommerce_api_orkestapay_card_complete_3ds_payment', [$this, 'orkestapay_card_complete_3ds_payment']);
        add_action('woocommerce_api_orkestapay_card_create_payment_blocks', [$this, 'orkestapay_card_create_payment_blocks']);

        // This action hook saves the settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * Plugin options
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable OrkestaPay', 'orkestapay-card'),
                'label' => __('Enable', 'orkestapay-card'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('Check the box to enable Orkesta as a payment method.', 'orkestapay-card'),
            ],
            'test_mode' => [
                'title' => __('Enable test mode', 'orkestapay-card'),
                'label' => __('Enable', 'orkestapay-card'),
                'type' => 'checkbox',
                'default' => 'yes',
                'description' => __('Check the box to make test payments.', 'orkestapay-card'),
            ],
            'title' => [
                'title' => __('Title', 'orkestapay-card'),
                'type' => 'text',
                'default' => __('Credit Card', 'orkestapay-card'),
                'description' => __('Payment method title that the customer will see on your checkout.', 'orkestapay-card'),
            ],
            'description' => [
                'title' => __('Description', 'orkestapay-card'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your website.', 'orkestapay-card'),
                'default' => __('Pay with your credit or debit card.', 'orkestapay-card'),
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'orkestapay-card'),
                'type' => 'text',
                'default' => '',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                ],
            ],
            'client_id' => [
                'title' => __('Client ID', 'orkestapay-card'),
                'type' => 'text',
                'default' => '',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                    'role' => 'presentation',
                ],
            ],
            'client_secret' => [
                'title' => __('Client Secret', 'orkestapay-card'),
                'type' => 'password',
                'default' => '',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                    'role' => 'presentation',
                ],
            ],
            'public_key' => [
                'title' => __('Public Key', 'orkestapay-card'),
                'type' => 'text',
                'default' => '',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                ],
            ],
        ];
    }

    /**
     * Handles admin notices
     *
     * @return void
     */
    public function admin_notices()
    {
        if ('no' == $this->enabled) {
            return;
        }

        /**
         * Check if WC is installed and activated
         */
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            // WooCommerce is NOT enabled!
            echo wp_kses_post('<div class="error"><p>');
            echo esc_html_e('OrkestaPay needs WooCommerce plugin is installed and activated to work.', 'orkestapay-card');
            echo wp_kses_post('</p></div>');
            return;
        }
    }

    function admin_options()
    {
        wp_enqueue_style('font_montserrat', 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&display=swap', ORKESTAPAY_CARD_WC_PLUGIN_FILE, [], $this->plugin_version);
        wp_enqueue_style('orkesta_admin_style', plugins_url('assets/css/admin-style.css', ORKESTAPAY_CARD_WC_PLUGIN_FILE), [], $this->plugin_version);

        $this->logo = plugins_url('assets/images/orkestapay.svg', ORKESTAPAY_CARD_WC_PLUGIN_FILE);

        include_once dirname(__DIR__) . '/templates/admin.php';
    }

    public function process_admin_options()
    {
        $settings = new WC_Admin_Settings();

        $post_data = $this->get_post_data();
        $client_id = $post_data['woocommerce_' . $this->id . '_client_id'];
        $client_secret = $post_data['woocommerce_' . $this->id . '_client_secret'];
        $merchant_id = $post_data['woocommerce_' . $this->id . '_merchant_id'];
        $public_key = $post_data['woocommerce_' . $this->id . '_public_key'];

        $this->settings['title'] = $post_data['woocommerce_' . $this->id . '_title'];
        $this->settings['description'] = $post_data['woocommerce_' . $this->id . '_description'];
        $this->settings['merchant_id'] = $merchant_id;
        $this->settings['public_key'] = $public_key;

        $this->settings['test_mode'] = $post_data['woocommerce_' . $this->id . '_test_mode'] == '1' ? 'yes' : 'no';
        $this->settings['enabled'] = $post_data['woocommerce_' . $this->id . '_enabled'] == '1' ? 'yes' : 'no';
        $this->test_mode = $post_data['woocommerce_' . $this->id . '_test_mode'] == '1';

        if ($merchant_id == '' || $public_key == '') {
            $this->settings['enabled'] = 'no';
            $settings->add_error('You need to enter all your credentials if you want to use this plugin.');
            return;
        }

        if (!$this->validateOrkestaCredentials($client_id, $client_secret)) {
            $this->settings['enabled'] = 'no';
            $this->settings['client_id'] = '';
            $this->settings['client_secret'] = '';

            $settings->add_error(__('Provided credentials are invalid.', 'orkestapay-card'));
        } else {
            $this->settings['client_id'] = $client_id;
            $this->settings['client_secret'] = $client_secret;
            $settings->add_message(__('Configuration completed successfully.', 'orkestapay-card'));
        }

        return update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings), 'yes');
    }

    /**
     * Loads (enqueue) static files (js & css) for the checkout page
     *
     * @return void
     */
    public function payment_scripts()
    {
        if (!is_checkout()) {
            return;
        }

        $cart = WC()->cart;
        $payment_args = [
            'currency' => get_woocommerce_currency(),
            'total_amount' => $cart->total,
            'plugin_payment_gateway_id' => $this->id,
            'merchant_id' => $this->getMerchantId(),
            'public_key' => $this->getPublicKey(),
            'is_sandbox' => $this->isTestMode(),
            'orkestapay_create_payment_url' => $this->getOrkestapayCreatePaymentUrl(),
            'orkestapay_complete_3ds_payment_url' => $this->getOrkestapayComplete3DSPaymentUrl(),
        ];

        $js_url = ORKESTAPAY_CARD_JS_URL;

        wp_enqueue_script('wc-credit-card-form');
        wp_enqueue_script('orkestapay_card_js_resource', $js_url . '/script/orkestapay.js', [], $this->plugin_version, true);
        wp_enqueue_script('orkestapay_card_payment_js', plugins_url('assets/js/orkesta-payment.js', ORKESTAPAY_CARD_WC_PLUGIN_FILE), ['jquery'], $this->plugin_version, true);
        wp_enqueue_style('orkestapay_card_checkout_style', plugins_url('assets/css/checkout-style.css', ORKESTAPAY_CARD_WC_PLUGIN_FILE), [], $this->plugin_version, 'all');
        wp_localize_script('orkestapay_card_payment_js', 'orkestapay_card_payment_args', $payment_args);
    }

    public function payment_fields()
    {
        $apiHost = $this->getApiHost();
        $this->brands = OrkestaPayCard_API::retrieve("$apiHost/v1/merchants/providers/brands");

        include_once dirname(__DIR__) . '/templates/payment.php';
    }

    public function validate_fields()
    {
        if (empty($_POST['orkestapay_device_session_id']) || empty($_POST['orkestapay_payment_method_id'])) {
            wc_add_notice('Some OrkestaPay ID is missing.', 'error');
            return false;
        }

        return true;
    }

    /**
     * Process payment at checkout
     *
     * @return int $order_id
     */
    public function process_payment($order_id)
    {
        global $woocommerce;
        $order = wc_get_order($order_id);

        try {
            $orkestapayPaymentId = wc_clean($_POST['orkestapay_payment_id']);
            $orkestapayOrderId = wc_clean($_POST['orkestapay_order_id']);

            $apiHost = $this->getApiHost();
            $orkestaOrder = OrkestaPayCard_API::retrieve("$apiHost/v1/orders/$orkestapayOrderId");

            // COMPLETED - we received the payment
            if ($orkestaOrder->status === self::STATUS_COMPLETED) {
                $order->payment_complete();
                $order->add_order_note(sprintf("%s payment completed with Payment ID of '%s'", $this->method_title, $orkestapayPaymentId));

                // Verificar si HPOS está habilitado
                if (OrderUtil::custom_orders_table_usage_is_enabled()) {
                    $order->update_meta_data('_orkestapay_order_id', $orkestapayOrderId);
                    $order->update_meta_data('_orkestapay_payment_id', $orkestapayPaymentId);
                    $order->save();
                } else {
                    update_post_meta($order->get_id(), '_orkestapay_order_id', $orkestapayOrderId);
                    update_post_meta($order->get_id(), '_orkestapay_payment_id', $orkestapayPaymentId);
                }
            } else {
                // awaiting the webhook's confirmation
                $order->set_status('on-hold');
                $order->save();
            }

            // Remove cart
            $woocommerce->cart->empty_cart();

            // Redirect to the thank you page
            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Exception $e) {
            OrkestaPayCard_Logger::error('#process_payment', ['error' => $e->getMessage()]);

            $order->add_order_note(sprintf("%s Credit card payment failed with message: '%s'", $this->method_title, $e->getMessage()));
            $order->update_status('failed');
            $order->save();

            wc_add_notice(__('A transaction error occurred. Your credit card has not been charged.', 'orkestapay-card'), 'error');

            return;
        }
    }

    /**
     * Process refund.
     *
     * If the gateway declares 'refunds' support, this will allow it to refund.
     *
     * @param  int        $order_id Order ID.
     * @param  float|null $amount Refund amount.
     * @param  string     $reason Refund reason.
     * @return bool|\WP_Error True or false based on success, or a WP_Error object.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        // Se valida que la orden exista
        if (!$order) {
            return false;
        }

        // Verificar si HPOS está habilitado
        if (OrderUtil::custom_orders_table_usage_is_enabled()) {
            $orkestaOrderId = $order->get_meta('_orkestapay_order_id', true);
            $orkestaOrderId = $order->get_meta('_orkestapay_payment_id', true);
        } else {
            $orkestaOrderId = get_post_meta($order_id, '_orkestapay_order_id', true);
            $orkestaPaymentId = get_post_meta($order_id, '_orkestapay_payment_id', true);
        }

        OrkestaPayCard_Logger::log('#process_refund', [
            'order_id' => $order_id,
            'amount' => $amount,
            'reason' => $reason,
            'orkesta_order_id' => $orkestaOrderId,
            'orkesta_payment_id' => $orkestaPaymentId,
        ]);

        if (OrkestaPayCard_Helper::is_null_or_empty_string($orkestaOrderId) || OrkestaPayCard_Helper::is_null_or_empty_string($orkestaPaymentId)) {
            return false;
        }

        try {
            $apiHost = $this->getApiHost();
            $orderNote = 'Automatic refunds are not enabled in OrkestaPay. Check this configuration in your OrkestaPay account.';
            $isRefundAvailable = OrkestaPayCard_API::retrieve("$apiHost/v1/merchants/settings?id=AVAILABLE_REFUND_BY_ECOMMERCE");
            $isRefunded = true;
            $refundData = ['description' => $reason, 'amount' => floatval($amount)];

            if ($isRefundAvailable->custom_settings->AVAILABLE_REFUND_BY_ECOMMERCE === true) {
                $refundResponse = OrkestaPayCard_API::request($refundData, "$apiHost/v1/payments/{$orkestaPaymentId}/refund", 'POST');
                $isRefunded = $refundResponse->status === self::STATUS_SUCCESS;
                $orderNote = $isRefunded ? 'Refund was successfully created.' : $refundResponse->provider->message;
            }

            $order->add_order_note($orderNote);

            return $isRefunded;
        } catch (Exception $e) {
            OrkestaPayCard_Logger::error('#orkesta_woocommerce_order_refunded', ['error' => $e->getMessage()]);
            $order->add_order_note('There was an error creating the refund: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Checks if the Orkesta key is valid
     *
     * @return boolean
     */
    protected function validateOrkestaCredentials($client_id, $client_secret)
    {
        $token_result = OrkestaPayCard_API::get_access_token($client_id, $client_secret);

        if (!array_key_exists('access_token', $token_result)) {
            OrkestaPayCard_Logger::error('#validateOrkestaCredentials', ['error' => 'Error al obtener access_token']);

            return false;
        }

        // Se valida que la respuesta sea un JWT
        $regex = preg_match('/^([a-zA-Z0-9_=]+)\.([a-zA-Z0-9_=]+)\.([a-zA-Z0-9_\-\+\/=]*)/', $token_result['access_token']);
        if ($regex !== 1) {
            return false;
        }

        return true;
    }

    /**
     * AJAX - Get access token function
     *
     * @return string
     */
    public function orkesta_get_access_token()
    {
        $token_result = OrkestaPayCard_API::get_access_token($this->client_id, $this->client_secret);

        if (!array_key_exists('access_token', $token_result)) {
            OrkestaPayCard_Logger::error('#orkesta_get_access_token', ['error' => 'Error al obtener access_token']);

            wp_send_json_error(
                [
                    'result' => 'fail',
                    'message' => __('An error occurred getting access token.', 'orkestapay-card'),
                ],
                400
            );
        }

        wp_send_json_success([
            'result' => 'success',
            'access_token' => $token_result['access_token'],
        ]);

        die();
    }

    public function getApiHost()
    {
        return $this->test_mode ? ORKESTAPAY_CARD_API_SAND_URL : ORKESTAPAY_CARD_API_URL;
    }

    /**
     * Create payment. Security is handled by WC.
     *
     */
    public function orkestapay_card_create_payment()
    {
        header('HTTP/1.1 200 OK');

        $deviceSessionId = wc_clean($_POST['orkestapay_device_session_id']);
        $paymentMethodId = wc_clean($_POST['orkestapay_payment_method_id']);
        $successUrl = esc_url(WC()->api_request_url('orkestapay_card_return_url'));

        try {
            $cart = WC()->cart;
            $apiHost = $this->getApiHost();
            $orkestaPayCartId = $this->getOrkestaPayCartId();
            $cancelUrl = wc_get_checkout_url();

            $customer = [
                'id' => $cart->get_customer()->get_id(),
                'first_name' => wc_clean(wp_unslash($_POST['billing_first_name'])),
                'last_name' => wc_clean(wp_unslash($_POST['billing_last_name'])),
                'email' => wc_clean(wp_unslash($_POST['billing_email'])),
                'phone' => isset($_POST['billing_phone']) ? wc_clean(wp_unslash($_POST['billing_phone'])) : '',
                'billing_country' => wc_clean(wp_unslash($_POST['billing_country'])),
                'shipping_address_1' => isset($_POST['shipping_address_1']) ? wc_clean(wp_unslash($_POST['shipping_address_1'])) : null,
                'shipping_address_2' => isset($_POST['shipping_address_2']) ? wc_clean(wp_unslash($_POST['shipping_address_2'])) : null,
                'shipping_city' => isset($_POST['shipping_city']) ? wc_clean(wp_unslash($_POST['shipping_city'])) : null,
                'shipping_state' => isset($_POST['shipping_state']) ? wc_clean(wp_unslash($_POST['shipping_state'])) : null,
                'shipping_postcode' => isset($_POST['shipping_postcode']) ? wc_clean(wp_unslash($_POST['shipping_postcode'])) : null,
                'shipping_country' => isset($_POST['shipping_country']) ? wc_clean(wp_unslash($_POST['shipping_country'])) : null,
            ];

            $orderDTO = OrkestaPayCard_Helper::transform_data_4_orders($customer, $cart, $orkestaPayCartId);
            $orkestaOrder = OrkestaPayCard_API::request($orderDTO, "$apiHost/v1/orders");

            $paymentDTO = OrkestaPayCard_Helper::transform_data_4_payment($orkestaOrder->order_id, $paymentMethodId, $orkestaPayCartId, $deviceSessionId, $successUrl, $cancelUrl);
            $orkestaPayment = OrkestaPayCard_API::request($paymentDTO, "$apiHost/v1/payments");

            $ajaxResponse = [
                'payment_id' => $orkestaPayment->payment_id,
                'order_id' => $orkestaPayment->order_id,
                'status' => $orkestaPayment->status,
                'message' => $orkestaPayment->transactions[0]->provider->message,
            ];

            if ($orkestaPayment->user_action_required) {
                $ajaxResponse['user_action_required'] = json_decode(json_encode($orkestaPayment->user_action_required), true);
            }

            // Response
            wp_send_json_success($ajaxResponse);

            die();
        } catch (Exception $e) {
            OrkestaPayCard_Logger::error('#orkestapay_card_create_payment', ['error' => $e->getMessage()]);

            wp_send_json_error(
                [
                    'result' => 'fail',
                    'message' => $e->getMessage(),
                ],
                400
            );

            die();
        }
    }

    public function orkestapay_card_complete_3ds_payment()
    {
        header('HTTP/1.1 200 OK');

        $input_data = WP_REST_Server::get_raw_data();
        $payload = sanitize_text_field($input_data);
        $request = json_decode($payload);
        $orkestapayPaymentId = $request->orkestapay_payment_id;

        try {
            $apiHost = $this->getApiHost();
            $transaction = OrkestaPayCard_API::request([], "$apiHost/v1/payments/$orkestapayPaymentId/complete");
            $message = $transaction->provider->message ?? __('A transaction error occurred. Your credit card has not been charged.', 'orkestapay-card');
            $ajaxResponse = ['type' => $transaction->type, 'transaction_id' => $transaction->transaction_id, 'status' => $transaction->status, 'message' => $message];

            // Response
            wp_send_json_success($ajaxResponse);

            die();
        } catch (Exception $e) {
            OrkestaPayCard_Logger::error('#orkestapay_card_complete_3ds_payment', ['error' => $e->getMessage()]);

            wp_send_json_error(
                [
                    'result' => 'fail',
                    'message' => $e->getMessage(),
                ],
                400
            );

            die();
        }
    }

    public function orkestapay_card_return_url()
    {
        $cart = WC()->cart;

        if ($cart->is_empty()) {
            wp_safe_redirect(wc_get_cart_url());
            exit();
        }

        $orkestapayOrderId = isset($_GET['order_id']) ? $_GET['order_id'] : $_GET['orderId'];
        OrkestaPayCard_Logger::log('#orkesta_return_url', ['orkestapay_order_id' => $orkestapayOrderId]);

        $apiHost = $this->getApiHost();
        $orkestaOrder = OrkestaPayCard_API::retrieve("$apiHost/v1/orders/$orkestapayOrderId");

        $shipping_cost = $cart->get_shipping_total();
        $current_shipping_method = WC()->session->get('chosen_shipping_methods');
        $shipping_label = $this->getShippingLabel();

        // create shipping object
        $shipping = new WC_Order_Item_Shipping();
        $shipping->set_method_title($shipping_label);
        $shipping->set_method_id($current_shipping_method[0]); // set an existing Shipping method ID
        $shipping->set_total($shipping_cost); // set the cost of shipping

        $customer = $cart->get_customer();
        $order = wc_create_order();
        $order->set_customer_id(get_current_user_id());

        // Se agregan los productos al pedido
        foreach ($cart->get_cart() as $item) {
            $product = wc_get_product($item['product_id']);
            $order->add_product($product, $item['quantity']);
        }

        // Se agrega el costo de envío
        $order->add_item($shipping);
        $order->calculate_totals();

        $order->set_payment_method($this->id);
        $order->set_payment_method_title($this->title);

        // Direcciones de envío y facturación
        $order->set_address($customer->get_billing(), 'billing');
        $order->set_address($customer->get_shipping(), 'shipping');

        if ($orkestaOrder->status === self::STATUS_COMPLETED) {
            $order->payment_complete();
        } else {
            // awaiting the webhook's confirmation
            $order->set_status('on-hold');
            $order->save();
        }

        // Obtener el pago de OrkestaPay relacionado a la orden
        $orkestaPayments = OrkestaPay_API::retrieve("$apiHost/v1/orders/$orkestapayOrderId/payments");

        // Verificar si HPOS está habilitado
        if (OrderUtil::custom_orders_table_usage_is_enabled()) {
            $order->update_meta_data('_orkestapay_order_id', $orkestaOrder->order_id);
            $order->update_meta_data('_orkestapay_payment_id', $orkestaPayments->content[0]->payment_id);
            $order->save();
        } else {
            update_post_meta($order->get_id(), '_orkestapay_order_id', $orkestaOrder->order_id);
            update_post_meta($order->get_id(), '_orkestapay_payment_id', $orkestaPayments->content[0]->payment_id);
        }

        // Remove cart
        $cart->empty_cart();

        wp_safe_redirect($this->get_return_url($order));
        exit();
    }

    public function getOrkestaPayCartId()
    {
        $bytes = random_bytes(16);
        return bin2hex($bytes);
    }

    private function getShippingLabel()
    {
        $shipping_methods = WC()->shipping->get_shipping_methods();
        $current_shipping_method = WC()->session->get('chosen_shipping_methods');
        $shipping_method = explode(':', $current_shipping_method[0]);
        $selected_shipping_method = $shipping_methods[$shipping_method[0]];

        return $selected_shipping_method->method_title;
    }

    public function orkestapay_card_create_payment_blocks()
    {
        header('HTTP/1.1 200 OK');

        try {
            $cart = WC()->cart;
            $apiHost = $this->getApiHost();
            $orkestaPayCartId = $this->getOrkestaPayCartId();
            $successUrl = esc_url(WC()->api_request_url('orkestapay_card_return_url'));
            $cancelUrl = wc_get_checkout_url();

            $json_data = WP_REST_Server::get_raw_data();
            $payload = json_decode($json_data, true);
            $billingAddress = $payload['billing_address'];
            $shippingAddress = $payload['shipping_address'];
            $paymentMethodId = $payload['payment_method_id'];
            $deviceSessionId = $payload['device_session_id'];

            $customer = [
                'id' => $cart->get_customer()->get_id(),
                'first_name' => wc_clean(wp_unslash($billingAddress['first_name'])),
                'last_name' => wc_clean(wp_unslash($billingAddress['last_name'])),
                'email' => wc_clean(wp_unslash($billingAddress['email'])),
                'phone' => isset($billingAddress['phone']) ? wc_clean(wp_unslash($billingAddress['phone'])) : '',
                'billing_country' => wc_clean(wp_unslash($billingAddress['country'])),
                'shipping_address_1' => isset($shippingAddress['address_1']) ? wc_clean(wp_unslash($shippingAddress['address_1'])) : null,
                'shipping_address_2' => isset($shippingAddress['address_2']) ? wc_clean(wp_unslash($shippingAddress['address_2'])) : null,
                'shipping_city' => isset($shippingAddress['city']) ? wc_clean(wp_unslash($shippingAddress['city'])) : null,
                'shipping_state' => isset($shippingAddress['state']) ? wc_clean(wp_unslash($shippingAddress['state'])) : null,
                'shipping_postcode' => isset($shippingAddress['postcode']) ? wc_clean(wp_unslash($shippingAddress['postcode'])) : null,
                'shipping_country' => isset($shippingAddress['country']) ? wc_clean(wp_unslash($shippingAddress['country'])) : null,
            ];

            $orderDTO = OrkestaPayCard_Helper::transform_data_4_orders($customer, $cart, $orkestaPayCartId);
            $orkestaOrder = OrkestaPayCard_API::request($orderDTO, "$apiHost/v1/orders");

            $paymentDTO = OrkestaPayCard_Helper::transform_data_4_payment($orkestaOrder->order_id, $paymentMethodId, $orkestaPayCartId, $deviceSessionId, $successUrl, $cancelUrl);
            $orkestaPayment = OrkestaPayCard_API::request($paymentDTO, "$apiHost/v1/payments");

            $ajaxResponse = [
                'payment_id' => $orkestaPayment->payment_id,
                'order_id' => $orkestaPayment->order_id,
                'status' => $orkestaPayment->status,
                'message' => $orkestaPayment->transactions[0]->provider->message,
            ];

            if ($orkestaPayment->user_action_required) {
                $ajaxResponse['user_action_required'] = json_decode(json_encode($orkestaPayment->user_action_required), true);
            }

            // Response
            wp_send_json_success($ajaxResponse);

            die();
        } catch (Exception $e) {
            OrkestaPayCard_Logger::error('#orkestapay_card_create_payment_blocks', ['error' => $e->getMessage()]);

            wp_send_json_error(
                [
                    'result' => 'fail',
                    'message' => $e->getMessage(),
                ],
                400
            );

            die();
        }
    }

    public function getMerchantId()
    {
        return $this->merchant_id;
    }

    public function getPublicKey()
    {
        return $this->public_key;
    }

    public function isTestMode()
    {
        return $this->test_mode;
    }

    public function getOrkestapayCreatePaymentUrl()
    {
        return esc_url(WC()->api_request_url('orkestapay_card_create_payment'));
    }

    public function getOrkestapayCreatePaymentBlocksUrl()
    {
        return esc_url(WC()->api_request_url('orkestapay_card_create_payment_blocks'));
    }

    public function getOrkestapayComplete3DSPaymentUrl()
    {
        return esc_url(WC()->api_request_url('orkestapay_card_complete_3ds_payment'));
    }

    public function getOrkestapayForm()
    {
        $apiHost = $this->getApiHost();
        $this->brands = OrkestaPayCard_API::retrieve("$apiHost/v1/merchants/providers/brands");

        // Captura el contenido del archivo PHP
        ob_start();
        include_once dirname(__DIR__) . '/templates/payment.php';
        $htmlContent = ob_get_clean(); // Obtiene la salida y la guarda en $htmlContent
        return $htmlContent; // Devuelve el HTML generado
    }
}
