<?php
/**
 * The "Agency Publisher" user that tokens act as. It is an Author, so it can
 * only touch its own posts and uploads, and it can't log in to wp-admin.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

use CPub\Connector\OAuth\Agency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PublisherUser {

	public const OPTION = 'cpub_connector_publisher_user_id';
	public const META   = 'cpub_connector_publisher';
	public const ROLE   = 'author';

	/** Capabilities an Author doesn't have. If the user gains any of them, tokens stop working. */
	public const FORBIDDEN_CAPS = array(
		'edit_others_posts',
		'edit_pages',
		'manage_categories',
		'moderate_comments',
		'manage_options',
		'edit_theme_options',
		'install_plugins',
		'activate_plugins',
		'edit_plugins',
		'create_users',
		'edit_users',
		'promote_users',
		'delete_users',
		'unfiltered_html',
		'unfiltered_upload',
	);

	public static function register(): void {
		add_filter( 'authenticate', array( self::class, 'block_login' ), 999, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'block_app_passwords' ), 10, 2 );
	}

	public static function get(): ?\WP_User {
		$id   = (int) get_option( self::OPTION, 0 );
		$user = $id ? get_userdata( $id ) : false;
		if ( $user && get_user_meta( $user->ID, self::META, true ) ) {
			return $user;
		}
		// After the plugin was deleted and reinstalled: reuse the user it created before.
		$found = get_users( array( 'meta_key' => self::META, 'meta_value' => '1', 'number' => 1, 'orderby' => 'ID', 'fields' => 'all' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $found ) {
			update_option( self::OPTION, $found[0]->ID, false );
			return $found[0];
		}
		return null;
	}

	/** Any account this plugin created for the agency (a reinstall may have left more than one). */
	public static function is_dedicated( int $user_id ): bool {
		return $user_id > 0 && (bool) get_user_meta( $user_id, self::META, true );
	}

	public static function is_publisher( int $user_id ): bool {
		$user = self::get();
		return $user && $user->ID === $user_id;
	}

	/** Creates the user, or puts its role back to Author if someone changed it. */
	public static function ensure(): int {
		$user = self::get();
		if ( $user ) {
			if ( array_values( $user->roles ) !== array( self::ROLE ) ) {
				$user->set_role( self::ROLE );
				ActivityLog::event( null, 'user_role_reset', 'The Agency Publisher user\'s role was set back to Author.', get_current_user_id() );
			}
			wp_update_user( array( 'ID' => $user->ID, 'display_name' => Agency::name() ) );
			return $user->ID;
		}

		$login = 'agency-publisher';
		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = 'agency-publisher-' . $i;
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				// Placeholder address on a reserved domain (RFC 2606): WordPress requires one,
				// and nothing should ever be emailed to this account.
				'user_email'   => $login . '+' . wp_generate_password( 8, false ) . '@example.invalid',
				'display_name' => Agency::name(),
				'nickname'     => Agency::name(),
				'role'         => self::ROLE,
				'description'  => 'Created by the Content Publisher Connector. Posts from ' . Agency::name() . ' are created as this user. It cannot log in.',
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Could not create the Agency Publisher user: ' . $id->get_error_message() );
		}
		update_user_meta( $id, self::META, 1 );
		update_option( self::OPTION, $id, false );
		ActivityLog::event( null, 'user_created', sprintf( 'Created the user "%s" (Author) for posts from %s.', $login, Agency::name() ), get_current_user_id() );
		return $id;
	}

	/** Roles a connection may post as. Never Administrator: see Auth\Sandbox. */
	public const ELIGIBLE_ROLES = array( 'author', 'editor' );

	/**
	 * Whether a connection may sign in as this user: the dedicated Agency
	 * Publisher account while it is an Author, or one of the site's own Authors
	 * or Editors who can write posts and upload images.
	 */
	public static function may_post_as( \WP_User $user ): bool {
		if ( ! $user->exists() ) {
			return false;
		}
		if ( self::is_dedicated( $user->ID ) ) {
			return ! self::too_powerful( $user );
		}
		$roles = array_values( (array) $user->roles );
		return $roles
			&& ! array_diff( $roles, self::ELIGIBLE_ROLES )
			&& ! is_super_admin( $user->ID )
			&& $user->has_cap( 'edit_posts' )
			&& $user->has_cap( 'upload_files' )
			&& ! $user->has_cap( 'manage_options' );
	}

	/** The site's own users a connection may post as, by name. @return \WP_User[] */
	public static function eligible_users(): array {
		$users = get_users( array( 'role__in' => self::ELIGIBLE_ROLES, 'orderby' => 'display_name', 'number' => 500 ) );
		return array_values( array_filter( $users, fn( $u ) => ! self::is_dedicated( $u->ID ) && self::may_post_as( $u ) ) );
	}

	public static function too_powerful( \WP_User $user ): bool {
		foreach ( self::FORBIDDEN_CAPS as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param \WP_User|\WP_Error|null $user */
	public static function block_login( $user, $username ) {
		if ( $user instanceof \WP_User && get_user_meta( $user->ID, self::META, true ) ) {
			return new \WP_Error( 'cpub_no_login', 'This account is used by the Content Publisher Connector and cannot log in.' );
		}
		return $user;
	}

	public static function block_app_passwords( bool $available, $user ): bool {
		return ( $user instanceof \WP_User && get_user_meta( $user->ID, self::META, true ) ) ? false : $available;
	}
}
