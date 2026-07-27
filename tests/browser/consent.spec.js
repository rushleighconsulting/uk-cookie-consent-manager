const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const consentCss = fs.readFileSync( path.join( process.cwd(), 'assets/css/consent.css' ), 'utf8' );
const blockerScript = fs.readFileSync( path.join( process.cwd(), 'assets/js/blocker.js' ), 'utf8' );
const consentScript = fs.readFileSync( path.join( process.cwd(), 'assets/js/consent.js' ), 'utf8' );

const fixture = `
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Consent test</title>
	<link rel="stylesheet" href="/assets/css/consent.css">
</head>
<body>
<div id="uccm-consent-root" class="uccm-consent" data-uccm-state="unknown">
	<section id="uccm-banner" class="uccm-banner" aria-labelledby="uccm-banner-title" hidden>
		<div class="uccm-banner__content">
			<h2 id="uccm-banner-title" class="uccm-title">Your cookie choices</h2>
			<p class="uccm-copy">We use one necessary cookie to remember your choice for 180 days. It is set whether you accept or reject optional cookies, so we do not ask you again. With your permission, we may also use optional cookies for functionality, analytics and marketing. You may change your choice at any time by clicking the little cookie logo.</p>
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
			<p>Choose which optional cookie categories this website may use. Necessary cookies are always active.</p>
			<p>We set one necessary cookie. This cookie remembers your cookie choices for 180 days, and is set when you accept, reject, or change your cookie options. You may reject any other cookies.</p>
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
<nav aria-label="Test pages">
	<a href="/page-1">Page 1</a>
	<a href="/page-2">Page 2</a>
	<a href="/page-3">Page 3</a>
	<a href="/page-4">Page 4</a>
	<a href="/page-5">Page 5</a>
</nav>
<script type="text/plain" data-uccm-blocked="script" data-uccm-category="analytics" data-uccm-rule="analytics-inline">
	window.analyticsExecutions = ( window.analyticsExecutions || 0 ) + 1;
</script>
<iframe title="Functional fixture" data-uccm-blocked="iframe" data-uccm-category="functional" data-uccm-rule="functional-frame" data-uccm-src="https://example.test/functional"></iframe>
<script src="/assets/js/blocker.js"></script>
<script src="/assets/js/consent.js"></script>
</body>
</html>
`;

async function boot( page, options = {} ) {
	const receipts = [];
	const pageFixture = options.restoredDialogOpen
		? fixture.replace( '<dialog id="uccm-preferences"', '<dialog open id="uccm-preferences"' )
		: fixture;

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

		if ( request.url().endsWith( '/assets/css/consent.css' ) ) {
			await route.fulfill( { status: 200, contentType: 'text/css', body: consentCss } );
			return;
		}

		if ( request.url().endsWith( '/assets/js/blocker.js' ) ) {
			await route.fulfill( { status: 200, contentType: 'text/javascript', body: blockerScript } );
			return;
		}

		if ( request.url().endsWith( '/assets/js/consent.js' ) ) {
			await route.fulfill( { status: 200, contentType: 'text/javascript', body: consentScript } );
			return;
		}

		if ( request.isNavigationRequest() && request.frame() === page.mainFrame() ) {
			await route.fulfill( { status: 200, contentType: 'text/html', body: pageFixture } );
			return;
		}

		await route.fulfill( { status: 204, body: '' } );
	} );

	await page.goto( 'https://example.test/' );

	return receipts;
}

async function expectBannerWithinViewport( page ) {
	const viewport = page.viewportSize();
	const banner = page.locator( '#uccm-banner' );
	const bannerBox = await banner.boundingBox();

	expect( viewport ).not.toBeNull();
	expect( bannerBox ).not.toBeNull();
	expect( bannerBox.x ).toBeGreaterThanOrEqual( 0 );
	expect( bannerBox.y ).toBeGreaterThanOrEqual( 0 );
	expect( bannerBox.x + bannerBox.width ).toBeLessThanOrEqual( viewport.width + 1 );
	expect( bannerBox.y + bannerBox.height ).toBeLessThanOrEqual( viewport.height + 1 );
	await expect( page.locator( '#uccm-banner-title' ) ).toBeVisible();
	await expect( banner.locator( '.uccm-copy' ) ).toBeVisible();
}

