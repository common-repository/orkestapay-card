<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Orkestapay_Card_Blocks_Support extends AbstractPaymentMethodType
{
    private $gateway;
    protected $name = 'orkestapay-card';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_orkestapay-card_settings', []);
        $this->gateway = new OrkestaPayCard_Gateway();
    }

    public function is_active()
    {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'orkestapay-card-blocks-integration',
            plugin_dir_url(__DIR__) . 'block/checkout.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n', 'jquery'],
            null,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('orkestapay-card-blocks-integration');
        }

        return ['orkestapay-card-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'merchant_id' => $this->gateway->getMerchantId(),
            'public_key' => $this->gateway->getPublicKey(),
            'is_sandbox' => $this->gateway->isTestMode(),
            'orkestapay_form' => $this->gateway->getOrkestapayForm(),
            'orkestapay_create_payment_blocks_url' => $this->gateway->getOrkestapayCreatePaymentBlocksUrl(),
            'orkestapay_complete_3ds_payment_url' => $this->gateway->getOrkestapayComplete3DSPaymentUrl(),
        ];
    }
}
