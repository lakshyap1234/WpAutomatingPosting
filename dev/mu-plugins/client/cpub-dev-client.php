<?php
/**
 * Plugin Name: Content Publisher Connector: local development (client)
 * Description: LOCAL DEVELOPMENT ONLY (dev/README.md). Makes the Connector trust the local
 *              agency site instead of the one built into config/agency.php. Never install
 *              on a real site.
 *
 * The Connector trusts exactly one agency, fixed when its zip is built. Locally that would
 * mean rebuilding it for https://agency.test; the Connector's cpub_connector_agency filter
 * overrides it instead (CPUB_DEV_AGENCY_URL, default https://agency.test).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( 'local' !== wp_get_environment_type() ) {
	return;
}

add_filter(
	'cpub_connector_agency',
	static function ( $config ) {
		$url = rtrim( defined( 'CPUB_DEV_AGENCY_URL' ) ? (string) CPUB_DEV_AGENCY_URL : 'https://agency.test', '/' );
		return array(
			'client_id'     => 'cpub-publisher',
			'name'          => defined( 'CPUB_DEV_AGENCY_NAME' ) ? (string) CPUB_DEV_AGENCY_NAME : 'Agency (local)',
			'url'           => $url,
			'logo'          => '',
			'redirect_uris' => array( $url . '/wp-admin/admin.php?page=cpub-publisher&cpub_oauth=callback' ),
		);
	}
);
