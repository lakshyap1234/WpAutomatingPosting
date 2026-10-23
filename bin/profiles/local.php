<?php
/**
 * Build profile: local development (bin/build.sh local, bin/build.sh config local).
 *
 * The dev stack's agency site (dev/README.md), plus a test tool on the approving
 * admin's own computer (http://127.0.0.1/callback, any port). The only profile
 * allowed a loopback return address. Never install this build on a live site.
 */

return array(
	'client_id'     => 'cpub-publisher',
	'name'          => 'Agency (local)',
	'url'           => 'https://agency.test',
	'logo'          => '',
	// The manifest installed Connectors check for new versions (DISTRIBUTION.md); '' for none.
	'update_url'    => '',
	'redirect_uris' => array(
		'https://agency.test/wp-admin/admin.php?page=cpub-publisher&cpub_oauth=callback',
		'http://127.0.0.1/callback',
	),
);
