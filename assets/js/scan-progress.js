( function () {
	'use strict';

	const config = window.UCCMScanProgress || {};
	const status = document.getElementById( 'uccm-scan-progress-status' );
	const runIds = Array.isArray( config.runIds ) ? config.runIds.filter( Number.isInteger ) : [];
	const messages = config.messages || {};
	let index = 0;

	if ( ! status || ! config.ajaxUrl || ! config.nonce || 0 === runIds.length ) {
		return;
	}

	status.textContent = messages.working || 'The scan is checking your public pages.';

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

			status.textContent = ( messages.working || 'The scan is checking your public pages.' ) +
				' ' + String( payload.data.visited || 0 ) + ' checked; ' +
				String( payload.data.remaining || 0 ) + ' remaining.';

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
			status.textContent = messages.failed || 'The scan could not continue in this browser.';
		}
	}

	next( 50 );
}() );
