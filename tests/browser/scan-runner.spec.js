const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const adminFixture = `
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Scanner runner test</title></head>
<body>
	<button id="uccm-run-browser-observations" type="button">Run browser observations</button>
	<p id="uccm-browser-observation-status" role="status" aria-live="polite"></p>
</body>
</html>
`;

const browserRequirement = 'For your privacy, this check needs a current Chrome, Edge or other Chromium browser. Safari and Firefox are not supported yet.';

const targetFixture = `
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Crawl target</title>
	<script>
		if ( document.cookie.includes( 'wordpress_logged_in_test' ) || localStorage.getItem( 'admin_secret' ) ) {
			localStorage.setItem( 'inherited_admin_state', 'unsafe' );
		}
		document.cookie = 'analytics_id=123; path=/';
		localStorage.setItem( 'marketing_preference', 'enabled' );
		sessionStorage.setItem( 'visit_state', 'started' );
	</script>
	<script id="analytics-loader" src="https://cdn.example.test/analytics.js"></script>
</head>
<body>
	<iframe title="Video provider" src="https://video.example.test/embed/1"></iframe>
	<img id="tracking-pixel" src="https://metrics.example.test/pixel.gif" width="1" height="1" alt="">
</body>
</html>
`;

test( 'runner disables the action before use when isolated visitor frames are unavailable', async ( { page } ) => {
	let submissions = 0;

	await page.addInitScript( () => {
		delete HTMLIFrameElement.prototype.credentialless;
		window.UCCMScanRunner = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			runId: 43,
			targets: [ 'https://example.test/page-one' ]
		};
	} );

	await page.route( '**/*', async ( route ) => {
		const request = route.request();

		if ( 'POST' === request.method() ) {
			submissions += 1;
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { success: true } )
			} );
			return;
		}

		await route.fulfill( { status: 200, contentType: 'text/html', body: adminFixture } );
	} );

	await page.goto( 'https://example.test/wp-admin/admin.php?page=uccm-scans&scan_id=43' );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/scan-runner.js' ) } );

	const button = page.getByRole( 'button', { name: 'Run browser observations' } );
	await expect( button ).toBeDisabled();
	await expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
	await expect( page.locator( '#uccm-browser-observation-status' ) ).toHaveText( browserRequirement );
	expect( submissions ).toBe( 0 );
} );

test( 'runner leaves server recovery possible when terminal and fallback submissions both lose connectivity', async ( { page } ) => {
	const submissions = [];

	await page.addInitScript( () => {
		window.UCCMScanRunner = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			runId: 44,
			maxTargets: 100,
			stepDelayMs: 250,
			submitRetryAttempts: 1,
			targets: []
		};
	} );

	await page.route( '**/*', async ( route ) => {
		const request = route.request();

		if ( 'POST' === request.method() ) {
			const submitted = new URLSearchParams( request.postData() || '' );
			submissions.push( JSON.parse( submitted.get( 'payload' ) ) );

			if ( 1 === submissions.length ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { success: true, data: { saved: true } } )
				} );
				return;
			}

			await route.abort( 'connectionrefused' );
			return;
		}

		await route.fulfill( { status: 200, contentType: 'text/html', body: adminFixture } );
	} );

	await page.goto( 'https://example.test/wp-admin/admin.php?page=uccm-scans&scan_id=44' );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/scan-runner.js' ) } );
	await page.getByRole( 'button', { name: 'Run browser observations' } ).click();

	await expect( page.locator( '#uccm-browser-observation-status' ) ).toHaveText( 'Failed to fetch' );
	expect( submissions.map( ( payload ) => payload.status ) ).toEqual( [ 'running', 'completed', 'failed' ] );
} );

test( 'runner retries a transient progress-save failure before scanning', async ( { page } ) => {
	const submissions = [];

	await page.addInitScript( () => {
		window.UCCMScanRunner = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			runId: 45,
			maxTargets: 5,
			stepDelayMs: 250,
			submitRetryAttempts: 2,
			submitTimeoutMs: 2000,
			targets: []
		};
	} );

	await page.route( '**/*', async ( route ) => {
		const request = route.request();

		if ( 'POST' === request.method() ) {
			const submitted = new URLSearchParams( request.postData() || '' );
			submissions.push( JSON.parse( submitted.get( 'payload' ) ) );

			if ( 1 === submissions.length ) {
				await route.abort( 'connectionrefused' );
				return;
			}

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { success: true, data: { saved: true } } )
			} );
			return;
		}

		await route.fulfill( { status: 200, contentType: 'text/html', body: adminFixture } );
	} );

	await page.goto( 'https://example.test/wp-admin/admin.php?page=uccm-scans&scan_id=45' );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/scan-runner.js' ) } );
	await page.getByRole( 'button', { name: 'Run browser observations' } ).click();

	await expect( page.locator( '#uccm-browser-observation-status' ) ).toHaveText(
		'Browser check saved. Reload this scan to review the results.'
	);
	expect( submissions.map( ( payload ) => payload.status ) ).toEqual( [ 'running', 'running', 'completed' ] );
} );

