const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const fixture = `
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Consent test</title></head>
<body>
<div id="uccm-consent-root" class="uccm-consent" data-uccm-state="unknown">
	<section id="uccm-banner" class="uccm-banner" aria-labelledby="uccm-banner-title" hidden>
		<div class="uccm-banner__content">
			<h2 id="uccm-banner-title" class="uccm-title">Your cookie choices</h2>
			<p class="uccm-copy">Choose optional cookies.</p>
		</div>
		<div class="uccm-actions uccm-actions--primary">
			<button type="button" class="uccm-button" data-uccm-action="accept-all">Accept all</button>
			<button type="button" class="uccm-button" data-uccm-action="reject-optional">Reject non-essential</button>
			<button type="button" class="uccm-button" data-uccm-action="manage">Manage preferences</button>
		</div>
	</section>
	<button type="button" class="uccm-settings" data-uccm-action="manage" aria-haspopup="dialog" hidden>Cookie settings</button>
	<dialog id="uccm-preferences" class="uccm-dialog" aria-labelledby="uccm-preferences-title">
		<div class="uccm-dialog__inner">
			<div class="uccm-dialog__header">
				<h2 id="uccm-preferences-title" tabindex="-1">Cookie preferences</h2>
				<button type="button" data-uccm-action="close" aria-label="Close cookie preferences">Close</button>
			</div>
			<label>Necessary <input type="checkbox" name="necessary" checked disabled aria-disabled="true"></label>
			<label>Functional <input type="checkbox" name="functional"></label>
			<label>Analytics <input type="checkbox" name="analytics"></label>
			<label>Marketing <input type="checkbox" name="marketing"></label>
			<button type="button" data-uccm-action="save">Save choices</button>
			<button type="button" data-uccm-action="withdraw">Withdraw optional consent</button>
		</div>
	</dialog>
	<p data-uccm-status role="status" aria-live="polite"></p>
</div>
<script type="text/plain" data-uccm-blocked="script" data-uccm-category="analytics" data-uccm-rule="analytics-inline">
	window.analyticsExecutions = ( window.analyticsExecutions || 0 ) + 1;
</script>
<iframe title="Functional fixture" data-uccm-blocked="iframe" data-uccm-category="functional" data-uccm-rule="functional-frame" data-uccm-src="https://example.test/functional"></iframe>
</body>
</html>
`;

async function boot( page ) {
	const receipts = [];

	await page.addInitScript( () => {
		window.uccmConsentConfig = {
			cookieName: 'uccm_consent',
			cookiePath: '/',
			lifetimeDays: 180,
			policyVersion: '1',
			pluginVersion: '0.1.0',
			receiptEndpoint: 'https://example.test/wp-json/uccm/v1/consents',
			messages: {
				saved: 'Your cookie choices have been saved.',
				withdrawn: 'Optional cookie consent has been withdrawn.',
			},
		};
	} );

	await page.route( 'https://example.test/**', async ( route ) => {
		const request = route.request();

		if ( 'POST' === request.method() && request.url().includes( '/wp-json/uccm/v1/consents' ) ) {
			receipts.push( request.postDataJSON() );
			await route.fulfill( {
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify( { status: 'stored' } ),
			} );
			return;
		}

		if ( request.isNavigationRequest() && request.frame() === page.mainFrame() ) {
			await route.fulfill( { status: 200, contentType: 'text/html', body: fixture } );
			return;
		}

		await route.fulfill( { status: 204, body: '' } );
	} );

	await page.goto( 'https://example.test/' );
	await page.addStyleTag( { path: path.join( process.cwd(), 'assets/css/consent.css' ) } );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/blocker.js' ) } );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/consent.js' ) } );

	return receipts;
}

test( 'first visit remains blocked until an equally prominent decision is made', async ( { page } ) => {
	const receipts = await boot( page );
	const banner = page.locator( '#uccm-banner' );
	const actions = banner.locator( '.uccm-actions--primary button' );

	await expect( banner ).toBeVisible();
	await expect( actions ).toHaveCount( 3 );
	await expect( page.locator( '[data-uccm-rule="analytics-inline"] + script' ) ).toHaveCount( 0 );
	await expect( page.evaluate( () => window.analyticsExecutions || 0 ) ).resolves.toBe( 0 );

	const widths = await actions.evaluateAll( ( buttons ) => buttons.map( ( button ) => button.getBoundingClientRect().width ) );
	expect( Math.max( ...widths ) - Math.min( ...widths ) ).toBeLessThan( 80 );

	await page.getByRole( 'button', { name: 'Accept all' } ).click();

	await expect( banner ).toBeHidden();
	await expect( page.getByRole( 'button', { name: 'Cookie settings' } ) ).toBeVisible();
	await expect.poll( () => receipts.length ).toBe( 1 );
	expect( receipts[0].action ).toBe( 'grant' );
	expect( receipts[0].categories ).toEqual( {
		necessary: true,
		functional: true,
		analytics: true,
		marketing: true,
	} );
	await expect.poll( () => page.evaluate( () => window.analyticsExecutions || 0 ) ).toBe( 1 );
} );

test( 'keyboard preferences support granular consent and withdrawal', async ( { page } ) => {
	const receipts = await boot( page );

	await page.getByRole( 'button', { name: 'Manage preferences' } ).click();
	await expect( page.locator( '#uccm-preferences' ) ).toHaveAttribute( 'open', '' );
	await expect( page.locator( '#uccm-preferences-title' ) ).toBeFocused();
	await expect( page.locator( 'input[name="necessary"]' ) ).toBeDisabled();

	await page.locator( 'input[name="functional"]' ).check();
	await page.locator( 'input[name="analytics"]' ).check();
	await page.getByRole( 'button', { name: 'Save choices' } ).click();

	await expect.poll( () => receipts.length ).toBe( 1 );
	expect( receipts[0].categories.functional ).toBe( true );
	expect( receipts[0].categories.analytics ).toBe( true );
	await expect( page.locator( '[data-uccm-rule="functional-frame"]' ) ).toHaveAttribute( 'src', 'https://example.test/functional' );

	await page.getByRole( 'button', { name: 'Cookie settings' } ).click();
	await page.getByRole( 'button', { name: 'Withdraw optional consent' } ).click();

	await expect.poll( () => receipts.length ).toBe( 2 );
	expect( receipts[1].action ).toBe( 'withdraw' );
	expect( receipts[1].categories.functional ).toBe( false );
	await expect( page.locator( '[data-uccm-rule="functional-frame"]' ) ).not.toHaveAttribute( 'src' );
	await expect( page.locator( '[data-uccm-status]' ) ).toHaveText( 'Optional cookie consent has been withdrawn.' );
} );
