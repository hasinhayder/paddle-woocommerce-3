<?php

class Paddle_WC_Settings
{
	// For comaptability with woocommerce, this needs to match the value of $this->plugin_id . $this->id,
	// as called from the gateway
	const PLUGIN_ID = 'woocommerce_paddle';

	const API_GENERATE_PAY_LINK_URL = 'api/2.0/product/generate_pay_link';
	const API_GET_PUBLIC_KEY_URL = 'api/2.0/user/get_public_key';
	const PADDLE_ROOT_URL = 'https://vendors.paddle.com/';
	const PADDLE_CHECKOUT_ROOT_URL = 'https://checkout.paddle.com/';
	const INTEGRATE_URL = 'vendor/external/integrate';
	const SIGNUP_LINK = 'https://www.paddle.com/sell?utm_source=WooCommerce&utm_campaign=WooCommerce&utm_medium=WooCommerce&utm_term=sell';

	private $settings = array(
		'paddle_vendor_id' => '',
		'paddle_api_key' => '',
		'product_icon' => '',
		'product_name' => '',
		'checkout_hook' => 'woocommerce_checkout_before_customer_details',
		'send_names' => 'no'
	);
	public $supported_currencies = array(
		'USD',
		'GBP',
		'EUR'
	);

	public $is_connected;
	public $settings_saved = false;
	public $currency_supported = false;

	public static function instance()
	{
		if(!isset($GLOBALS['paddle_wc_settings'])) {
			$GLOBALS['paddle_wc_settings'] = new static();
		}
		return $GLOBALS['paddle_wc_settings'];
	}

	public function __construct()
	{
		// Load settings
		$this->settings = array_merge($this->settings, get_option(static::PLUGIN_ID . '_settings', []));
		$this->is_connected = ($this->settings['paddle_api_key'] && $this->settings['paddle_vendor_id']);
		$this->currency_supported = in_array(get_woocommerce_currency(), $this->supported_currencies);
	}

	public function getOptions()
	{
		return $this->settings;
	}

	public function get($key)
	{
		return $this->settings[$key];
	}

	public function set($key, $value)
	{
		$this->settings[$key] = $value;
		update_option(static::PLUGIN_ID . '_settings', $this->settings);
	}

	/**
	 * Get the vendor key, querying if necessary
	 *
	 * Attempts to retrieve the vendors public key, and if it's not set,
	 * then it calls the padle server to retrieve it.
	 *
	 * @uses get_vendor_public_key to get the vendor key from paddle servers
	 * @return string vendor key or '' if not set
	 */
	public function getPaddleVendorKey() {
		$vendorId = $this->get('paddle_vendor_id');
		$key = get_option('paddle_vendor_public_key') ?: '';
		if(empty($key) && $this->is_connected) {
			$key = $this->get_vendor_public_key($this->settings['paddle_vendor_id'], $this->settings['paddle_api_key']);
			update_option('paddle_vendor_public_key', $key);
		}
		return $key;
	}

	/**
	 * Retrieves from paddle api and returns vendor_public_key
	 * @param int $vendorId
	 * @param string $vendorApiKey
	 * @return string
	 */
	protected function get_vendor_public_key($vendorId, $vendorApiKey) {
		// data to be send to paddle gateway
		$data = array();
		$data['vendor_id'] = $vendorId;
		$data['vendor_auth_code'] = $vendorApiKey;

		$apiCallResponse = wp_remote_get(self::PADDLE_ROOT_URL . self::API_GET_PUBLIC_KEY_URL, array(
			'method' => 'POST',
			'timeout' => 45,
			'httpversion' => '1.1',
			'blocking' => true,
			'body' => $data,
			'sslverify' => false
			)
		);

		if (is_wp_error($apiCallResponse)) {
			echo 'Something went wrong. Unable to get API response.';
			error_log('Paddle error. Unable to get API response. Method: ' . __METHOD__ . ' Error message: ' . $apiCallResponse->get_error_message());
			exit;
		} else {
			$oApiResponse = json_decode($apiCallResponse['body']);

			if ($oApiResponse->success === true) {
				return $oApiResponse->response->public_key;
			} else {
				echo 'Something went wrong. Make sure that Paddle Vendor Id and Paddle Api Key are correct.';
				error_log('Paddle error. Error response from API. Errors: ' . print_r($oApiResponse->error, true));
				exit;
			}
		}
	}

}
