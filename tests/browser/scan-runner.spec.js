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
		document.cookie = 'analytics_id=123; path=/';
		document.cookie = 'wordpress_logged_in_test=secret; path=/';
		localStorage.setItem( 'marketing_preference', 'enabled' );
	</script>
	<script id="analytics-loader" src="https://cdn.example.test/analytics.js"></script>
</head>
<body>
	<iframe title="Video provider" src="https://video.example.test/embed/1"></iframe>
	<img id="tracking-pixel" src="https://metrics.example.test/pixel.gif" width="1" height="1" alt="">
</body>
</html>
`;

test( 'authenticated runner collects bounded observations and rejects cross-origin targets', async ( { page } ) => {
	let submitted;

	await page.addInitScript( () => {
		window.UCCMScanRunner = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			runId: 42,
			maxTargets: 100,
			targets: [
				'https://example.test/crawl-target',
				'https://outside.test/not-allowed'
			]
		};
	} );

	await page.route( '**/*', async ( route ) => {
		const request = route.request();
		const url = new URL( request.url() );

		if ( 'POST' === request.method() && '/wp-admin/admin-ajax.php' === url.pathname ) {
			submitted = new URLSearchParams( request.postData() || '' );
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

		if ( '/crawl-target' === url.pathname ) {
			await route.fulfill( { status: 200, contentType: 'text/html', body: targetFixture } );
			return;
		}

		await route.fulfill( { status: 204, body: '' } );
	} );

	await page.goto( 'https://example.test/wp-admin/admin.php?page=uccm-scans&scan_id=42' );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/scan-runner.js' ) } );
	await page.getByRole( 'button', { name: 'Run browser observations' } ).click();

	await expect( page.locator( '#uccm-browser-observation-status' ) ).toHaveText(
		'Browser observations saved. Reload this scan to review updated findings and coverage.'
	);
	expect( submitted ).toBeDefined();
	expect( submitted.get( 'action' ) ).toBe( 'uccm_browser_scan_observations' );
	expect( submitted.get( 'nonce' ) ).toBe( 'test-nonce' );
	expect( submitted.get( 'scan_id' ) ).toBe( '42' );

	const payload = JSON.parse( submitted.get( 'payload' ) );
	expect( payload.target_count ).toBe( 1 );
	expect( payload.observations ).toEqual(
		expect.arrayContaining( [
			expect.objectContaining( { type: 'cookie', storage_key: 'analytics_id' } ),
			expect.objectContaining( { type: 'local_storage', storage_key: 'marketing_preference' } ),
			expect.objectContaining( { type: 'script', storage_key: 'analytics-loader' } ),
			expect.objectContaining( { type: 'iframe', storage_key: 'Video provider' } ),
			expect.objectContaining( { type: 'pixel', storage_key: 'tracking-pixel' } )
		] )
	);
	expect( payload.observations.some( ( observation ) => 'wordpress_logged_in_test' === observation.storage_key ) ).toBe( false );
	expect( payload.observations.some( ( observation ) => observation.source_url.includes( 'outside.test' ) ) ).toBe( false );
} );
