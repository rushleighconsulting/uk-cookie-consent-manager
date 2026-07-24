const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const fixture = `
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Privacy settings test</title></head>
<body>
	<form>
		<label>
			<input
				type="checkbox"
				data-uccm-trust-proxy-headers
				aria-controls="uccm-trusted-proxies-settings"
				aria-expanded="false"
			>
			Trust forwarded IP headers
		</label>
		<div id="uccm-trusted-proxies-settings" data-uccm-trusted-proxies-settings hidden>
			<label for="uccm-trusted-proxies">Trusted proxy IPs</label>
			<textarea id="uccm-trusted-proxies" disabled aria-disabled="true">192.0.2.10</textarea>
		</div>
	</form>
</body>
</html>
`;

async function boot( page ) {
	await page.setContent( fixture );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/admin-privacy.js' ) } );
}

test( 'proxy addresses are available only while forwarded headers are trusted', async ( { page } ) => {
	await boot( page );

	const trustHeaders = page.getByRole( 'checkbox', { name: 'Trust forwarded IP headers' } );
	const proxyAddresses = page.locator( '#uccm-trusted-proxies' );
	const proxySettings = page.locator( '[data-uccm-trusted-proxies-settings]' );

	await expect( proxySettings ).toBeHidden();
	await expect( proxyAddresses ).toBeDisabled();
	await expect( trustHeaders ).toHaveAttribute( 'aria-expanded', 'false' );

	await trustHeaders.check();
	await expect( proxySettings ).toBeVisible();
	await expect( proxyAddresses ).toBeEnabled();
	await expect( trustHeaders ).toHaveAttribute( 'aria-expanded', 'true' );

	await proxyAddresses.fill( '198.51.100.20' );
	await trustHeaders.uncheck();
	await expect( proxySettings ).toBeHidden();
	await expect( proxyAddresses ).toBeDisabled();
	await expect( proxyAddresses ).toHaveValue( '198.51.100.20' );
	await expect( trustHeaders ).toHaveAttribute( 'aria-expanded', 'false' );
} );
