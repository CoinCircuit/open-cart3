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
	var activeOverlay = null;

	function injectStyles() {
		if (document.getElementById(STYLES_ID)) return;
		var style = document.createElement('style');
		style.id = STYLES_ID;
		style.textContent = `
  #coincircuit-checkout-overlay {
    --cc-surface: #fafafa;
    --cc-foreground: #171717;
    --cc-muted: #666;
    --cc-edge: rgba(255, 255, 255, 0.85);
    --cc-control: rgba(250, 250, 250, 0.88);
    --cc-track: rgba(0, 0, 0, 0.1);
    --cc-accent: oklch(0.52 0.22 255);
    position: fixed;
    inset: 0;
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    padding: 24px 64px;
    background: rgba(12, 14, 18, 0.42);
    -webkit-backdrop-filter: blur(10px);
    backdrop-filter: blur(10px);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    color: var(--cc-foreground);
    opacity: 0;
    transition: opacity 200ms ease;
  }
  #coincircuit-checkout-overlay[data-theme="dark"] {
    --cc-surface: #111216;
    --cc-foreground: #f5f5f5;
    --cc-muted: #aaa;
    --cc-edge: rgba(255, 255, 255, 0.18);
    --cc-control: rgba(28, 29, 33, 0.9);
    --cc-track: rgba(255, 255, 255, 0.14);
    --cc-accent: oklch(0.72 0.29 263.25);
    color-scheme: dark;
  }
  #coincircuit-checkout-overlay, #coincircuit-checkout-overlay * {
    box-sizing: border-box;
  }
  #coincircuit-checkout-overlay.cc-visible {
    opacity: 1;
  }
  #coincircuit-checkout-overlay .cc-modal {
    position: relative;
    width: 100%;
    max-width: 740px;
    height: min(900px, calc(100vh - 48px));
    height: min(900px, calc(100dvh - 48px));
    border: 1px solid var(--cc-edge);
    border-radius: 30px;
    background: var(--cc-surface);
    box-shadow: 0 32px 100px -24px rgba(0, 0, 0, 0.38);
    transform: translateY(8px) scale(0.99);
    transition: transform 200ms cubic-bezier(0.22, 1, 0.36, 1);
  }
  #coincircuit-checkout-overlay.cc-visible .cc-modal {
    transform: translateY(0) scale(1);
  }
  #coincircuit-checkout-overlay .cc-frame {
    position: relative;
    width: 100%;
    height: 100%;
    overflow: hidden;
    border-radius: inherit;
    background: var(--cc-surface);
  }
  #coincircuit-checkout-overlay .cc-close {
    position: absolute;
    top: 0;
    right: -56px;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    margin: 0;
    padding: 0;
    border: 1px solid var(--cc-edge);
    border-radius: 50%;
    background: var(--cc-control);
    -webkit-backdrop-filter: blur(16px);
    backdrop-filter: blur(16px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    color: var(--cc-foreground);
    cursor: pointer;
    touch-action: manipulation;
    transition: background-color 160ms ease, transform 160ms ease;
  }
  #coincircuit-checkout-overlay .cc-close:hover {
    background: var(--cc-surface);
  }
  #coincircuit-checkout-overlay .cc-close:active {
    transform: scale(0.96);
  }
  #coincircuit-checkout-overlay .cc-close:focus-visible {
    outline: 2px solid var(--cc-accent);
    outline-offset: 4px;
  }
  #coincircuit-checkout-overlay .cc-close svg {
    display: block;
    width: 18px;
    height: 18px;
    pointer-events: none;
  }
  #coincircuit-checkout-overlay iframe {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
    opacity: 0;
    visibility: hidden;
    transition: opacity 200ms ease;
  }
  #coincircuit-checkout-overlay .cc-ready iframe {
    opacity: 1;
    visibility: visible;
  }
  #coincircuit-checkout-overlay .cc-spinner {
    position: absolute;
    inset: 0;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    background: var(--cc-surface);
    color: var(--cc-muted);
    font-size: 14px;
    line-height: 1.5;
    transition: opacity 200ms ease;
  }
  #coincircuit-checkout-overlay .cc-spinner-ring {
    width: 32px;
    height: 32px;
    border: 2px solid var(--cc-track);
    border-top-color: var(--cc-accent);
    border-radius: 50%;
    animation: cc-spin 800ms linear infinite;
  }
  #coincircuit-checkout-overlay .cc-ready .cc-spinner {
    opacity: 0;
    pointer-events: none;
  }
  @keyframes cc-spin { to { transform: rotate(360deg); } }
  @media (max-width: 720px) {
    #coincircuit-checkout-overlay {
      padding: 0;
      background: var(--cc-surface);
      -webkit-backdrop-filter: none;
      backdrop-filter: none;
    }
    #coincircuit-checkout-overlay .cc-modal {
      max-width: none;
      height: 100vh;
      height: 100dvh;
      padding-top: calc(56px + env(safe-area-inset-top, 0px));
      border: 0;
      border-radius: 0;
      box-shadow: none;
      transform: none;
    }
    #coincircuit-checkout-overlay .cc-close {
      top: calc(6px + env(safe-area-inset-top, 0px));
      right: max(12px, env(safe-area-inset-right, 0px));
      border-color: var(--cc-track);
      background: transparent;
      box-shadow: none;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    #coincircuit-checkout-overlay, #coincircuit-checkout-overlay * {
      animation: none !important;
      transition: none !important;
    }
    #coincircuit-checkout-overlay .cc-modal, #coincircuit-checkout-overlay .cc-close:active {
      transform: none;
    }
  }
  @media (prefers-reduced-transparency: reduce), (prefers-contrast: more) {
    #coincircuit-checkout-overlay {
      background: rgba(12, 14, 18, 0.85);
      -webkit-backdrop-filter: none;
      backdrop-filter: none;
    }
    #coincircuit-checkout-overlay .cc-close {
      background: var(--cc-surface);
      -webkit-backdrop-filter: none;
      backdrop-filter: none;
    }
  }
  @media (prefers-contrast: more) {
    #coincircuit-checkout-overlay .cc-modal, #coincircuit-checkout-overlay .cc-close {
      border-color: var(--cc-foreground);
    }
  }
`;
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
	 *   theme          optional light or dark overlay
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
		overlay.setAttribute('data-theme', options.theme === 'dark' ? 'dark' : 'light');
		var previousFocus = document.activeElement;
		var previousOverflow = document.body.style.overflow;
		var previousPaddingRight = document.body.style.paddingRight;

		var modal = document.createElement('div');
		modal.className = 'cc-modal';
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-label', 'CoinCircuit checkout');

		var frame = document.createElement('div');
		frame.className = 'cc-frame';
		frame.setAttribute('aria-busy', 'true');

		var spinner = document.createElement('div');
		spinner.className = 'cc-spinner';
		spinner.setAttribute('role', 'status');
		var spinnerRing = document.createElement('div');
		spinnerRing.className = 'cc-spinner-ring';
		spinnerRing.setAttribute('aria-hidden', 'true');
		var spinnerLabel = document.createElement('span');
		spinnerLabel.textContent = 'Loading checkout';
		spinner.appendChild(spinnerRing);
		spinner.appendChild(spinnerLabel);

		var iframe = document.createElement('iframe');
		iframe.src = buildEmbedUrl(options.url);
		iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox');
		iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
		iframe.setAttribute('title', 'CoinCircuit Checkout');
		iframe.setAttribute('allow', 'payment');
		iframe.tabIndex = -1;

		var closeBtn = document.createElement('button');
		closeBtn.className = 'cc-close';
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', 'Close checkout');
		var closeIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		closeIcon.setAttribute('viewBox', '0 0 24 24');
		closeIcon.setAttribute('aria-hidden', 'true');
		closeIcon.setAttribute('fill', 'none');
		closeIcon.setAttribute('stroke', 'currentColor');
		closeIcon.setAttribute('stroke-width', '1.75');
		closeIcon.setAttribute('stroke-linecap', 'round');
		var closePath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		closePath.setAttribute('d', 'M6 6l12 12M18 6L6 18');
		closeIcon.appendChild(closePath);
		closeBtn.appendChild(closeIcon);

		frame.appendChild(iframe);
		frame.appendChild(spinner);
		modal.appendChild(closeBtn);
		modal.appendChild(frame);
		overlay.appendChild(modal);

		function dismiss() {
			if (activeOverlay !== overlay) return;
			close();
			if (options.onClose) options.onClose();
		}
		closeBtn.addEventListener('click', dismiss);
		// An accidental tap on the backdrop must not abandon a payment.

		function onKeydown(e) {
			if (activeOverlay !== overlay) return;
			if (e.key === 'Escape') {
				e.preventDefault();
				dismiss();
			} else if (e.key === 'Tab' && e.shiftKey && document.activeElement === closeBtn && modal.classList.contains('cc-ready')) {
				e.preventDefault();
				iframe.focus();
			}
		}
		function onFocus(e) {
			if (activeOverlay === overlay && !overlay.contains(e.target)) closeBtn.focus();
		}
		document.addEventListener('keydown', onKeydown);
		document.addEventListener('focusin', onFocus);

		var loadTimeout = setTimeout(function () {
			if (activeOverlay !== overlay) return;
			close();
			if (options.onLoadFailure) options.onLoadFailure();
		}, LOAD_TIMEOUT_MS);
		iframe.addEventListener('error', function () {
			if (activeOverlay !== overlay) return;
			close();
			if (options.onLoadFailure) options.onLoadFailure();
		});

		var ready = false;
		function onMessage(event) {
			if (activeOverlay !== overlay || event.origin !== allowedOrigin || event.source !== iframe.contentWindow) return;
			var msg = event.data;
			if (!msg || typeof msg.type !== 'string' || msg.type.indexOf('coincircuit:') !== 0) return;

			switch (msg.type) {
				case 'coincircuit:ready':
					if (ready) break;
					ready = true;
					clearTimeout(loadTimeout);
					frame.setAttribute('aria-busy', 'false');
					iframe.tabIndex = 0;
					modal.classList.add('cc-ready');
					spinner.style.opacity = '0';
					setTimeout(function () {
						if (spinner.parentNode) spinner.parentNode.removeChild(spinner);
					}, 200);
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

		overlay._ccCleanup = function () {
			clearTimeout(loadTimeout);
			window.removeEventListener('message', onMessage);
			document.removeEventListener('keydown', onKeydown);
			document.removeEventListener('focusin', onFocus);
		};
		overlay._ccPreviousFocus = previousFocus;
		overlay._ccPreviousOverflow = previousOverflow;
		overlay._ccPreviousPaddingRight = previousPaddingRight;

		activeOverlay = overlay;
		document.body.appendChild(overlay);
		var scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
		if (scrollbarWidth > 0) {
			var padding = parseFloat(window.getComputedStyle(document.body).paddingRight) || 0;
			document.body.style.paddingRight = padding + scrollbarWidth + 'px';
		}
		document.body.style.overflow = 'hidden';
		void overlay.offsetHeight; // reflow so the fade-in transition runs
		overlay.classList.add('cc-visible');
		closeBtn.focus({ preventScroll: true });
	}

	function close() {
		var overlay = activeOverlay;
		if (!overlay) return;
		activeOverlay = null;
		overlay._ccCleanup();
		overlay.classList.remove('cc-visible');
		overlay.style.pointerEvents = 'none';
		overlay.inert = true;
		document.body.style.overflow = overlay._ccPreviousOverflow;
		document.body.style.paddingRight = overlay._ccPreviousPaddingRight;
		if (overlay._ccPreviousFocus && overlay._ccPreviousFocus.focus) {
			overlay._ccPreviousFocus.focus({ preventScroll: true });
		}
		setTimeout(function () {
			if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
		}, 200);
	}

	window.CoinCircuitCheckoutEmbed = { open: open, close: close };
})();
