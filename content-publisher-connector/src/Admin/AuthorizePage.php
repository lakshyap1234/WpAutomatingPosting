<?php
/**
 * The consent screen: where a site administrator approves or denies the agency.
 *
 * It's a hidden wp-admin page, so WordPress's own login (including any 2FA
 * plugin) protects it, and after logging in the administrator lands back here.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Admin;

use CPub\Connector\ActivityLog;
use CPub\Connector\OAuth\Agency;
use CPub\Connector\OAuth\Entities\UserEntity;
use CPub\Connector\OAuth\GrantContext;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\Psr;
use CPub\Connector\OAuth\Scopes;
use CPub\Connector\OAuth\Server;
use CPub\Connector\PublisherUser;
use CPub\Connector\Vendor\League\OAuth2\Server\Exception\OAuthServerException;
use CPub\Connector\Vendor\League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use CPub\Connector\Vendor\Psr\Http\Message\ResponseInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthorizePage {

	public const SLUG   = 'cpub-connector-authorize';
	public const NONCE  = 'cpub_connector_authorize';
	public const CAP    = 'manage_options';

	private ?AuthorizationRequestInterface $auth_request = null;
	private string $error                               = '';

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	public function add_page(): void {
		// Empty parent: reachable by URL, not shown in the menu.
		$hook = add_submenu_page( '', 'Approve access', 'Approve access', 'read', self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/** Runs before any output, so it can redirect. */
	public function load(): void {
		$GLOBALS['title'] = 'Approve access'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- hidden pages have no title otherwise.
		nocache_headers();

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html( 'Only an administrator of this site can give ' . Agency::name() . ' access. Log in with an administrator account and open the link again.' ), 'Administrator needed', array( 'response' => 403 ) );
		}

		GrantContext::clear();
		$query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		unset( $query['page'] );

		try {
			$server             = Server::authorization_server();
			$this->auth_request = $server->validateAuthorizationRequest( Psr::request( 'GET', self::url(), $query ) );
			if ( empty( $query['code_challenge'] ) || 'S256' !== ( $query['code_challenge_method'] ?? '' ) ) {
				throw new OAuthServerException( 'The request is missing a required parameter.', 3, 'invalid_request', 400, 'PKCE with code_challenge_method=S256 is required', $this->redirect_base() );
			}
		} catch ( OAuthServerException $e ) {
			$this->fail( $e );
			return;
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		check_admin_referer( self::NONCE );
		$approved = isset( $_POST['approve'] );

		try {
			if ( $approved ) {
				$user_id = self::chosen_user( sanitize_text_field( wp_unslash( (string) ( $_POST['post_as'] ?? 'agency' ) ) ) );
				if ( ! $user_id ) {
					$this->error = 'Posts can appear under one of this site\'s Authors or Editors, or under a separate agency account. Choose one of those and approve again. Nothing was changed.';
					return;
				}
				$can_publish = ! empty( $_POST['can_publish'] );
				$scopes      = array_map( fn( $s ) => $s->getIdentifier(), $this->auth_request->getScopes() );
				$grant_id    = Grants::create_pending( Agency::client_id(), $user_id, get_current_user_id(), $scopes, $can_publish );
				GrantContext::set( $grant_id );
				$this->auth_request->setUser( new UserEntity( $user_id ) );
				$this->auth_request->setAuthorizationApproved( true );
				$response = $server->completeAuthorizationRequest( $this->auth_request, Psr::response() );
				$who = get_userdata( $user_id );
				ActivityLog::event( $grant_id, 'approved', sprintf( 'Access approved for: %s. Posts appear under %s. Publishing directly: %s.', implode( ', ', $scopes ), $who ? $who->display_name : '#' . $user_id, $can_publish ? 'allowed' : 'not allowed' ), get_current_user_id() );
			} else {
				ActivityLog::event( null, 'denied', 'Access request denied.', get_current_user_id() );
				$this->auth_request->setUser( new UserEntity( get_current_user_id() ) );
				$this->auth_request->setAuthorizationApproved( false );
				$response = $server->completeAuthorizationRequest( $this->auth_request, Psr::response() );
			}
		} catch ( OAuthServerException $e ) {
			$this->fail( $e );
			return;
		} catch ( \RuntimeException $e ) {
			$this->error = $e->getMessage();
			return;
		} finally {
			GrantContext::clear();
		}
		$this->redirect( $response );
	}

	/**
	 * The user posts will appear under: one of the site's Authors or Editors,
	 * or "agency" for the separate Agency Publisher account. 0 if not allowed.
	 */
	private static function chosen_user( string $choice ): int {
		if ( 'agency' === $choice ) {
			return PublisherUser::ensure();
		}
		$user = ctype_digit( $choice ) ? get_userdata( (int) $choice ) : false;
		if ( ! $user || PublisherUser::is_dedicated( $user->ID ) || ! PublisherUser::may_post_as( $user ) ) {
			return 0;
		}
		return $user->ID;
	}

	/** The client's redirect_uri with its state, for errors found after the client was verified. */
	private function redirect_base(): string {
		$uri = $this->auth_request->getRedirectUri() ?? Agency::config()['redirect_uris'][0];
		return null !== $this->auth_request->getState() ? add_query_arg( 'state', rawurlencode( $this->auth_request->getState() ), $uri ) : $uri;
	}

	/**
	 * Problems with the client or its return address are shown here (sending the
	 * browser to an unverified address would be unsafe); anything else goes back
	 * to the agency as an OAuth error.
	 */
	private function fail( OAuthServerException $e ): void {
		if ( $e->hasRedirect() ) {
			$this->redirect( $e->generateHttpResponse( Psr::response() ) );
		}
		$this->error = 'This approval link isn\'t valid' . ( $e->getHint() ? ': ' . $e->getHint() : '.' ) . ' It may be incomplete, or it did not come from ' . Agency::name() . '. Nothing was changed.';
	}

	private function redirect( ResponseInterface $response ): void {
		// RFC 9207: say who is answering, so the agency can't be tricked into
		// sending this site's code to a different site (mix-up attack).
		$location = add_query_arg( 'iss', rawurlencode( home_url( '/' ) ), $response->getHeaderLine( 'Location' ) );
		// wp_redirect, not wp_safe_redirect: the address was checked against config/agency.php.
		wp_redirect( $location, 302, 'Content Publisher Connector' ); // phpcs:ignore WordPress.Security.SafeRedirect
		if ( ! apply_filters( 'cpub_connector_exit_after_redirect', true ) ) {
			return;
		}
		exit;
	}

	public function render(): void {
		echo '<div class="wrap" id="cpub-authorize" style="max-width:640px">';
		if ( $this->error ) {
			echo '<h1>Access request</h1><div class="notice notice-error inline" id="cpub-authorize-error"><p>' . esc_html( $this->error ) . '</p></div></div>';
			return;
		}
		if ( ! $this->auth_request ) {
			echo '</div>';
			return;
		}

		$agency   = Agency::name();
		$logo     = Agency::logo_url();
		$return   = (string) ( $this->auth_request->getRedirectUri() ?? Agency::config()['redirect_uris'][0] );
		$host     = (string) wp_parse_url( $return, PHP_URL_HOST );
		$loopback = Agency::is_loopback( $return );
		$user     = PublisherUser::get();
		$current  = Grants::active();
		?>
		<div style="display:flex;align-items:center;gap:16px;margin:24px 0 8px">
			<?php if ( $logo ) : ?>
				<img src="<?php echo esc_url( $logo ); ?>" alt="" style="width:56px;height:56px;object-fit:contain">
			<?php else : ?>
				<div aria-hidden="true" style="width:56px;height:56px;border-radius:8px;background:#2271b1;color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:600"><?php echo esc_html( mb_strtoupper( mb_substr( $agency, 0, 1 ) ) ); ?></div>
			<?php endif; ?>
			<h1 style="margin:0"><?php echo esc_html( $agency ); ?> wants access to <?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
		</div>

		<p>If you approve, <?php echo esc_html( $agency ); ?> will be able to:</p>
		<ul style="list-style:disc;padding-left:2em" id="cpub-scopes">
			<?php foreach ( $this->auth_request->getScopes() as $scope ) : ?>
				<li data-scope="<?php echo esc_attr( $scope->getIdentifier() ); ?>"><?php echo esc_html( Scopes::LABELS[ $scope->getIdentifier() ] ?? $scope->getIdentifier() ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p>It will <strong>not</strong> be able to touch posts or images it didn't create, change or delete anything else, change settings, or log in to this dashboard. You can cut off access at any time under Settings &rarr; Content Publisher.</p>

		<?php if ( $current ) : ?>
			<div class="notice notice-info inline"><p>This site is already connected (since <?php echo esc_html( get_date_from_gmt( $current->activated_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>). Approving replaces that connection.</p></div>
		<?php endif; ?>

		<?php if ( $loopback ) : ?>
			<div class="notice notice-warning inline" id="cpub-loopback-warning"><p><strong>Test connection.</strong> After approving, you'll be sent to a test tool running on your own computer (<?php echo esc_html( $host ); ?>), not to <?php echo esc_html( $agency ); ?>'s website. Only approve if you started that test yourself.</p></div>
		<?php else : ?>
			<p class="description">After you choose, you'll return to <strong><?php echo esc_html( $host ); ?></strong>.</p>
		<?php endif; ?>

		<form method="post" style="margin-top:24px">
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php $people = PublisherUser::eligible_users(); ?>
			<p><label for="cpub-post-as"><strong>Posts appear under</strong></label><br>
				<select name="post_as" id="cpub-post-as" style="min-width:320px">
					<?php foreach ( $people as $person ) : ?>
						<option value="<?php echo (int) $person->ID; ?>"><?php echo esc_html( $person->display_name . ' (' . implode( ', ', array_map( 'ucfirst', (array) $person->roles ) ) . ')' ); ?></option>
					<?php endforeach; ?>
					<option value="agency"><?php echo esc_html( $user ? 'A separate account: ' . $user->user_login : 'A separate account, agency-publisher (created now, cannot log in)' ); ?></option>
				</select>
			</p>
			<p class="description" style="margin-top:-8px">Their name is shown as the author. Only Authors and Editors can be chosen<?php echo $people ? '' : ' (this site has none yet: add one under Users to have posts appear under a real person)'; ?>. The agency only ever gets the few permissions listed above, whoever you choose, and only for its own posts.</p>
			<p><label><input type="checkbox" name="can_publish" value="1" id="cpub-can-publish"> <strong>Let <?php echo esc_html( $agency ); ?> publish directly</strong></label><br>
				<span class="description">It can then publish and schedule the posts it creates, and correct or unpublish them later. Without this, everything arrives as a draft for your editors to publish. You can change this later under Settings &rarr; Content Publisher.</span></p>
			<button type="submit" name="approve" value="1" class="button button-primary button-hero" id="cpub-approve">Approve</button>
			<button type="submit" name="deny" value="1" class="button button-hero" id="cpub-deny" style="margin-left:8px">Deny</button>
		</form>
		</div>
		<?php
	}
}
