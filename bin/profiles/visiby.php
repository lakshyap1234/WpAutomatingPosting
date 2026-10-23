<?php
/**
 * Build profile: Visiby (bin/build.sh visiby, bin/build.sh config visiby).
 *
 * Visiby's backend is the OAuth client: production and staging. The callback path
 * is the one visiby-backend's cms-connections module serves; change both together.
 * The logo is a file in content-publisher-connector/assets/ or an https:// address.
 */

return array(
	'client_id'     => 'visiby',
	'name'          => 'Visiby',
	'url'           => 'https://visiby.net',
	'logo'          => '',
	// The manifest installed Connectors check for new versions (DISTRIBUTION.md); '' for none.
	'update_url'    => '',
	'redirect_uris' => array(
		'https://api.visiby.net/cms-connections/wordpress/callback',
		'https://stag-api.visiby.net/cms-connections/wordpress/callback',
	),
);
