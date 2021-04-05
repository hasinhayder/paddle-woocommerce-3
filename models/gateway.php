<?php

class Paddle_WC_Gateway extends WC_Payment_Gateway {

	/**
	 * Instance of our settings object. Not to be confused with $settings (which is the parent class
	 * WC_Payment_Gateway's settings object).
	 *
	 * @var Paddle_WC_Settings
	 */
	private $paddle_settings;
		
	/**
	 * Paddle_WC_Payment_Gateway Constructor.
	 */
	public function __construct($settings = null) {
		
		$this->paddle_settings    = isset($settings) ? $settings : new Paddle_WC_Settings();

		$this->id                 = 'paddle';
		$this->method_title       = 'Paddle.com Payment Gateway';
		$this->method_description = 'Allow customers to securely checkout with credit cards or PayPal';
		$this->title              = $this->paddle_settings->get('title');
		$this->description        = $this->paddle_settings->get('description');
		$this->icon               = apply_filters('wc_paddle_icon', '');
		$this->supports           = array('products');	// We only support purchases
		$this->has_fields         = true;
			
		// Setup fields used for admin side
		$this->init_form_fields();
		// Load settings (we haven't overriden, but must be called in ctor)
		$this->init_settings();
		
		$this->enabled 			  = $this->paddle_settings->get('enabled');
		
		if (is_admin() && $this->enabled == 'yes') {
			if(!$this->paddle_settings->currency_supported) {
				// Inform users if they are not able to use this plugin due to currency
				WC_Admin_Settings::add_error(
					'Paddle does not support your store currency. ' .
					"Your store currency is " . get_woocommerce_currency() .", and we only support " . implode(', ', $this->paddle_settings->supported_currencies)
				);
			}
			// Check we are setup in admin, and display message if not connected
			$this->admin_check_connected();
		}
	}
	
	/**
	 * Registers the callbacks (WC hooks) that we need for the Gateway to function.
	 */
	public function register_callbacks() {
		$this->register_webhook_actions();
		$this->register_admin_actions();
	}
	
	/**
	 * Registers the our webhook callbacks to listen to Paddle after payment hooks.
	 */
	protected function register_webhook_actions() {
		// Add the handler for the webhook - register gateway response listener
		add_action('woocommerce_api_paddle_complete', array($this, 'on_paddle_payment_webhook_response'));
	}
	
