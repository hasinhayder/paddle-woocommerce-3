<?php
/*
 * Plugin Name: Paddle
 * Plugin URI: https://github.com/hasinhayder/paddle-woocommerce-3
 * Description: Paddle Payment Gateway for WooCommerce
 * Version: 3.0.1
 * Author: Paddle.com (Improvements by ThemeBucket)
 * Author URI: https://github.com/hasinhayder
 */

defined('ABSPATH') or die("Plugin must be run as part of wordpress");

if (!class_exists('Paddle_WC')) :

/**
 * Main Paddle_WC Class.
 *
 * @class Paddle_WC
 * @version	3.0.0
 */
final class Paddle_WC {
	
	/**
	 * Instance of our settings object.
	 *
	 * @var Paddle_WC_Settings
	 */
	private $settings;
	
	/**
	 * Instance of our checkout handler.
	 *
	 * @var Paddle_WC_Checkout
	 */
	private $checkout;
	
	/**
	 * The gateway that handles the payments and the admin setup.
	 *
	 * @var Paddle_WC_Gateway
	 */
	private $gateway;
	
	/**
	 * The single instance of the class.
	 *
	 * @var Paddle_WC
	 */
	private static $_instance = null;
	
	/**
	 * Main Paddle_WC Instance.
	 * Ensures only one instance of WooCommerce is loaded or can be loaded.
	 *
	 * @static
	 * @return Paddle_WC - Main instance.
	 */
	public static function instance() {
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}
	
	/**
	 * Paddle_WC Constructor.
	 */
	public function __construct() {
		$this->register_init_callback();
	}
	
	/**
	 * Registers the init callback for when WP is done loading plugins.
	 */
	private function register_init_callback() {
		add_action('plugins_loaded', array($this, 'on_wp_plugins_loaded'));
	}
	
	/**
	 * Callback called during plugin load to setup the Paddle_WC.
	 */
	public function on_wp_plugins_loaded() {
		// Don't load extension if WooCommerce is not active
		if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
			
			include_once('models/api.php');
			include_once('models/checkout.php');
			include_once('models/gateway.php');
			include_once('models/settings.php');

			// Register the Paddle gateway with WC
			add_filter('woocommerce_payment_gateways', array($this, 'on_register_woocommerce_gateways'));

			// Add the checkout scripts and actions, if enabled
			$this->settings = new Paddle_WC_Settings();
			if($this->settings->get('enabled') == 'yes') {
				
				// Setup checkout object and register intercepts to render page content 
				$this->checkout = new Paddle_WC_Checkout($this->settings);
				$this->checkout->register_callbacks();
				
			}
			
			// Always setup the gateway as its needed to change admin settings
			$this->gateway = new Paddle_WC_Gateway($this->settings);
			$this->gateway->register_callbacks();
		}
	}
	
	/**
	 * Callback called during plugin load to setup the Paddle_WC.
	 */
	public function on_register_woocommerce_gateways($methods) {
		$methods[] = 'Paddle_WC_Gateway';
		return $methods;
	}
}

endif;

$GLOBALS['paddle_wc'] = Paddle_WC::instance();