test( 'runner isolates administrator state, uses bounded post-password bootstrap and groups affected pages', async ( { page } ) => {
	const submissions = [];
	let bootstrapRequests = 0;

	await page.addInitScript( () => {
		window.UCCMScanRunner = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			runId: 42,
			maxTargets: 100,
			stepDelayMs: 250,
			submitRetryAttempts: 1,
			cookieName: 'uccm_consent',
			cookiePath: '/',
			policyVersion: '1',
			pluginVersion: '0.1.0-rc.4',
			lifetimeDays: 365,
			protectedTargets: [ 'https://example.test/page-two' ],
			postPasswordToken: 'opaque-browser-token',
			targets: [
				'https://example.test/page-one',
				'https://example.test/page-two',
				'https://outside.test/not-allowed'
			]
		};
	} );

	await page.route( '**/*', async ( route ) => {
		const request = route.request();
		const url = new URL( request.url() );

		if ( 'POST' === request.method() && '/wp-admin/admin-ajax.php' === url.pathname ) {
			const submitted = new URLSearchParams( request.postData() || '' );

			if ( 'uccm_post_password_bootstrap' === submitted.get( 'action' ) ) {
				bootstrapRequests += 1;
				expect( submitted.get( 'token' ) ).toBe( 'opaque-browser-token' );
				expect( submitted.get( 'scan_id' ) ).toBe( '42' );
				expect( submitted.get( 'target' ) ).toBe( 'https://example.test/page-two' );
				await route.fulfill( {
					status: 200,
					headers: {
						'content-type': 'text/html; charset=UTF-8',
						'set-cookie': 'wp-postpass_test=%24P%24Bhash; Path=/; HttpOnly; SameSite=Lax'
					},
					body: '<!doctype html><title>Protected page access prepared</title>'
				} );
				return;
			}

			submissions.push( JSON.parse( submitted.get( 'payload' ) ) );
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { success: true, data: { saved: true } } )
			} );
			return;
		}

		if ( request.isNavigationRequest() && request.frame() === page.mainFrame() ) {
			await route.fulfill( { status: 200, contentType: 'text/html', body: adminFixture } );
			return;
		}

		if ( [ '/page-one', '/page-two' ].includes( url.pathname ) ) {
			await route.fulfill( { status: 200, contentType: 'text/html', body: targetFixture } );
			return;
		}

		await route.fulfill( { status: 204, body: '' } );
	} );

	await page.goto( 'https://example.test/wp-admin/admin.php?page=uccm-scans&scan_id=42' );
	await page.evaluate( () => {
		document.cookie = 'wordpress_logged_in_test=secret; path=/';
		localStorage.setItem( 'admin_secret', 'must-not-leak' );
	} );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/scan-runner.js' ) } );
	await page.getByRole( 'button', { name: 'Run browser observations' } ).click();

	await expect( page.locator( '#uccm-browser-observation-status' ) ).toHaveText(
		'Browser check saved. Reload this scan to review the results.',
		{ timeout: 45000 }
	);

	expect( submissions[0] ).toEqual( expect.objectContaining( {
		status: 'running',
		target_count: 2,
		scenario_count: 6
	} ) );
	expect( submissions.filter( ( submission ) => 'running' === submission.status ) ).toHaveLength( 2 );

	const payload = submissions.at( -1 );
	expect( {
		status: payload.status,
		target_count: payload.target_count,
		completed_steps: payload.completed_steps,
		bootstrap_requests: bootstrapRequests
	} ).toEqual( {
		status: 'completed',
		target_count: 2,
		completed_steps: 12,
		bootstrap_requests: 6
	} );
	expect( payload.observations.filter( ( observation ) => 'analytics_id' === observation.storage_key ) ).toHaveLength( 1 );

	const cookie = payload.observations.find( ( observation ) => 'analytics_id' === observation.storage_key );
	expect( cookie.source_count ).toBe( 2 );
	expect( cookie.source_urls ).toEqual( [
		'https://example.test/page-one',
		'https://example.test/page-two'
	] );
	expect( cookie.consent_states ).toEqual( [
		'pre-consent',
		'reject',
		'accept-all',
		'functional',
		'analytics',
		'marketing'
	] );

	expect( payload.observations ).toEqual(
		expect.arrayContaining( [
			expect.objectContaining( { type: 'local_storage', storage_key: 'marketing_preference' } ),
			expect.objectContaining( { type: 'session_storage', storage_key: 'visit_state' } ),
			expect.objectContaining( { type: 'script', storage_key: 'analytics-loader' } ),
			expect.objectContaining( { type: 'iframe', storage_key: 'Video provider' } ),
			expect.objectContaining( { type: 'pixel', storage_key: 'tracking-pixel' } )
		] )
	);
	expect( bootstrapRequests ).toBe( 6 );
	expect( payload.observations.some( ( observation ) => 'uccm_consent' === observation.storage_key ) ).toBe( false );
	expect( payload.observations.some( ( observation ) => 'wp-postpass_test' === observation.storage_key ) ).toBe( false );
	expect( payload.observations.some( ( observation ) => 'inherited_admin_state' === observation.storage_key ) ).toBe( false );
	expect( payload.observations.some( ( observation ) => observation.source_url.includes( 'outside.test' ) ) ).toBe( false );
} );
