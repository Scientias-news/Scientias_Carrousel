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

	function attachSwipeHandlers(target) {
		if (!target || target.dataset.sycSwipeReady === '1') {
			return;
		}

		target.dataset.sycSwipeReady = '1';

		var startY = 0;
		var startX = 0;
		var tracking = false;
		var edgeStart = false;

		target.addEventListener(
			'touchstart',
			function (e) {
				if (e.target && e.target.closest('.syc-modal-close, .syc-modal-nav, iframe')) {
					return;
				}

				if (!e.touches || e.touches.length !== 1) {
					return;
				}
				tracking = true;
				startY = e.touches[0].clientY;
				startX = e.touches[0].clientX;
				edgeStart = startY < 120 || startY > window.innerHeight - 120;
			},
			{ passive: true }
		);

		target.addEventListener(
			'touchend',
			function (e) {
				if (!tracking) {
					return;
				}
				tracking = false;
				if (!e.changedTouches || !e.changedTouches.length) {
					return;
				}

				var dx = e.changedTouches[0].clientX - startX;
				var dy = e.changedTouches[0].clientY - startY;

				if (!edgeStart || Math.abs(dy) < 35 || Math.abs(dy) < Math.abs(dx)) {
					return;
				}

				if (dy < 0) {
					showRelativeItem(1);
				} else {
					showRelativeItem(-1);
				}
			},
			{ passive: true }
		);
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
			'  <button type="button" class="syc-modal-nav syc-modal-prev" aria-label="Vorige video">&lsaquo;</button>' +
			'  <button type="button" class="syc-modal-nav syc-modal-next" aria-label="Volgende video">&rsaquo;</button>' +
			'  <button type="button" class="syc-modal-edge syc-modal-edge-prev" aria-label="Vorige video"></button>' +
			'  <button type="button" class="syc-modal-edge syc-modal-edge-next" aria-label="Volgende video"></button>' +
			'  <button type="button" class="syc-modal-vert syc-modal-vert-prev" aria-label="Vorige video"></button>' +
			'  <button type="button" class="syc-modal-vert syc-modal-vert-next" aria-label="Volgende video"></button>' +
			'  <div class="syc-modal-inner">' +
			'    <div class="syc-modal-media"></div>' +
			'    <div class="syc-modal-title"></div>' +
			'  </div>' +
			'</div>';

		document.body.appendChild(modal);

		modalMedia = modal.querySelector('.syc-modal-media');
		modalTitle = modal.querySelector('.syc-modal-title');

		var closeBtn = modal.querySelector('.syc-modal-close');
		var prevBtn = modal.querySelector('.syc-modal-prev');
		var nextBtn = modal.querySelector('.syc-modal-next');
		var edgePrev = modal.querySelector('.syc-modal-edge-prev');
		var edgeNext = modal.querySelector('.syc-modal-edge-next');
		var vertPrev = modal.querySelector('.syc-modal-vert-prev');
		var vertNext = modal.querySelector('.syc-modal-vert-next');
		var backdrop = modal.querySelector('.syc-modal-backdrop');

		attachSwipeHandlers(modal);

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
		closeBtn.addEventListener('touchend', function (e) {
			e.preventDefault();
			closeModal();
		});
		prevBtn.addEventListener('click', function () {
			showRelativeItem(-1);
		});
		nextBtn.addEventListener('click', function () {
			showRelativeItem(1);
		});
		edgePrev.addEventListener('click', function () {
			showRelativeItem(-1);
		});
		edgeNext.addEventListener('click', function () {
			showRelativeItem(1);
		});
		vertPrev.addEventListener('click', function () {
			showRelativeItem(-1);
		});
		vertNext.addEventListener('click', function () {
			showRelativeItem(1);
		});

		// Swipe omhoog/omlaag op de edge-zones (boven en onder de video).
		// De iframe onderschept alle aanrakingen op de video zelf, dus we gebruiken
		// de transparante padding-zones als swipe-target. touchend volgt altijd het
		// element waar touchstart begon, dus ook als de vinger over de iframe beweegt.
		(function addEdgeSwipe(btn) {
			var sy = 0, sx = 0, tracking = false;

			btn.addEventListener('touchstart', function (e) {
				if (!e.touches || e.touches.length !== 1) { return; }
				tracking = true;
				sy = e.touches[0].clientY;
				sx = e.touches[0].clientX;
			}, { passive: true });

			btn.addEventListener('touchend', function (e) {
				if (!tracking) { return; }
				tracking = false;
				if (!e.changedTouches || !e.changedTouches.length) { return; }
				var dx = e.changedTouches[0].clientX - sx;
				var dy = e.changedTouches[0].clientY - sy;
				if (Math.abs(dy) < 35 || Math.abs(dy) < Math.abs(dx)) { return; }
				showRelativeItem(dy < 0 ? 1 : -1);
			}, { passive: true });
		}(edgePrev));

		(function addEdgeSwipe(btn) {
			var sy = 0, sx = 0, tracking = false;

			btn.addEventListener('touchstart', function (e) {
				if (!e.touches || e.touches.length !== 1) { return; }
				tracking = true;
				sy = e.touches[0].clientY;
				sx = e.touches[0].clientX;
			}, { passive: true });

			btn.addEventListener('touchend', function (e) {
				if (!tracking) { return; }
				tracking = false;
				if (!e.changedTouches || !e.changedTouches.length) { return; }
				var dx = e.changedTouches[0].clientX - sx;
				var dy = e.changedTouches[0].clientY - sy;
				if (Math.abs(dy) < 35 || Math.abs(dy) < Math.abs(dx)) { return; }
				showRelativeItem(dy < 0 ? 1 : -1);
			}, { passive: true });
		}(edgeNext));
		backdrop.addEventListener('click', closeModal);

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal.classList.contains('is-open')) {
				closeModal();
			}

			if (!modal.classList.contains('is-open') || !modalItems.length) {
				return;
			}

			if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
				e.preventDefault();
				showRelativeItem(-1);
			} else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
				e.preventDefault();
				showRelativeItem(1);
			}
		});

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

		var items = root.querySelectorAll('.syc-video-button[data-video-url]');
		if (!items.length) {
			return;
		}

		if (items.length <= 3) {
			root.classList.add('syc-has-few-items');
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
			function getScrollAmount() {
				var first = track.querySelector('.syc-item');
				if (!first) {
					return Math.max(240, Math.round(track.clientWidth * 0.8));
				}
				var styles = window.getComputedStyle(first);
				return first.offsetWidth + parseFloat(styles.marginRight || 0);
			}

			function scrollTrack(delta) {
				var left = delta * getScrollAmount();

				if (typeof track.scrollBy === 'function') {
					track.scrollBy({
						left: left,
						behavior: 'smooth',
					});
				} else {
					track.scrollLeft += left;
				}
			}

			prev.addEventListener('click', function () {
				scrollTrack(-1);
			});

			next.addEventListener('click', function () {
				scrollTrack(1);
			});

			track.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowLeft') {
					e.preventDefault();
					scrollTrack(-1);
				} else if (e.key === 'ArrowRight') {
					e.preventDefault();
					scrollTrack(1);
				}
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

			(function enableTouchDragScroll(el) {
				var startX = 0;
				var startY = 0;
				var startScrollLeft = 0;
				var tracking = false;
				var horizontal = false;

				el.addEventListener(
					'touchstart',
					function (e) {
						if (!e.touches || e.touches.length !== 1) {
							return;
						}
						tracking = true;
						horizontal = false;
						startX = e.touches[0].clientX;
						startY = e.touches[0].clientY;
						startScrollLeft = el.scrollLeft;
					},
					{ passive: true }
				);

				el.addEventListener(
					'touchmove',
					function (e) {
						if (!tracking || !e.touches || e.touches.length !== 1) {
							return;
						}

						var dx = e.touches[0].clientX - startX;
						var dy = e.touches[0].clientY - startY;

						if (!horizontal && Math.abs(dx) > 8 && Math.abs(dx) > Math.abs(dy)) {
							horizontal = true;
						}

						if (!horizontal) {
							return;
						}

						e.preventDefault();
						el.scrollLeft = startScrollLeft - dx;
					},
					{ passive: false }
				);

				el.addEventListener(
					'touchend',
					function () {
						tracking = false;
					},
					{ passive: true }
				);
			})(track);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var carousels = document.querySelectorAll('.syc-carousel');
		Array.prototype.forEach.call(carousels, initCarousel);
	});
})();
