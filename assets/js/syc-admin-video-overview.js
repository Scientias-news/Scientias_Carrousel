(function ($, wp) {
	'use strict';

	function announce(message) {
		if (wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message);
		}
	}

	$('.syc-article-search').each(function () {
		var $search = $(this);
		var $form = $search.closest('form');
		var $postId = $form.find('.syc-article-post-id');

		$search.autocomplete({
			minLength: 2,
			delay: 250,
			source: function (request, response) {
				$postId.val('');
				announce(sycVideoOverview.loading);
				wp.ajax.send('syc_search_editorial_posts', {
					data: {
						nonce: sycVideoOverview.nonce,
						term: request.term
					}
				}).done(function (items) {
					response(items);
					if (!items.length) {
						announce(sycVideoOverview.noResults);
					}
				}).fail(function () {
					response([]);
					announce(sycVideoOverview.noResults);
				});
			},
			select: function (event, ui) {
				event.preventDefault();
				$search.val(ui.item.title);
				$postId.val(ui.item.post_id);
			},
			focus: function (event, ui) {
				event.preventDefault();
				$search.val(ui.item.title);
			}
		});

		$search.on('input', function () {
			$postId.val('');
		});

		$form.on('submit', function (event) {
			if (!$postId.val()) {
				event.preventDefault();
				announce(sycVideoOverview.selectFirst);
				$search.trigger('focus');
			}
		});
	});
}(jQuery, window.wp));
