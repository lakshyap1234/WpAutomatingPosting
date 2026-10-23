<?php
/**
 * Daily check of every connected site: still reachable through WordPress.com,
 * and what the person who approved may do there.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HealthCheck {

	public const HOOK      = 'cpub_publisher_health_check';
	public const SITE_HOOK = 'cpub_publisher_check_site';
	public const GROUP     = 'cpub';
	public const SPACING   = 30; // seconds between sites, so one slow site can't hold up the rest

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run_all' ) );
		add_action( self::SITE_HOOK, array( self::class, 'check_site' ) );
		add_action( 'action_scheduler_init', array( self::class, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
	}

	/** Daily: queue one check per connected site, spaced out. */
	public static function run_all(): void {
		foreach ( array_values( Sites::all( Sites::CONNECTED ) ) as $i => $site ) {
			$args = array( (int) $site->id );
			if ( ! as_has_scheduled_action( self::SITE_HOOK, $args, self::GROUP ) ) {
				as_schedule_single_action( time() + $i * self::SPACING, self::SITE_HOOK, $args, self::GROUP );
			}
		}
	}

	public static function check_site( $site_id ): void {
		$site = Sites::get( (int) $site_id );
		if ( $site && Sites::CONNECTED === $site->status ) {
			self::check( $site );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Reads the site through WordPress.com, and what the person who approved may
	 * do there: that decides whether posts can be published directly, and the
	 * screen warns when they are an administrator (the token has all their rights).
	 *
	 * @return bool whether the site is connected, answering, and posts can be sent
	 */
	public static function check( object $site ): bool {
		$was    = (string) $site->last_error;
		$result = SiteClient::request( $site, 'GET', '/rest/v1.1', array( 'query' => array( 'fields' => 'ID,name,URL,jetpack,capabilities' ) ) );
		$now    = gmdate( 'Y-m-d H:i:s' );

		if ( ! is_wp_error( $result ) && 200 === $result['status'] && is_array( $result['data'] ) ) {
			$d    = $result['data'];
			$caps = array_map( 'boolval', (array) ( $d['capabilities'] ?? array() ) );
			$me   = SiteClient::request( $site, 'GET', '/rest/v1.1/me', array( 'query' => array( 'fields' => 'display_name,username' ) ) );
			$who  = ! is_wp_error( $me ) && 200 === $me['status'] && is_array( $me['data'] ) ? (string) ( $me['data']['display_name'] ?? $me['data']['username'] ?? '' ) : '';
			$name = sanitize_text_field( html_entity_decode( (string) ( $d['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
			// Without these, nothing can be sent: say so instead of failing at the first send.
			$problem = empty( $caps['edit_posts'] ) || empty( $caps['upload_files'] )
				? 'The person who approved can\'t write posts or upload images on this site. Disconnect, then reconnect approving as one of its Authors, Editors or administrators.'
				: null;
			Sites::update(
				(int) $site->id,
				array(
					'last_check_at' => $now,
					'last_error'    => $problem,
					'remote_user'   => sanitize_text_field( $who ),
					'remote_role'   => Wpcom::role( $caps ),
					'can_publish'   => empty( $caps['publish_posts'] ) ? 0 : 1,
					'name'          => '' !== $name ? $name : $site->name,
				)
			);
			if ( $problem && $problem !== $was ) {
				Events::log( 'check_failed', $problem, (int) $site->id );
			} elseif ( ! $problem && '' !== $was ) {
				Events::log( 'check_ok', 'The site is answering again.', (int) $site->id );
			}
			return null === $problem;
		}

		$fresh = Sites::get( (int) $site->id );
		if ( $fresh && Sites::CONNECTED === $fresh->status ) {
			$msg = 'Check failed: ' . Http::describe( $result );
			Sites::update( (int) $site->id, array( 'last_check_at' => $now, 'last_error' => $msg ) );
			if ( $msg !== $was ) {
				Events::log( 'check_failed', $msg, (int) $site->id );
			}
		} elseif ( $fresh ) {
			Sites::update( (int) $site->id, array( 'last_check_at' => $now ) );
		}
		return false;
	}
}
