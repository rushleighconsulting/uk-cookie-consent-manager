(() => {
	'use strict';

	const config = window.uccmConsentConfig;
	const optionalCategories = [ 'functional', 'analytics', 'marketing' ];

	function initialise() {
		const root = document.getElementById( 'uccm-consent-root' );

		if ( ! root || ! config ) {
			return;
		}

		const banner = root.querySelector( '#uccm-banner' );
		const dialog = root.querySelector( '#uccm-preferences' );
		const settingsButton = root.querySelector( '.uccm-settings' );
		const status = root.querySelector( '[data-uccm-status]' );
		let dialogInvoker = null;
		let suppressFocusReturn = false;

		if ( ! banner || ! dialog || ! settingsButton || ! status ) {
			return;
		}

		function emptyChoices( optionalValue = false ) {
			return {
				necessary: true,
				functional: optionalValue,
				analytics: optionalValue,
				marketing: optionalValue,
			};
		}

		function normaliseChoices( choices ) {
			const normalised = emptyChoices();

			if ( ! choices || 'object' !== typeof choices ) {
				return normalised;
			}

			optionalCategories.forEach( ( category ) => {
				normalised[ category ] = true === choices[ category ];
			} );

			return normalised;
		}

		function readCookie() {
			const prefix = `${ encodeURIComponent( config.cookieName ) }=`;
			const item = document.cookie
				.split( '; ' )
				.find( ( cookie ) => cookie.startsWith( prefix ) );

			if ( ! item ) {
				return null;
			}

			try {
				const decision = JSON.parse( decodeURIComponent( item.slice( prefix.length ) ) );
				const validAction = [ 'grant', 'reject', 'update', 'withdraw' ].includes( decision.action );
				const validExpiry = Number.isFinite( decision.expiresAt ) && decision.expiresAt > Date.now();

				if ( decision.policyVersion !== config.policyVersion || ! validAction || ! validExpiry ) {
					return null;
				}

				decision.categories = normaliseChoices( decision.categories );
				return decision;
			} catch {
				return null;
			}
		}

		function newReceiptId() {
			if ( window.crypto && 'function' === typeof window.crypto.randomUUID ) {
				return window.crypto.randomUUID();
			}

			return `uccm-${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;
		}

		function storeReceipt( decision ) {
			if ( ! config.receiptEndpoint || 'function' !== typeof window.fetch ) {
				return;
			}

			window.fetch( config.receiptEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( decision ),
			} ).then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( 'receipt-not-stored' );
				}
			} ).catch( () => {
				window.dispatchEvent( new CustomEvent( 'uccm:receipt-failed', {
					detail: { receiptId: decision.receiptId },
				} ) );
			} );
		}

		function writeDecision( action, choices ) {
			const lifetimeSeconds = Number( config.lifetimeDays ) * 24 * 60 * 60;
			const now = Date.now();
			const decision = {
				receiptId: newReceiptId(),
				policyVersion: config.policyVersion,
				pluginVersion: config.pluginVersion,
				decidedAt: new Date( now ).toISOString(),
				expiresAt: now + ( lifetimeSeconds * 1000 ),
				action,
				categories: normaliseChoices( choices ),
			};
			const secure = 'https:' === window.location.protocol ? '; Secure' : '';
			const cookiePath = config.cookiePath || '/';

			document.cookie = `${ encodeURIComponent( config.cookieName ) }=${ encodeURIComponent( JSON.stringify( decision ) ) }; Path=${ cookiePath }; Max-Age=${ lifetimeSeconds }; SameSite=Lax${ secure }`;
			applyDecision( decision );
			window.dispatchEvent( new CustomEvent( 'uccm:consent-changed', { detail: decision } ) );
			storeReceipt( decision );

			return decision;
		}

		function applyDecision( decision ) {
			const choices = decision ? normaliseChoices( decision.categories ) : emptyChoices();

			root.dataset.uccmState = decision ? 'decided' : 'undecided';
			banner.hidden = Boolean( decision );
			settingsButton.hidden = false;

			Object.entries( choices ).forEach( ( [ category, allowed ] ) => {
				const value = allowed ? 'granted' : 'denied';
				root.dataset[ `uccm${ category.charAt( 0 ).toUpperCase() }${ category.slice( 1 ) }` ] = value;
				document.documentElement.dataset[ `uccm${ category.charAt( 0 ).toUpperCase() }${ category.slice( 1 ) }` ] = value;
			} );
		}

		function updatePreferenceControls( decision ) {
			const choices = decision ? normaliseChoices( decision.categories ) : emptyChoices();

			optionalCategories.forEach( ( category ) => {
				const input = dialog.querySelector( `input[name="${ category }"]` );

				if ( input ) {
					input.checked = choices[ category ];
				}
			} );
		}

		function openPreferences( event ) {
			if ( ! event || ! event.isTrusted || ! event.currentTarget || ! root.contains( event.currentTarget ) ) {
				return;
			}

			updatePreferenceControls( readCookie() );
			dialogInvoker = event.currentTarget;
			dialog.dataset.uccmExplicitOpen = 'true';

			try {
				dialog.showModal();
			} catch {
				delete dialog.dataset.uccmExplicitOpen;
				return;
			}

			dialog.querySelector( '#uccm-preferences-title' ).focus();
		}

		function closePreferences( restoreFocus = true ) {
			suppressFocusReturn = ! restoreFocus;
			delete dialog.dataset.uccmExplicitOpen;

			if ( dialog.open ) {
				dialog.close();
				return;
			}

			dialog.removeAttribute( 'open' );
			suppressFocusReturn = false;
		}

		function selectedChoices() {
			const choices = emptyChoices();

			optionalCategories.forEach( ( category ) => {
				const input = dialog.querySelector( `input[name="${ category }"]` );
				choices[ category ] = Boolean( input && input.checked );
			} );

			return choices;
		}

		closePreferences( false );

		dialog.addEventListener( 'close', () => {
			delete dialog.dataset.uccmExplicitOpen;
			dialog.removeAttribute( 'open' );

			if ( ! suppressFocusReturn ) {
				const target = dialogInvoker && ! dialogInvoker.hidden ? dialogInvoker : settingsButton;

				if ( target && ! target.hidden && 'function' === typeof target.focus ) {
					target.focus();
				}
			}

			suppressFocusReturn = false;
			dialogInvoker = null;
		} );
		dialog.addEventListener( 'keydown', ( event ) => {
			if ( 'Tab' !== event.key || ! dialog.open ) {
				return;
			}

			const controls = Array.from(
				dialog.querySelectorAll( 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])' )
			).filter( ( control ) => ! control.hidden );

			if ( 0 === controls.length ) {
				event.preventDefault();
				return;
			}

			const first = controls[0];
			const last = controls[ controls.length - 1 ];

			if ( event.shiftKey && ( document.activeElement === first || ! dialog.contains( document.activeElement ) ) ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );

		const dialogGuard = new MutationObserver( () => {
			if ( dialog.open && 'true' !== dialog.dataset.uccmExplicitOpen ) {
				closePreferences( false );
			}
		} );
		dialogGuard.observe( dialog, { attributes: true, attributeFilter: [ 'open' ] } );

		window.addEventListener( 'pagehide', () => closePreferences( false ) );
		window.addEventListener( 'pageshow', ( event ) => {
			if ( event.persisted ) {
				closePreferences( false );
			}
		} );

		root.querySelectorAll( '[data-uccm-action="manage"]' ).forEach( ( button ) => {
			button.addEventListener( 'click', openPreferences );
		} );
		root.querySelector( '[data-uccm-action="accept-all"]' ).addEventListener( 'click', () => {
			writeDecision( 'grant', emptyChoices( true ) );
			status.textContent = config.messages.saved;
		} );
		root.querySelector( '[data-uccm-action="reject-optional"]' ).addEventListener( 'click', () => {
			writeDecision( 'reject', emptyChoices() );
			status.textContent = config.messages.saved;
		} );
		root.querySelector( '[data-uccm-action="close"]' ).addEventListener( 'click', closePreferences );
		root.querySelector( '[data-uccm-action="save"]' ).addEventListener( 'click', () => {
			const existing = readCookie();
			const choices = selectedChoices();
			const hasOptionalConsent = optionalCategories.some( ( category ) => choices[ category ] );
			const action = existing ? 'update' : ( hasOptionalConsent ? 'grant' : 'reject' );

			writeDecision( action, choices );
			closePreferences();
			status.textContent = config.messages.saved;
		} );
		root.querySelector( '[data-uccm-action="withdraw"]' ).addEventListener( 'click', () => {
			writeDecision( 'withdraw', emptyChoices() );
			closePreferences();
			status.textContent = config.messages.withdrawn;
		} );

		const initialDecision = readCookie();
		applyDecision( initialDecision );

		if ( ! initialDecision && config.messages.available ) {
			status.textContent = config.messages.available;
		}

		window.dispatchEvent( new CustomEvent( 'uccm:consent-ready', {
			detail: initialDecision || { categories: emptyChoices() },
		} ) );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initialise, { once: true } );
	} else {
		initialise();
	}
})();
