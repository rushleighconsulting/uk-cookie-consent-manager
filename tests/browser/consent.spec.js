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
			<p class="uccm-copy">We use one necessary cookie to remember your choice for a 180-day period. It is set whether you accept or reject optional cookies, so we do not ask you again.</p>
		</div>
		<div class="uccm-actions uccm-actions--primary">
			<button type="button" class="uccm-button" data-uccm-action="accept-all">Accept all</button>
			<button type="button" class="uccm-button" data-uccm-action="reject-optional">Reject non-essential</button>
			<button type="button" class="uccm-button" data-uccm-action="manage">Manage preferences</button>
		</div>
	</section>
	<button type="button" class="uccm-settings" data-uccm-action="manage" aria-haspopup="dialog" aria-label="Cookie settings" data-uccm-label="Cookie settings" hidden>
		<svg class="uccm-settings__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
			<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-3.87A4 4 0 0 1 12.13 3 4 4 0 0 1 12 2Z"></path>
			<path d="M8.5 8.5h.01M16 15.5h.01M10.5 16.5h.01"></path>
		</svg>
	</button>
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
	expect( await page.evaluate( () => window.analyticsExecutions || 0 ) ).toBe( 0 );

	const actionStyles = await actions.evaluateAll( ( buttons ) => buttons.map( ( button ) => {
		const style = getComputedStyle( button );
		return {
			visible: button.getBoundingClientRect().height >= 44,
			background: style.backgroundColor,
			fontWeight: style.fontWeight,
		};
	} ) );
	expect( actionStyles.every( ( style ) => style.visible ) ).toBe( true );
	expect( new Set( actionStyles.map( ( style ) => style.background ) ).size ).toBe( 1 );
	expect( new Set( actionStyles.map( ( style ) => style.fontWeight ) ).size ).toBe( 1 );

	await page.getByRole( 'button', { name: 'Accept all' } ).click();

	await expect( banner ).toBeHidden();
	const settingsButton = page.getByRole( 'button', { name: 'Cookie settings' } );
	await expect( settingsButton ).toBeVisible();
	await expect( settingsButton.locator( '.uccm-settings__icon' ) ).toBeVisible();

	const settingsBox = await settingsButton.boundingBox();
	expect( settingsBox ).not.toBeNull();
	expect( settingsBox.width ).toBeGreaterThanOrEqual( 44 );
	expect( settingsBox.width ).toBeLessThanOrEqual( 52 );
	expect( settingsBox.height ).toBeGreaterThanOrEqual( 44 );
	expect( settingsBox.height ).toBeLessThanOrEqual( 52 );

	await settingsButton.focus();
	await expect( settingsButton ).toBeFocused();
	await expect.poll(
		() => settingsButton.evaluate( ( element ) => getComputedStyle( element, '::after' ).opacity )
	).toBe( '1' );
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

test( 'rejecting optional cookies stores and discloses the necessary choice cookie', async ( { page } ) => {
	const receipts = await boot( page );

	await expect( page.locator( '#uccm-banner .uccm-copy' ) ).toContainText(
		'It is set whether you accept or reject optional cookies'
	);
	await page.getByRole( 'button', { name: 'Reject non-essential' } ).click();
	await expect.poll( () => receipts.length ).toBe( 1 );

	const decision = await page.evaluate( () => {
		const item = document.cookie.split( '; ' ).find( ( cookie ) => cookie.startsWith( 'uccm_consent=' ) );
		return item ? JSON.parse( decodeURIComponent( item.slice( 'uccm_consent='.length ) ) ) : null;
	} );

	expect( decision ).not.toBeNull();
	expect( decision.action ).toBe( 'reject' );
	expect( decision.categories ).toEqual( {
		necessary: true,
		functional: false,
		analytics: false,
		marketing: false,
	} );
	expect( decision.expiresAt ).toBeGreaterThan( Date.now() + ( 179 * 24 * 60 * 60 * 1000 ) );
	expect( receipts[0].action ).toBe( 'reject' );
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
