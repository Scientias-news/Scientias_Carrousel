(function () {
	'use strict';

	function parseYouTubeId(url) {
		if (!url) {
			return null;
		}

		try {
			var u = new URL(url);

			// ?v=VIDEOID
			if (u.searchParams.has('v')) {
				return u.searchParams.get('v');
			}

			// youtu.be/VIDEOID
			if (u.hostname.indexOf('youtu.be') !== -1) {
				return u.pathname.replace('/', '');
			}

			// /shorts/VIDEOID
			var parts = u.pathname.split('/');
			var shortsIndex = parts.indexOf('shorts');
			if (shortsIndex !== -1 && parts[shortsIndex + 1]) {
				return parts[shortsIndex + 1];
			}
		} catch (e) {
			// Fallback voor wanneer URL niet door new URL() kan.
			var match = url.match(/(?:v=|be\/|shorts\/)([a-zA-Z0-9_-]{6,})/);
			if (match && match[1]) {
				return match[1];
			}
		}

		return null;
	}

	function createIframe(url) {
		var id = parseYouTubeId(url);
		if (!id) {
			return null;
		}

		var iframe = document.createElement('iframe');
		iframe.setAttribute('allowfullscreen', 'allowfullscreen');
		iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen');
		iframe.setAttribute('webkitallowfullscreen', 'webkitallowfullscreen');
		iframe.setAttribute('mozallowfullscreen', 'mozallowfullscreen');
		iframe.src = 'https://www.youtube.com/embed/' + id + '?autoplay=1&rel=0';
		return iframe;
	}

	// Gedeelde modal state.
	var modal;
	var modalMedia;
	var modalTitle;
	var modalRoot;
	var modalItems = [];
	var modalIndex = -1;
	var ignoreClickUntil = 0;

	function ensureModal() {
		if (modal) {
			return;
		}

		modal = document.createElement('div');
		modal.className = 'syc-modal';
		modal.setAttribute('aria-hidden', 'true');

		modal.innerHTML =
			'<div class="syc-modal-backdrop"></div>' +
			'<div class="syc-modal-content" role="dialog" aria-modal="true">' +
			'  <button type="button" class="syc-modal-close" aria-label="Sluit video">&times;</button>' +
			'  <div class="syc-modal-inner">' +
			'    <div class="syc-modal-media"></div>' +
			'    <div class="syc-modal-title"></div>' +
			'  </div>' +
			'</div>';

		document.body.appendChild(modal);

		modalMedia = modal.querySelector('.syc-modal-media');
		modalTitle = modal.querySelector('.syc-modal-title');

		var closeBtn = modal.querySelector('.syc-modal-close');
		var backdrop = modal.querySelector('.syc-modal-backdrop');
		var inner = modal.querySelector('.syc-modal-inner');

		// Overlaylaag boven de video om verticale swipes op te vangen.
		var swipeLayer = document.createElement('div');
		swipeLayer.className = 'syc-modal-swipe';
		modalMedia.appendChild(swipeLayer);

		function closeModal() {
			if (!modal.classList.contains('is-open')) {
				return;
			}
			modal.classList.remove('is-open');
			modal.setAttribute('aria-hidden', 'true');
			modalMedia.innerHTML = '';
			modalItems = [];
			modalIndex = -1;
			document.documentElement.classList.remove('syc-modal-open');
		}

		closeBtn.addEventListener('click', closeModal);
		backdrop.addEventListener('click', closeModal);

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal.classList.contains('is-open')) {
				closeModal();
			}

			if (!modal.classList.contains('is-open') || !modalItems.length) {
				return;
			}

			if (e.key === 'ArrowUp') {
				e.preventDefault();
				showRelativeItem(-1);
			} else if (e.key === 'ArrowDown') {
				e.preventDefault();
				showRelativeItem(1);
			}
		});

		// Verticale swipe in modal (touch) – swipe buiten de iframe (op titel / randen).
		var touchStartY = 0;
		var touchStartX = 0;
		var tracking = false;

		swipeLayer.addEventListener(
			'touchstart',
			function (e) {
				if (!e.touches || e.touches.length !== 1) {
					return;
				}
				tracking = true;
				touchStartY = e.touches[0].clientY;
				touchStartX = e.touches[0].clientX;
			},
			{ passive: true }
		);

		swipeLayer.addEventListener(
			'touchend',
			function (e) {
				if (!tracking) {
					return;
				}
				tracking = false;
				if (!e.changedTouches || !e.changedTouches.length) {
					return;
				}
				var dy = e.changedTouches[0].clientY - touchStartY;
				var dx = e.changedTouches[0].clientX - touchStartX;

				if (Math.abs(dy) < 40 || Math.abs(dy) < Math.abs(dx)) {
					return;
				}

				if (dy < 0) {
					// Swipe omhoog -> volgende.
					showRelativeItem(1);
				} else {
					// Swipe omlaag -> vorige.
					showRelativeItem(-1);
				}
			},
			{ passive: true }
		);

		// Helper zodat showRelativeItem beschikbaar is.
		function showRelativeItem(delta) {
			if (!modalItems.length) {
				return;
			}
			var total = modalItems.length;
			var nextIndex = (modalIndex + delta + total) % total;
			openModalItem(nextIndex);
		}

		// Expose helper op modal element zodat we het elders kunnen gebruiken.
		modal._showRelativeItem = showRelativeItem;
		modal._closeModal = closeModal;
	}

	function openModalItem(index) {
		if (!modal || !modalItems.length) {
			return;
		}
		if (index < 0 || index >= modalItems.length) {
			return;
		}

		modalIndex = index;

		var item = modalItems[index];
		if (!item) {
			return;
		}

		var url = item.getAttribute('data-video-url');
		var title = item.getAttribute('aria-label') || '';

		var iframe = createIframe(url);
		if (!iframe) {
			return;
		}

		modalMedia.innerHTML = '';
		modalMedia.appendChild(iframe);
		modalTitle.textContent = title;

		modal.classList.add('is-open');
		modal.setAttribute('aria-hidden', 'false');
		document.documentElement.classList.add('syc-modal-open');
	}

	function showRelativeItem(delta) {
		if (!modal || typeof modal._showRelativeItem !== 'function') {
			return;
		}
		modal._showRelativeItem(delta);
	}

	function initCarousel(root) {
		if (!root) {
			return;
		}

		var items = root.querySelectorAll('.syc-item[data-video-url]');
		if (!items.length) {
			return;
		}

		ensureModal();

		var itemsArray = Array.prototype.slice.call(items);

		Array.prototype.forEach.call(items, function (item, idx) {
			item.dataset.index = String(idx);

			item.addEventListener('click', function () {
				if (Date.now() < ignoreClickUntil) {
					return;
				}
				modalRoot = root;
				modalItems = itemsArray;
				openModalItem(idx);
			});
		});

		// Horizontale navigatie met knoppen + swipe/drag.
		var track = root.querySelector('.syc-items');
		var prev = root.querySelector('.syc-nav-prev');
		var next = root.querySelector('.syc-nav-next');

		if (track && prev && next) {
			var scrollAmount = 260 + 16; // kaartbreedte + marge.

			prev.addEventListener('click', function () {
				track.scrollBy({
					left: -scrollAmount,
					behavior: 'smooth',
				});
			});

			next.addEventListener('click', function () {
				track.scrollBy({
					left: scrollAmount,
					behavior: 'smooth',
				});
			});

			// Mouse drag (touch heeft native swipe).
			(function enableMouseDragScroll(el) {
				var isDown = false;
				var startX = 0;
				var startScrollLeft = 0;
				var didDrag = false;

				function onMouseDown(e) {
					if (e.button !== 0) {
						return;
					}
					isDown = true;
					didDrag = false;
					startX = e.clientX;
					startScrollLeft = el.scrollLeft;
				}

				function onMouseMove(e) {
					if (!isDown) {
						return;
					}
					var dx = e.clientX - startX;

					if (!didDrag && Math.abs(dx) < 8) {
						return;
					}

					didDrag = true;
					e.preventDefault();
					el.scrollLeft = startScrollLeft - dx;
				}

				function endMouse() {
					if (!isDown) {
						return;
					}
					isDown = false;
					if (didDrag) {
						ignoreClickUntil = Date.now() + 300;
					}
				}

				el.addEventListener('mousedown', onMouseDown);
				el.addEventListener('mousemove', onMouseMove);
				window.addEventListener('mouseup', endMouse);
				el.addEventListener('mouseleave', endMouse);
			})(track);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var carousels = document.querySelectorAll('.syc-carousel');
		Array.prototype.forEach.call(carousels, initCarousel);
	});
})();

