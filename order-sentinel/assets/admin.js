/* global OrderSentinel, jQuery */
(function ($) {
	$(document).ready(function () {
		var params = new URLSearchParams(window.location.search);
		if (params.get('ordersentinel_run') === '1') {
			var $el = $('<div class="notice notice-info inline"><p>OrderSentinel: running research on selected orders…</p></div>');
			$('.wrap .wp-heading-inline').first().after($el);
			$.post(OrderSentinel.ajax_url, {
				action: 'ordersentinel_run_bulk_research',
				nonce: OrderSentinel.nonce
			}, function (res) {
				if (res && res.success) {
					$el.removeClass('notice-info').addClass('notice-success').find('p').text('OrderSentinel: research attached to selected orders.');
					if (history && history.replaceState) {
						var url = new URL(window.location);
						url.searchParams.delete('ordersentinel_run');
						history.replaceState({}, '', url);
					}
				} else {
					$el.removeClass('notice-info').addClass('notice-error').find('p').text('OrderSentinel: failed to run research. ' + (res && res.data ? res.data : ''));
				}
			}).fail(function () {
				$el.removeClass('notice-info').addClass('notice-error').find('p').text('OrderSentinel: AJAX error. See console.');
			});
		}

		// Single order screen: re-run button in meta box
		$(document).on('click', '#ordersentinel-rerun', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var orderId = $btn.data('order-id');
			$btn.prop('disabled', true).text('Running…');
			$.post(OrderSentinel.ajax_url, {
				action: 'ordersentinel_run_single_research',
				nonce: OrderSentinel.nonce,
				order_id: orderId
			}, function (res) {
				if (res && res.success) {
					$btn.text('Done');
					location.reload();
				} else {
					$btn.prop('disabled', false).text('Retry');
					alert('OrderSentinel: Failed to re-run research.');
				}
			}).fail(function () {
				$btn.prop('disabled', false).text('Retry');
				alert('OrderSentinel: AJAX error.');
			});
		});
	});
})(jQuery);
