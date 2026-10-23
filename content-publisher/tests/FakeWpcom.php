<?php
/**
 * A stand-in for WordPress.com and one client site connected to it through
 * Jetpack, answering the Publisher's HTTP calls in-process (pre_http_request).
 *
 * It follows WordPress.com's rules as the Publisher relies on them: public site
 * lookup, the code exchange (client secret, single-use codes, a token for one
 * site), bearer tokens that don't expire but can be revoked, the site's
 * /wp/v2 routes under /wp/v2/sites/{id}, and v1.1 media uploads (multipart).
 * Requests are recorded as "METHOD route", with the site part left out:
 * "POST /wp/v2/posts", "POST /rest/v1.1/media/new", "GET /rest/v1.1".
 */
class FakeWpcom {

	public const API           = 'https://public-api.wordpress.com';
	public const CLIENT_ID     = '12345';
	public const CLIENT_SECRET = 'test-secret';

	public string $url = 'https://client.test';
	public int $site_id = 4242;
	public string $site_name = 'Client Test';
	/** Whether WordPress.com knows the site (Jetpack connected). */
	public bool $known = true;
	public bool $offline = false;
	/** The person who approved: their name and rights on the site. */
	public string $author = 'Agency';
	public bool $can_publish = false;
	public bool $admin = false;
	public bool $can_upload = true;
	/** Whether the site lets them add tags (WordPress lets anyone who can write posts). */
	public bool $tags_writable = true;
	/** The site the next approval is for (the approver may pick another one). */
	public ?int $approve_for = null;

	public array $codes = array();      // code => site id
	public array $tokens = array();     // token => active (bool)
	public array $calls = array();      // "METHOD route"
	public ?array $last = null;         // last request: method, route, headers, body, query
	/** Client-side posts and media: id => array. */
	public array $posts = array();
	public array $media = array();
	public array $tags = array( array( 'id' => 20, 'name' => 'coffee' ), array( 'id' => 21, 'name' => 'Home Brewing' ) );
	/** Make the next matching request fail: "METHOD route-prefix" => status (int) or 'timeout'. Consumed once. */
	public array $fail_next = array();
	/** Accept a create, then "lose" the reply (as if the connection dropped). Consumed once. */
	public array $lose_reply = array();
	private int $next_id = 100;
	public array $categories = array(
		array( 'id' => 1, 'name' => 'Uncategorized', 'parent' => 0 ),
		array( 'id' => 5, 'name' => 'Coffee &amp; Tea', 'parent' => 0 ),
		array( 'id' => 6, 'name' => 'Espresso', 'parent' => 5 ),
	);

	public function install(): void {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
		add_filter( 'cpub_publisher_wpcom_app', array( $this, 'app' ) );
	}

	public function uninstall(): void {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
		remove_filter( 'cpub_publisher_wpcom_app', array( $this, 'app' ) );
	}

	/** The agency's app, as wp-config.php would define it. */
	public function app(): array {
		return array( 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET );
	}

	/** What WordPress.com would hand the browser after the person approves. */
	public function issue_code(): string {
		$code                 = 'code_' . wp_generate_password( 20, false );
		$this->codes[ $code ] = $this->approve_for ?? $this->site_id;
		return $code;
	}

	/** The person who approved revokes the app on WordPress.com (or Jetpack is disconnected). */
	public function revoke(): void {
		$this->tokens = array_map( '__return_false', $this->tokens );
	}

	public function capabilities(): array {
		return array(
			'edit_posts'        => true,
			'upload_files'      => $this->can_upload,
			'publish_posts'     => $this->can_publish || $this->admin,
			'edit_others_posts' => $this->admin,
			'manage_categories' => $this->admin,
			'manage_options'    => $this->admin,
		);
	}

