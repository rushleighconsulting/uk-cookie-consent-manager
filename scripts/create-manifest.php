<?php
/**
 * Create a signed release manifest.
 *
 * Usage: php scripts/create-manifest.php <version> <package-url> <sha256> <output>
 * The UCCM_MANIFEST_PRIVATE_KEY environment variable must contain a base64
 * Ed25519 seed (32 bytes) or secret key (64 bytes).
 *
 * @package UCCM
 */

declare(strict_types=1);

if ( 5 !== $argc ) {
	fwrite( STDERR, "Usage: php scripts/create-manifest.php <version> <package-url> <sha256> <output>\n" );
	exit( 2 );
}

if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
	fwrite( STDERR, "The sodium extension is required.\n" );
	exit( 2 );
}

$version     = $argv[1];
$package_url = $argv[2];
$sha256      = strtolower( $argv[3] );
$output      = $argv[4];
$encoded_key = getenv( 'UCCM_MANIFEST_PRIVATE_KEY' );
$key_bytes   = is_string( $encoded_key ) ? base64_decode( $encoded_key, true ) : false;

if ( false === $key_bytes ) {
	fwrite( STDERR, "UCCM_MANIFEST_PRIVATE_KEY must be valid base64.\n" );
	exit( 2 );
}

if ( SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $key_bytes ) ) {
	$key_pair  = sodium_crypto_sign_seed_keypair( $key_bytes );
	$key_bytes = sodium_crypto_sign_secretkey( $key_pair );
}

if ( SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $key_bytes ) ) {
	fwrite( STDERR, "UCCM_MANIFEST_PRIVATE_KEY must decode to 32 or 64 bytes.\n" );
	exit( 2 );
}

$manifest = array(
	'slug'         => 'uk-cookie-consent-manager',
	'version'      => $version,
	'package_url'  => $package_url,
	'sha256'       => $sha256,
	'requires_php' => '8.2',
	'requires_wp'  => '6.8',
);

$payload = json_encode( $manifest, JSON_UNESCAPED_SLASHES );

if ( false === $payload ) {
	fwrite( STDERR, "Manifest encoding failed.\n" );
	exit( 2 );
}

$manifest['signature'] = base64_encode( sodium_crypto_sign_detached( $payload, $key_bytes ) );
$json                  = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

if ( false === $json || false === file_put_contents( $output, $json . "\n" ) ) {
	fwrite( STDERR, "Manifest writing failed.\n" );
	exit( 2 );
}
