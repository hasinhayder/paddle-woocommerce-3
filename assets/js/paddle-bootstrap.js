jQuery(function($) {
    
    Paddle.Setup({
        vendor: parseInt(paddle_data.vendor)
    });
    
    // Can submit from either checkout or order review forms
    var form = jQuery('form.checkout, form#order_review');

    // Intercept form button (Bind to click instead of WC trigger to avoid popup) 
    jQuery('form.checkout').on('click', ':submit', function(event) {
        return !invokeOverlayCheckout();
    });
    // Intercept submit for order review
    jQuery('form#order_review').on('submit', function(event) {
        return !invokeOverlayCheckout();
    });
    
    // Some customers (Inky) have themes where the button is outside the form
    jQuery('#checkout_buttons button').on('click', function(event) {
		jQuery('form#order_review').submit();
		return false; // Don't fire the submit event twice if the buttons ARE in the form
	});
    
    // Starts the overlay checkout process (returns false if we can't)
    function invokeOverlayCheckout() {
        // Check payment method etc
        if(isPaddlePaymentMethodSelected()) {
            // Need to show spinner before standard checkout as we have to spend time getting the pay URL
            Paddle.Spinner.show();
            
            getSignedCheckoutUrlViaAjax();
            
            // Make sure we don't submit the form normally
            return true;   
        } 
        
        // We didn't fire
        return false;
    }
    
    // Gets if the payment method is set to Paddle (in case multiple methods are available)
    function isPaddlePaymentMethodSelected () {
        if ($('#payment_method_paddle').is(':checked')) {
			return true;
		}
    }
    
    // Requests the signed checkout link via the Paddle WC plugin
	function getSignedCheckoutUrlViaAjax() {
        jQuery.ajax({
            dataType: "json",
            method: "POST",
            url: paddle_data.order_url,
            data: form.serializeArray(),
            success: function (response) {
                // WC will send the error contents in a normal request
                if(response.result == "success") {
                    startPaddleCheckoutOverlay(response.checkout_url, response.email, response.country, response.postcode);
                } else {
                    handleErrorResponse(response);
                }
            },
            error: function (jqxhr, status) {
                // We got a 500 or something if we hit here. Shouldn't normally happen
                alert("We were unable to process your order, please try again in a few minutes.");
            }
        });
    };
    
    // Starts the Paddle.js overlay once we have the checkout url.
    function startPaddleCheckoutOverlay(checkoutUrl, emailAddress, country, postCode) {
        Paddle.Checkout.open({
            email: emailAddress,
            country: country,
            postcode: postCode,
            override: checkoutUrl
        });
    };
    
    // Shows any errors we encountered
    function handleErrorResponse(response) {
        // Note: This error handling code is copied from the woocommerce checkout.js file
        if (response.reload === 'true') {
			window.location.reload();
		    return;
		}

        // Remove old errors
        jQuery( '.woocommerce-error, .woocommerce-message' ).remove();
        // Add new errors
        if (response.messages) {
            form.prepend(response.messages);
        }

        // Cancel processing
        form.removeClass('processing').unblock();

        // Lose focus for all fields
        form.find('.input-text, select').blur();

        // Scroll to top
        jQuery('html, body').animate({
            scrollTop: (form.offset().top - 100)
        }, 1000 );

        if (response.nonce) {
            form.find('#_wpnonce').val(response.nonce);
        }

        // Trigger update in case we need a fresh nonce
        if (response.refresh === 'true') {
            jQuery('body').trigger('update_checkout');
        }
        
        // Clear the Paddle spinner manually as we didn't start checkout
        Paddle.Spinner.hide();
    };
    
});