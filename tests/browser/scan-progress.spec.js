const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const fixture = `
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Scan progress test</title></head>
<body><p id="uccm-scan-progress-status" aria-live="polite"></p></body>
</html>
`;

test( 'authenticated scans screen completes queued work without refreshes or visitor traffic', async ( { page } ) => {
	const submissions = [];
	let batch = 0;
	let navigations = 0;

	await page.addInitScript( () => {
		window.UCCMScanProgress = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'progress-nonce',
			runIds: [ 42 ]
		};
	} );

	await page.route( '**/*', async ( route ) => {
		const request = route.request();
		const url = new URL( request.url() );

		if ( 'POST' === request.method() && '/wp-admin/admin-ajax.php' === url.pathname ) {
			const submitted = new URLSearchParams( request.postData() || '' );
			submissions.push( Object.fromEntries( submitted ) );
			batch += 1;
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					success: true,
					data: {
						status: 2 > batch ? 'running' : 'completed',
						visited: batch,
						remaining: Math.max( 0, 2 - batch ),
						busy: false
					}
				} )
			} );
			return;
		}

		if ( request.isNavigationRequest() ) {
			navigations += 1;
			await route.fulfill( { status: 200, contentType: 'text/html', body: fixture } );
			return;
		}

		await route.fulfill( { status: 204, body: '' } );
	} );

	await page.goto( 'https://example.test/wp-admin/admin.php?page=uccm-scans' );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/scan-progress.js' ) } );
	await expect.poll( () => submissions.length ).toBe( 2 );
	await expect.poll( () => navigations ).toBe( 2 );

	expect( submissions[0] ).toEqual( {
		action: 'uccm_process_scan_batch',
		nonce: 'progress-nonce',
		scan_id: '42'
	} );
} );

test( 'worker stops and shows a recoverable message when the request fails', async ( { page } ) => {
	await page.addInitScript( () => {
		window.wp = {
			i18n: {
				__( text, domain ) {
					if (
						'uk-cookie-consent-manager' === domain &&
						'The scan could not continue in this browser. Its saved progress is safe; review the dashboard problem or use Resume.' === text
					) {
						return 'Saved progress is safe.';
					}

					return text;
				}
			}
		};
		window.UCCMScanProgress = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'progress-nonce',
			runIds: [ 42 ]
		};
	} );

	await page.route( '**/*', async ( route ) => {
		if ( 'POST' === route.request().method() ) {
			await route.fulfill( { status: 500, contentType: 'application/json', body: '{"success":false}' } );
			return;
		}

		await route.fulfill( { status: 200, contentType: 'text/html', body: fixture } );
	} );

	await page.goto( 'https://example.test/wp-admin/admin.php?page=uccm-scans' );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/scan-progress.js' ) } );
	await expect( page.locator( '#uccm-scan-progress-status' ) ).toHaveText( 'Saved progress is safe.' );
} );
