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

/**
 * Verwijder alle plugindata voor de momenteel actieve site.
 */
function syc_uninstall_current_site() {
	global $wpdb;
	$wp_roles = wp_roles();
	foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->remove_cap( 'syc_manage_youtube_content' );
		}
	}

	$settings = get_option( 'syc_settings', array() );
	$registry = get_option( 'syc_playlist_cache_registry', array() );
	$channel  = isset( $settings['channel_id'] ) ? trim( (string) $settings['channel_id'] ) : '';
	$maximum  = isset( $settings['max_items'] ) ? min( max( 1, absint( $settings['max_items'] ) ), 50 ) : 8;
	$main_key = md5( $channel . '|' . $maximum );

	wp_clear_scheduled_hook( 'syc_refresh_feed_event' );
	wp_clear_scheduled_hook( 'syc_cleanup_removed_playlists_event' );

	delete_option( 'syc_settings' );
	delete_option( 'syc_api_feed_meta' );
	delete_option( 'syc_autodraft_map' );
	delete_option( 'syc_api_feed_stale' );
	delete_option( 'syc_api_feed_stale_' . $main_key );
	delete_option( 'syc_playlist_refresh_cursor' );
	delete_option( 'syc_playlist_cache_registry' );
	delete_option( 'syc_pending_playlist_cleanup' );
	delete_option( 'syc_onboarding_redirect' );
	delete_option( 'syc_editorial_video_index' );
	delete_option( 'syc_capability_version' );
	delete_option( 'syc_cron_activated_at' );
	delete_option( 'syc_cron_last_run_at' );
	delete_option( 'syc_cron_last_completed_at' );
	delete_option( 'syc_cron_schedule_error' );

	delete_transient( 'syc_api_feed_cache' );
	delete_transient( 'syc_api_feed_cache_' . $main_key );
	delete_transient( 'syc_autodraft_lock' );
	delete_transient( 'syc_feed_refresh_lock' );
	delete_option( '_syc_lock_syc_autodraft_lock' );
	delete_option( '_syc_lock_syc_editorial_index_lock' );
	delete_option( '_syc_lock_syc_feed_refresh_lock' );
	delete_option( '_syc_lock_syc_settings_lock' );
	delete_option( '_syc_lock_syc_cron_schedule_lock' );

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
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		syc_uninstall_current_site();
		restore_current_blog();
	}
} else {
	syc_uninstall_current_site();
}
