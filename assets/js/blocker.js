(() => {
	'use strict';

	const optionalCategories = new Set( [ 'functional', 'analytics', 'marketing' ] );
	let choices = {
		functional: false,
		analytics: false,
		marketing: false,
	};

	function categoryFor( resource ) {
		return resource.dataset.uccmCategory || '';
	}

	function reportUnknown( resource, reason ) {
		if ( 'true' === resource.dataset.uccmReported ) {
			return;
		}

		resource.dataset.uccmReported = 'true';
		window.dispatchEvent( new CustomEvent( 'uccm:resource-unknown', {
			detail: {
				rule: resource.dataset.uccmRule || '',
				type: resource.dataset.uccmBlocked || '',
				reason,
			},
		} ) );
	}

	function permitted( resource ) {
		const category = categoryFor( resource );

		if ( ! optionalCategories.has( category ) ) {
			reportUnknown( resource, 'unsupported-category' );
			return false;
		}

		return true === choices[ category ];
	}

	function copyScriptAttributes( source, target ) {
		Array.from( source.attributes ).forEach( ( attribute ) => {
			if ( 'type' === attribute.name || attribute.name.startsWith( 'data-uccm-' ) ) {
				return;
			}

			target.setAttribute( attribute.name, attribute.value );
		} );

		if ( source.dataset.uccmSrc ) {
			target.src = source.dataset.uccmSrc;
		}

		if ( source.dataset.uccmOriginalType && 'text/javascript' !== source.dataset.uccmOriginalType ) {
			target.type = source.dataset.uccmOriginalType;
		}
	}

	function activateScript( resource ) {
		if ( 'true' === resource.dataset.uccmActivated ) {
			return;
		}

		if ( ! resource.src && ! resource.dataset.uccmSrc && ! resource.textContent.trim() ) {
			reportUnknown( resource, 'missing-script' );
			return;
		}

		const script = document.createElement( 'script' );
		copyScriptAttributes( resource, script );
		script.textContent = resource.textContent;
		resource.dataset.uccmActivated = 'true';
		resource.after( script );

		window.dispatchEvent( new CustomEvent( 'uccm:resource-activated', {
			detail: {
				rule: resource.dataset.uccmRule || '',
				category: categoryFor( resource ),
				type: 'script',
			},
		} ) );
	}

	function activateSource( resource ) {
		if ( 'true' === resource.dataset.uccmActivated ) {
			return;
		}

		const source = resource.dataset.uccmSrc;

		if ( ! source ) {
			reportUnknown( resource, 'missing-source' );
			return;
		}

		resource.src = source;
		resource.dataset.uccmActivated = 'true';
		window.dispatchEvent( new CustomEvent( 'uccm:resource-activated', {
			detail: {
				rule: resource.dataset.uccmRule || '',
				category: categoryFor( resource ),
				type: resource.dataset.uccmBlocked || '',
			},
		} ) );
	}

	function deactivateSource( resource ) {
		if ( 'true' !== resource.dataset.uccmActivated || 'script' === resource.dataset.uccmBlocked ) {
			return;
		}

		resource.removeAttribute( 'src' );
		resource.dataset.uccmActivated = 'false';
		window.dispatchEvent( new CustomEvent( 'uccm:resource-blocked', {
			detail: {
				rule: resource.dataset.uccmRule || '',
				category: categoryFor( resource ),
				type: resource.dataset.uccmBlocked || '',
			},
		} ) );
	}

	function evaluate( resource ) {
		if ( ! resource.matches( '[data-uccm-blocked]' ) ) {
			return;
		}

		if ( ! permitted( resource ) ) {
			deactivateSource( resource );
			return;
		}

		if ( 'script' === resource.dataset.uccmBlocked ) {
			activateScript( resource );
		} else if ( [ 'iframe', 'embed', 'pixel' ].includes( resource.dataset.uccmBlocked ) ) {
			activateSource( resource );
		} else {
			reportUnknown( resource, 'unsupported-type' );
		}
	}

	function scan( root = document ) {
		if ( root instanceof Element && root.matches( '[data-uccm-blocked]' ) ) {
			evaluate( root );
		}

		if ( 'function' === typeof root.querySelectorAll ) {
			root.querySelectorAll( '[data-uccm-blocked]' ).forEach( evaluate );
		}
	}

	function updateChoices( nextChoices ) {
		optionalCategories.forEach( ( category ) => {
			choices[ category ] = true === nextChoices?.[ category ];
		} );
		scan();
	}

	window.addEventListener( 'uccm:consent-ready', ( event ) => {
		updateChoices( event.detail?.categories || {} );
	} );
	window.addEventListener( 'uccm:consent-changed', ( event ) => {
		updateChoices( event.detail?.categories || {} );
	} );

	const observer = new MutationObserver( ( mutations ) => {
		mutations.forEach( ( mutation ) => {
			mutation.addedNodes.forEach( ( node ) => {
				if ( node instanceof Element ) {
					scan( node );
				}
			} );
		} );
	} );

	function initialise() {
		scan();
		observer.observe( document.documentElement, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initialise, { once: true } );
	} else {
		initialise();
	}
})();
