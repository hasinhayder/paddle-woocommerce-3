<?php

/**
 * Class that wraps Paddle API functionality.
 */  
class Paddle_WC_API {
    
    /**
     * Fetches a signed payment URL using the Paddle API to pay for the given order
     *
     * @param WC_Order $order The order to generate a Payment URL for.
     * @param WC_Customer $customer The customer this order is for.
     * @param Paddle_WC_Settings $settings Plugin settings.
     */
    public static function get_pay_url_for_order($order, $customer, $settings) {

        // With or without tax depends on option
		$order_total = $order->get_total();
        if($settings->get('vat_included_in_price') != 'yes') {
            $order_total -= $order->get_total_tax();
        } 
        
        // Data to be sent to Paddle gateway
		$data = array();
		$data['vendor_id']             = $settings->get('paddle_vendor_id');
		$data['vendor_auth_code']      = $settings->get('paddle_api_key');
        $data['prices']                = array(get_woocommerce_currency().':'.$order_total);   // Why was tax being removed?
		$data['return_url']            = static::get_return_url($order);
		$data['title']                 = str_replace('{#order}', $order->get_id(), $settings->get('product_name'));
		$data['image_url']             = $settings->get('product_icon');
		$data['webhook_url']           = static::get_webhook_url($order->get_id());
		$data['discountable']          = 0;
		$data['quantity_variable']     = 0;
		$data['customer_email']        = $order->get_billing_email();
		$data['customer_postcode']     = $customer->get_billing_postcode();
		$data['customer_country']      = $customer->get_billing_country();
		
		// Add the product name(s) as custom message
		if($settings->get('send_names') == 'yes') {
			$items = $order->get_items();
			$names = array();
			$passthrough = array();
			foreach($items as $item) {
				$names[] = $item['name'];
				$passthrough[] = array("products"=>array("id"=>$item['product_id'],"name"=>$item['name'])); //so that you can trace the order history later directly from paddle dashboard
			}
			$data['custom_message'] = implode(', ', array_unique($names));
			$data['title'] = implode(', ', array_unique($names));
			$data['passthrough'] = base64_encode(json_encode($passthrough));
		}
		
		// Get pay link from Paddle API
		$post_url = Paddle_WC_Settings::PADDLE_ROOT_URL . Paddle_WC_Settings::API_GENERATE_PAY_LINK_URL;
		$api_start_time = microtime(true);
		$api_call_response = wp_remote_post($post_url, array(
			'method' => 'POST',
			'timeout' => 45,
			'httpversion' => '1.1',
			'blocking' => true,
			'body' => $data,
			'sslverify' => false
			)
		);
		$api_duration = (microtime(true) - $api_start_time);

		// Pass back to AJAX response unless error
		if (is_wp_error($api_call_response)) {
			// We failed to get a response
			wc_add_notice( 'Something went wrong getting checkout url. Unable to get API response.', 'error');
			error_log('Paddle error. Unable to get API response. Method: ' . __METHOD__ . ' Error message: ' . $api_call_response->get_error_message());
			return json_encode(array(
				'result' => 'failure',
				'errors' => array('Something went wrong. Unable to get API response.')
			));
		} else {
			$api_response = json_decode($api_call_response['body']);
			if ($api_response && $api_response->success === true) {
				// We got a valid response
				return json_encode(array(
					'result' => 'success',
					'order_id' => $order->get_id(),
					'checkout_url' => $api_response->response->url,
					'email' => $order->get_billing_email(),
                    'country' => $customer->get_billing_country(),
                    'postcode' => $customer->get_billing_postcode(),
					'duration_s' => $api_duration
				));
			} else {
				// We got a response, but it was an error response
				wc_add_notice('Something went wrong getting checkout url. Check if gateway is integrated.', 'error');
				if (is_object($api_response)) {
					error_log('Paddle error. Error response from API. Method: ' . __METHOD__ . ' Errors: ' . print_r($api_response->error, true));
				} else {
					error_log('Paddle error. Error response from API. Method: ' . __METHOD__ . ' Response: ' . print_r($api_call_response, true));
				}
				return json_encode(array(
					'result' => 'failure',
					'errors' => array('Something went wrong. Check if Paddle account is properly integrated.')
				));
			}
		}
	}
    
    /**
     * Gets the URL we want Paddle to call on payment completion.
     * 
     * @param int $order_id The WC id of the order that will be paid.
     */
    private static function get_webhook_url($order_id) {
        // Adding index.php makes it work for customers without permalinks, and doesn't seem to affect ones with
	    return get_bloginfo('url') . '/index.php/wc-api/paddle_complete?order_id=' . $order_id;
	}

    /**
     * Gets the the checkout should return to once it is complete.
     * 
     * @param int $order_id The WC id of the order that will be paid.
     */
	private static function get_return_url($order) {
		$return_url = $order->get_checkout_order_received_url();
		if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes' ) {
			$return_url = str_replace('http:', 'https:', $return_url);
		}
		return apply_filters('woocommerce_get_return_url', $return_url);
	}
    
    /**
	 * Checks the signature from a given webhook so we know it's genuine
	 * 
	 * @return int Returns 1 if the signature is correct, 0 if it is incorrect, and -1 on error.
	 */
	public static function check_webhook_signature() {  
		// Log error if vendor_public_key is not set
		$vendor_public_key = Paddle_WC_Settings::instance()->getPaddleVendorKey();
		if (empty($vendor_public_key)) {
            error_log('Paddle error. Unable to verify webhook callback - vendor_public_key is not set.');
			return -1;
		}

		// Copy get input to separate variable to not modify superglobal array
		$webhook_data = $_POST;
		foreach ($webhook_data as $k => $v) {
			$webhook_data[$k] = stripslashes($v);
		}

		// Pop signature from webhook data
		$signature = base64_decode($webhook_data['p_signature']);
		unset($webhook_data['p_signature']);

		// Check signature and return result
		ksort($webhook_data);
		$data = serialize($webhook_data);
		return openssl_verify($data, $signature, $vendor_public_key, OPENSSL_ALGO_SHA1);
	}
    
}