	/**
	 * Registers the callbacks used by the admin interface.
	 */
	protected function register_admin_actions() {
		if(is_admin()) {	// Can skip if not an admin user
			// Callback to hit on save
			if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			} else {
				add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
			}
			
			// Callback to inject extra JS to admin page
			add_action('admin_enqueue_scripts', array($this, 'on_admin_enqueue_scripts'));
		}
	}

	/**
	 * Adds the custom scripts used by the admin page.
	 */
	public function on_admin_enqueue_scripts() {
		wp_register_script('paddle-helpers', plugins_url('../assets/js/paddle-helpers.js', __FILE__), array('jquery'));
		$integration_url = Paddle_WC_Settings::PADDLE_ROOT_URL . Paddle_WC_Settings::INTEGRATE_URL . '?' . http_build_query(array(
			'app_name' => 'WooCommerce Paddle Payment Gateway',
			'app_description' => 'WooCommerce Paddle Payment Gateway Plugin. Site name: ' . get_bloginfo('name'),
			'app_icon' => plugins_url('../assets/images/woo.png', __FILE__)
		));
		wp_localize_script('paddle-helpers', 'integrationData', array('url' => $integration_url));
		if ('woocommerce_page_wc-settings' == get_current_screen()->id) {
			wp_enqueue_script('paddle-helpers');
		}
	}
	
	/**
	 * Check if this gateway can be used.
	 */
	public function is_available() {
		// Parent class checks enabled flag anyway
		$is_available = parent::is_available();
		
		// Check all required fields set
		$is_available = $is_available && 
			$this->paddle_settings->currency_supported && 	// Check if WooCoommerce currency is supported by gateway
			$this->paddle_settings->is_connected; 			// Check if gateway is integrated with paddle vendor account
		
        return $is_available;
	}
	
	/**
	 * Checks if we are connected, and displays an error message if not.
	 */
	public function admin_check_connected() {
		static $added = false;
		if(!$this->paddle_settings->is_connected) {
			if($added) return;
			WC_Admin_Settings::add_error("You must connect to paddle before the paddle checkout plugin can be used");
			$added = true;
		}
	}

	/**
	 * After processing the admin option saving, check if we are connected and display error message if not
	 */
	public function process_admin_options() {
		// Whenever we save, also reset the public key, to force it to be reloaded in case the vendor has changed
		update_option('paddle_vendor_public_key', '');
		$result = parent::process_admin_options();
		
		$this->admin_check_connected();
		
		return $result;
	}

	/**
	 * This function is called by WC when user places order with Paddle chosen as the payment method.
	 * We actually want to get the payment URL and pass it back client side to the overlay checkout.
	 * @param int $order_id
	 * @return mixed
	 */
	public function process_payment($order_id) {
		global $woocommerce;
		$order = new WC_Order($order_id);
		$pay_url_json = Paddle_WC_API::get_pay_url_for_order($order, $woocommerce->customer, $this->paddle_settings);

		if(wc_notice_count('error') > 0) {
			// Errors prevented completion
			$result = json_encode(array(
				'result' => 'failure',
				'errors' => WC()->session->get('wc_notices', array())
			));
		} else {
			$result = $pay_url_json;
		}
		
		echo $result;
		exit;
	}
	
	/**
	 * Called when we get a webhook response from Paddle to indicate the payment completed.
	 *
	 * Returns HTTP 200 if OK, 500 otherwise
	 */
	public function on_paddle_payment_webhook_response() {
		if (Paddle_WC_API::check_webhook_signature()) {
			$order_id = $_GET['order_id'];
			if (is_numeric($order_id) && (int) $order_id == $order_id) {
				$order = new WC_Order($order_id);
				if (is_object($order) && $order instanceof WC_Order) {
					$order->payment_complete();
					status_header(200);
					exit;
				} else {
					error_log('Paddle error. Unable to complete payment - order ' . $order_id . ' does not exist');
				}
			} else {
				error_log('Paddle error. Unable to complete payment - order_id is not integer. Got \'' . $order_id . '\'.');
			}
		} else {
			error_log('Paddle error. Unable to verify webhook callback - bad signature.');
		}
		status_header(500);
		exit;
	}

	/**
	 * Displays error messages in the admin system (called externally by WC)
	 */
	public function display_errors() {
		foreach ($this->errors as $k => $error) {
			WC_Admin_Settings::add_error("Unable to save due to error: " . $error);
			unset($this->errors[$k]);
		}
	}

	/**
	 * Check that the given url leads to an actual file
	 */
	protected function url_valid($url) {
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_NOBODY, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		curl_exec($curl);
		$info = curl_getinfo($curl);
		curl_close($curl);
		return $info['http_code'] == 200;
	}

	/**
	 * Custom validation function to check that a product icon is usable. Called externally by woocommerce
	 * 
	 * If the value is invalid in some way, it fixes minor issues (e.g. converting http to https)
	 *
	 * @param string the name of the field to be validated
	 * @return string the validated/corrected url
	 */
	public function validate_product_icon_field($key) {
		if (!isset($_POST[$this->plugin_id . $this->id . '_' . $key]) || empty($_POST[$this->plugin_id . $this->id . '_' . $key])) {
			return '';
		}
		$image_url = $_POST[$this->plugin_id . $this->id . '_' . $key];

		//If the new url is the same as the old one, AND we know it is valid already (because we are enabled), then skip validation
		if($this->get_option($key) == $image_url && $this->enabled == 'yes') return $image_url;

		if (!$this->url_valid($image_url)) {
			$this->errors[] = 'Product Icon url is not valid';
		} else if (substr($image_url, 0, 5) != 'https') {
			//confirmed that base url is valid; now need to make it secure
			$new_url = 'https' . substr($image_url, 4);
			if (!$this->url_valid($new_url)) {
				//image server does not allow secure connection, so bounce it off our proxy
				$vendor_id = $this->getPaddleVendorId();
				$key = $this->getPaddleVendorKey();
				openssl_public_encrypt($image_url, $urlcode, $key);
				$new_url = self::IMAGE_BOUNCE_PROXY_URL . $vendor_id . '/' . str_replace(array('+', '/'), array('-', '_'), base64_encode($urlcode));
				WC_Admin_Settings::add_message("Product Icon URL has been converted to use a secure proxy");
			}
			$image_url = $new_url;
		}

		return $image_url;
	}
	
	/**
	 * Setup admin fields to be shown in plugin settings.
	 */
	public function init_form_fields() {
		// Note: Not sure I really like this mash of HTML inside the class, but this seems to be pretty
		//	standard among WC plugins so I'm going with it for consistency. 
		
		if ($this->paddle_settings->is_connected) {
			$connection_button = '<p style=\'color:green\'>Your paddle account has already been connected</p>' .
				'<a class=\'button-primary open_paddle_popup\'>Reconnect your Paddle Account</a>';
		} else {
			$connection_button = '<a class=\'button-primary open_paddle_popup\'>Connect your Paddle Account</a>';
		}

		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'woocommerce'),
				'type' => 'checkbox',
				'label' => __('Enable', 'woocommerce'),
				'default' => $this->enabled ? 'yes' : 'no'
			),
			'title' => array(
				'title' => __('Title', 'woocommerce'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
				'default' => __('Paddle', 'woocommerce')
			),
			'description' => array(
				'title' => __('Customer Message', 'woocommerce'),
				'type' => 'textarea',
				'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
				'default' => __('Pay using Visa, Mastercard, Amex or PayPal via Paddle', 'woocommerce')
			),
			'paddle_showlink' => array(
				'title' => 'Vendor Account',
				'content' => $connection_button . '<br /><p class = "description"><a href="#!" id=\'toggleVendorAccountEntry\'>Click here to enter your account details manually</a></p>',
				'type' => 'raw',
				'default' => ''
			),
			'paddle_vendor_id' => array(
				'title' => __('Paddle Vendor ID', 'woocommerce'),
				'type' => 'text',
				'description' => __('<a href="#" class="open_paddle_popup">Click here to integrate Paddle account.</a>', 'woocommerce'),
				'default' => '',
				'row_attributes' => array('style' => 'display:none')
			),
			'paddle_api_key' => array(
				'title' => __('Paddle API Key', 'woocommerce'),
				'type' => 'textarea',
				'description' => __('<a href="#" class="open_paddle_popup">Click here to integrate Paddle account.</a>', 'woocommerce'),
				'default' => '',
				'row_attributes' => array('style' => 'display:none')
			),
			'product_name' => array(
				'title' => __('Product Name'),
				'description' => __('The name of the product to use in the paddle checkout'),
				'type' => 'text',
				'default' => get_bloginfo('name') . ' Checkout'
			),
			'product_icon' => array(
				'title' => __('Product Icon'),
				'description' => __('The url of the icon to show next to the product name during checkout'),
				'type' => 'text',
				'default' => 'https://s3.amazonaws.com/paddle/default/default_product_icon.png'
			),
			'send_names' => array(
				'title' => __('Send Product Names'),
				'description' => __('Should the names of the product(s) in the cart be shown on the checkout?'),
				'type' => 'checkbox',
				'label' => __('Send Names', 'woocommerce'),
				'default' => $this->enabled ? 'yes' : 'no'
			),
			'vat_included_in_price' => array(
				'title' => __('VAT Included In Price?'),
				'description' => __('This must match your Paddle account settings under VAT/Taxes'),
				'type' => 'checkbox',
				'label' => __('Prices Include VAT', 'woocommerce'),
				'default' => 'yes'
			)
		);
	}

	/**
	 * Custom HTML generate method for inserting raw HTML in the.
	 * Called externally by WooCommerce based on the type field in $this->form_fields
	 */
	public function generate_raw_html($key, $data) {
		$defaults = array(
			'title' => '',
			'disabled' => false,
			'type' => 'raw',
			'content' => '',
			'desc_tip' => false,
			'label' => $this->plugin_id . $this->id . '_' . $key
		);

		$data = wp_parse_args($data, $defaults);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr($data['label']); ?>"><?php echo wp_kses_post($data['title']); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<?php echo $data['content']; ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

}
