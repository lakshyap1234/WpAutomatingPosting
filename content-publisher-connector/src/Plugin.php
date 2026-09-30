<?php
/**
 * Wires the plugin's parts together.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

use CPub\Connector\Admin\AuthorizePage;
use CPub\Connector\Admin\ConnectionActions;
use CPub\Connector\Admin\StatusPage;
use CPub\Connector\Auth\BearerAuth;
use CPub\Connector\Auth\RouteGate;
use CPub\Connector\Rest\AuthorizationProbe;
use CPub\Connector\Rest\OAuthController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const REST_NAMESPACE = 'cpub-connector/v1';

	private static ?Plugin $instance = null;
	private bool $booted             = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_action( Installer::DAILY_HOOK, array( Installer::class, 'cleanup' ) );

		BearerAuth::register();
		Auth\Sandbox::register();
		RouteGate::register();
		Rest\Ownership::register();
		Rest\Idempotency::register();
		PublisherUser::register();

		add_action( 'rest_api_init', array( new AuthorizationProbe(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new OAuthController(), 'register_routes' ) );

		if ( is_admin() ) {
			( new StatusPage() )->register();
			( new AuthorizePage() )->register();
			ConnectionActions::register();
		}
	}
}
