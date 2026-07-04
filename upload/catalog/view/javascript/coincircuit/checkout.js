/**
 * CoinCircuit embedded checkout for OpenCart.
 *
 * Vanilla port of the @coincircuit/checkout SDK modal: opens the hosted
 * checkout page in a full-screen iframe overlay on the store's own page and
 * listens for its postMessage events (coincircuit:ready / payment_complete /
 * payment_failed / expired / close). No dependencies, no build step.
 *
 * If the embed never becomes ready (blocked iframe, network failure), the
 * onLoadFailure callback fires so the caller can fall back to a full
 * redirect - the shopper must always have a way to pay.
 */
(function () {
	'use strict';

	var OVERLAY_ID = 'coincircuit-checkout-overlay';
	var STYLES_ID = 'coincircuit-checkout-styles';
	var LOAD_TIMEOUT_MS = 30000;

	function injectStyles() {
		if (document.getElementById(STYLES_ID)) return;
		var style = document.createElement('style');
		style.id = STYLES_ID;
		style.textContent = '' +
			'#' + OVERLAY_ID + '{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);opacity:0;transition:opacity 0.2s ease;}' +
			'#' + OVERLAY_ID + '.cc-visible{opacity:1;}' +
			'#' + OVERLAY_ID + ' .cc-modal{position:relative;width:min(600px,96vw);max-width:600px;height:min(88vh,800px);border-radius:16px;background:#fff;box-shadow:0 25px 50px -12px rgba(0,0,0,0.4);opacity:0;transform:scale(0.96);transition:opacity 0.25s ease,transform 0.25s ease;}' +
			'#' + OVERLAY_ID + ' .cc-modal.cc-ready{opacity:1;transform:scale(1);}' +
			'#' + OVERLAY_ID + ' .cc-close{position:fixed;top:16px;right:16px;z-index:1000000;width:36px;height:36px;border:none;border-radius:50%;background:rgba(255,255,255,0.15);backdrop-filter:blur(8px);color:#fff;font-size:20px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.15s,transform 0.15s;}' +
			'#' + OVERLAY_ID + ' .cc-close:hover{background:rgba(255,255,255,0.25);transform:scale(1.1);}' +
			'#' + OVERLAY_ID + ' iframe{width:100%;height:100%;border:none;display:block;border-radius:inherit;}' +
			'#' + OVERLAY_ID + ' .cc-spinner{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:1;transition:opacity 0.2s;}' +
			'#' + OVERLAY_ID + ' .cc-spinner div{width:36px;height:36px;border:3px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:cc-spin 0.8s linear infinite;}' +
			'@keyframes cc-spin{to{transform:rotate(360deg);}}' +
			'@media (max-width:720px){#' + OVERLAY_ID + ' .cc-modal{width:100%;max-width:100%;height:100dvh;border-radius:0;}}';
		document.head.appendChild(style);
	}

	/** Append embed=true, preserving any existing query string. */
	function buildEmbedUrl(rawUrl) {
		try {
			var url = new URL(rawUrl, window.location.href);
			url.searchParams.set('embed', 'true');
			return url.toString();
		} catch (e) {
			return rawUrl + (rawUrl.indexOf('?') === -1 ? '?embed=true' : '&embed=true');
		}
	}

	/** Origin of the checkout URL, for postMessage validation. */
	function originOf(rawUrl) {
		try {
			return new URL(rawUrl, window.location.href).origin;
		} catch (e) {
			var a = document.createElement('a');
			a.href = rawUrl;
			return a.protocol + '//' + a.host;
		}
	}

	/**
	 * Open the checkout modal.
	 *
	 * options:
	 *   url            (required) hosted checkout URL for the session
	 *   onComplete     payment confirmed by the checkout page
	 *   onClose        shopper dismissed the modal (X button, Escape, or
	 *                  the page's own close action)
	 *   onLoadFailure  embed never became ready - caller should redirect
	 */
	function open(options) {
		if (!options || !options.url) {
			throw new Error('CoinCircuitCheckoutEmbed: "url" is required.');
		}

		close(); // only one modal at a time

		injectStyles();

		var allowedOrigin = originOf(options.url);
		var overlay = document.createElement('div');
		overlay.id = OVERLAY_ID;

		var modal = document.createElement('div');
		modal.className = 'cc-modal';

		var spinner = document.createElement('div');
		spinner.className = 'cc-spinner';
		spinner.appendChild(document.createElement('div'));

		var iframe = document.createElement('iframe');
		iframe.src = buildEmbedUrl(options.url);
		iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox');
		iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
		iframe.setAttribute('title', 'CoinCircuit Checkout');
		iframe.setAttribute('allow', 'payment');

		var closeBtn = document.createElement('button');
		closeBtn.className = 'cc-close';
		closeBtn.setAttribute('aria-label', 'Close checkout');
		closeBtn.textContent = '✕';

		modal.appendChild(iframe);
		overlay.appendChild(spinner);
		overlay.appendChild(modal);
		overlay.appendChild(closeBtn);

		function dismiss() {
			close();
			if (options.onClose) options.onClose();
		}

		closeBtn.addEventListener('click', dismiss);
		// No backdrop-click dismissal: an accidental tap outside the modal
		// must never abandon a payment in progress. Closing is deliberate
		// only - the X button, Escape, or the checkout page's own close.

		function onKeydown(e) {
			if (e.key === 'Escape') dismiss();
		}
		document.addEventListener('keydown', onKeydown);

		var loadTimeout = setTimeout(function () {
			close();
			if (options.onLoadFailure) options.onLoadFailure();
		}, LOAD_TIMEOUT_MS);

		iframe.addEventListener('error', function () {
			clearTimeout(loadTimeout);
			close();
			if (options.onLoadFailure) options.onLoadFailure();
		});

		function onMessage(event) {
			if (event.origin !== allowedOrigin) return;

			var msg = event.data;
			if (!msg || typeof msg.type !== 'string' || msg.type.indexOf('coincircuit:') !== 0) return;

			switch (msg.type) {
				case 'coincircuit:ready':
					clearTimeout(loadTimeout);
					var s = overlay.querySelector('.cc-spinner');
					if (s) {
						s.style.opacity = '0';
						setTimeout(function () {
							if (s.parentNode) s.parentNode.removeChild(s);
						}, 200);
					}
					modal.className = 'cc-modal cc-ready';
					break;
				case 'coincircuit:payment_complete':
					if (options.onComplete) options.onComplete(msg.data || {});
					break;
				case 'coincircuit:close':
					dismiss();
					break;
				// payment_failed / expired: the embedded page presents its own
				// state; the shopper can close the modal and try again.
			}
		}
		window.addEventListener('message', onMessage);

		// Track cleanup handles on the overlay itself so close() can find
		// them even when called for a previous instance.
		overlay._ccCleanup = function () {
			clearTimeout(loadTimeout);
			window.removeEventListener('message', onMessage);
			document.removeEventListener('keydown', onKeydown);
		};

		document.body.appendChild(overlay);
		document.body.style.overflow = 'hidden';
		void overlay.offsetHeight; // reflow so the fade-in transition runs
		overlay.className = 'cc-visible';
	}

	function close() {
		var overlay = document.getElementById(OVERLAY_ID);
		if (!overlay) return;

		if (overlay._ccCleanup) overlay._ccCleanup();
		overlay.className = '';
		document.body.style.overflow = '';
		setTimeout(function () {
			if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
		}, 200);
	}

	window.CoinCircuitCheckoutEmbed = { open: open, close: close };
})();
