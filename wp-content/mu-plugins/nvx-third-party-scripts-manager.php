<?php
/**
 * Plugin Name: NUVANX Third-party Scripts Manager
 * Description: Prevents rogue third-party tracking scripts (Facebook, HubSpot tracking, Klaviyo.js) from being added to initial server-rendered HTML.
 * Version: 1.1.0
 * Author: NUVANX
 */

defined( 'ABSPATH' ) || exit;

class Nvx_Third_Party_Scripts_Manager {
	/**
	 * List of domains whose scripts should be blocked from server-side rendering.
	 * These are considered "rogue" as per the acceptance test contract.
	 *
	 * @var string[]
	 */
	private const ROGUE_SCRIPT_SOURCES = array(
		'connect.facebook.net',
		'js.hs-scripts.com',
		'hs-analytics.net',
		'static.klaviyo.com',
		'static-tracking.klaviyo.com',
	);

	/**
	 * Registers the necessary hooks to manage third-party scripts.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'remove_server_side_scripts' ) );
	}

	/**
	 * Removes known script hooks from third-party plugins to prevent them from
	 * appearing in the initial server-side rendered HTML.
	 */
	public static function remove_server_side_scripts(): void {
		// Remove HubSpot script actions if their plugin is active.
		if ( class_exists( '\HubSpot\WordPress\Hooks' ) ) {
			remove_action( 'wp_head', array( \HubSpot\WordPress\Hooks::class, 'add_tracking_code' ) );
			remove_action( 'wp_footer', array( \HubSpot\WordPress\Hooks::class, 'add_tracking_code' ) );
		}

		// Attempt to remove actions from other common tracking plugins by looking for
		// typical method names. This is a defensive measure.
		if ( isset( $GLOBALS['facebook_pixel_plugin'] ) ) {
			remove_action( 'wp_head', array( $GLOBALS['facebook_pixel_plugin'], 'insert_pixel_code' ) );
		}

		// Generic filter to remove script tags by src during rendering.
		// This is a stronger guarantee if hook removal fails.
		add_filter( 'script_loader_tag', array( self::class, 'filter_rogue_script_tags' ), 999, 2 );
	}

	/**
	 * Filters script tags and removes any that match the rogue sources list.
	 *
	 * @param string $tag    The <script> tag for the enqueued script.
	 * @param string $handle The script's handle.
	 * @return string The filtered <script> tag.
	 */
	public static function filter_rogue_script_tags( string $tag, string $handle ): string {
		foreach ( self::ROGUE_SCRIPT_SOURCES as $source ) {
			if ( strpos( $tag, $source ) !== false ) {
				// Returning an empty string removes the script tag.
				return '';
			}
		}
		return $tag;
	}
}

Nvx_Third_Party_Scripts_Manager::init();
