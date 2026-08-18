<?php
/**
 * Wires the modules together and loads whatever integrations apply.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string,object> Loaded modules. */
	private array $modules = array();

	public static function instance(): Plugin {
		self::$instance ??= new self();
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		$this->modules = array(
			'events'  => new Events(),
			'queue'   => new Queue(),
			'admin'   => new Settings_Page(),
			'profile' => new Profile_Fields(),
		);

		foreach ( $this->integrations() as $key => $integration ) {
			$this->modules[ $key ] = $integration;
		}

		foreach ( $this->modules as $module ) {
			if ( method_exists( $module, 'register' ) ) {
				$module->register();
			}
		}
	}

	/**
	 * Integrations that apply to this site.
	 *
	 * An integration is nothing more than a bundle of events registered through
	 * the public API, kept in this plugin because the software it covers is
	 * common enough to be worth shipping. Sites can add their own the same way,
	 * from their own code.
	 *
	 * @return array<string,object>
	 */
	private function integrations(): array {
		$integrations = array();

		if ( class_exists( 'WooCommerce' ) ) {
			$integrations['woocommerce'] = new Integrations\WooCommerce();
		}

		return $integrations;
	}

	public function module( string $key ): ?object {
		return $this->modules[ $key ] ?? null;
	}
}