	private static function reply( int $status, $body, array $headers = array() ): array {
		return array(
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array( 'code' => $status, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function handle( $pre, $args, $url ) {
		if ( false !== $pre || ! str_starts_with( $url, self::API . '/' ) ) {
			return $pre;
		}
		if ( $this->offline ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$headers = array_change_key_case( $args['headers'] ?? array() );
		$method  = strtoupper( $args['method'] );
		$type    = (string) ( $headers['content-type'] ?? '' );
		$body    = is_array( $args['body'] ?? null ) ? $args['body'] : ( str_starts_with( $type, 'multipart/form-data' ) ? self::parse_multipart( (string) $args['body'], $type ) : ( json_decode( (string) ( $args['body'] ?? '' ), true ) ?? array() ) );

		if ( '/oauth2/token' === $path ) {
			$this->calls[] = 'POST /oauth2/token';
			return $this->token( $body );
		}
		// The public lookup, without a token: /rest/v1.1/sites/client.test
		if ( preg_match( '#^/rest/v1\.1/sites/([^/]+)$#', $path, $m ) && ! ctype_digit( $m[1] ) ) {
			$this->calls[] = 'GET /rest/v1.1/sites/' . $m[1];
			$host          = preg_replace( '#^https://#', '', $this->url );
			if ( ! $this->known || rawurldecode( $m[1] ) !== str_replace( '/', '::', $host ) ) {
				return self::reply( 404, array( 'error' => 'unknown_blog', 'message' => 'Unknown blog' ) );
			}
			return self::reply( 200, array( 'ID' => $this->site_id, 'name' => $this->site_name, 'URL' => $this->url, 'jetpack' => true ) );
		}

		// Everything else needs the token.
		$auth  = $headers['authorization'] ?? '';
		$token = str_starts_with( $auth, 'Bearer ' ) ? substr( $auth, 7 ) : '';
		if ( empty( $this->tokens[ $token ] ) ) {
			$this->calls[] = "$method $path";
			return self::reply( 401, array( 'error' => 'invalid_token', 'message' => 'The OAuth2 token is invalid.' ) );
		}
		if ( '/rest/v1.1/me' === $path ) {
			$route = '/rest/v1.1/me';
		} elseif ( preg_match( '#^/(wp/v2|rest/v1\.1)/sites/(\d+)(/.*)?$#', $path, $m ) ) {
			if ( (int) $m[2] !== $this->site_id ) {
				return self::reply( 403, array( 'error' => 'unauthorized', 'message' => 'This token is for another site.' ) );
			}
			$route = '/' . $m[1] . ( $m[3] ?? '' );
		} else {
			return self::reply( 404, array( 'error' => 'unknown_route' ) );
		}
		$this->calls[] = "$method $route";
		$this->last    = compact( 'method', 'route', 'headers', 'body' ) + array( 'query' => $q );

		foreach ( $this->fail_next as $match => $how ) {
			if ( str_starts_with( "$method $route", $match ) ) {
				unset( $this->fail_next[ $match ] );
				return 'timeout' === $how ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : self::reply( $how, array( 'code' => 'fake_failure', 'message' => "Fake failure {$how}" ) );
			}
		}

		if ( '/rest/v1.1/me' === $route ) {
			return self::reply( 200, array( 'display_name' => $this->author, 'username' => 'agency' ) );
		}
		if ( '/rest/v1.1' === $route ) {
			return self::reply( 200, array( 'ID' => $this->site_id, 'name' => $this->site_name, 'URL' => $this->url, 'jetpack' => true, 'capabilities' => $this->capabilities() ) );
		}
		if ( 'POST' === $method && '/rest/v1.1/media/new' === $route ) {
			return $this->upload( $body );
		}
		if ( 'POST' === $method && '/wp/v2/posts' === $route ) {
			$status = $this->status_for( $body, 'draft' );
			if ( is_array( $status ) ) {
				return $status;
			}
			$id                 = $this->next_id++;
			$this->posts[ $id ] = array_merge( array( 'slug' => '' ), $body, array( 'id' => $id, 'link' => "{$this->url}/?p={$id}" ) );
			$this->posts[ $id ]['status'] = $status;
			return $this->maybe_lose( "POST $route", self::reply( 201, $this->posts[ $id ] ) );
		}
		if ( 'GET' === $method && '/wp/v2/posts' === $route ) {
			$statuses = isset( $q['status'] ) ? explode( ',', (string) $q['status'] ) : array( 'publish' );
			$rows     = array_values(
				array_filter(
					$this->posts,
					fn( $p ) => in_array( $p['status'], $statuses, true ) && ( ! isset( $q['slug'] ) || (string) $p['slug'] === (string) $q['slug'] )
				)
			);
			return self::reply( 200, $rows );
		}
		if ( 'GET' === $method && '/wp/v2/media' === $route ) {
			$search = (string) ( $q['search'] ?? '' );
			$rows   = array_values( array_filter( $this->media, fn( $m ) => '' === $search || str_contains( $m['description']['raw'] . ' ' . $m['title'], $search ) ) );
			return self::reply( 200, $rows );
		}
		if ( preg_match( '#^/wp/v2/(posts|media)/(\d+)$#', $route, $m ) ) {
			$store = 'posts' === $m[1] ? 'posts' : 'media';
			$id    = (int) $m[2];
			if ( ! isset( $this->$store[ $id ] ) ) {
				return self::reply( 404, array( 'code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.' ) );
			}
			if ( 'POST' === $method ) {
				if ( 'posts' === $store ) {
					$status = $this->status_for( $body + array( 'date_gmt' => $this->posts[ $id ]['date_gmt'] ?? null ), $this->posts[ $id ]['status'] );
					if ( is_array( $status ) ) {
						return $status;
					}
					$body['status'] = $status;
				}
				$this->$store[ $id ] = array_merge( $this->$store[ $id ], $body );
				return $this->maybe_lose( "POST $route", self::reply( 200, $this->$store[ $id ] ) );
			}
			return self::reply( 200, $this->$store[ $id ] );
		}
		if ( 'POST' === $method && '/wp/v2/tags' === $route ) {
			if ( ! $this->tags_writable ) {
				return self::reply( 403, array( 'code' => 'rest_cannot_create', 'message' => 'Sorry, you are not allowed to create terms in this taxonomy.' ) );
			}
			foreach ( $this->tags as $t ) {
				if ( mb_strtolower( $t['name'] ) === mb_strtolower( (string) ( $body['name'] ?? '' ) ) ) {
					return self::reply( 400, array( 'code' => 'term_exists', 'message' => 'A term with the name provided already exists.', 'data' => array( 'status' => 400, 'term_id' => $t['id'] ) ) );
				}
			}
			$id           = $this->next_id++;
			$this->tags[] = array( 'id' => $id, 'name' => (string) $body['name'] );
			return self::reply( 201, array( 'id' => $id, 'name' => (string) $body['name'] ) );
		}
		if ( '/wp/v2/tags' === $route ) {
			$page = max( 1, (int) ( $q['page'] ?? 1 ) );
			$tags = isset( $q['search'] ) ? array_values( array_filter( $this->tags, fn( $t ) => false !== mb_stripos( $t['name'], (string) $q['search'] ) ) ) : $this->tags;
			$rows = array_slice( $tags, ( $page - 1 ) * 100, 100 );
			return $rows || 1 === $page ? self::reply( 200, $rows ) : self::reply( 400, array( 'code' => 'rest_post_invalid_page_number' ) );
		}
		if ( '/wp/v2/categories' === $route ) {
			$page = max( 1, (int) ( $q['page'] ?? 1 ) );
			$per  = (int) ( $q['per_page'] ?? 10 );
			$rows = array_slice( $this->categories, ( $page - 1 ) * $per, $per );
			return $rows || 1 === $page ? self::reply( 200, $rows ) : self::reply( 400, array( 'code' => 'rest_post_invalid_page_number' ) );
		}
		return self::reply( 200, array( 'ok' => true, 'method' => $method ) );
	}

	/** The code exchange: our app's secret, a code used once, a token for the site that was approved. */
	private function token( array $b ) {
		if ( self::CLIENT_ID !== ( $b['client_id'] ?? '' ) || self::CLIENT_SECRET !== ( $b['client_secret'] ?? '' ) ) {
			return self::reply( 400, array( 'error' => 'invalid_client', 'error_description' => 'Unknown client or wrong secret.' ) );
		}
		$site = $this->codes[ $b['code'] ?? '' ] ?? null;
		unset( $this->codes[ $b['code'] ?? '' ] );
		if ( 'authorization_code' !== ( $b['grant_type'] ?? '' ) || null === $site ) {
			return self::reply( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid or used code.' ) );
		}
		$token                  = 'wpcom_' . wp_generate_password( 40, false );
		$this->tokens[ $token ] = $site === $this->site_id;
		return self::reply( 200, array( 'access_token' => $token, 'token_type' => 'bearer', 'blog_id' => (string) $site, 'blog_url' => $site === $this->site_id ? $this->url : 'https://other.example', 'scope' => '' ) );
	}

	/** POST /rest/v1.1/sites/{id}/media/new with media[] and attrs[0][...] (multipart). */
	private function upload( array $parts ) {
		if ( ! $this->can_upload ) {
			return self::reply( 403, array( 'error' => 'unauthorized', 'message' => 'User cannot upload media.' ) );
		}
		$file = $parts['files']['media[]'] ?? null;
		if ( ! $file ) {
			return self::reply( 400, array( 'error' => 'invalid_input', 'message' => 'No media provided.' ) );
		}
		$attr = static fn( string $k ) => (string) ( $parts['fields'][ "attrs[0][$k]" ] ?? '' );
		$id   = $this->next_id++;
		$this->media[ $id ] = array(
			'id'            => $id,
			'source_url'    => "{$this->url}/wp-content/uploads/{$id}.png",
			'media_details' => array( 'sizes' => array( 'large' => array( 'source_url' => "{$this->url}/wp-content/uploads/{$id}-1024x768.png" ) ) ),
			'title'         => pathinfo( $file['filename'], PATHINFO_FILENAME ),
			'alt_text'      => $attr( 'alt' ),
			'caption'       => $attr( 'caption' ),
			'description'   => array( 'raw' => $attr( 'description' ), 'rendered' => '<p>' . esc_html( $attr( 'description' ) ) . '</p>' ),
			'post'          => 0,
			'bytes'         => strlen( $file['value'] ),
			'type'          => $file['type'],
			'filename'      => $file['filename'],
		);
		$v1 = array(
			'ID'         => $id,
			'URL'        => $this->media[ $id ]['source_url'],
			'thumbnails' => array( 'large' => $this->media[ $id ]['media_details']['sizes']['large']['source_url'] ),
		);
		return $this->maybe_lose( 'POST /rest/v1.1/media/new', self::reply( 200, array( 'media' => array( $v1 ) ) ) );
	}

	/** @return array{fields: array<string,string>, files: array<string, array{filename:string, type:string, value:string}>} */
	private static function parse_multipart( string $body, string $type ): array {
		$out = array( 'fields' => array(), 'files' => array() );
		if ( ! preg_match( '/boundary=(.+)$/', $type, $m ) ) {
			return $out;
		}
		foreach ( explode( '--' . trim( $m[1] ), $body ) as $chunk ) {
			if ( ! str_contains( $chunk, "\r\n\r\n" ) ) {
				continue;
			}
			[ $head, $value ] = explode( "\r\n\r\n", ltrim( $chunk, "\r\n" ), 2 );
			$value            = substr( $value, 0, -2 ); // the \r\n before the next boundary
			preg_match( '/name="([^"]*)"/', $head, $n );
			if ( preg_match( '/filename="([^"]*)"/', $head, $f ) ) {
				preg_match( '/Content-Type: (\S+)/i', $head, $t );
				$out['files'][ $n[1] ] = array( 'filename' => $f[1], 'type' => $t[1] ?? '', 'value' => $value );
			} else {
				$out['fields'][ $n[1] ?? '' ] = $value;
			}
		}
		return $out;
	}

	/** The status WordPress would give the post, or its refusal, for someone with these rights. */
	private function status_for( array $body, string $current ) {
		$status = (string) ( $body['status'] ?? $current );
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && $status !== $current ) {
			if ( ! $this->can_publish && ! $this->admin ) {
				return self::reply( 403, array( 'code' => 'rest_cannot_publish', 'message' => 'Sorry, you are not allowed to publish posts as this user.' ) );
			}
		}
		if ( in_array( $status, array( 'publish', 'future' ), true ) ) {
			$at = isset( $body['date_gmt'] ) ? strtotime( $body['date_gmt'] . ' UTC' ) : false;
			return $at && $at > time() ? 'future' : 'publish';
		}
		return $status;
	}

	/** A key ending in "/" matches any ID after it: 'POST /wp/v2/posts/'. */
	private function maybe_lose( string $what, array $reply ) {
		foreach ( array_keys( $this->lose_reply ) as $key ) {
			if ( $key === $what || ( str_ends_with( $key, '/' ) && str_starts_with( $what, $key ) ) ) {
				unset( $this->lose_reply[ $key ] );
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20000 milliseconds' );
			}
		}
		return $reply;
	}
}