async function expectPreferencesClosedAcrossNavigations( page ) {
	for ( let pageNumber = 1; pageNumber <= 5; pageNumber++ ) {
		await page.getByRole( 'link', { name: `Page ${ pageNumber }`, exact: true } ).click();

		const dialog = page.locator( '#uccm-preferences' );
		await expect( page ).toHaveURL( `https://example.test/page-${ pageNumber }` );
		await expect( page.locator( '#uccm-banner' ) ).toBeHidden();
		await expect( page.getByRole( 'button', { name: 'Cookie settings' } ) ).toBeVisible();
		await expect( dialog ).not.toHaveAttribute( 'open', '' );
		await expect( dialog ).not.toHaveAttribute( 'data-uccm-explicit-open', 'true' );
		await expect( dialog ).toBeHidden();
	}
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

	await page.keyboard.press( 'Tab' );
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

test( 'accepted consent keeps preferences closed across full page navigations', async ( { page } ) => {
	await boot( page );
	await page.getByRole( 'button', { name: 'Accept all' } ).click();

	await expectPreferencesClosedAcrossNavigations( page );
} );

test( 'rejected consent keeps preferences closed across full page navigations', async ( { page } ) => {
	await boot( page );
	await page.getByRole( 'button', { name: 'Reject non-essential' } ).click();

	await expectPreferencesClosedAcrossNavigations( page );
} );

test( 'saved granular consent keeps preferences closed across full page navigations', async ( { page } ) => {
	await boot( page );
	await page.getByRole( 'button', { name: 'Manage preferences' } ).click();
	await page.locator( 'input[name="functional"]' ).check();
	await page.getByRole( 'button', { name: 'Save choices' } ).click();

	await expectPreferencesClosedAcrossNavigations( page );
} );

test( 'withdrawn optional consent keeps preferences closed across full page navigations', async ( { page } ) => {
	await boot( page );
	await page.getByRole( 'button', { name: 'Accept all' } ).click();
	await page.getByRole( 'button', { name: 'Cookie settings' } ).click();
	await page.getByRole( 'button', { name: 'Withdraw optional consent' } ).click();

	await expectPreferencesClosedAcrossNavigations( page );
} );

test( 'restored or cached open state is closed before it can block the page', async ( { page } ) => {
	await boot( page, { restoredDialogOpen: true } );

	const dialog = page.locator( '#uccm-preferences' );
	await expect( dialog ).not.toHaveAttribute( 'open', '' );
	await expect( dialog ).not.toHaveAttribute( 'data-uccm-explicit-open', 'true' );
	await expect( dialog ).toBeHidden();
	await expect( page.locator( '#uccm-banner' ) ).toBeVisible();
} );

test( 'only a trusted visitor action can open preferences', async ( { page } ) => {
	await boot( page );
	await page.getByRole( 'button', { name: 'Accept all' } ).click();

	const dialog = page.locator( '#uccm-preferences' );
	await page.evaluate( () => {
		document.querySelector( '.uccm-settings' ).click();
	} );
	await expect( dialog ).not.toHaveAttribute( 'open', '' );

	await page.evaluate( () => {
		document.querySelector( '#uccm-preferences' ).showModal();
	} );
	await expect( dialog ).not.toHaveAttribute( 'open', '' );
	await expect( dialog ).toBeHidden();

	await page.getByRole( 'button', { name: 'Cookie settings' } ).click();
	await expect( dialog ).toHaveAttribute( 'open', '' );
	await expect( dialog ).toHaveAttribute( 'data-uccm-explicit-open', 'true' );
	await expect( dialog ).toBeVisible();
} );

test( 'mobile portrait keeps compact actions and the complete banner within the viewport', async ( { page } ) => {
	await page.setViewportSize( { width: 390, height: 844 } );
	await boot( page );
	await expectBannerWithinViewport( page );

	const actions = page.locator( '#uccm-banner .uccm-actions--primary button' );
	const actionBoxes = await actions.evaluateAll( ( buttons ) => buttons.map( ( button ) => {
		const box = button.getBoundingClientRect();

		return {
			height: box.height,
			width: box.width,
		};
	} ) );

	expect( actionBoxes ).toHaveLength( 3 );
	expect( actionBoxes.every( ( box ) => box.height >= 44 && box.height <= 72 ) ).toBe( true );
	expect( new Set( actionBoxes.map( ( box ) => box.height ) ).size ).toBe( 1 );
	expect( new Set( actionBoxes.map( ( box ) => box.width ) ).size ).toBe( 1 );
} );

test( 'mobile landscape remains compact and fully visible', async ( { page } ) => {
	await page.setViewportSize( { width: 844, height: 390 } );
	await boot( page );
	await expectBannerWithinViewport( page );

	const actions = page.locator( '#uccm-banner .uccm-actions--primary button' );
	const actionHeights = await actions.evaluateAll( ( buttons ) => (
		buttons.map( ( button ) => button.getBoundingClientRect().height )
	) );

	expect( actionHeights.every( ( height ) => height >= 44 && height <= 72 ) ).toBe( true );
} );

test( 'constrained portrait view keeps enlarged content reachable by touch scrolling', async ( { page } ) => {
	await page.setViewportSize( { width: 390, height: 500 } );
	await boot( page );
	await page.addStyleTag( { content: 'html { font-size: 200%; }' } );
	await expectBannerWithinViewport( page );

	const banner = page.locator( '#uccm-banner' );
	const overflow = await banner.evaluate( ( element ) => ( {
		clientHeight: element.clientHeight,
		scrollHeight: element.scrollHeight,
	} ) );

	expect( overflow.scrollHeight ).toBeGreaterThan( overflow.clientHeight );

	const finalAction = page.getByRole( 'button', { name: 'Manage preferences' } );
	await finalAction.scrollIntoViewIfNeeded();
	await expect( finalAction ).toBeVisible();
	await finalAction.click();
	await expect( page.locator( '#uccm-preferences' ) ).toHaveAttribute( 'open', '' );
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
