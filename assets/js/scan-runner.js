( function () {
	'use strict';

	var config = window.UCCMScanRunner || {};
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : {};
	var __ = i18n.__ || function ( text ) {
		return text;
	};
	var sprintf = i18n.sprintf || function ( format ) {
		var values = Array.prototype.slice.call( arguments, 1 );
		return format.replace( /%(\d+)\$[ds]/g, function ( match, position ) {
			return String( values[ Number( position ) - 1 ] ?? match );
		} );
	};
	var button = document.getElementById( 'uccm-run-browser-observations' );
	var status = document.getElementById( 'uccm-browser-observation-status' );
	var optionalCategories = [ 'functional', 'analytics', 'marketing' ];
	var scenarios = [
		{ name: 'pre-consent', label: __( 'Before a choice', 'uk-cookie-consent-manager' ), action: '', allowed: [] },
		{ name: 'reject', label: __( 'Reject non-essential', 'uk-cookie-consent-manager' ), action: 'reject', allowed: [] },
		{ name: 'accept-all', label: __( 'Accept all', 'uk-cookie-consent-manager' ), action: 'grant', allowed: optionalCategories },
		{ name: 'functional', label: __( 'Functional only', 'uk-cookie-consent-manager' ), action: 'grant', allowed: [ 'functional' ] },
		{ name: 'analytics', label: __( 'Analytics only', 'uk-cookie-consent-manager' ), action: 'grant', allowed: [ 'analytics' ] },
		{ name: 'marketing', label: __( 'Marketing only', 'uk-cookie-consent-manager' ), action: 'grant', allowed: [ 'marketing' ] }
	];
	var sourceLimit = 20;
	var stepDelayMs = Math.max( 250, Math.min( 5000, Number( config.stepDelayMs ) || 500 ) );
	var protectedLookup = {};
	var isolatedContextAvailable = 'credentialless' in HTMLIFrameElement.prototype;
	var browserRequirement = __( 'For your privacy, this check needs a current Chrome, Edge or other Chromium browser. Safari and Firefox are not supported yet.', 'uk-cookie-consent-manager' );

	if ( ! button || ! status || ! Array.isArray( config.targets ) ) {
		return;
	}

	if ( Array.isArray( config.protectedTargets ) ) {
		config.protectedTargets.forEach( function ( target ) {
			protectedLookup[ String( target ) ] = true;
		} );
	}

	if ( ! isolatedContextAvailable ) {
		button.disabled = true;
		button.setAttribute( 'aria-disabled', 'true' );
		announce( browserRequirement );
		return;
	}

	function announce( message ) {
		status.textContent = message;
	}

	function wait( milliseconds ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, milliseconds );
		} );
	}

	function safeName( value ) {
		return String( value || '' ).trim().slice( 0, 191 );
	}

	function observationKey( observation ) {
		return [ observation.type, observation.storage_key, observation.domain ].join( '|' ).toLowerCase();
	}

	function safeUrl( value, base ) {
		try {
			return new URL( value, base );
		} catch ( error ) {
			return null;
		}
	}

	function addObservation( collected, observation, target, scenarioName ) {
		observation.storage_key = safeName( observation.storage_key );
		observation.domain = safeName( observation.domain ).toLowerCase();

		if ( ! observation.storage_key ) {
			return;
		}

		var key = observationKey( observation );
		var existing = collected[ key ];

		if ( ! existing ) {
			existing = {
				type: observation.type,
				storage_key: observation.storage_key,
				domain: observation.domain,
				source_url: target,
				source_urls: [],
				source_count: 0,
				source_seen: {},
				consent_states: []
			};
			collected[ key ] = existing;
		}

		if ( ! existing.source_seen[ target ] ) {
			existing.source_seen[ target ] = true;
			existing.source_count += 1;

			if ( existing.source_urls.length < sourceLimit ) {
				existing.source_urls.push( target );
			}
		}

		if ( -1 === existing.consent_states.indexOf( scenarioName ) ) {
			existing.consent_states.push( scenarioName );
		}
	}

	function clearVisitorState( frameWindow, frameDocument ) {
		try {
			frameWindow.localStorage.clear();
		} catch ( error ) {
			// Storage can be unavailable under browser policy.
		}

		try {
			frameWindow.sessionStorage.clear();
		} catch ( error ) {
			// Storage can be unavailable under browser policy.
		}

		String( frameDocument.cookie || '' ).split( ';' ).forEach( function ( pair ) {
			var name = safeName( pair.split( '=' )[ 0 ] );
			var paths = [ '/', frameWindow.location.pathname || '/' ];

			if ( ! name ) {
				return;
			}

			paths.forEach( function ( path ) {
				frameDocument.cookie = encodeURIComponent( name ) + '=; Path=' + path + '; Max-Age=0; SameSite=Lax';
			} );
		} );
	}

	function applyScenario( frameWindow, frameDocument, scenario ) {
		if ( ! scenario.action ) {
			return;
		}

		var categories = { necessary: true, functional: false, analytics: false, marketing: false };
		scenario.allowed.forEach( function ( category ) {
			categories[ category ] = true;
		} );

		var now = Date.now();
		var lifetimeDays = Math.max( 1, Math.min( 730, Number( config.lifetimeDays ) || 180 ) );
		var decision = {
			receiptId: 'browser-scan-' + scenario.name,
			policyVersion: String( config.policyVersion || '1' ),
			pluginVersion: String( config.pluginVersion || '' ),
			decidedAt: new Date( now ).toISOString(),
			expiresAt: now + ( lifetimeDays * 86400000 ),
			action: scenario.action,
			categories: categories
		};
		var secure = 'https:' === frameWindow.location.protocol ? '; Secure' : '';
		var cookiePath = String( config.cookiePath || '/' );

		frameDocument.cookie = encodeURIComponent( String( config.cookieName || 'uccm_consent' ) ) + '=' +
			encodeURIComponent( JSON.stringify( decision ) ) + '; Path=' + cookiePath + '; Max-Age=' +
			( lifetimeDays * 86400 ) + '; SameSite=Lax' + secure;
	}

	function collect( frame, target, scenarioName, collected ) {
		var frameWindow = frame.contentWindow;
		var frameDocument = frame.contentDocument;
		var consentCookie = String( config.cookieName || 'uccm_consent' ).toLowerCase();

		String( frameDocument.cookie || '' ).split( ';' ).forEach( function ( pair ) {
			var name = safeName( pair.split( '=' )[ 0 ] );

			if ( ! name || name.toLowerCase() === consentCookie || /^(?:wordpress_|wordpress_logged_in_|wp-settings)/i.test( name ) ) {
				return;
			}

			addObservation( collected, {
				type: 'cookie',
				storage_key: name,
				domain: frameWindow.location.hostname
			}, target, scenarioName );
		} );

		[ [ 'local_storage', 'localStorage' ], [ 'session_storage', 'sessionStorage' ] ].forEach( function ( storage ) {
			try {
				for ( var index = 0; index < frameWindow[ storage[ 1 ] ].length; index += 1 ) {
					addObservation( collected, {
						type: storage[ 0 ],
						storage_key: frameWindow[ storage[ 1 ] ].key( index ),
						domain: frameWindow.location.hostname
					}, target, scenarioName );
				}
			} catch ( error ) {
				// Continue with the evidence the browser permits.
			}
		} );

		frameDocument.querySelectorAll( 'script[src]' ).forEach( function ( element ) {
			var source = safeUrl( element.getAttribute( 'src' ), target );
			if ( source ) {
				addObservation( collected, {
					type: 'script',
					storage_key: element.getAttribute( 'id' ) || source.href,
					domain: source.hostname
				}, target, scenarioName );
			}
		} );

		frameDocument.querySelectorAll( 'iframe[src], embed[src]' ).forEach( function ( element ) {
			var source = safeUrl( element.getAttribute( 'src' ), target );
			if ( source ) {
				addObservation( collected, {
					type: 'iframe',
					storage_key: element.getAttribute( 'title' ) || element.getAttribute( 'id' ) || source.href,
					domain: source.hostname
				}, target, scenarioName );
			}
		} );

		frameDocument.querySelectorAll( 'img[src]' ).forEach( function ( element ) {
			if ( 1 < element.naturalWidth && 1 < element.naturalHeight && 1 < element.width && 1 < element.height ) {
				return;
			}

			var source = safeUrl( element.getAttribute( 'src' ), target );
			if ( source ) {
				addObservation( collected, {
					type: 'pixel',
					storage_key: element.getAttribute( 'id' ) || source.href,
					domain: source.hostname
				}, target, scenarioName );
			}
		} );
	}

	function inspectScenario( target, scenario, collected, protectedTarget ) {
		return new Promise( function ( resolve, reject ) {
			var frame = document.createElement( 'iframe' );
			var phase = 'prepare';
			var settled = false;
			var timer;

			function finish( error ) {
				if ( settled ) {
					return;
				}
				settled = true;
				window.clearTimeout( timer );
				frame.remove();
				error ? reject( error ) : resolve();
			}

			function handleObservationLoad() {
				window.setTimeout( function () {
					try {
						if ( 'prepare' === phase ) {
							clearVisitorState( frame.contentWindow, frame.contentDocument );
							applyScenario( frame.contentWindow, frame.contentDocument, scenario );
							phase = 'observe';
							frame.contentWindow.location.replace( target );
							return;
						}

						collect( frame, target, scenario.name, collected );
						clearVisitorState( frame.contentWindow, frame.contentDocument );
						finish();
					} catch ( error ) {
						finish( error instanceof Error ? error : new Error( 'page-observation-failed' ) );
					}
				}, 'prepare' === phase ? 50 : 750 );
			}

			function submitProtectedBootstrap() {
				frame.removeEventListener( 'load', submitProtectedBootstrap );
				frame.addEventListener( 'load', handleObservationLoad );

				var frameDocument = frame.contentDocument;
				if ( ! frameDocument || ! frameDocument.body ) {
					finish( new Error( 'protected-page-frame-unavailable' ) );
					return;
				}

				var form = frameDocument.createElement( 'form' );
				form.method = 'post';
				form.action = String( config.ajaxUrl || '' );
				form.hidden = true;
				[ [ 'action', 'uccm_post_password_bootstrap' ], [ 'token', config.postPasswordToken ], [ 'scan_id', config.runId ], [ 'target', target ] ].forEach( function ( field ) {
					var input = frameDocument.createElement( 'input' );
					input.type = 'hidden';
					input.name = field[ 0 ];
					input.value = String( field[ 1 ] || '' );
					form.appendChild( input );
				} );
				frameDocument.body.appendChild( form );
				form.submit();
			}

			frame.setAttribute( 'title', __( 'Cookie scan temporary visitor frame', 'uk-cookie-consent-manager' ) );
			frame.credentialless = true;
			frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1280px;height:800px;opacity:0;pointer-events:none;';
			timer = window.setTimeout( function () {
				finish( new Error( 'page-observation-timed-out' ) );
			}, 15000 );

			if ( protectedTarget ) {
				if ( ! config.postPasswordToken ) {
					finish( new Error( 'protected-page-access-unavailable' ) );
					return;
				}

				frame.name = 'uccm-post-password-' + Date.now() + '-' + Math.random().toString( 16 ).slice( 2 );
				frame.addEventListener( 'load', submitProtectedBootstrap );
				frame.srcdoc = '<!doctype html><title>' + __( 'Cookie scan preparation', 'uk-cookie-consent-manager' ).replace( /[&<>"']/g, function ( character ) {
					return {
						'&': '&amp;',
						'<': '&lt;',
						'>': '&gt;',
						'"': '&quot;',
						"'": '&#039;'
					}[ character ];
				} ) + '</title>';
				document.body.appendChild( frame );
				return;
			}

			frame.addEventListener( 'load', handleObservationLoad );
			frame.src = target;
			document.body.appendChild( frame );
		} );
	}

	async function submit( payload ) {
		var body = new URLSearchParams();
		body.set( 'action', 'uccm_browser_scan_observations' );
		body.set( 'nonce', String( config.nonce || '' ) );
		body.set( 'scan_id', String( config.runId || 0 ) );
		body.set( 'payload', JSON.stringify( payload ) );

		var response = await window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} );
		var result = await response.json();

		if ( ! response.ok || ! result.success ) {
			throw new Error( result.data && result.data.message ? result.data.message : __( 'The browser check could not be saved.', 'uk-cookie-consent-manager' ) );
		}
	}

	button.addEventListener( 'click', async function () {
		button.disabled = true;
		var targets = config.targets.filter( function ( target ) {
			var parsed = safeUrl( target, window.location.href );
			return parsed && parsed.origin === window.location.origin;
		} ).slice( 0, Number( config.maxTargets ) || 100 );
		var collected = {};
		var completedSteps = 0;
		var failedSteps = 0;
		var totalSteps = targets.length * scenarios.length;

		try {
			await submit( { status: 'running', observations: [], target_count: targets.length, scenario_count: scenarios.length } );

			for ( var targetIndex = 0; targetIndex < targets.length; targetIndex += 1 ) {
				for ( var scenarioIndex = 0; scenarioIndex < scenarios.length; scenarioIndex += 1 ) {
					announce( sprintf(
						/* translators: 1: current page number, 2: total pages, 3: consent scenario. */
						__( 'Checking page %1$d of %2$d (%3$s)…', 'uk-cookie-consent-manager' ),
						targetIndex + 1,
						targets.length,
						scenarios[ scenarioIndex ].label
					) );
					try {
						await inspectScenario( targets[ targetIndex ], scenarios[ scenarioIndex ], collected, !! protectedLookup[ targets[ targetIndex ] ] );
						completedSteps += 1;
					} catch ( error ) {
						failedSteps += 1;
					}

					if ( completedSteps + failedSteps < totalSteps ) {
						await wait( stepDelayMs );
					}
				}

				if ( targetIndex + 1 < targets.length ) {
					await submit( {
						status: 'running',
						observations: [],
						target_count: targets.length,
						scenario_count: scenarios.length,
						completed_steps: completedSteps,
						total_steps: totalSteps
					} );
				}
			}

			var observations = Object.keys( collected ).map( function ( key ) {
				var observation = collected[ key ];
				delete observation.source_seen;
				return observation;
			} );
			var finalStatus = 0 === failedSteps ? 'completed' : ( 0 < completedSteps ? 'partial' : 'failed' );

			await submit( {
				status: finalStatus,
				problem: 0 < failedSteps ? 'some-pages-could-not-be-checked' : '',
				observations: observations,
				target_count: targets.length,
				scenario_count: scenarios.length,
				completed_steps: completedSteps,
				total_steps: totalSteps
			} );

			announce( 'completed' === finalStatus
				? __( 'Browser check saved. Reload this scan to review the results.', 'uk-cookie-consent-manager' )
				: __( 'Browser check saved, but some pages could not be checked. Reload this scan for details.', 'uk-cookie-consent-manager' ) );
		} catch ( error ) {
			try {
				await submit( {
					status: 'failed',
					problem: 'browser-check-failed',
					observations: [],
					target_count: targets.length,
					scenario_count: scenarios.length,
					completed_steps: completedSteps,
					total_steps: totalSteps
				} );
			} catch ( submitError ) {
				// Do not replace the original failure message.
			}
			announce( error instanceof Error ? error.message : __( 'The browser check could not be completed.', 'uk-cookie-consent-manager' ) );
		} finally {
			button.disabled = false;
		}
	} );
}() );
