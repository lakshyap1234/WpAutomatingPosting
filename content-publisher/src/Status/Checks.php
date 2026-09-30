<?php
/**
 * Health checks shown on the Status page. Each check says what is wrong and
 * what to do about it, so staff can fix setup problems without a developer.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Status;

use CPub\Publisher\Crypto\Key;
use CPub\Publisher\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Checks {

	public const OK    = 'ok';
	public const WARN  = 'warning';
	public const ERROR = 'error';

	/**
	 * @return array<int, array{id:string, label:string, status:string, detail:string}>
	 */
	public static function run(): array {
		return array(
			self::encryption_key(),
			self::tables(),
			self::libraries(),
			self::action_scheduler(),
			self::cron(),
			self::https(),
			self::return_address(),
			self::unicode(),
			self::ai(),
		);
	}

	public static function encryption_key( ?string $state = null ): array {
		$state ??= Key::state();
		return match ( $state ) {
			Key::OK      => self::result( 'encryption_key', 'Encryption key', self::OK, 'CPUB_PUBLISHER_KEY is set. Access tokens for client sites will be stored encrypted.' ),
			Key::MISSING => self::result( 'encryption_key', 'Encryption key', self::ERROR, 'CPUB_PUBLISHER_KEY is not set in wp-config.php. Client sites can\'t be connected until it is, because their access tokens are stored encrypted with it.' ),
			default      => self::result( 'encryption_key', 'Encryption key', self::ERROR, 'CPUB_PUBLISHER_KEY is set but is not a valid key (it must be 32 random bytes, base64-encoded). Replace it with the line below.' ),
		};
	}

	public static function tables(): array {
		$missing = Installer::missing_tables();
		if ( $missing ) {
			return self::result( 'tables', 'Database tables', self::ERROR, 'Missing: ' . implode( ', ', $missing ) . '. Deactivate and reactivate the plugin; if that doesn\'t fix it, the database user may lack CREATE permission.' );
		}
		return self::result( 'tables', 'Database tables', self::OK, sprintf( 'All %d tables exist (schema version %d).', count( Installer::TABLES ), (int) get_option( Installer::SCHEMA_OPTION ) ) );
	}

	public static function libraries(): array {
		if ( class_exists( \CPub\Publisher\Vendor\League\CommonMark\GithubFlavoredMarkdownConverter::class ) ) {
			return self::result( 'libraries', 'Bundled libraries', self::OK, 'Loaded under the plugin\'s own namespace, so other plugins\' copies can\'t conflict.' );
		}
		return self::result( 'libraries', 'Bundled libraries', self::ERROR, 'The vendor-prefixed folder is missing or incomplete. Reinstall the plugin from the release zip.' );
	}

	public static function action_scheduler(): array {
		if ( ! class_exists( 'ActionScheduler_Versions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return self::result( 'action_scheduler', 'Job queue (Action Scheduler)', self::ERROR, 'Action Scheduler did not load. Reinstall the plugin from the release zip.' );
		}
		$version = \ActionScheduler_Versions::instance()->latest_version();
		if ( method_exists( 'ActionScheduler', 'is_initialized' ) && ! \ActionScheduler::is_initialized() ) {
			return self::result( 'action_scheduler', 'Job queue (Action Scheduler)', self::WARN, "Version {$version} loaded but not initialised yet. Reload this page; if it persists, another plugin may be loading an incompatible copy." );
		}
		return self::result( 'action_scheduler', 'Job queue (Action Scheduler)', self::OK, "Version {$version} is running." );
	}

	public static function cron(): array {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return self::result( 'cron', 'Background processing (WP-Cron)', self::WARN, 'DISABLE_WP_CRON is on. That is fine only if the server runs wp-cron.php on a schedule (every minute is best); otherwise uploaded posts will never be processed.' );
		}
		return self::result( 'cron', 'Background processing (WP-Cron)', self::OK, 'WP-Cron runs on page visits. On a quiet site, jobs wait for the next visit; a server cron calling wp-cron.php every minute makes this reliable.' );
	}

	public static function https(): array {
		if ( function_exists( 'wp_is_using_https' ) && wp_is_using_https() ) {
			return self::result( 'https', 'HTTPS', self::OK, 'The site uses HTTPS, which OAuth requires.' );
		}
		return self::result( 'https', 'HTTPS', self::ERROR, 'The site address is not https://. Client sites will refuse to send the connection approval back to a non-HTTPS address.' );
	}

	/** What client Connectors must be built to trust. */
	public static function return_address(): array {
		$uri      = \CPub\Publisher\Connections\ConnectFlow::redirect_uri();
		$expected = untrailingslashit( site_url() ) . '/wp-admin/admin.php?page=cpub-publisher&cpub_oauth=callback';
		if ( $uri !== $expected ) {
			return self::result( 'return_address', 'Connector return address', self::WARN, "This site's admin address is unusual ($uri). Client Connectors must be built to trust exactly that address; the standard build expects $expected." );
		}
		return self::result( 'return_address', 'Connector return address', self::OK, 'Build client Connectors with AGENCY_URL=' . untrailingslashit( site_url() ) . ' (return address: ' . $uri . ').' );
	}

	/**
	 * Without PHP's intl extension, text isn't converted to one Unicode form, so
	 * an "é" typed as e + accent stays two characters. Rare, and nothing is lost.
	 */
	public static function unicode( ?bool $available = null ): array {
		if ( $available ?? class_exists( \Normalizer::class ) ) {
			return self::result( 'unicode', 'Unicode normalisation', self::OK, 'PHP\'s intl extension is available.' );
		}
		return self::result( 'unicode', 'Unicode normalisation', self::WARN, 'PHP\'s intl extension is missing. Posts still work; accented letters typed as two characters (letter + accent) are kept that way instead of being combined. Ask the host to enable intl.' );
	}

	public static function ai(): array {
		$s = \CPub\Publisher\Settings\AiSettings::get();
		if ( ! $s['has_key'] ) {
			return self::result( 'ai', 'AI for structuring posts', self::WARN, 'No API key yet. Add one under Content Publisher > Settings. Until then only .md files and the recorded sample structures work.' );
		}
		return self::result( 'ai', 'AI for structuring posts', self::OK, \CPub\Publisher\Settings\AiSettings::PROVIDERS[ $s['provider'] ]['label'] . " · {$s['model']} (key ending …{$s['key_hint']})." );
	}

	private static function result( string $id, string $label, string $status, string $detail ): array {
		return compact( 'id', 'label', 'status', 'detail' );
	}
}
