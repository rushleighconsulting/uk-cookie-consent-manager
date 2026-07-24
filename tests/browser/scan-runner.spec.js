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

test( 'runner isolates administrator state, uses bounded post-password bootstrap and groups affected pages', async ( { page } ) => {
	const submissions = [];
	let bootstrapRequests = 0;

	await page.addInitScript( () => {
		window.UCCMScanRunner = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			runId: 42,
			maxTargets: 100,
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
					status: 302,
					headers: {
						location: 'https://example.test/page-two',
						'set-cookie': 'wp-postpass_test=%24P%24Bhash; Path=/; HttpOnly; SameSite=Lax'
					},
					body: ''
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
		/Browser check saved/,
		{ timeout: 45000 }
	);

	expect( submissions[0] ).toEqual( expect.objectContaining( {
		status: 'running',
		target_count: 2,
		scenario_count: 6
	} ) );

	const payload = submissions.at( -1 );
	expect( {
		status: payload.status,
		target_count: payload.target_count,
		completed_steps: payload.completed_steps,
		bootstrap_requests: bootstrapRequests,
		failed_problems: payload.failed_problems
	} ).toEqual( {
		status: 'completed',
		target_count: 2,
		completed_steps: 12,
		bootstrap_requests: 6,
		failed_problems: []
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
