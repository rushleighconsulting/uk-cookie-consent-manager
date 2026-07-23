( function () {
	'use strict';

	var config = window.UCCMScanRunner || {};
	var button = document.getElementById( 'uccm-run-browser-observations' );
	var status = document.getElementById( 'uccm-browser-observation-status' );

	if ( ! button || ! status || ! Array.isArray( config.targets ) ) {
		return;
	}

	function announce( message ) {
		status.textContent = message;
	}

	function observationKey( observation ) {
		return [ observation.type, observation.storage_key, observation.domain, observation.source_url ].join( '|' );
	}

	function safeName( value ) {
		return String( value || '' ).trim().slice( 0, 191 );
	}

	function collect( frame, target ) {
		var observations = [];
		var seen = {};
		var frameWindow = frame.contentWindow;
		var frameDocument = frame.contentDocument;

		function add( observation ) {
			observation.storage_key = safeName( observation.storage_key );

			if ( ! observation.storage_key ) {
				return;
			}

			var key = observationKey( observation );

			if ( ! seen[ key ] ) {
				seen[ key ] = true;
				observations.push( observation );
			}
		}

		String( frameDocument.cookie || '' ).split( ';' ).forEach( function ( pair ) {
			var name = safeName( pair.split( '=' )[ 0 ] );

			if ( ! name || /^(?:wordpress_|wordpress_logged_in_|wp-settings)/i.test( name ) ) {
				return;
			}

			add( {
				type: 'cookie',
				storage_key: name,
				domain: frameWindow.location.hostname,
				source_url: target
			} );
		} );

		try {
			for ( var index = 0; index < frameWindow.localStorage.length; index += 1 ) {
				add( {
					type: 'local_storage',
					storage_key: frameWindow.localStorage.key( index ),
					domain: frameWindow.location.hostname,
					source_url: target
				} );
			}
		} catch ( error ) {
			// Storage access can be denied by browser policy; continue with DOM evidence.
		}

		frameDocument.querySelectorAll( 'script[src]' ).forEach( function ( element ) {
			add( {
				type: 'script',
				storage_key: element.getAttribute( 'id' ) || element.src,
				domain: new URL( element.src, target ).hostname,
				source_url: new URL( element.src, target ).href
			} );
		} );

		frameDocument.querySelectorAll( 'iframe[src], embed[src]' ).forEach( function ( element ) {
			var source = new URL( element.getAttribute( 'src' ), target );
			add( {
				type: 'iframe',
				storage_key: element.getAttribute( 'title' ) || element.getAttribute( 'id' ) || source.href,
				domain: source.hostname,
				source_url: source.href
			} );
		} );

		frameDocument.querySelectorAll( 'img[src]' ).forEach( function ( element ) {
			if ( 1 < element.naturalWidth && 1 < element.naturalHeight && 1 < element.width && 1 < element.height ) {
				return;
			}

			var source = new URL( element.getAttribute( 'src' ), target );
			add( {
				type: 'pixel',
				storage_key: element.getAttribute( 'id' ) || source.href,
				domain: source.hostname,
				source_url: source.href
			} );
		} );

		return observations;
	}

	function inspectTarget( target ) {
		return new Promise( function ( resolve ) {
			var targetUrl;

			try {
				targetUrl = new URL( target, window.location.href );
			} catch ( error ) {
				resolve( [] );
				return;
			}

			if ( targetUrl.origin !== window.location.origin ) {
				resolve( [] );
				return;
			}

			var frame = document.createElement( 'iframe' );
			var settled = false;
			var finish = function ( observations ) {
				if ( settled ) {
					return;
				}

				settled = true;
				frame.remove();
				resolve( observations );
			};
			var timer = window.setTimeout( function () {
				finish( [] );
			}, 10000 );

			frame.setAttribute( 'title', 'Cookie scan observation frame' );
			frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1280px;height:800px;opacity:0;pointer-events:none;';
			frame.addEventListener( 'load', function () {
				window.setTimeout( function () {
					var observations = [];

					try {
						observations = collect( frame, targetUrl.href );
					} catch ( error ) {
						observations = [];
					}

					window.clearTimeout( timer );
					finish( observations );
				}, 750 );
			} );
			frame.src = targetUrl.href;
			document.body.appendChild( frame );
		} );
	}

	async function submit( observations, targetCount ) {
		var body = new URLSearchParams();
		body.set( 'action', 'uccm_browser_scan_observations' );
		body.set( 'nonce', String( config.nonce || '' ) );
		body.set( 'scan_id', String( config.runId || 0 ) );
		body.set( 'payload', JSON.stringify( { observations: observations, target_count: targetCount } ) );

		var response = await window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} );
		var result = await response.json();

		if ( ! response.ok || ! result.success ) {
			throw new Error( result.data && result.data.message ? result.data.message : 'Browser observations could not be saved.' );
		}
	}

	button.addEventListener( 'click', async function () {
		button.disabled = true;
		var targets = config.targets.filter( function ( target ) {
			try {
				return new URL( target, window.location.href ).origin === window.location.origin;
			} catch ( error ) {
				return false;
			}
		} ).slice( 0, Number( config.maxTargets ) || 100 );
		var observations = [];

		try {
			for ( var index = 0; index < targets.length; index += 1 ) {
				announce( 'Inspecting page ' + ( index + 1 ) + ' of ' + targets.length + '…' );
				observations = observations.concat( await inspectTarget( targets[ index ] ) );
			}

			await submit( observations, targets.length );
			announce( 'Browser observations saved. Reload this scan to review updated findings and coverage.' );
		} catch ( error ) {
			announce( error instanceof Error ? error.message : 'Browser observations could not be completed.' );
		} finally {
			button.disabled = false;
		}
	} );
}() );
