const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const fixture = `
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Blocking rule editor test</title></head>
<body>
<form>
	<div data-uccm-rule-editor>
		<div data-uccm-rule-list></div>
		<p data-uccm-empty>No blocking rules have been added yet.</p>
		<button type="button" data-uccm-add-rule>Add rule</button>
		<details>
			<summary>Advanced JSON view</summary>
			<textarea data-uccm-rules-json name="rules" readonly>{}</textarea>
		</details>
		<template data-uccm-rule-template>
			<fieldset data-uccm-rule>
				<legend><strong data-uccm-rule-legend>New rule</strong></legend>
				<input name="uccm_rules[__INDEX__][id]" data-uccm-field="id" required pattern="[A-Za-z0-9_-]+">
				<select name="uccm_rules[__INDEX__][type]" data-uccm-field="type">
					<option value="script">Script</option>
					<option value="iframe">Iframe</option>
					<option value="embed">Embed</option>
					<option value="pixel">Pixel</option>
				</select>
				<select name="uccm_rules[__INDEX__][category]" data-uccm-field="category">
					<option value="functional">Functional</option>
					<option value="analytics" selected>Analytics</option>
					<option value="marketing">Marketing</option>
				</select>
				<p><input name="uccm_rules[__INDEX__][handle]" data-uccm-field="handle"></p>
				<input type="url" name="uccm_rules[__INDEX__][source]" data-uccm-field="source">
				<input name="uccm_rules[__INDEX__][title]" data-uccm-field="title">
				<button type="button" data-uccm-remove-rule>Remove rule</button>
			</fieldset>
		</template>
	</div>
	<button type="submit">Save blocking rules</button>
</form>
</body>
</html>
`;

async function boot( page ) {
	await page.setContent( fixture );
	await page.evaluate( () => {
		window.UCCMBlockingEditor = {
			newRule: 'New rule',
			handleOrSource: 'Enter a WordPress handle or an HTTPS source.',
			httpsSource: 'Enter a complete HTTPS source.',
			duplicateId: 'Each Rule ID must be unique.',
		};
	} );
	await page.addScriptTag( { path: path.join( process.cwd(), 'assets/js/admin-blocking.js' ) } );
}

test( 'administrator can add and remove an Analytics script without writing JSON', async ( { page } ) => {
	await boot( page );

	await expect( page.locator( '[data-uccm-rules-json]' ) ).toHaveValue( '{}' );
	await page.getByRole( 'button', { name: 'Add rule' } ).click();
	await page.locator( '[data-uccm-field="id"]' ).fill( 'analytics-test' );
	await page.locator( '[data-uccm-field="handle"]' ).fill( 'analytics-test' );
	await page.locator( '[data-uccm-field="title"]' ).fill( 'Analytics test script' );

	const advanced = JSON.parse( await page.locator( '[data-uccm-rules-json]' ).inputValue() );
	expect( advanced[ 'analytics-test' ] ).toMatchObject( {
		type: 'script',
		category: 'analytics',
		handle: 'analytics-test',
		title: 'Analytics test script',
	} );

	await page.getByRole( 'button', { name: 'Remove rule' } ).click();
	await expect( page.locator( '[data-uccm-rule]' ) ).toHaveCount( 0 );
	await expect( page.locator( '[data-uccm-rules-json]' ) ).toHaveValue( '{}' );
} );

test( 'invalid resource fields expose accessible browser validation', async ( { page } ) => {
	await boot( page );
	await page.getByRole( 'button', { name: 'Add rule' } ).click();
	await page.locator( '[data-uccm-field="id"]' ).fill( 'map' );
	await page.locator( '[data-uccm-field="type"]' ).selectOption( 'iframe' );
	await page.locator( '[data-uccm-field="source"]' ).fill( 'http://maps.example.test/embed' );
	await page.getByRole( 'button', { name: 'Save blocking rules' } ).click();

	const message = await page.locator( '[data-uccm-field="source"]' ).evaluate( ( input ) => input.validationMessage );
	expect( message ).toBe( 'Enter a complete HTTPS source.' );
} );
