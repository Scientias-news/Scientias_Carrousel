<?php
/**
 * Uninstall-routine: ruimt plugin-opties en -transients op.
 * Wordt uitsluitend door WordPress aangeroepen bij "Verwijderen" (niet bij deactiveren).
 *
 * @package Scientias_YouTube_Carrousel
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$settings = get_option( 'syc_settings', array() );
$registry = get_option( 'syc_playlist_cache_registry', array() );
$channel  = isset( $settings['channel_id'] ) ? trim( (string) $settings['channel_id'] ) : '';
$maximum  = isset( $settings['max_items'] ) ? min( max( 1, absint( $settings['max_items'] ) ), 50 ) : 8;
$main_key = md5( $channel . '|' . $maximum );

wp_clear_scheduled_hook( 'syc_refresh_feed_event' );

delete_option( 'syc_settings' );
delete_option( 'syc_api_feed_meta' );
delete_option( 'syc_autodraft_map' );
delete_option( 'syc_api_feed_stale' );
delete_option( 'syc_api_feed_stale_' . $main_key );
delete_option( 'syc_playlist_refresh_cursor' );
delete_option( 'syc_playlist_cache_registry' );
delete_option( 'syc_pending_playlist_cleanup' );

delete_transient( 'syc_api_feed_cache' );
delete_transient( 'syc_api_feed_cache_' . $main_key );
delete_transient( 'syc_autodraft_lock' );
delete_transient( 'syc_feed_refresh_lock' );
delete_option( '_syc_lock_syc_autodraft_lock' );
delete_option( '_syc_lock_syc_feed_refresh_lock' );
delete_option( '_syc_lock_syc_settings_lock' );
delete_option( '_syc_lock_syc_cron_schedule_lock' );
wp_clear_scheduled_hook( 'syc_cleanup_removed_playlists_event' );

if ( is_array( $registry ) ) {
	foreach ( $registry as $playlist_id ) {
		$hash = md5( $playlist_id );
		delete_transient( 'syc_playlist_cache_' . $hash );
		delete_option( 'syc_playlist_stale_' . $hash );
		delete_option( 'syc_playlist_feed_meta_' . $hash );
	}
}

if ( ! empty( $settings['custom_carrousels'] ) && is_array( $settings['custom_carrousels'] ) ) {
	foreach ( $settings['custom_carrousels'] as $carrousel ) {
		if ( ! empty( $carrousel['playlist_id'] ) ) {
			$hash = md5( $carrousel['playlist_id'] );
			delete_transient( 'syc_playlist_cache_' . $hash );
			delete_option( 'syc_playlist_stale_' . $hash );
			delete_option( 'syc_playlist_feed_meta_' . $hash );
		}
	}
}

// Vangnet voor cache-opties die niet meer aan actuele instellingen gekoppeld zijn.
// De query zoekt alleen optienamen; delete_option() hieronder verzorgt ook de
// correcte invalidatie van een eventuele persistente object-cache.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$orphaned_cache_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_syc_playlist_cache_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_syc_playlist_cache_' ) . '%',
		$wpdb->esc_like( 'syc_playlist_stale_' ) . '%',
		$wpdb->esc_like( 'syc_playlist_feed_meta_' ) . '%',
		$wpdb->esc_like( '_transient_syc_api_feed_cache_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_syc_api_feed_cache_' ) . '%',
		$wpdb->esc_like( 'syc_api_feed_stale_' ) . '%',
		$wpdb->esc_like( '_transient_syc_admin_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_syc_admin_notice_' ) . '%',
		$wpdb->esc_like( '_transient_syc_manual_refresh_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_syc_manual_refresh_notice_' ) . '%'
	)
);

foreach ( $orphaned_cache_options as $option_name ) {
	delete_option( $option_name );
}
