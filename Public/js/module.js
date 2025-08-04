/**
 * Module's JavaScript.
 */
 var pmpro_customer_email = '';

 // Initialize the JS
function initPMPro(customer_email, load) {
    pmpro_customer_email = customer_email;

    $(document).ready(function(){

        if (load) {
            pmproLoadOrders();
        }

		// If the $to is changed, let's try display things?
		$("#to").on('change', function(e) {
			
			// Hook into the default customer AJAX event from freescout to squeeze it in.
			fsAjax({
				action: 'load_customer_info',
				customer_email: $(this).val(),
				mailbox_id: getGlobalAttr('mailbox_id'),
				conversation_id: getGlobalAttr('conversation_id')
			}, laroute.route('conversations.ajax'), function(response) {
				if (isAjaxSuccess(response) && typeof(response.html) != "undefined") {
					// Create an empty div now, generate the content, then move it later.
					$('.footer').append('<div id="pmpro-temp-customer-info" style="display:none;"><div id="pmpro-orders" class="pmpro-orders"></div></div>');
					pmproLoadOrders();
					$('#pmpro-temp-customer-info').insertAfter($('.conv-sidebar-block').last()).removeAttr('style');

				}
			}, true, function() {
				// Do nothing
			});
		});

        $('.pmpro-refresh').click(function(e) {
            pmproLoadOrders();
            e.preventDefault();
        });
    });
}
 
 function pmproLoadOrders()
 {
     $('#pmpro-orders').addClass('pmpro-loading');
	
     fsAjax({
             action: 'orders',
             customer_email: pmpro_customer_email,
             mailbox_id: getGlobalAttr('mailbox_id')
         }, 
         laroute.route('pmpro.ajax'), 
         function(response) {
			
             if (typeof(response.status) != "undefined" && response.status == 'success'
                 && typeof(response.html) != "undefined" && response.html
             ) {
                 $('#pmpro-orders').html(response.html);
                 $('#pmpro-orders').removeClass('pmpro-loading');
 
                 $('.pmpro-refresh').click(function(e) {
                     pmproLoadOrders();
                     e.preventDefault();
                 });
             } else {
                 //showAjaxError(response);
             }
         }, true
     );
 }