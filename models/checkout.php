<?php

/**
 * Class that registers and handles the intercepts on the WC Checkout page.
 */  
class Paddle_WC_Checkout {
	
	/**
	 * Instance of our settings object.
	 *
	 * @var Paddle_WC_Settings
	 */
	private $settings;
	
	/**
	 * Paddle_WC_Checkout Constructor.
	 */
	public function __construct($settings) {
		$this->settings = $settings;
	}
	
	/**
	 * Registers the callbacks (WC hooks) that we need to inject Paddle checkout functionality.
	 */
	public function register_callbacks() {
		$this->register_checkout_actions();
	}
	
	/**
	 * Registers the callbacks needed to handle the WC checkout.
	 */
	protected function register_checkout_actions() {
		// Inject scripts and CSS we need for checkout
		add_action('wp_enqueue_scripts', array($this, 'on_wp_enqueue_scripts'));

		// Add the place order button target url handler
		add_action('wc_ajax_paddle_checkout', array($this, 'on_ajax_process_checkout'));
		add_action('wc_ajax_nopriv_paddle_checkout', array($this, 'on_ajax_process_checkout'));
		// And handle old-version style
		add_action('wp_ajax_paddle_checkout', array($this, 'on_ajax_process_checkout'));
		add_action('wp_ajax_nopriv_paddle_checkout', array($this, 'on_ajax_process_checkout'));

		// Do the same, but for the order-pay page instead of the checkout page - ie. order already exists
		add_action('wc_ajax_paddle_checkout_pay', array($this, 'on_ajax_process_checkout_pay'));
		add_action('wc_ajax_nopriv_paddle_checkout_pay', array($this, 'on_ajax_process_checkout_pay'));
		add_action('wp_ajax_paddle_checkout_pay', array($this, 'on_ajax_process_checkout_pay'));
		add_action('wp_ajax_nopriv_paddle_checkout_pay', array($this, 'on_ajax_process_checkout_pay'));
	}

	/**
	 * Callback when WP is building the list of scripts for the page.
	 */
	public function on_wp_enqueue_scripts() {
		// Inject standard Paddle checkout JS
		wp_enqueue_script('paddle-checkout', 'https://cdn.paddle.com/paddle/paddle.js');
		
		// Inject our bootstrap JS to intercept the WC button press and invoke standard JS
		wp_register_script('paddle-bootstrap', plugins_url('../assets/js/paddle-bootstrap.js', __FILE__), array('jquery'));
				
		// Use wp_localize_script to write JS config that can't be embedded in the script
		$endpoint = is_wc_endpoint_url('order-pay') ? 'paddle_checkout_pay' : 'paddle_checkout';
		$paddle_data = array(
			'order_url' => $this->get_ajax_endpoint_path($endpoint),
			'vendor' => $this->settings->get('paddle_vendor_id')
		);
		wp_localize_script('paddle-bootstrap', 'paddle_data', $paddle_data);
		wp_enqueue_script('paddle-bootstrap');
	}
	
	/**
	 * Receives our AJAX callback to process the checkout
	 */
	public function on_ajax_process_checkout() {
		// Invoke our Paddle gateway to call out for the Paddle checkout url and return via JSON
		WC()->checkout()->process_checkout();
	}

	/**
	 * Skip the order creation, and go straight to payment processing
	 */
	public function on_ajax_process_checkout_pay() {		
		if (!WC()->session->order_awaiting_payment) {
			wc_add_notice('We were unable to process your order, please try again.', 'error');
			ob_start();
			wc_print_notices();
			$messages = ob_get_contents();
			ob_end_clean();
			echo json_encode(array(
				'result' => 'failure',
				'messages' => $messages,
				'errors' => array('We were unable to process your order, please try again.')
			));
			exit;
		}
		
		// Need the id of the pre-created order
		$order_id = WC()->session->order_awaiting_payment;
		// Get the paddle payment gateway - the payment_method should be posted as "paddle"
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$available_gateways['paddle']->process_payment($order_id);
		// The process_payment function will exit, so we don't need to return anything here
	}
	
	/**
	 * Gets the path to be called to invoke the given AJAX endpoint.
	 *
	 * @param String $endpoint The endpoint the AJAX request will be calling.
	 */
	private function get_ajax_endpoint_path($endpoint) {
		if(version_compare(WOOCOMMERCE_VERSION, '2.4.0', '>=')) {
			// WC AJAX callback (Added in 2.4.0)
			$url = parse_url($_SERVER['REQUEST_URI']);
			parse_str(isset($url['query']) ? $url['query'] : '', $query);
			$query['wc-ajax'] = $endpoint;
			$order_url = $url['path'].'?'.http_build_query($query);
		} else {
			// Older callback (not sure we should care about supporting this old)
			$order_url = admin_url('admin-ajax.php?action='.$endpoint);
		}
		return $order_url;
	}
	
}
