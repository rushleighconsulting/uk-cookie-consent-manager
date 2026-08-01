'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const directory = path.join( process.cwd(), 'assets', 'js' );
const files = fs.readdirSync( directory ).filter( ( file ) => file.endsWith( '.js' ) );
const failures = [];
const visibleLiteralPatterns = [
	/\b(?:textContent|innerText)\s*=\s*(['"`])([A-Z][^'"`\n]{2,})\1/g,
	/\bsetCustomValidity\(\s*(['"`])([A-Z][^'"`\n]{2,})\1\s*\)/g,
	/\bsetAttribute\(\s*(['"`])(?:title|aria-label)\1\s*,\s*(['"`])([A-Z][^'"`\n]{2,})\2\s*\)/g,
	/\bannounce\(\s*(['"`])([A-Z][^'"`\n]{2,})\1\s*\)/g,
];

for ( const file of files ) {
	const source = fs.readFileSync( path.join( directory, file ), 'utf8' );

	for ( const pattern of visibleLiteralPatterns ) {
		for ( const match of source.matchAll( pattern ) ) {
			failures.push( `${ file }: visible text is not passed through WordPress i18n: ${ match[0] }` );
		}
	}

	for ( const match of source.matchAll( /\b__\(\s*(['"`])([\s\S]*?)\1\s*(?:,\s*(['"`])([^'"`]+)\3)?\s*\)/g ) ) {
		if ( 'rushleigh-cookie-choices' !== match[4] ) {
			failures.push( `${ file }: gettext call has a missing or incorrect text domain: ${ match[0] }` );
		}
	}
}

if ( failures.length ) {
	process.stderr.write( `${ failures.join( '\n' ) }\n` );
	process.exitCode = 1;
}

