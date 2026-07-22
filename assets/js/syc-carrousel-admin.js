(function ($) {
	'use strict';

	function reindexItems($tbody) {
		$tbody.children('tr').each(function (index) {
			$(this).find('[name]').each(function () {
				this.name = this.name.replace(/\[items\]\[\d+\]/, '[items][' + index + ']');
			});
		});
	}

	$('.syc-sortable-items').each(function () {
		var $tbody = $(this);
		$tbody.sortable({
			axis: 'y',
			handle: '.syc-drag-handle',
			items: '> tr',
			update: function () {
				reindexItems($tbody);
			}
		});
	});

	var $order = $('.syc-sortable-carrousels');
	if ($order.length) {
		$order.sortable({
			axis: 'y',
			handle: '.syc-drag-handle',
			items: '> li'
		});

		$order.on('click', '.syc-move-up, .syc-move-down', function () {
			var $item = $(this).closest('li');
			if ($(this).hasClass('syc-move-up')) {
				$item.prev().before($item);
			} else {
				$item.next().after($item);
			}
			$item.find('.syc-drag-handle').trigger('focus');
		});
	}

	$(document).on('click', '.syc-copy-shortcode', function () {
		var shortcode = this.getAttribute('data-shortcode');
		var button = this;
		var fallback = function () {
			var textarea = document.createElement('textarea');
			textarea.value = shortcode;
			textarea.setAttribute('readonly', 'readonly');
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild(textarea);
			textarea.select();
			var copied = document.execCommand('copy');
			document.body.removeChild(textarea);
			button.textContent = copied ? sycCarrouselAdmin.copied : sycCarrouselAdmin.copyFailed;
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(shortcode).then(function () {
				button.textContent = sycCarrouselAdmin.copied;
			}, fallback);
		} else {
			fallback();
		}
	});
}(jQuery));
