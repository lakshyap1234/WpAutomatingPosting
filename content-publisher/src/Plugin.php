<?php
/**
 * Wires the plugin's parts together.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher;

use CPub\Publisher\Admin\Menu;
use CPub\Publisher\Admin\SettingsPage;
use CPub\Publisher\Admin\SitesPage;
use CPub\Publisher\Connections\HealthCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

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

		// Upgrades that arrive without re-activation (zip replaced, auto-update).
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		HealthCheck::register();
		Jobs\Processor::register();
		Jobs\Sender::register();
		Jobs\AssetController::register();
		Rest\JobsController::register();

		if ( is_admin() ) {
			( new Menu() )->register();
			SitesPage::register();
			SettingsPage::register();
			Admin\AddPostsPage::register();
			Admin\PostsPage::register();
			Admin\SiteSettingsPage::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cpub pipeline', Cli\PipelineCommand::class );
		}
	}
}
