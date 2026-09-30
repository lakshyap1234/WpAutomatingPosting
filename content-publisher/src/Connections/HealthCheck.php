<?php
/**
 * Daily check of every connected site. Also keeps the connection alive:
 * a refresh token that isn't used for 90 days expires.
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

	/** @return bool whether the site is connected and answering */
	public static function check( object $site ): bool {
		$was    = (string) $site->last_error;
		$result = ConnectorClient::request( $site, 'GET', '/cpub-connector/v1/connection' );
		$now    = gmdate( 'Y-m-d H:i:s' );

		if ( ! is_wp_error( $result ) && 200 === $result['status'] && is_array( $result['data'] ) ) {
			$d = $result['data'];
			Sites::update(
				(int) $site->id,
				array(
					'last_check_at'     => $now,
					'last_error'        => null,
					'remote_user'       => sanitize_text_field( (string) ( $d['user']['name'] ?? '' ) ),
					'can_publish'       => empty( $d['can_publish'] ) ? 0 : 1, // Connector 0.5.0+: the site allows direct publishing
					'connector_version' => sanitize_text_field( (string) ( $d['connector_version'] ?? '' ) ),
					'name'              => sanitize_text_field( (string) ( $d['site_name'] ?? $site->name ) ),
				)
			);
			if ( isset( $d['scopes'] ) && is_array( $d['scopes'] ) ) {
				Credentials::set_scopes( (int) $site->id, $d['scopes'] );
			}
			if ( '' !== $was ) {
				Events::log( 'check_ok', 'The site is answering again.', (int) $site->id );
			}
			return true;
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
