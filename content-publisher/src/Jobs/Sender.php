<?php
/**
 * Sending an approved post to its client site through the Connector: as a
 * draft, or published or scheduled when the site allows it (Connector 0.5.0+).
 * Runs on Action Scheduler, one post at a time per client site.
 *
 * Every step can be repeated safely: images already uploaded are remembered
 * in the job, and each image and the draft carry a one-time key the Connector
 * (0.4.0+) uses to hand back what it already made when a reply was lost.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

use CPub\Publisher\Connections\ConnectorClient;
use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Connections\HealthCheck;
use CPub\Publisher\Connections\Http;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\SiteSettings;
use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sender {

	public const HOOK          = 'cpub_send_job';
	public const GROUP         = 'cpub';
	public const MIN_CONNECTOR = '0.4.0';

	/** Waits (seconds) before retrying a temporary problem; then the send fails. */
	public const RETRY_DELAYS = array( 60, 300, 900 );
	public const BUSY_DELAY   = 30;

	public const DOWNLOAD_TIMEOUT = 30;
	public const UPLOAD_TIMEOUT   = 90;

	/** Days our copies of a post's files are kept after it goes live, so it can still be corrected. */
	public const RETENTION_DAYS = 30;

	/** Published posts are looked at daily for this long (the site may take one down). */
	public const WATCH_DAYS = 90;

	/** Statuses of a live post on the client site. */
	public const LIVE = array( 'publish', 'future' );

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'send' ) );
		add_action( HealthCheck::SITE_HOOK, array( self::class, 'check_site' ), 20 ); // daily, after the connection check
	}

	public static function queue( int $job_id, int $delay = 0 ): void {
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $job_id ), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK, array( $job_id ), self::GROUP, true );
		}
	}

	/** Action Scheduler entry point. */
	public static function send( $job_id ): void {
		global $wpdb;
		$job = Jobs::get( (int) $job_id );
		if ( ! $job || Jobs::APPROVED !== $job->status ) {
			return;
		}
		$lock = 'cpub_send_' . $job->site_id . '_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			self::queue( $job->id, self::BUSY_DELAY );
			return;
		}
		try {
			if ( ! Jobs::update( $job->id, array( 'status' => Jobs::SENDING, 'last_error' => null ), null, array( Jobs::APPROVED ) ) ) {
				return; // reopened or deleted meanwhile
			}
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::run( Jobs::get( $job->id ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function run( object $job ): void {
		try {
			$site = Sites::get( (int) $job->site_id );
			if ( ! $site ) {
				throw new SendException( 'The client site was removed.' );
			}
			self::check_connector( $site );
			$media = self::upload_images( $job, $site );
			$post  = self::create_post( Jobs::get( $job->id ), $site, $media );
			self::done( Jobs::get( $job->id ), $site, $post, $media );
		} catch ( SendException $e ) {
			self::failed( $job, $e );
		} catch ( \Throwable $e ) {
			error_log( 'Content Publisher send ' . $job->id . ': ' . $e ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			self::failed( $job, new SendException( 'Unexpected error (details are in the PHP error log): ' . $e->getMessage() ) );
		}
	}

	/** Connectors before 0.4.0 can't protect against duplicates; refuse rather than risk two drafts. */
	private static function check_connector( object $site ): void {
		if ( Sites::CONNECTED !== $site->status ) {
			throw new SendException( sprintf( '%s is not connected. Reconnect it under Client sites, then retry.', $site->name ?: $site->url ) );
		}
		if ( version_compare( (string) $site->connector_version, self::MIN_CONNECTOR, '>=' ) ) {
			return;
		}
		HealthCheck::check( $site ); // it may have been updated since we last looked
		$fresh = Sites::get( (int) $site->id );
		if ( ! $fresh || version_compare( (string) $fresh->connector_version, self::MIN_CONNECTOR, '<' ) ) {
			throw new SendException( sprintf( '%1$s runs Content Publisher Connector %2$s. Sending needs %3$s or later: install the new Connector zip on that site, then retry.', $site->name ?: $site->url, $fresh && '' !== $fresh->connector_version ? $fresh->connector_version : '(unknown version)', self::MIN_CONNECTOR ) );
		}
	}

	/** The draft's key: the same across retries, new only after the client binned an earlier draft. */
	private static function post_key( object $job ): string {
		$gen = (int) ( $job->data['send_generation'] ?? 0 );
		return self::key( $job, 'post' . ( $gen ? '|' . $gen : '' ) );
	}

	/** A key that stays the same for this job (and image) across every retry. */
	private static function key( object $job, string $what ): string {
		return 'cpub' . $job->id . '-' . substr( hash( 'sha256', home_url() . '|' . $job->id . '|' . $job->created_at . '|' . $what ), 0, 40 );
	}

	/**
	 * Upload every image the post uses now (files uploaded with it, and web
	 * addresses, downloaded safely). An image is identified by its content,
	 * alt text and caption: one uploaded by an earlier attempt is reused only
	 * if none of those changed, so a replaced file or a new caption is sent.
	 *
	 * @return array<string, array{id:int, src:string, sizeSlug:string, hash:string}> src => client media, only the images in the post
	 */
	private static function upload_images( object $job, object $site ): array {
		$sent   = (array) ( $job->data['media'] ?? array() ); // everything uploaded so far, by src
		$media  = array();
		$assets = Assets::for_job( $job->id, Assets::IMAGE );
		foreach ( MarkdownToPost::convert( (string) $job->markdown, array( 'format' => 'html' ) )['images'] as $img ) {
			$src = (string) $img['src'];
			if ( isset( $media[ $src ] ) ) {
				continue;
			}
			$asset = ImageMatcher::find( $src, $assets );
			// Entries from before 0.6.0 don't record alt text and caption: nothing to compare, reuse.
			$unchanged = ! array_key_exists( 'alt', $sent[ $src ] ?? array() ) || ( $sent[ $src ]['alt'] === (string) $img['alt'] && ( $sent[ $src ]['caption'] ?? '' ) === (string) $img['caption'] );
			if ( ! $asset && ! empty( $sent[ $src ]['purged'] ) && $unchanged ) {
				// The file was deleted after the retention period; the client already has it, unchanged.
				$media[ $src ] = $sent[ $src ];
				continue;
			}
			if ( ! $asset && ! empty( $sent[ $src ]['purged'] ) ) {
				throw new SendException( sprintf( 'Our copy of the image %s was deleted %d days after the post went live, so its alt text or caption can\'t be changed from here. Upload the file again in the editor, or put the alt text and caption back.', rawurldecode( $src ), self::RETENTION_DAYS ) );
			}
			if ( $asset ) {
				$bytes = Assets::bytes( $asset );
				if ( null === $bytes ) {
					throw new SendException( sprintf( 'The image file %s is missing from storage. Upload it again in the editor, then retry.', $asset->filename ) );
				}
				$name = (string) $asset->filename;
			} else {
				[ $bytes, $name ] = self::download( $src );
			}
			$hash = hash( 'sha256', $bytes . "\0" . $img['alt'] . "\0" . $img['caption'] );
			if ( isset( $sent[ $src ]['hash'] ) && $sent[ $src ]['hash'] === $hash ) {
				$media[ $src ] = $sent[ $src ];
				continue;
			}
			$type = Assets::sniff_image( $bytes );
			if ( ! $type ) {
				throw new SendException( sprintf( 'Image %s isn\'t a JPEG, PNG, GIF or WebP image.', rawurldecode( $src ) ) );
			}
			$name = self::file_name( $name, $type['ext'] );
			$r    = ConnectorClient::request(
				$site,
				'POST',
				'/wp/v2/media',
				array(
					'body'    => $bytes,
					'timeout' => self::UPLOAD_TIMEOUT,
					'query'   => array_filter( array( 'alt_text' => (string) $img['alt'], 'caption' => (string) $img['caption'] ), 'strlen' ),
					'headers' => array(
						'Content-Type'           => $type['mime'],
						'Content-Disposition'    => 'attachment; filename="' . $name . '"',
						'X-CPub-Idempotency-Key' => self::key( $job, 'image|' . $hash ),
					),
				)
			);
			self::expect( $r, array( 200, 201 ), sprintf( 'Uploading image %s', $name ) );
			$d             = (array) $r['data'];
			$large         = $d['media_details']['sizes']['large']['source_url'] ?? null;
			$media[ $src ] = array(
				'id'       => (int) ( $d['id'] ?? 0 ),
				'src'      => esc_url_raw( (string) ( $large ?? $d['source_url'] ?? '' ) ),
				'sizeSlug' => $large ? 'large' : 'full',
				'hash'     => $hash,
				'alt'      => (string) $img['alt'],
				'caption'  => (string) $img['caption'],
			);
			if ( $media[ $src ]['id'] <= 0 || '' === $media[ $src ]['src'] ) {
				throw new SendException( sprintf( 'The client site accepted image %s but didn\'t say where it is.', $name ) );
			}
			// Remember it now: a retry after a later failure must not upload it again.
			$sent[ $src ]  = $media[ $src ];
			$data          = Jobs::get( $job->id )->data;
			$data['media'] = $sent;
			Jobs::update( $job->id, array( 'data' => $data ) );
			Events::log( 'image_sent', sprintf( 'Uploaded image %s to the client\'s Media Library.', $name ), (int) $site->id, $job->id );
		}
		return $media;
	}

	/**
	 * A web image, downloaded without letting its address reach our own network.
	 *
	 * WordPress's safe download checks only the first address a host name
	 * resolves to, and the download then resolves the name again. So every
	 * address of the host is checked here, the connection is pinned to a
	 * checked one, and redirects are followed by hand, each checked the same way.
	 *
	 * @return array{0:string, 1:string} [bytes, file name]
	 */
	private static function download( string $src ): array {
		if ( ! MarkdownToPost::is_web_address( $src ) ) {
			throw new SendException( sprintf( 'Image %s has neither an uploaded file nor a web address.', rawurldecode( $src ) ) );
		}
		$url = $src;
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			$ip  = self::public_address( $url ); // throws if the host is (or resolves to) a private address
			$pin = static function ( $handle, $args, $request_url ) use ( $url, $ip ) {
				$p = wp_parse_url( $request_url );
				if ( $request_url === $url && ! empty( $p['host'] ) && null !== $ip && defined( 'CURLOPT_RESOLVE' ) ) {
					$port = (int) ( $p['port'] ?? ( 'https' === strtolower( (string) $p['scheme'] ) ? 443 : 80 ) );
					curl_setopt( $handle, CURLOPT_RESOLVE, array( $p['host'] . ':' . $port . ':' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			};
			add_action( 'http_api_curl', $pin, 10, 3 );
			$r = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => self::DOWNLOAD_TIMEOUT,
					'redirection'         => 0,
					'limit_response_size' => Assets::MAX_IMAGE_BYTES + 1,
					'user-agent'          => 'ContentPublisher/' . CPUB_PUBLISHER_VERSION,
				)
			);
			remove_action( 'http_api_curl', $pin, 10 );
			if ( is_wp_error( $r ) ) {
				throw new SendException( sprintf( 'Couldn\'t download image %s: %s', $src, $r->get_error_message() ), ! str_contains( $r->get_error_message(), 'valid URL' ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$next = (string) wp_remote_retrieve_header( $r, 'location' );
				$url  = \WP_Http::make_absolute_url( $next, $url );
				if ( '' === $next || ! MarkdownToPost::is_web_address( $url ) ) {
					throw new SendException( sprintf( 'Couldn\'t download image %s: it redirects to an unusable address.', $src ) );
				}
				continue;
			}
			$bytes = (string) wp_remote_retrieve_body( $r );
			if ( 200 !== $code ) {
				throw new SendException( sprintf( 'Couldn\'t download image %s (HTTP %d).', $src, $code ), $code >= 500 || 429 === $code );
			}
			if ( strlen( $bytes ) > Assets::MAX_IMAGE_BYTES ) {
				throw new SendException( sprintf( 'Image %s is larger than 10 MB.', $src ) );
			}
			return array( $bytes, basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		}
		throw new SendException( sprintf( 'Couldn\'t download image %s: too many redirects.', $src ) );
	}

	/**
	 * The address to connect to for this URL's host, after checking that every
	 * address it resolves to is public. Null when the host is let through by the
	 * http_request_host_is_external filter (as WordPress itself allows).
	 *
	 * @throws SendException
	 */
	public static function public_address( string $url ): ?string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = trim( $host, '[]' );
		if ( '' === $host ) {
			throw new SendException( sprintf( 'Image address %s has no host.', $url ) );
		}
		if ( apply_filters( 'http_request_host_is_external', false, $host, $url ) ) {
			return null;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$ips = (array) ( @gethostbynamel( $host ) ?: array() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( (array) ( @dns_get_record( $host, DNS_AAAA ) ?: array() ) as $rec ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( ! empty( $rec['ipv6'] ) ) {
					$ips[] = $rec['ipv6'];
				}
			}
		}
		if ( ! $ips ) {
			throw new SendException( sprintf( 'Couldn\'t download image %s: its host name doesn\'t resolve.', $url ), true );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				throw new SendException( sprintf( 'Couldn\'t download image %s: it points to a private or local network address, which is never fetched.', $url ) );
			}
		}
		return $ips[0];
	}

	public static function is_public_ip( string $ip ): bool {
		// IPv4 written as IPv6 (::ffff:10.0.0.1): judge the IPv4 part.
		if ( preg_match( '/^(?:0*:)*:?ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m ) || preg_match( '/^::(\d+\.\d+\.\d+\.\d+)$/', $ip, $m ) ) {
			$ip = $m[1];
		}
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | ( defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ? FILTER_FLAG_GLOBAL_RANGE : 0 );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
			return false;
		}
		// Shared address space (carrier NAT) and IPv6 link-local / unique-local, in case the flags miss them.
		if ( preg_match( '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip ) || preg_match( '/^(fe[89ab]|f[cd])/i', $ip ) ) {
			return false;
		}
		return true;
	}

	/** A plain file name for the client's Media Library, with the extension the content has. */
	private static function file_name( string $name, string $ext ): string {
		$stem = sanitize_file_name( pathinfo( rawurldecode( $name ), PATHINFO_FILENAME ) );
		$stem = trim( (string) preg_replace( '/[^A-Za-z0-9._-]/', '-', $stem ), '-.' );
		return ( '' !== $stem ? mb_substr( $stem, 0, 80 ) : 'image' ) . '.' . $ext;
	}

	/**
	 * What sending this post does on the client site: a draft; published now or
	 * scheduled (the site's "Send as" is "publish" and the site allows it); or,
	 * for a post that was live there when we last looked, an update that keeps
	 * it as it is (keep_live: never puts back a post the client took down).
	 *
	 * @return array{status:string, date_gmt:?string, label:string, note:string, keep_live:bool}
	 */
	public static function target( object $job, object $site ): array {
		$name      = $site->name ?: $site->url;
		$settings  = SiteSettings::get( $site );
		$allowed   = ! empty( $site->can_publish );
		$live      = $allowed && $job->remote_post_id && in_array( (string) $job->remote_post_status, self::LIVE, true );
		$scheduled = $live && 'future' === (string) $job->remote_post_status;
		$at        = (string) ( $job->data['publish_at'] ?? '' );
		$ts        = '' !== $at ? strtotime( $at ) : false;
		$when      = $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
		if ( ( 'publish' === $settings['send_as'] || $live ) && $allowed ) {
			if ( $ts && $ts > time() + 60 ) {
				return array( 'status' => 'publish', 'date_gmt' => gmdate( 'Y-m-d\TH:i:s', $ts ), 'label' => sprintf( 'Schedule on %s for %s', $name, $when ), 'note' => '', 'keep_live' => $live );
			}
			if ( $live ) {
				$label = $scheduled ? sprintf( 'Update the scheduled post on %s (it keeps its date)', $name ) : sprintf( 'Update the published post on %s', $name );
				return array( 'status' => 'publish', 'date_gmt' => null, 'label' => $label, 'note' => 'If the site has taken it down meanwhile, it is updated as a draft instead.', 'keep_live' => true );
			}
			$note = ! empty( $job->data['taken_down'] ) ? sprintf( '%s took this post down on %s; approving publishes it again.', $name, wp_date( get_option( 'date_format' ), (int) strtotime( (string) $job->data['taken_down'] ) ) ) : '';
			return array( 'status' => 'publish', 'date_gmt' => null, 'label' => sprintf( 'Publish on %s now', $name ), 'note' => $note, 'keep_live' => false );
		}
		$note = 'publish' === $settings['send_as'] ? sprintf( '%s hasn\'t allowed direct publishing, so this goes as a draft for its editors.', $name ) : '';
		if ( $job->remote_post_id && ! $live ) {
			return array( 'status' => 'draft', 'date_gmt' => null, 'label' => sprintf( 'Update the draft on %s', $name ), 'note' => $note ?: 'Changes made to it on the site are replaced.', 'keep_live' => false );
		}
		return array( 'status' => 'draft', 'date_gmt' => null, 'label' => sprintf( 'Send to %s as a draft', $name ), 'note' => $note, 'keep_live' => false );
	}

	/**
	 * The target to send with: what the reviewer approved, never more. A post
	 * approved as a draft goes as a draft even if the site's settings changed
	 * since; one approved to publish goes as a draft if publishing is no
	 * longer allowed.
	 */
	public static function send_target( object $job, object $site ): array {
		$now      = self::target( $job, $site );
		$approved = $job->data['approved_target'] ?? null;
		if ( ! is_array( $approved ) ) {
			return $now; // approved before 0.6.0
		}
		if ( 'publish' !== ( $approved['status'] ?? '' ) || 'publish' !== $now['status'] ) {
			return array( 'status' => 'draft', 'date_gmt' => null, 'label' => $now['label'], 'note' => $now['note'], 'keep_live' => false );
		}
		return array(
			'status'    => 'publish',
			'date_gmt'  => $approved['date_gmt'] ?? null,
			'label'     => (string) ( $approved['label'] ?? $now['label'] ),
			'note'      => '',
			'keep_live' => ! empty( $approved['keep_live'] ),
		);
	}

	/** Create the post (or get back the one a lost reply already created), attach its images. */
	private static function create_post( object $job, object $site, array $media ): array {
		$post = MarkdownToPost::convert( (string) $job->markdown, array( 'format' => 'blocks', 'media' => $media ) );
		if ( $post['errors'] ) {
			throw new SendException( 'The approved post has problems to fix first: ' . $post['errors'][0]['message'] );
		}
		$terms  = self::terms( $job, $site );
		$target = self::send_target( $job, $site );
		$body   = array(
			'title'      => $post['title'],
			'content'    => $post['content'],
			// A correction to a post that was live never creates or restores a live
			// one: if the site took it down (or deleted it) meanwhile, it goes as a draft.
			'status'     => $target['keep_live'] ? 'draft' : $target['status'],
			'categories' => $terms['categories'],
			'tags'       => $terms['tags'],
		);
		if ( $target['date_gmt'] ) {
			$body['date_gmt'] = $target['date_gmt'];
		}
		$settings = SiteSettings::get( $site );
		if ( ! empty( $settings['featured_image'] ) && $media ) {
			$first                  = reset( $media );
			$body['featured_media'] = (int) $first['id'];
		}
		if ( ! $body['categories'] ) {
			unset( $body['categories'] ); // the site's own default applies
		}
		$r = ConnectorClient::request( $site, 'POST', '/wp/v2/posts', array( 'json' => $body, 'headers' => array( 'X-CPub-Idempotency-Key' => self::post_key( $job ) ) ) );
		self::expect( $r, array( 200, 201 ), 'Creating the draft' );
		$d        = (array) $r['data'];
		$existing = '1' === (string) ( $r['headers']['x-cpub-existing'] ?? '' );
		if ( $existing && 'trash' === ( $d['status'] ?? '' ) ) {
			// The client binned the draft an earlier attempt made: send a new one.
			$data                    = Jobs::get( $job->id )->data;
			$data['send_generation'] = (int) ( $data['send_generation'] ?? 0 ) + 1;
			Jobs::update( $job->id, array( 'data' => $data ) );
			$job = Jobs::get( $job->id );
			$r   = ConnectorClient::request( $site, 'POST', '/wp/v2/posts', array( 'json' => $body, 'headers' => array( 'X-CPub-Idempotency-Key' => self::post_key( $job ) ) ) );
			self::expect( $r, array( 200, 201 ), 'Creating the draft' );
			$d        = (array) $r['data'];
			$existing = '1' === (string) ( $r['headers']['x-cpub-existing'] ?? '' );
		}
		$now_live = in_array( $d['status'] ?? '', self::LIVE, true );
		if ( $existing && $now_live && ! empty( $site->can_publish ) ) {
			// Correcting a post that is live on the site now: it stays as it is,
			// scheduled posts keep their date unless the reviewer set a new one.
			$body['status'] = 'publish' === $target['status'] && ! empty( $target['date_gmt'] ) ? 'publish' : (string) $d['status'];
			if ( 'publish' !== $body['status'] || empty( $target['date_gmt'] ) ) {
				unset( $body['date_gmt'] );
			}
		} elseif ( $existing && ! in_array( $d['status'] ?? '', array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			throw new SendException( sprintf( 'An earlier version of this post was already sent and the client has %s it (post %d), so it can\'t be replaced from here. Make the changes on the client site.', 'publish' === ( $d['status'] ?? '' ) ? 'published' : 'moved on with', (int) ( $d['id'] ?? 0 ) ) );
		}
		if ( $existing ) {
			// An earlier attempt made it; make sure it says what was approved.
			$u = ConnectorClient::request( $site, 'POST', '/wp/v2/posts/' . (int) $d['id'], array( 'json' => $body ) );
			self::expect( $u, array( 200 ), 'Updating the draft an earlier attempt created' );
			$d = (array) $u['data'];
		}
		if ( ! is_int( $d['id'] ?? null ) || $d['id'] <= 0 ) {
			throw new SendException( 'The client site said it created the draft but didn\'t say which post it is.', true );
		}
		$attach_failed = array();
		foreach ( $media as $m ) {
			$a = ConnectorClient::request( $site, 'POST', '/wp/v2/media/' . (int) $m['id'], array( 'json' => array( 'post' => (int) $d['id'] ) ) );
			if ( is_wp_error( $a ) || 200 !== $a['status'] ) {
				$attach_failed[] = (int) $m['id'];
			}
			Jobs::update( $job->id, array() ); // still working: keeps the stale-send recovery away
		}
		return array(
			'id'            => (int) ( $d['id'] ?? 0 ),
			'status'        => (string) ( $d['status'] ?? '' ),
			'link'          => esc_url_raw( (string) ( $d['link'] ?? '' ) ),
			'existing'      => $existing,
			'tags_missing'  => $terms['tags_missing'],
			'category'      => $terms['category_name'],
			'attach_failed' => $attach_failed,
		);
	}

	/**
	 * Category and tags: the site's default category, or the post's "Category:"
	 * line when it names one of the site's categories; "Tags:" only for tags
	 * that exist there (the connection may read tags, not create them).
	 *
	 * @return array{categories:int[], category_name:string, tags:int[], tags_missing:string[]}
	 */
	private static function terms( object $job, object $site ): array {
		$settings = SiteSettings::get( $site );
		$out      = array( 'categories' => array(), 'category_name' => '', 'tags' => array(), 'tags_missing' => array() );
		if ( $settings['default_category'] ) {
			$out['categories']    = array( $settings['default_category']['id'] );
			$out['category_name'] = $settings['default_category']['name'];
		}
		$lines = self::metadata( $job );
		if ( $settings['use_post_category'] && $lines['categories'] ) {
			$cats = SiteSettings::categories( $site );
			if ( is_wp_error( $cats ) ) {
				throw new SendException( 'Reading the client\'s categories: ' . $cats->get_error_message(), true );
			}
			foreach ( $lines['categories'] as $want ) {
				foreach ( $cats as $c ) {
					if ( mb_strtolower( $c['name'] ) === mb_strtolower( $want ) ) {
						$out['categories']    = array( $c['id'] );
						$out['category_name'] = $c['name'];
						break 2;
					}
				}
			}
		}
		$wanted = self::wanted_tags( $job );
		if ( $wanted ) {
			$have   = self::tags( $site, $wanted );
			$create = self::can_create_tags( $site );
			foreach ( $wanted as $want ) {
				$id = $have[ mb_strtolower( $want ) ] ?? ( $create ? self::create_tag( $site, $want ) : null );
				if ( $id ) {
					$out['tags'][] = $id;
				} else {
					$out['tags_missing'][] = $want;
				}
			}
			$out['tags'] = array_values( array_unique( $out['tags'] ) );
		}
		return $out;
	}

	/** The tags chosen in the editor, or else those on the post's "Tags:" line. @return string[] */
	public static function wanted_tags( object $job ): array {
		if ( isset( $job->data['tags'] ) && is_array( $job->data['tags'] ) ) {
			return array_values( array_filter( array_map( 'strval', $job->data['tags'] ), 'strlen' ) );
		}
		return self::metadata( $job )['tags'];
	}

	/** Whether this connection may add tags to the site (Connector 0.5.0+, approved with terms:write). */
	public static function can_create_tags( object $site ): bool {
		return in_array( 'terms:write', Credentials::scopes( (int) $site->id ), true );
	}

	/** A new tag on the client site; its id, or null if the site refused. */
	private static function create_tag( object $site, string $name ): ?int {
		$r = ConnectorClient::request( $site, 'POST', '/wp/v2/tags', array( 'json' => array( 'name' => $name ) ) );
		if ( ! is_wp_error( $r ) && in_array( $r['status'], array( 200, 201 ), true ) && isset( $r['data']['id'] ) ) {
			return (int) $r['data']['id'];
		}
		if ( ! is_wp_error( $r ) && 400 === $r['status'] && 'term_exists' === ( $r['data']['code'] ?? '' ) ) {
			return (int) ( $r['data']['data']['term_id'] ?? 0 ) ?: null; // made meanwhile, or differs only in case
		}
		if ( is_wp_error( $r ) || $r['status'] >= 500 || 429 === $r['status'] ) {
			throw new SendException( sprintf( 'Adding the tag "%s" to the client site: %s', $name, Http::describe( $r ) ), true );
		}
		return null;
	}

	/** "Category: …" and "Tags: a, b" from the lines left out of the post. @return array{categories:string[], tags:string[]} */
	public static function metadata( object $job ): array {
		$out = array( 'categories' => array(), 'tags' => array() );
		foreach ( (array) ( $job->data['removed'] ?? array() ) as $r ) {
			$text = trim( (string) ( $r['text'] ?? '' ) );
			if ( preg_match( '/^categor(?:y|ies)\s*:\s*(.+)$/iu', $text, $m ) ) {
				$out['categories'] = array_merge( $out['categories'], self::split( $m[1] ) );
			} elseif ( preg_match( '/^(?:tags?|keywords)\s*:\s*(.+)$/iu', $text, $m ) ) {
				$out['tags'] = array_merge( $out['tags'], self::split( $m[1] ) );
			}
		}
		return $out;
	}

	private static function split( string $list ): array {
		return array_values( array_filter( array_map( fn( $t ) => trim( $t, " \t.#" ), preg_split( '/[,;|]/u', $list ) ?: array() ), 'strlen' ) );
	}

	/** @return array<string,int> lowercase name => id, for the wanted names that exist on the site */
	private static function tags( object $site, array $wanted ): array {
		$out = array();
		foreach ( array_unique( array_map( 'mb_strtolower', $wanted ) ) as $want ) {
			$r = ConnectorClient::request( $site, 'GET', '/wp/v2/tags', array( 'query' => array( 'search' => $want, 'per_page' => 100, '_fields' => 'id,name' ) ) );
			self::expect( $r, array( 200 ), 'Reading the client\'s tags' );
			foreach ( (array) $r['data'] as $t ) {
				$name = is_array( $t ) ? mb_strtolower( html_entity_decode( (string) ( $t['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) : '';
				if ( $name === $want && isset( $t['id'] ) ) {
					$out[ $name ] = (int) $t['id'];
				}
			}
		}
		return $out;
	}

	/** Throw a SendException unless the response has one of the expected statuses. */
	private static function expect( $r, array $ok, string $doing ): void {
		if ( ! is_wp_error( $r ) && in_array( $r['status'], $ok, true ) && is_array( $r['data'] ) ) {
			return;
		}
		if ( is_wp_error( $r ) ) {
			$permanent = in_array( $r->get_error_code(), array( 'cpub_not_connected', 'cpub_disconnected', 'cpub_vault' ), true );
			throw new SendException( $doing . ': ' . $r->get_error_message(), ! $permanent );
		}
		$status    = $r['status'];
		$transient = $status >= 500 || in_array( $status, array( 408, 425, 429 ), true );
		$hint      = 403 === $status ? ' The client site\'s Connector refused it.' : ( 401 === $status ? ' The client site no longer accepts our access; check its Connector status page.' : '' );
		throw new SendException( $doing . ' failed: ' . Http::describe( $r ) . '.' . $hint, $transient );
	}

	private static function done( object $job, object $site, array $post, array $media ): void {
		$data         = $job->data;
		$data['sent'] = array(
			'at'            => gmdate( 'c' ),
			'edit_url'      => $site->url . '/wp-admin/post.php?post=' . $post['id'] . '&action=edit',
			'preview_url'   => '' !== $post['link'] ? add_query_arg( 'preview', 'true', $post['link'] ) : '',
			'link'          => $post['link'],
			'category'      => $post['category'],
			'tags_missing'  => $post['tags_missing'],
			'attach_failed' => $post['attach_failed'],
			'images'        => count( $media ),
		);
		$data['send_retries'] = 0;
		$data['send_cut_off'] = 0;
		$live                 = 'publish' === $post['status'];
		if ( $live ) {
			$data['published_at']   = $data['published_at'] ?? gmdate( 'c' );
			$data['published_link'] = $post['link'];
			unset( $data['taken_down'], $data['client_deleted'] );
			if ( Assets::for_job( $job->id ) ) {
				// Files we still hold (or new ones from a correction): keep them another retention period.
				$data['retain_from'] = gmdate( 'c' );
				unset( $data['files_deleted'] );
			}
		}
		$ok = Jobs::update(
			$job->id,
			array( 'status' => $live ? Jobs::PUBLISHED : Jobs::SENT, 'remote_post_id' => $post['id'], 'remote_post_status' => $post['status'], 'data' => $data, 'last_error' => null ),
			null,
			array( Jobs::SENDING )
		);
		if ( ! $ok ) {
			// The job moved on meanwhile (recovered as cut off, or reopened). Keep the draft's
			// details so the next send updates this same draft; say what happened.
			Jobs::update( $job->id, array( 'remote_post_id' => $post['id'], 'remote_post_status' => $post['status'] ) );
			Events::log( 'job_sent', sprintf( 'The draft reached %s (post %d), but the post had changed state here meanwhile; sending it again updates that draft.', $site->name ?: $site->url, $post['id'] ), (int) $site->id, $job->id );
			return;
		}
		$notes = array();
		if ( $post['existing'] ) {
			$notes[] = 'An earlier attempt had already created it; that draft was used.';
		}
		if ( $post['tags_missing'] ) {
			$notes[] = 'Tags not on the site (not added): ' . implode( ', ', $post['tags_missing'] ) . '.';
		}
		if ( $post['attach_failed'] ) {
			$notes[] = count( $post['attach_failed'] ) . ' image(s) could not be attached to the draft (they are in the Media Library and in the post).';
		}
		$how = 'publish' === $post['status'] ? ( $post['existing'] ? 'Published (updated) on %s' : 'Published on %s' ) : ( 'future' === $post['status'] ? 'Scheduled on %s' : 'Sent to %s as a draft' );
		if ( $post['existing'] && 'publish' === $post['status'] ) {
			$notes = array_values( array_filter( $notes, fn( $n ) => ! str_starts_with( $n, 'An earlier attempt' ) ) );
		}
		Events::log( 'publish' === $post['status'] ? 'job_published' : 'job_sent', trim( sprintf( $how . ' (post %d, %d image(s)). %s', $site->name ?: $site->url, $post['id'], count( $media ), implode( ' ', $notes ) ) ), (int) $site->id, $job->id );
	}

	private static function failed( object $job, SendException $e ): void {
		$fresh = Jobs::get( $job->id ) ?? $job;
		$tries = (int) ( $fresh->data['send_retries'] ?? 0 );
		$data  = $fresh->data;
		if ( $e->transient && $tries < count( self::RETRY_DELAYS ) ) {
			$data['send_retries'] = $tries + 1;
			Jobs::update( $job->id, array( 'status' => Jobs::APPROVED, 'last_error' => $e->getMessage(), 'data' => $data ), null, array( Jobs::SENDING ) );
			Events::log( 'send_retry', 'Sending hit a temporary problem; trying again in ' . human_time_diff( 0, self::RETRY_DELAYS[ $tries ] ) . '. ' . $e->getMessage(), (int) $job->site_id, $job->id );
			self::queue( $job->id, self::RETRY_DELAYS[ $tries ] );
			return;
		}
		$data['send_retries'] = 0;
		Jobs::update( $job->id, array( 'status' => Jobs::SEND_FAILED, 'last_error' => mb_substr( $e->getMessage(), 0, 2000 ), 'data' => $data ), null, array( Jobs::SENDING ) );
		Events::log( 'send_failed', $e->getMessage(), (int) $job->site_id, $job->id );
	}

	/** Staff: try a failed send again. */
	public static function retry( object $job ): bool {
		// A failed send, or a sent draft the client has since deleted.
		$from = ! empty( $job->data['client_deleted'] ) ? array( Jobs::SEND_FAILED, Jobs::SENT ) : array( Jobs::SEND_FAILED );
		$data = $job->data;
		unset( $data['client_deleted'] );
		$data['send_retries'] = 0;
		if ( ! Jobs::update( $job->id, array( 'status' => Jobs::APPROVED, 'last_error' => null, 'data' => $data ), null, $from ) ) {
			return false;
		}
		as_unschedule_all_actions( self::HOOK, array( $job->id ), self::GROUP );
		Events::log( 'send_retried', 'Sending again.', (int) $job->site_id, $job->id );
		self::queue( $job->id );
		return true;
	}

	// ------------------------------------------------------------ after sending

	/** Daily, per site: which sent drafts the client has published since. */
	public static function check_site( $site_id ): void {
		$site = Sites::get( (int) $site_id );
		if ( ! $site || Sites::CONNECTED !== $site->status ) {
			return;
		}
		// List them all first: checking moves published ones out of "sent", which
		// would shift the pages under a loop that checked as it went.
		$ids = array();
		for ( $page = 1; $page <= 50; $page++ ) {
			[ $jobs, $total ] = Jobs::query( array( 'site_id' => (int) $site_id, 'status' => array( Jobs::SENT, Jobs::PUBLISHED ), 'per_page' => 200, 'page' => $page, 'orderby' => 'created_at', 'order' => 'asc' ) );
			foreach ( $jobs as $job ) {
				// Published posts: those that went live in the last WATCH_DAYS, which is
				// when corrections happen (and whether the site took one down matters).
				$live_at = strtotime( (string) ( $job->data['published_at'] ?? '' ) );
				if ( Jobs::SENT === $job->status || ( $live_at && $live_at > time() - self::WATCH_DAYS * DAY_IN_SECONDS ) ) {
					$ids[] = (int) $job->id;
				}
			}
			if ( $page * 200 >= $total ) {
				break;
			}
		}
		foreach ( $ids as $id ) {
			$job = Jobs::get( $id );
			if ( $job ) {
				self::check( $job );
			}
		}
		self::purge_files( (int) $site_id );
	}

	/**
	 * A post that is live on the client site: has the site taken it down or
	 * deleted it since? Then it is shown as such, and a correction won't put
	 * it back (Sender::target).
	 */
	private static function check_published( object $job, object $site ): string {
		$r = ConnectorClient::request( $site, 'GET', '/wp/v2/posts/' . (int) $job->remote_post_id, array( 'query' => array( 'context' => 'edit', '_fields' => 'id,status,link' ) ) );
		if ( is_wp_error( $r ) ) {
			return 'error';
		}
		$gone   = 404 === $r['status'] || 410 === $r['status'];
		$status = $gone ? 'trash' : (string) ( $r['data']['status'] ?? '' );
		if ( ! $gone && 200 !== $r['status'] ) {
			return 'error';
		}
		if ( 'publish' === $status ) {
			return 'publish';
		}
		$data               = $job->data;
		$data['taken_down'] = gmdate( 'c' );
		if ( 'trash' === $status ) {
			$data['client_deleted'] = gmdate( 'c' );
		}
		Jobs::update( $job->id, array( 'status' => Jobs::SENT, 'remote_post_status' => mb_substr( $status, 0, 20 ), 'data' => $data ), null, array( Jobs::PUBLISHED ) );
		Events::log( 'client_unpublished', 'trash' === $status ? 'The post was deleted on the client site.' : sprintf( 'The client site took the post down (it is now %s there).', 'future' === $status ? 'scheduled' : $status ), (int) $site->id, $job->id );
		return 'trash' === $status ? 'deleted' : 'draft';
	}

	/**
	 * Upgrading to 0.6.0: posts published before it had their files deleted at
	 * once, without the marks corrections now rely on. Mark them.
	 */
	public static function mark_legacy_deleted_files(): int {
		$n = 0;
		for ( $page = 1; $page <= 500; $page++ ) {
			[ $jobs, $total ] = Jobs::query( array( 'status' => Jobs::PUBLISHED, 'per_page' => 200, 'page' => $page, 'orderby' => 'created_at', 'order' => 'asc' ) );
			foreach ( $jobs as $job ) {
				if ( ! empty( $job->data['files_deleted'] ) || Assets::for_job( $job->id ) ) {
					continue;
				}
				$data                  = $job->data;
				$data['files_deleted'] = (string) ( $data['published_at'] ?? gmdate( 'c' ) );
				foreach ( (array) ( $data['media'] ?? array() ) as $src => $m ) {
					if ( ! MarkdownToPost::is_web_address( (string) $src ) ) {
						$data['media'][ $src ]['purged'] = true;
					}
				}
				Jobs::update( $job->id, array( 'data' => $data ) );
				++$n;
			}
			if ( $page * 200 >= $total ) {
				break;
			}
		}
		return $n;
	}

	/**
	 * Retention: our copies of a live post's source file and images are deleted
	 * RETENTION_DAYS after it went live. The post's text and history stay.
	 */
	public static function purge_files( int $site_id ): int {
		$cutoff = time() - self::RETENTION_DAYS * DAY_IN_SECONDS;
		$due    = array();
		for ( $page = 1; $page <= 50; $page++ ) {
			[ $jobs, $total ] = Jobs::query( array( 'site_id' => $site_id, 'status' => Jobs::PUBLISHED, 'per_page' => 200, 'page' => $page, 'orderby' => 'created_at', 'order' => 'asc' ) );
			foreach ( $jobs as $job ) {
				$at = strtotime( (string) ( $job->data['retain_from'] ?? $job->data['published_at'] ?? '' ) );
				if ( empty( $job->data['files_deleted'] ) && $at && $at < $cutoff ) {
					$due[] = (int) $job->id;
				}
			}
			if ( $page * 200 >= $total ) {
				break;
			}
		}
		foreach ( $due as $id ) {
			$job = Jobs::get( $id );
			if ( ! $job || Jobs::PUBLISHED !== $job->status ) {
				continue;
			}
			Assets::delete_for_job( $job->id );
			if ( ! empty( $job->data['dir'] ) ) {
				Storage::delete_dir( (string) $job->data['dir'] );
			}
			$data                  = $job->data;
			$data['files_deleted'] = gmdate( 'c' );
			foreach ( (array) ( $data['media'] ?? array() ) as $src => $m ) {
				if ( ! MarkdownToPost::is_web_address( (string) $src ) ) {
					$data['media'][ $src ]['purged'] = true; // the client has it; we no longer do
				}
			}
			Jobs::update( $job->id, array( 'data' => $data ) );
			Events::log( 'files_deleted', sprintf( 'Our copies of the source file and images were deleted, %d days after the post went live.', self::RETENTION_DAYS ), $site_id, $job->id );
		}
		return count( $due );
	}

	/**
	 * Take a post the agency published off the client site (back to draft
	 * there). Only when the site allows direct publishing.
	 *
	 * @return true|\WP_Error
	 */
	public static function unpublish( object $job ) {
		$site = Sites::get( (int) $job->site_id );
		if ( ! $site || empty( $site->can_publish ) ) {
			return new \WP_Error( 'cpub_not_allowed', 'The client site hasn\'t allowed direct publishing, so only its own editors can unpublish the post.', array( 'status' => 403 ) );
		}
		if ( ! $job->remote_post_id || ! in_array( $job->status, array( Jobs::PUBLISHED, Jobs::SENT ), true ) || ! in_array( (string) $job->remote_post_status, self::LIVE, true ) ) {
			return new \WP_Error( 'cpub_conflict', 'Only a published or scheduled post can be unpublished.', array( 'status' => 409 ) );
		}
		$r = ConnectorClient::request( $site, 'POST', '/wp/v2/posts/' . (int) $job->remote_post_id, array( 'json' => array( 'status' => 'draft' ) ) );
		if ( is_wp_error( $r ) || 200 !== $r['status'] ) {
			return new \WP_Error( 'cpub_unpublish', 'Couldn\'t unpublish it: ' . Http::describe( $r ) . '.', array( 'status' => 502 ) );
		}
		Jobs::update( $job->id, array( 'status' => Jobs::SENT, 'remote_post_status' => 'draft' ), null, array( Jobs::PUBLISHED, Jobs::SENT ) );
		Events::log( 'unpublished', sprintf( 'Unpublished: the post is a draft on %s again.', $site->name ?: $site->url ), (int) $site->id, $job->id );
		return true;
	}

	/**
	 * Read the draft's state on the client site. Once published, our copies of
	 * the source file and images are deleted (retention decision).
	 *
	 * @return string what was found: draft, publish, deleted, error
	 */
	public static function check( object $job ): string {
		$site = Sites::get( (int) $job->site_id );
		if ( ! $site || ! in_array( $job->status, array( Jobs::SENT, Jobs::PUBLISHED ), true ) || ! $job->remote_post_id ) {
			return 'error';
		}
		if ( Jobs::PUBLISHED === $job->status ) {
			return self::check_published( $job, $site );
		}
		$r = ConnectorClient::request( $site, 'GET', '/wp/v2/posts/' . (int) $job->remote_post_id, array( 'query' => array( 'context' => 'edit', '_fields' => 'id,status,link' ) ) );
		if ( is_wp_error( $r ) ) {
			return 'error';
		}
		$data = $job->data;
		if ( 404 === $r['status'] || 410 === $r['status'] || ( 200 === $r['status'] && 'trash' === ( $r['data']['status'] ?? '' ) ) ) {
			if ( empty( $data['client_deleted'] ) ) {
				$data['client_deleted'] = gmdate( 'c' );
				Jobs::update( $job->id, array( 'data' => $data ) );
				Events::log( 'client_deleted', 'The draft was deleted on the client site. Our files are kept, in case it needs sending again.', (int) $job->site_id, $job->id );
			}
			return 'deleted';
		}
		if ( 200 !== $r['status'] || ! is_array( $r['data'] ) ) {
			return 'error';
		}
		$status = (string) ( $r['data']['status'] ?? '' );
		if ( ! empty( $data['client_deleted'] ) ) {
			unset( $data['client_deleted'] ); // restored from the bin
			Jobs::update( $job->id, array( 'data' => $data ) );
			$job = Jobs::get( $job->id );
		}
		if ( 'publish' === $status ) { // published for everyone (private or scheduled isn't yet)
			$data['published_at']   = gmdate( 'c' );
			$data['published_link'] = esc_url_raw( (string) ( $r['data']['link'] ?? '' ) );
			Jobs::update( $job->id, array( 'status' => Jobs::PUBLISHED, 'remote_post_status' => $status, 'data' => $data ), null, array( Jobs::SENT ) );
			Events::log( 'client_published', sprintf( 'The post is live on the client site. Our copies of the source file and images are kept for %d days, then deleted.', self::RETENTION_DAYS ), (int) $job->site_id, $job->id );
			return 'publish';
		}
		if ( $status !== $job->remote_post_status ) {
			Jobs::update( $job->id, array( 'remote_post_status' => mb_substr( $status, 0, 20 ) ), null, array( Jobs::SENT ) );
		}
		return 'draft';
	}
}
