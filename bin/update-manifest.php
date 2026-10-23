<?php
/**
 * Writes the update manifest for a Connector zip (see DISTRIBUTION.md), when its
 * config/agency.php has an update_url. Called by bin/build.sh zips.
 *
 *   php bin/update-manifest.php <zip> <unpacked plugin folder>
 *
 * The manifest goes next to the zip, as <zip name>.update.json. Publish it at
 * update_url, and the zip in the same folder as update_url.
 */

[ , $zip, $dir ] = $argv + array( '', '', '' );
if ( ! is_file( $zip ) || ! is_dir( $dir ) ) {
	fwrite( STDERR, "usage: php bin/update-manifest.php <zip> <unpacked plugin folder>\n" );
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' ); // config/agency.php refuses to load without it
$config     = require $dir . '/config/agency.php';
$update_url = (string) ( $config['update_url'] ?? '' );
if ( '' === $update_url ) {
	exit( 0 ); // no update channel for this build
}

$main   = (string) file_get_contents( $dir . '/content-publisher-connector.php' );
$readme = (string) file_get_contents( $dir . '/readme.txt' );
$header = static fn( string $text, string $name ) => preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $text, $m ) ? trim( $m[1] ) : '';

$version = $header( $main, 'Version' );

// This version's changelog entry, from readme.txt ("= 0.6.0 =" then "* ..." lines).
$changelog = '';
if ( preg_match( '/^= ' . preg_quote( $version, '/' ) . ' =\s*$(.*?)(?=^= |\z)/ms', $readme, $m ) ) {
	$items = array_filter( array_map( static fn( $l ) => trim( ltrim( trim( $l ), '*' ) ), explode( "\n", $m[1] ) ) );
	if ( $items ) {
		$changelog = '<ul><li>' . implode( '</li><li>', array_map( static fn( $i ) => htmlspecialchars( $i, ENT_QUOTES ), $items ) ) . '</li></ul>';
	}
}

$manifest = array(
	'version'      => $version,
	'download_url' => substr( $update_url, 0, strrpos( $update_url, '/' ) + 1 ) . basename( $zip ),
	'sha256'       => hash_file( 'sha256', $zip ),
	'requires'     => $header( $main, 'Requires at least' ),
	'requires_php' => $header( $main, 'Requires PHP' ),
	'tested'       => $header( $readme, 'Tested up to' ),
	'changelog'    => $changelog,
);
$out = preg_replace( '/\.zip$/', '', $zip ) . '.update.json';
file_put_contents( $out, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo $out, "\n";
