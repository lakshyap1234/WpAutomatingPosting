<?php
/**
 * Which AI structures posts, and its API key (stored encrypted with CPUB_PUBLISHER_KEY).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Settings;

use CPub\Publisher\Crypto\Vault;
use CPub\Publisher\Pipeline\Llm\Anthropic;
use CPub\Publisher\Pipeline\Llm\Gemini;
use CPub\Publisher\Pipeline\Llm\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AiSettings {

	public const OPTION    = 'cpub_publisher_ai';
	public const CONTEXT   = 'ai:key';
	public const PROVIDERS = array(
		'gemini'    => array( 'label' => 'Google Gemini', 'model' => Gemini::DEFAULT_MODEL, 'prefix' => 'gemini-' ),
		'anthropic' => array( 'label' => 'Anthropic Claude', 'model' => Anthropic::DEFAULT_MODEL, 'prefix' => 'claude-' ),
	);

	/** @return array{provider:string, model:string, key_hint:string, has_key:bool} */
	public static function get(): array {
		$o        = (array) get_option( self::OPTION, array() );
		$provider = isset( self::PROVIDERS[ $o['provider'] ?? '' ] ) ? $o['provider'] : 'gemini';
		return array(
			'provider' => $provider,
			'model'    => (string) ( $o['model'] ?? '' ) ?: self::PROVIDERS[ $provider ]['model'],
			'key_hint' => (string) ( $o['key_hint'] ?? '' ),
			'has_key'  => ! empty( $o['key'] ),
		);
	}

	/**
	 * @param ?string $key null keeps the stored key; '' removes it
	 * @return true|\WP_Error
	 */
	public static function save( string $provider, string $model, ?string $key ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return new \WP_Error( 'cpub_provider', 'Unknown AI provider.' );
		}
		$model = trim( $model );
		if ( '' !== $model && ( ! str_starts_with( $model, self::PROVIDERS[ $provider ]['prefix'] ) || ! preg_match( '/^[a-z0-9.\-]+$/', $model ) ) ) {
			return new \WP_Error( 'cpub_model', sprintf( 'That doesn\'t look like a %s model name (they start with "%s").', self::PROVIDERS[ $provider ]['label'], self::PROVIDERS[ $provider ]['prefix'] ) );
		}
		$old  = (array) get_option( self::OPTION, array() );
		$next = array( 'provider' => $provider, 'model' => $model );
		if ( null === $key ) {
			$next['key']      = $old['key'] ?? '';
			$next['key_hint'] = $old['key_hint'] ?? '';
			if ( ( $old['provider'] ?? $provider ) !== $provider ) {
				$next['key'] = $next['key_hint'] = ''; // a Gemini key is no use for Claude
			}
		} elseif ( '' === trim( $key ) ) {
			$next['key'] = $next['key_hint'] = '';
		} else {
			$key = trim( $key );
			if ( strlen( $key ) < 20 || preg_match( '/\s|\.\.\./', $key ) ) {
				return new \WP_Error( 'cpub_key', 'That doesn\'t look like a complete API key.' );
			}
			try {
				$next['key'] = Vault::encrypt( $key, self::CONTEXT );
			} catch ( \RuntimeException $e ) {
				return new \WP_Error( 'cpub_key', 'Set the encryption key first (Content Publisher > Status): ' . $e->getMessage() );
			}
			$next['key_hint'] = substr( $key, -4 );
		}
		update_option( self::OPTION, $next, false );
		return true;
	}

	/**
	 * The configured provider, or null if there's no usable key.
	 * Filter `cpub_publisher_ai_provider` lets a test or development setup supply its own.
	 */
	public static function provider(): ?Provider {
		$provider = self::configured();
		$filtered = apply_filters( 'cpub_publisher_ai_provider', $provider );
		return $filtered instanceof Provider ? $filtered : null;
	}

	private static function configured(): ?Provider {
		$o = (array) get_option( self::OPTION, array() );
		if ( empty( $o['key'] ) ) {
			return null;
		}
		try {
			$key = Vault::decrypt( (string) $o['key'], self::CONTEXT );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		$s = self::get();
		return 'anthropic' === $s['provider'] ? new Anthropic( $key, $s['model'] ) : new Gemini( $key, $s['model'] );
	}
}
