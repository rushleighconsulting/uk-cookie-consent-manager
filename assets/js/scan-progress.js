( function () {
	'use strict';

	const config = window.UCCMScanProgress || {};
	const i18n = window.wp && window.wp.i18n ? window.wp.i18n : {};
	const __ = i18n.__ || ( ( text ) => text );
	const sprintf = i18n.sprintf || ( ( format, ...values ) => format.replace(
		/%(\d+)\$[ds]/g,
		( match, position ) => String( values[ Number( position ) - 1 ] ?? match )
	) );
	const status = document.getElementById( 'uccm-scan-progress-status' );
	const runIds = Array.isArray( config.runIds ) ? config.runIds.filter( Number.isInteger ) : [];
	let index = 0;

	if ( ! status || ! config.ajaxUrl || ! config.nonce || 0 === runIds.length ) {
		return;
	}

	status.textContent = __(
		'The scan is checking your public pages. Keep this page open while it works; you can leave and return without losing saved progress.',
		'uk-cookie-consent-manager'
	);

	function next( delay ) {
		window.setTimeout( processRun, delay );
	}

	async function processRun() {
		const runId = runIds[ index ];
		const body = new URLSearchParams();

		body.set( 'action', 'uccm_process_scan_batch' );
		body.set( 'nonce', config.nonce );
		body.set( 'scan_id', String( runId ) );

		try {
			const response = await window.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
				body: body.toString()
			} );
			const payload = await response.json();

			if ( ! response.ok || ! payload.success || ! payload.data ) {
				throw new Error( 'scan-progress-request-failed' );
			}

			status.textContent = sprintf(
				/* translators: 1: number of pages checked, 2: number of pages remaining. */
				__( '%1$d checked; %2$d remaining.', 'uk-cookie-consent-manager' ),
				Number( payload.data.visited || 0 ),
				Number( payload.data.remaining || 0 )
			);

			if ( payload.data.busy || [ 'queued', 'running' ].includes( payload.data.status ) ) {
				next( payload.data.busy ? 1500 : 250 );
				return;
			}

			index += 1;

			if ( index < runIds.length ) {
				next( 100 );
				return;
			}

			window.location.reload();
		} catch ( error ) {
			void error;
			status.textContent = __(
				'The scan could not continue in this browser. Its saved progress is safe; review the dashboard problem or use Resume.',
				'uk-cookie-consent-manager'
			);
		}
	}

	next( 50 );
}() );
