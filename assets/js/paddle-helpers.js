jQuery(document).ready(function(){
	jQuery('#toggleVendorAccountEntry').click(function(){
		var row = jQuery(this).closest('tr');
		row.next().show();
		row.next().next().show();
		row.hide();
	});
	jQuery('#woocommerce_paddle_paddle_vendor_id').closest('tr').hide();
	jQuery('#woocommerce_paddle_paddle_api_key').closest('tr').hide();
	jQuery('.open_paddle_popup').click(function(event) {
		// don't reload admin page when popup is created
		event.preventDefault();

		// open paddle integration popup
		window.open(integrationData.url, 'mywindow', 'location=no,status=0,scrollbars=0,width=800,height=600');

		// handle message sent from popup
		window.addEventListener('message', function(e) {
			var arrData = e.data.split(" ");
			jQuery('#woocommerce_paddle_paddle_vendor_id').val(arrData[0]);
			jQuery('#woocommerce_paddle_paddle_api_key').val(arrData[1]);
			jQuery('#toggleVendorAccountEntry').click();
		});
	});
});
