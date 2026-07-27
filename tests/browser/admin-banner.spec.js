const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const editorScript = fs.readFileSync( path.join( process.cwd(), 'assets/js/admin-banner.js' ), 'utf8' );

const fixture = `
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Banner settings</title></head>
<body>
	<form>
		<div data-uccm-banner-editor>
			<label>Banner background <input type="color" name="uccm[banner_surface_color]" value="#ffffff" data-uccm-style-field="banner_surface_color"> <code>#ffffff</code></label>
			<label>Button background <input type="color" name="uccm[banner_button_color]" value="#174ea6" data-uccm-style-field="banner_button_color"> <code>#174ea6</code></label>
			<label>Font <select name="uccm[banner_font]" data-uccm-style-field="banner_font"><option value="system">System</option><option value="theme">Theme</option></select></label>
			<label>Corner radius <input type="number" name="uccm[banner_corner_radius]" value="12"></label>
			<label>Banner position <select name="uccm[banner_position]" data-uccm-style-field="banner_position"><option value="bottom">Bottom</option><option value="top">Top</option></select></label>
			<label>Icon position <select name="uccm[icon_position]" data-uccm-style-field="icon_position"><option value="right">Right</option><option value="left">Left</option></select></label>
		</div>
		<section data-uccm-banner-preview data-font="system" data-position="bottom" data-icon-position="right" style="--uccm-preview-surface:#ffffff;--uccm-preview-accent:#174ea6;--uccm-preview-radius:12px">
			<div class="uccm-banner-preview__banner"></div>
			<span class="uccm-banner-preview__icon"></span>
		</section>
		<button type="submit" name="reset_banner_style" value="1" formnovalidate>Reset appearance to defaults</button>
	</form>
	<script src="/assets/js/admin-banner.js"></script>
</body>
</html>
`;

test( 'supported appearance controls update the representative preview', async ( { page } ) => {
	await page.route( 'https://example.test/**', async ( route ) => {
		if ( route.request().url().endsWith( '/assets/js/admin-banner.js' ) ) {
			await route.fulfill( { status: 200, contentType: 'text/javascript', body: editorScript } );
			return;
		}

		await route.fulfill( { status: 200, contentType: 'text/html', body: fixture } );
	} );
	await page.goto( 'https://example.test/wp-admin/admin.php?page=uccm-banner' );

	await page.getByLabel( 'Banner background' ).fill( '#fffef8' );
	await page.getByLabel( 'Button background' ).fill( '#6b214f' );
	await page.getByLabel( 'Font' ).selectOption( 'theme' );
	await page.getByLabel( 'Corner radius' ).fill( '20' );
	await page.getByLabel( 'Banner position' ).selectOption( 'top' );
	await page.getByLabel( 'Icon position' ).selectOption( 'left' );

	const preview = page.locator( '[data-uccm-banner-preview]' );
	await expect( preview ).toHaveAttribute( 'data-font', 'theme' );
	await expect( preview ).toHaveAttribute( 'data-position', 'top' );
	await expect( preview ).toHaveAttribute( 'data-icon-position', 'left' );
	await expect.poll( () => preview.evaluate( ( element ) => element.style.getPropertyValue( '--uccm-preview-surface' ) ) ).toBe( '#fffef8' );
	await expect.poll( () => preview.evaluate( ( element ) => element.style.getPropertyValue( '--uccm-preview-accent' ) ) ).toBe( '#6b214f' );
	await expect.poll( () => preview.evaluate( ( element ) => element.style.getPropertyValue( '--uccm-preview-radius' ) ) ).toBe( '20px' );
	await expect( page.getByLabel( 'Banner background' ).locator( '..' ).locator( 'code' ) ).toHaveText( '#fffef8' );
	await expect( page.getByRole( 'button', { name: 'Reset appearance to defaults' } ) ).toHaveAttribute( 'formnovalidate', '' );
} );
