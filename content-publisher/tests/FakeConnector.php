<?php
/**
 * A stand-in for a client site running the Connector, answering the
 * Publisher's HTTP calls in-process (via pre_http_request). It follows the
 * real Connector's rules: PKCE S256, single-use codes, rotating single-use
 * refresh tokens where reuse revokes everything, revocation, bearer checks.
 * The real pairing is exercised end to end on the two local sites.
 */
class FakeConnector {

	public string $url = 'https://client.test';
	public bool $grant_active = true;
	public bool $offline = false;
	public bool $refresh_500 = false;
	public bool $delete_405 = false;
	public array $codes = array();      // code => challenge
	public array $refresh = array();    // token => ['state' => fresh|used|retired, 'used_at' => int, 'child' => token]
	public bool $lose_next_reply = false; // rotate, then "lose" the reply (timeout)
	public ?array $refresh_reply = null;  // force a reply: [status, body]
	public array $access = array();     // token => expires (unix)
	public array $calls = array();      // "METHOD route"
	public int $refresh_calls = 0;
	public ?array $last = null;         // last request: method, route, headers, body
	public array $metadata_override = array();
	public string $version = '0.4.0';
	/** Connector 0.5.0: the site lets the agency publish; tags may be created. */
	public bool $can_publish = false;
	public bool $tags_writable = true;
	public string $author = 'Agency';
	/** Permissions the connection was granted, as /connection reports them. */
	public array $granted = array( 'posts:write', 'media:write', 'terms:read', 'account:read' );
	/** Client-side posts and media: id => array; keys: idempotency key => id. */
	public array $posts = array();
	public array $media = array();
	public array $keys = array();
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
	}

	public function uninstall(): void {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	public function issue_code( string $challenge ): string {
		$code                 = 'code_' . wp_generate_password( 20, false );
		$this->codes[ $code ] = $challenge;
		return $code;
	}

	private function tokens(): array {
		$a                    = 'cpub_at_' . wp_generate_password( 40, false );
		$r                    = 'rt_' . wp_generate_password( 40, false );
		$this->access[ $a ]   = time() + 3600;
		$this->refresh[ $r ]  = array( 'state' => 'fresh', 'used_at' => 0, 'child' => null, 'access' => $a );
		return array( 'token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => $a, 'refresh_token' => $r );
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
		if ( false !== $pre || ! str_starts_with( $url, $this->url . '/' ) ) {
			return $pre;
		}
		if ( $this->offline ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$route   = $q['rest_route'] ?? '';
		$headers = array_change_key_case( $args['headers'] ?? array() );
		$method  = strtoupper( $headers['x-http-method-override'] ?? $args['method'] );
		$body    = is_array( $args['body'] ?? null ) ? $args['body'] : ( json_decode( (string) ( $args['body'] ?? '' ), true ) ?? array() );
		$this->calls[] = "$method $route";
		$this->last    = compact( 'method', 'route', 'headers', 'body' ) + array( 'http_method' => $args['method'] );

		if ( '/cpub-connector/v1/oauth/metadata' === $route ) {
			$base = $this->url . '/wp-json/cpub-connector/v1/oauth';
			return self::reply(
				200,
				array_merge(
					array(
						'issuer'                           => $this->url . '/',
						'authorization_response_iss_parameter_supported' => true,
						'authorization_endpoint'           => $this->url . '/wp-admin/admin.php?page=cpub-connector-authorize',
						'token_endpoint'                   => $base . '/token',
						'revocation_endpoint'              => $base . '/revoke',
						'code_challenge_methods_supported' => array( 'S256' ),
						'scopes_supported'                 => array( 'posts:write', 'media:write', 'terms:read', 'account:read' ),
						'connector_version'                => $this->version,
						'site_name'                        => 'Client Test',
					),
					$this->metadata_override
				)
			);
		}
		if ( str_ends_with( wp_parse_url( $url, PHP_URL_PATH ) ?? '', '/oauth/token' ) ) {
			return $this->token( $body );
		}
		if ( str_ends_with( wp_parse_url( $url, PHP_URL_PATH ) ?? '', '/oauth/revoke' ) ) {
			if ( isset( $this->refresh[ $body['token'] ?? '' ] ) || isset( $this->access[ $body['token'] ?? '' ] ) ) {
				$this->grant_active = false;
			}
			return self::reply( 200, '' );
		}

		// Resource requests need a valid bearer token.
		$auth  = $headers['authorization'] ?? '';
		$token = str_starts_with( $auth, 'Bearer ' ) ? substr( $auth, 7 ) : '';
		if ( ! $this->grant_active || ! isset( $this->access[ $token ] ) || $this->access[ $token ] < time() ) {
			return self::reply( 401, array( 'code' => 'cpub_invalid_token', 'message' => 'The access token is invalid, expired or revoked.' ) );
		}
		if ( 'DELETE' === strtoupper( $args['method'] ) && $this->delete_405 ) {
			return self::reply( 405, '<html>Method Not Allowed</html>' );
		}
		if ( '/cpub-connector/v1/connection' === $route ) {
			return self::reply( 200, array( 'site_name' => 'Client Test', 'connector_version' => $this->version, 'scopes' => $this->granted, 'user' => array( 'id' => 7, 'name' => $this->author, 'role' => 'author' ), 'can_publish' => $this->can_publish ) );
		}
		foreach ( $this->fail_next as $match => $how ) {
			if ( str_starts_with( "$method $route", $match ) ) {
				unset( $this->fail_next[ $match ] );
				return 'timeout' === $how ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : self::reply( $how, array( 'code' => 'fake_failure', 'message' => "Fake failure {$how}" ) );
			}
		}
		$key = $headers['x-cpub-idempotency-key'] ?? '';
		if ( 'POST' === $method && ( '/wp/v2/posts' === $route || '/wp/v2/media' === $route ) && '' !== $key && isset( $this->keys[ $route . $key ] ) ) {
			$id  = $this->keys[ $route . $key ];
			$obj = '/wp/v2/posts' === $route ? $this->posts[ $id ] : $this->media[ $id ];
			return self::reply( 200, $obj, array( 'X-CPub-Existing' => '1' ) );
		}
		if ( 'POST' === $method && '/wp/v2/media' === $route ) {
			$id                 = $this->next_id++;
			$this->media[ $id ] = array(
				'id'            => $id,
				'source_url'    => "{$this->url}/wp-content/uploads/{$id}.png",
				'media_details' => array( 'sizes' => array( 'large' => array( 'source_url' => "{$this->url}/wp-content/uploads/{$id}-1024x768.png" ) ) ),
				'alt_text'      => $q['alt_text'] ?? '',
				'caption'       => $q['caption'] ?? '',
				'post'          => 0,
				'bytes'         => strlen( (string) $args['body'] ),
				'type'          => $headers['content-type'] ?? '',
				'filename'      => $headers['content-disposition'] ?? '',
			);
			if ( '' !== $key ) {
				$this->keys[ $route . $key ] = $id;
			}
			return $this->maybe_lose( "POST $route", self::reply( 201, $this->media[ $id ] ) );
		}
		if ( 'POST' === $method && '/wp/v2/posts' === $route ) {
			$status = $this->status_for( $body, 'draft' );
			if ( is_array( $status ) ) {
				return $status;
			}
			$id                 = $this->next_id++;
			$this->posts[ $id ] = array( 'id' => $id, 'link' => "{$this->url}/?p={$id}" ) + $body;
			$this->posts[ $id ]['status'] = $status;
			if ( '' !== $key ) {
				$this->keys[ $route . $key ] = $id;
			}
			return $this->maybe_lose( "POST $route", self::reply( 201, $this->posts[ $id ] ) );
		}
		if ( preg_match( '#^/wp/v2/(posts|media)/(\d+)$#', $route, $m ) ) {
			$store = 'posts' === $m[1] ? 'posts' : 'media';
			$id    = (int) $m[2];
			if ( ! isset( $this->$store[ $id ] ) ) {
				return self::reply( 404, array( 'code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.' ) );
			}
			if ( 'POST' === $method ) {
				if ( 'posts' === $store && ! in_array( $this->posts[ $id ]['status'], array( 'draft', 'pending' ), true ) && ! ( $this->can_publish && in_array( $this->posts[ $id ]['status'], array( 'publish', 'future' ), true ) ) ) {
					return self::reply( 403, array( 'code' => 'cpub_post_locked', 'message' => 'Only drafts can be changed.' ) );
				}
				if ( 'posts' === $store ) {
					$status = $this->status_for( $body + array( 'date_gmt' => $this->posts[ $id ]['date_gmt'] ?? null ), $this->posts[ $id ]['status'] );
					if ( is_array( $status ) ) {
						return $status;
					}
					$body['status'] = $status;
				}
				$this->$store[ $id ] = array_merge( $this->$store[ $id ], $body );
			}
			return self::reply( 200, $this->$store[ $id ] );
		}
		if ( 'POST' === $method && '/wp/v2/tags' === $route ) {
			if ( ! $this->tags_writable ) {
				return self::reply( 403, array( 'code' => 'cpub_forbidden', 'message' => 'This connection is not allowed to use this part of the site.' ) );
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

	private function token( array $b ) {
		if ( 'authorization_code' === ( $b['grant_type'] ?? '' ) ) {
			$challenge = $this->codes[ $b['code'] ?? '' ] ?? null;
			unset( $this->codes[ $b['code'] ?? '' ] );
			$expected = rtrim( strtr( base64_encode( hash( 'sha256', (string) ( $b['code_verifier'] ?? '' ), true ) ), '+/', '-_' ), '=' );
			if ( null === $challenge || ! hash_equals( $challenge, $expected ) ) {
				return self::reply( 400, array( 'error' => 'invalid_grant', 'error_description' => 'bad code or verifier' ) );
			}
			$this->grant_active = true;
			return self::reply( 200, $this->tokens() );
		}
		if ( 'refresh_token' === ( $b['grant_type'] ?? '' ) ) {
			++$this->refresh_calls;
			if ( $this->refresh_500 ) {
				return self::reply( 503, array( 'error' => 'server_error' ) );
			}
			if ( $this->refresh_reply ) {
				return self::reply( $this->refresh_reply[0], $this->refresh_reply[1] );
			}
			$rt  = $b['refresh_token'] ?? '';
			$row = $this->refresh[ $rt ] ?? null;
			if ( ! $this->grant_active || ! $row || 'retired' === $row['state'] && null === $row['child'] ) {
				return self::reply( 400, array( 'error' => 'invalid_grant', 'error_description' => 'revoked' ) );
			}
			if ( 'used' === $row['state'] ) {
				$child = $this->refresh[ $row['child'] ] ?? null;
				// Grace: repeated within 2 minutes and its replacement unused -> re-issue.
				if ( $row['used_at'] >= time() - 120 && $child && 'fresh' === $child['state'] ) {
					$this->refresh[ $row['child'] ]['state'] = 'retired';
					unset( $this->access[ $child['access'] ] );
				} else {
					$this->grant_active = false; // reuse: cut off
					return self::reply( 400, array( 'error' => 'invalid_grant', 'error_description' => 'reused' ) );
				}
			} elseif ( 'retired' === $row['state'] ) {
				$this->grant_active = false;
				return self::reply( 400, array( 'error' => 'invalid_grant', 'error_description' => 'reused' ) );
			}
			unset( $this->access[ $row['access'] ] );
			$pair                              = $this->tokens();
			$this->refresh[ $rt ]['state']     = 'used';
			$this->refresh[ $rt ]['used_at']   = time();
			$this->refresh[ $rt ]['child']     = $pair['refresh_token'];
			if ( $this->lose_next_reply ) {
				$this->lose_next_reply = false;
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30000 milliseconds' );
			}
			return self::reply( 200, $pair );
		}
		return self::reply( 400, array( 'error' => 'unsupported_grant_type' ) );
	}

	/** The status WordPress would give the post, or a refusal reply, as the Connector 0.5.0 rules say. */
	private function status_for( array $body, string $current ) {
		$status = (string) ( $body['status'] ?? $current );
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			if ( ! $this->can_publish ) {
				return self::reply( 403, array( 'code' => 'rest_cannot_publish', 'message' => 'Sorry, you are not allowed to publish posts as this user.' ) );
			}
			if ( 'private' === $status ) {
				return self::reply( 403, array( 'code' => 'cpub_status_not_allowed', 'message' => 'Not private.' ) );
			}
			$at = isset( $body['date_gmt'] ) ? strtotime( $body['date_gmt'] . ' UTC' ) : false;
			return $at && $at > time() ? 'future' : 'publish';
		}
		return $status;
	}

	private function maybe_lose( string $what, array $reply ) {
		if ( isset( $this->lose_reply[ $what ] ) ) {
			unset( $this->lose_reply[ $what ] );
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20000 milliseconds' );
		}
		return $reply;
	}
}
