<?php
/**
 * Plugin Name: Scientias YouTube Carrousel
 * Description: Voegt een shortcode toe voor een YouTube-video carrousel met titel, thumbnail en video-URL.
 * Version:     1.5.14
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Scientias
 * Text Domain: scientias-youtube-carrousel
 *
 * @package Scientias_YouTube_Carrousel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SYC_VERSION', '1.5.14' );
define( 'SYC_API_FEED_CACHE_KEY', 'syc_api_feed_cache' );
define( 'SYC_API_FEED_STALE_OPTION', 'syc_api_feed_stale' );
define( 'SYC_PLAYLIST_CACHE_PREFIX', 'syc_playlist_cache_' );
define( 'SYC_PLAYLIST_STALE_PREFIX', 'syc_playlist_stale_' );
define( 'SYC_PLAYLIST_META_PREFIX', 'syc_playlist_feed_meta_' );
// Cache-TTL bewust langer dan het cron-ververs-interval, zodat een net-te-late
// cronrun geen gat laat vallen waarin bezoekers een lege cache treffen.
define( 'SYC_API_FEED_CACHE_TTL', 15 * MINUTE_IN_SECONDS );
define( 'SYC_AUTODRAFT_MAP_OPTION', 'syc_autodraft_map' );
define( 'SYC_AUTODRAFT_LOCK_KEY', 'syc_autodraft_lock' );
define( 'SYC_EDITORIAL_INDEX_LOCK_KEY', 'syc_editorial_index_lock' );
define( 'SYC_FEED_REFRESH_LOCK_KEY', 'syc_feed_refresh_lock' );
define( 'SYC_SETTINGS_LOCK_KEY', 'syc_settings_lock' );
define( 'SYC_CRON_SCHEDULE_LOCK_KEY', 'syc_cron_schedule_lock' );
// Standaard-auteur voor automatisch aangemaakte concept-berichten (WP-Cron heeft
// geen ingelogde gebruiker, dus zonder dit zou post_author op 0 blijven staan).
define( 'SYC_DEFAULT_DRAFT_AUTHOR_NAME', 'Diederik Jekel' );
define( 'SYC_CRON_HOOK', 'syc_refresh_feed_event' );
define( 'SYC_CRON_INTERVAL', 'syc_five_minutes' );
define( 'SYC_CRON_REFRESH_INTERVAL', 5 * MINUTE_IN_SECONDS );
define( 'SYC_CRON_ACTIVATED_OPTION', 'syc_cron_activated_at' );
define( 'SYC_CRON_LAST_RUN_OPTION', 'syc_cron_last_run_at' );
define( 'SYC_CRON_LAST_COMPLETED_OPTION', 'syc_cron_last_completed_at' );
define( 'SYC_CRON_SCHEDULE_ERROR_OPTION', 'syc_cron_schedule_error' );
define( 'SYC_REFRESH_LOCK_TTL', 2 * MINUTE_IN_SECONDS );
define( 'SYC_PLAYLIST_REFRESH_BATCH_SIZE', 4 );
define( 'SYC_PLAYLIST_REFRESH_CURSOR_OPTION', 'syc_playlist_refresh_cursor' );
define( 'SYC_PLAYLIST_CACHE_REGISTRY_OPTION', 'syc_playlist_cache_registry' );
define( 'SYC_PENDING_PLAYLIST_CLEANUP_OPTION', 'syc_pending_playlist_cleanup' );
define( 'SYC_PLAYLIST_CLEANUP_HOOK', 'syc_cleanup_removed_playlists_event' );
define( 'SYC_ONBOARDING_REDIRECT_OPTION', 'syc_onboarding_redirect' );
define( 'SYC_EDITORIAL_VIDEO_INDEX_OPTION', 'syc_editorial_video_index' );
define( 'SYC_EDITORIAL_CAPABILITY', 'syc_manage_youtube_content' );
define( 'SYC_CAPABILITY_VERSION_OPTION', 'syc_capability_version' );
define( 'SYC_CAPABILITY_VERSION', 1 );
define( 'SYC_API_REQUEST_TIMEOUT', 4 );
define( 'SYC_CSV_IMPORT_MAX_FILE_SIZE', 2 * MB_IN_BYTES );
define( 'SYC_CSV_IMPORT_MAX_ROWS', 5000 );
define( 'SYC_CONFIG_IMPORT_MAX_FILE_SIZE', 2 * MB_IN_BYTES );
define( 'SYC_MAX_LINK_OVERRIDES', 5000 );
define( 'SYC_MAX_CUSTOM_CARROUSELS', 100 );
define( 'SYC_MAX_CUSTOM_CARROUSEL_ITEMS', 100 );

/**
 * Aantal lege invoerrijen op het extra carrousels-scherm.
 */
define( 'SYC_CUSTOM_CARROUSEL_EMPTY_ROWS', 5 );

/**
 * Laad vertalingen uit de languages-map van de plugin.
 */
function syc_load_textdomain() {
	load_plugin_textdomain( 'scientias-youtube-carrousel', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'syc_load_textdomain' );

/**
 * Registreer een cron-interval van 5 minuten, korter dan de feed-cache TTL.
 *
 * @param array $schedules Bestaande cron-intervallen.
 * @return array
 */
function syc_register_cron_interval( $schedules ) {
	if ( ! isset( $schedules[ SYC_CRON_INTERVAL ] ) ) {
		$schedules[ SYC_CRON_INTERVAL ] = array(
			'interval' => SYC_CRON_REFRESH_INTERVAL,
			'display'  => __( 'Elke 5 minuten (Scientias YouTube Carrousel)', 'scientias-youtube-carrousel' ),
		);
	}
	return $schedules;
}
// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval is defined by SYC_CRON_REFRESH_INTERVAL.
add_filter( 'cron_schedules', 'syc_register_cron_interval' );

/**
 * Controleer of deze request uitsluitend een diagnostisch rapport downloadt.
 *
 * @return bool
 */
function syc_is_diagnostics_download_request() {
	// Alleen requestroutering; er worden hier geen gegevens gelezen of gewijzigd.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	return 'syc_download_diagnostics' === $action;
}

/**
 * Plan het periodieke feed-ververs-event in, tenzij het al gepland staat.
 *
 * Draait zowel op activatie als (zelfherstellend) op elke 'init', zodat een
 * update buiten de activatie-hook om (bv. via automatische deploy) het cron-
 * event niet stilletjes laat verdwijnen.
 */
function syc_schedule_cron_event() {
	if ( syc_is_diagnostics_download_request() ) {
		return;
	}
	if ( wp_next_scheduled( SYC_CRON_HOOK ) ) {
		if ( ! get_option( SYC_CRON_ACTIVATED_OPTION, 0 ) ) {
			update_option( SYC_CRON_ACTIVATED_OPTION, time(), false );
		}
		if ( get_option( SYC_CRON_SCHEDULE_ERROR_OPTION, false ) ) {
			delete_option( SYC_CRON_SCHEDULE_ERROR_OPTION );
		}
		return;
	}

	$lock = syc_acquire_lock( SYC_CRON_SCHEDULE_LOCK_KEY, 30 );
	if ( false === $lock ) {
		return;
	}

	try {
		if ( ! wp_next_scheduled( SYC_CRON_HOOK ) ) {
			$scheduled = wp_schedule_event( time(), SYC_CRON_INTERVAL, SYC_CRON_HOOK, array(), true );
			if ( is_wp_error( $scheduled ) ) {
				update_option(
					SYC_CRON_SCHEDULE_ERROR_OPTION,
					array(
						'code'       => sanitize_key( $scheduled->get_error_code() ),
						'message'    => __( 'WordPress kon de automatische feedverversing niet plannen.', 'scientias-youtube-carrousel' ),
						'updated_at' => time(),
					),
					false
				);
			} else {
				delete_option( SYC_CRON_SCHEDULE_ERROR_OPTION );
			}
		}
		if ( ! get_option( SYC_CRON_ACTIVATED_OPTION, 0 ) ) {
			update_option( SYC_CRON_ACTIVATED_OPTION, time(), false );
		}
	} finally {
		syc_release_lock( SYC_CRON_SCHEDULE_LOCK_KEY, $lock );
	}
}
add_action( 'init', 'syc_schedule_cron_event' );

/**
 * Installeer de redactionele capability voor de standaardrollen.
 */
function syc_install_capabilities() {
	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->add_cap( SYC_EDITORIAL_CAPABILITY );
		}
	}
	update_option( SYC_CAPABILITY_VERSION_OPTION, SYC_CAPABILITY_VERSION, false );
}

/**
 * Installeer capabilities ook na een ZIP-update zonder activatiehook.
 */
function syc_maybe_install_capabilities() {
	if ( syc_is_diagnostics_download_request() ) {
		return;
	}
	if ( (int) get_option( SYC_CAPABILITY_VERSION_OPTION, 0 ) < SYC_CAPABILITY_VERSION ) {
		syc_install_capabilities();
	}
}
add_action( 'init', 'syc_maybe_install_capabilities', 5 );

/**
 * Initialiseer een nieuwe multisitesite wanneer de plugin netwerkactief is.
 *
 * @param WP_Site $new_site Nieuwe site.
 */
function syc_initialize_new_site( $new_site ) {
	$active = get_site_option( 'active_sitewide_plugins', array() );
	if ( ! $new_site instanceof WP_Site || ! isset( $active[ plugin_basename( __FILE__ ) ] ) ) {
		return;
	}

	switch_to_blog( $new_site->blog_id );
	syc_install_capabilities();
	syc_schedule_cron_event();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'syc_initialize_new_site', 200 );

/**
 * Initialiseer planning en onboarding bij een echte pluginactivatie.
 *
 * @param bool $network_wide Of de plugin voor een heel multisitenetwerk wordt geactiveerd.
 */
function syc_activate_plugin( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( $site_id );
			syc_install_capabilities();
			update_option( SYC_CRON_ACTIVATED_OPTION, time(), false );
			syc_schedule_cron_event();
			delete_option( SYC_ONBOARDING_REDIRECT_OPTION );
			restore_current_blog();
		}
		return;
	}

	syc_install_capabilities();
	update_option( SYC_CRON_ACTIVATED_OPTION, time(), false );
	syc_schedule_cron_event();
	$settings = syc_get_settings();
	if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) {
		update_option( SYC_ONBOARDING_REDIRECT_OPTION, time(), false );
	} else {
		delete_option( SYC_ONBOARDING_REDIRECT_OPTION );
	}
}
register_activation_hook( __FILE__, 'syc_activate_plugin' );

/**
 * Verwijder het geplande event bij het deactiveren van de plugin.
 *
 * @param bool $network_wide Of de plugin voor een heel multisitenetwerk wordt gedeactiveerd.
 */
function syc_unschedule_cron_event( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( $site_id );
			wp_clear_scheduled_hook( SYC_CRON_HOOK );
			wp_clear_scheduled_hook( SYC_PLAYLIST_CLEANUP_HOOK );
			restore_current_blog();
		}
		return;
	}

	wp_clear_scheduled_hook( SYC_CRON_HOOK );
	wp_clear_scheduled_hook( SYC_PLAYLIST_CLEANUP_HOOK );
}
register_deactivation_hook( __FILE__, 'syc_unschedule_cron_event' );

/**
 * Bouw brongebonden cachesleutels, zodat een gewijzigd kanaal nooit oude
 * feeddata van het vorige kanaal toont.
 *
 * @param array|null $settings Plugininstellingen.
 * @return array
 */
function syc_get_main_feed_storage_keys( $settings = null ) {
	$settings    = is_array( $settings ) ? $settings : syc_get_settings();
	$fingerprint = md5( (string) $settings['channel_id'] . '|' . (int) $settings['max_items'] );

	return array(
		'cache' => SYC_API_FEED_CACHE_KEY . '_' . $fingerprint,
		'stale' => SYC_API_FEED_STALE_OPTION . '_' . $fingerprint,
	);
}

/**
 * Sla de uitkomst van een hoofdfeedpoging op zonder het vorige succes te verliezen.
 *
 * @param string        $status   Status: ok of error.
 * @param int|null      $items    Aantal ontvangen items bij succes.
 * @param WP_Error|null $error    Fout bij een mislukte poging.
 * @param string        $feed_key Cachesleutel van de aangevraagde bron.
 */
function syc_update_main_feed_meta( $status, $items = null, $error = null, $feed_key = '' ) {
	$meta = get_option( 'syc_api_feed_meta', array() );
	$meta = is_array( $meta ) ? $meta : array();
	if ( isset( $meta['status'] ) && 'ok' === $meta['status'] ) {
		if ( empty( $meta['last_success_at'] ) && ! empty( $meta['updated_at'] ) ) {
			$meta['last_success_at'] = (int) $meta['updated_at'];
		}
		if ( ! isset( $meta['last_success_items'] ) && isset( $meta['items'] ) ) {
			$meta['last_success_items'] = (int) $meta['items'];
		}
	}

	$meta['status']          = 'ok' === $status ? 'ok' : 'error';
	$meta['updated_at']      = time();
	$meta['last_attempt_at'] = $meta['updated_at'];
	$meta['feed_key']        = $feed_key;

	if ( 'ok' === $meta['status'] ) {
		$meta['items']              = max( 0, (int) $items );
		$meta['last_success_items'] = $meta['items'];
		$meta['last_success_at']    = $meta['updated_at'];
		$meta['message']            = '';
		$meta['code']               = '';
	} elseif ( $error instanceof WP_Error ) {
		$meta['message'] = $error->get_error_message();
		$meta['code']    = $error->get_error_code();
	}

	update_option( 'syc_api_feed_meta', $meta, false );
}

/**
 * Geef de statusoptienaam voor een playlist.
 *
 * @param string $playlist_id YouTube playlist-ID.
 * @return string
 */
function syc_get_playlist_meta_option_name( $playlist_id ) {
	return SYC_PLAYLIST_META_PREFIX . md5( syc_extract_youtube_playlist_id( $playlist_id ) );
}

/**
 * Sla de uitkomst van een playlistpoging op zonder het vorige succes te verliezen.
 *
 * @param string        $playlist_id YouTube playlist-ID.
 * @param string        $status      Status: ok of error.
 * @param int|null      $items       Aantal ontvangen items bij succes.
 * @param WP_Error|null $error       Fout bij een mislukte poging.
 */
function syc_update_playlist_feed_meta( $playlist_id, $status, $items = null, $error = null ) {
	$playlist_id = syc_extract_youtube_playlist_id( $playlist_id );
	if ( '' === $playlist_id ) {
		return;
	}

	$option_name              = syc_get_playlist_meta_option_name( $playlist_id );
	$meta                     = get_option( $option_name, array() );
	$meta                     = is_array( $meta ) ? $meta : array();
	$meta['status']           = 'ok' === $status ? 'ok' : 'error';
	$meta['updated_at']       = time();
	$meta['last_attempt_at']  = $meta['updated_at'];
	$meta['playlist_id_hash'] = md5( $playlist_id );

	if ( 'ok' === $meta['status'] ) {
		$meta['items']              = max( 0, (int) $items );
		$meta['last_success_items'] = $meta['items'];
		$meta['last_success_at']    = $meta['updated_at'];
		$meta['message']            = '';
		$meta['code']               = '';
	} elseif ( $error instanceof WP_Error ) {
		$meta['message'] = $error->get_error_message();
		$meta['code']    = $error->get_error_code();
	}

	update_option( $option_name, $meta, false );
}

/**
 * Ververs de hoofdfeed en alle playlists van extra carrousels (en
 * synchroniseer de auto-drafts) op de achtergrond via WP-Cron, in plaats van
 * tijdens het laden van een bezoekerspagina. Ook bruikbaar als handmatige
 * "ververs nu"-actie vanuit de instellingenpagina.
 *
 * De lock voorkomt dat een trage cronrun en een handmatige refresh elkaar
 * overlappen.
 *
 * @param bool $force Ook nog geldige caches opnieuw ophalen.
 * @return array|WP_Error Resultaattellingen of een lockfout.
 */
function syc_refresh_all_feeds( $force = false ) {
	$lock = syc_acquire_lock( SYC_FEED_REFRESH_LOCK_KEY, SYC_REFRESH_LOCK_TTL );
	if ( false === $lock ) {
		return new WP_Error( 'syc_refresh_locked', __( 'Er is al een feedverversing actief.', 'scientias-youtube-carrousel' ) );
	}

	$result = array(
		'refreshed' => 0,
		'skipped'   => 0,
		'errors'    => array(),
	);

	try {
		$settings  = syc_get_settings();
		$main_keys = syc_get_main_feed_storage_keys( $settings );
		if ( $force || ! is_array( get_transient( $main_keys['cache'] ) ) ) {
			$main_result = syc_fetch_and_cache_api_shorts_items();
			if ( is_wp_error( $main_result ) ) {
				$result['errors'][] = $main_result->get_error_message();
			} else {
				++$result['refreshed'];
			}
		} else {
			++$result['skipped'];
		}

		$playlists = array();
		foreach ( $settings['custom_carrousels'] as $carrousel ) {
			if ( ! empty( $carrousel['playlist_id'] ) ) {
				$playlist_id = syc_extract_youtube_playlist_id( $carrousel['playlist_id'] );
				if ( '' !== $playlist_id ) {
					$playlists[ $playlist_id ] = $playlist_id;
				}
			}
		}

		$playlists = array_values( $playlists );
		if ( ! empty( $playlists ) ) {
			$playlist_count = count( $playlists );
			$cursor         = absint( get_option( SYC_PLAYLIST_REFRESH_CURSOR_OPTION, 0 ) ) % $playlist_count;
			$inspected      = 0;
			$attempted      = 0;

			while ( $inspected < $playlist_count && $attempted < SYC_PLAYLIST_REFRESH_BATCH_SIZE ) {
				$playlist_id = $playlists[ ( $cursor + $inspected ) % $playlist_count ];
				++$inspected;
				$cache_key = SYC_PLAYLIST_CACHE_PREFIX . md5( $playlist_id );
				if ( ! $force && is_array( get_transient( $cache_key ) ) ) {
					++$result['skipped'];
					continue;
				}

				++$attempted;
				$playlist_result = syc_fetch_and_cache_api_playlist_items( $playlist_id );
				if ( is_wp_error( $playlist_result ) ) {
					$result['errors'][] = $playlist_result->get_error_message();
				} else {
					++$result['refreshed'];
				}
			}

			update_option( SYC_PLAYLIST_REFRESH_CURSOR_OPTION, ( $cursor + $inspected ) % $playlist_count, false );
		}
	} finally {
		syc_release_lock( SYC_FEED_REFRESH_LOCK_KEY, $lock );
	}
	if ( $result['refreshed'] > 0 ) {
		syc_purge_page_caches();
	}

	return $result;
}
/**
 * Registreer dat de geplande cronhook werkelijk is uitgevoerd.
 */
function syc_run_scheduled_refresh() {
	update_option( SYC_CRON_LAST_RUN_OPTION, time(), false );
	try {
		syc_refresh_all_feeds();
	} finally {
		update_option( SYC_CRON_LAST_COMPLETED_OPTION, time(), false );
	}
}
add_action( SYC_CRON_HOOK, 'syc_run_scheduled_refresh' );

/**
 * Bepaal de gemeten gezondheid van automatische feedverversing.
 *
 * @return array
 */
function syc_get_cron_health() {
	$now            = time();
	$next_run       = (int) wp_next_scheduled( SYC_CRON_HOOK );
	$activated_at   = (int) get_option( SYC_CRON_ACTIVATED_OPTION, 0 );
	$last_run       = (int) get_option( SYC_CRON_LAST_RUN_OPTION, 0 );
	$last_completed = (int) get_option( SYC_CRON_LAST_COMPLETED_OPTION, 0 );
	$schedule_error = get_option( SYC_CRON_SCHEDULE_ERROR_OPTION, array() );
	$recent_limit   = 3 * SYC_CRON_REFRESH_INTERVAL + MINUTE_IN_SECONDS;
	$disabled       = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

	if ( is_array( $schedule_error ) && ! empty( $schedule_error['code'] ) ) {
		return array(
			'code'              => 'schedule_failed',
			'label'             => __( 'Planning mislukt', 'scientias-youtube-carrousel' ),
			'type'              => 'error',
			'message'           => __( 'WordPress kon de automatische feedverversing niet plannen.', 'scientias-youtube-carrousel' ),
			'next_run_at'       => $next_run,
			'last_run_at'       => $last_run,
			'last_completed_at' => $last_completed,
		);
	}
	if ( ! $next_run ) {
		return array(
			'code'              => 'missing',
			'label'             => __( 'Cron-event ontbreekt', 'scientias-youtube-carrousel' ),
			'type'              => 'error',
			'message'           => __( 'Er staat geen automatische feedverversing gepland.', 'scientias-youtube-carrousel' ),
			'next_run_at'       => 0,
			'last_run_at'       => $last_run,
			'last_completed_at' => $last_completed,
		);
	}
	if ( $last_run > $last_completed ) {
		$running = $now - $last_run <= SYC_REFRESH_LOCK_TTL;
		return array(
			'code'              => $running ? 'running' : 'stalled',
			'label'             => $running ? __( 'Cronrefresh bezig', 'scientias-youtube-carrousel' ) : __( 'Cronrefresh niet voltooid', 'scientias-youtube-carrousel' ),
			'type'              => $running ? 'neutral' : 'warning',
			'message'           => $running ? __( 'De automatische feedverversing wordt momenteel uitgevoerd.', 'scientias-youtube-carrousel' ) : __( 'De laatste cronrefresh is gestart maar niet voltooid. Controleer PHP-fouten en uitvoeringstijd.', 'scientias-youtube-carrousel' ),
			'next_run_at'       => $next_run,
			'last_run_at'       => $last_run,
			'last_completed_at' => $last_completed,
		);
	}
	if ( $last_completed > 0 && $now - $last_completed <= $recent_limit ) {
		return array(
			'code'              => $disabled ? 'external_healthy' : 'healthy',
			'label'             => $disabled ? __( 'Servercron actief', 'scientias-youtube-carrousel' ) : __( 'WP-Cron actief', 'scientias-youtube-carrousel' ),
			'type'              => 'success',
			'message'           => $disabled ? __( 'WordPress-bezoekerscron is uitgeschakeld, maar een externe servercron voert de hook aantoonbaar uit.', 'scientias-youtube-carrousel' ) : __( 'De automatische feedverversing is recent uitgevoerd.', 'scientias-youtube-carrousel' ),
			'next_run_at'       => $next_run,
			'last_run_at'       => $last_run,
			'last_completed_at' => $last_completed,
		);
	}
	if ( $activated_at > 0 && $now - $activated_at <= $recent_limit ) {
		return array(
			'code'              => 'not_observed',
			'label'             => __( 'Wacht op eerste cronrun', 'scientias-youtube-carrousel' ),
			'type'              => 'neutral',
			'message'           => __( 'De cronplanning is nieuw en heeft nog geen uitvoering geregistreerd.', 'scientias-youtube-carrousel' ),
			'next_run_at'       => $next_run,
			'last_run_at'       => $last_run,
			'last_completed_at' => $last_completed,
		);
	}

	return array(
		'code'              => 'late',
		'label'             => __( 'Cronuitvoering te laat', 'scientias-youtube-carrousel' ),
		'type'              => 'warning',
		'message'           => $disabled ? __( 'Een servercron is vereist, maar er is geen recente uitvoering gemeten.', 'scientias-youtube-carrousel' ) : __( 'WP-Cron is gepland maar heeft de feedhook niet recent uitgevoerd.', 'scientias-youtube-carrousel' ),
		'next_run_at'       => $next_run,
		'last_run_at'       => $last_run,
		'last_completed_at' => $last_completed,
	);
}

/**
 * Ververs uitsluitend de hoofdfeed voor de onboarding.
 *
 * @return array|WP_Error Feeditems of fout.
 */
function syc_refresh_main_feed_now() {
	$lock = syc_acquire_lock( SYC_FEED_REFRESH_LOCK_KEY, SYC_REFRESH_LOCK_TTL );
	if ( false === $lock ) {
		return new WP_Error( 'syc_refresh_locked', __( 'Er is al een feedverversing actief.', 'scientias-youtube-carrousel' ) );
	}

	try {
		$result = syc_fetch_and_cache_api_shorts_items();
	} finally {
		syc_release_lock( SYC_FEED_REFRESH_LOCK_KEY, $lock );
	}

	if ( ! is_wp_error( $result ) ) {
		syc_purge_page_caches();
	}

	return $result;
}

/**
 * Verkrijg atomair een tijdelijke lock via een niet-autoloaded option.
 *
 * @param string $key Locknaam.
 * @param int    $ttl Geldigheidsduur in seconden.
 * @return array|false Lockhandle, of false als de lock bezet is.
 */
function syc_acquire_lock( $key, $ttl ) {
	global $wpdb;

	$option_name = '_syc_lock_' . sanitize_key( $key );
	$now         = time();
	$current     = get_option( $option_name, false );
	$expires     = is_array( $current ) && isset( $current['expires'] ) ? (int) $current['expires'] : (int) $current;
	$lock        = array(
		'token'   => wp_generate_uuid4(),
		'expires' => $now + max( 1, absint( $ttl ) ),
	);

	if ( $expires > $now ) {
		return false;
	}

	if ( false === $current ) {
		return add_option( $option_name, $lock, '', false ) ? $lock : false;
	}

	// Directe CAS-query maakt het overnemen van een verlopen lock atomair.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			maybe_serialize( $lock ),
			$option_name,
			maybe_serialize( $current )
		)
	);

	if ( 1 !== $updated ) {
		return false;
	}

	wp_cache_delete( $option_name, 'options' );
	return $lock;
}

/**
 * Geef een eerder verkregen lock vrij.
 *
 * @param string $key  Locknaam.
 * @param array  $lock Lockhandle van syc_acquire_lock().
 */
function syc_release_lock( $key, $lock ) {
	global $wpdb;

	if ( ! is_array( $lock ) || empty( $lock['token'] ) ) {
		return;
	}

	$option_name = '_syc_lock_' . sanitize_key( $key );
	// De waardevoorwaarde voorkomt dat een oude eigenaar een nieuwe lock verwijdert.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			$option_name,
			maybe_serialize( $lock )
		)
	);
	wp_cache_delete( $option_name, 'options' );
}

/**
 * Probeer kort een settingslock te verkrijgen.
 *
 * @return array|false
 */
function syc_acquire_settings_lock() {
	for ( $attempt = 0; $attempt < 20; $attempt++ ) {
		$lock = syc_acquire_lock( SYC_SETTINGS_LOCK_KEY, 30 );
		if ( false !== $lock ) {
			return $lock;
		}
		usleep( 100000 );
	}

	return false;
}

/**
 * Probeer kort de redactionele indexlock te verkrijgen.
 *
 * @return array|false
 */
function syc_acquire_editorial_index_lock() {
	for ( $attempt = 0; $attempt < 20; $attempt++ ) {
		$lock = syc_acquire_lock( SYC_EDITORIAL_INDEX_LOCK_KEY, 30 );
		if ( false !== $lock ) {
			return $lock;
		}
		usleep( 50000 );
	}

	return false;
}

/**
 * Geef een settingslock uit de normale options.php-savecyclus vrij.
 */
function syc_release_pending_settings_lock() {
	if ( empty( $GLOBALS['syc_pending_settings_lock'] ) ) {
		return;
	}

	syc_release_lock( SYC_SETTINGS_LOCK_KEY, $GLOBALS['syc_pending_settings_lock'] );
	unset( $GLOBALS['syc_pending_settings_lock'] );
}

/**
 * Geef de options.php-lock vrij zodra de optie is bijgewerkt.
 *
 * @param string $option Bijgewerkte optienaam.
 */
function syc_maybe_release_settings_lock( $option ) {
	if ( 'syc_settings' === $option ) {
		syc_release_pending_settings_lock();

		if ( ! empty( $GLOBALS['syc_removed_main_feed_keys'] ) ) {
			delete_transient( $GLOBALS['syc_removed_main_feed_keys']['cache'] );
			delete_option( $GLOBALS['syc_removed_main_feed_keys']['stale'] );
			delete_option( 'syc_api_feed_meta' );
			unset( $GLOBALS['syc_removed_main_feed_keys'] );
		}

		if ( ! empty( $GLOBALS['syc_removed_playlist_ids'] ) ) {
			syc_cleanup_playlist_caches( $GLOBALS['syc_removed_playlist_ids'] );
			unset( $GLOBALS['syc_removed_playlist_ids'] );
		}

		if ( ! empty( $GLOBALS['syc_settings_should_purge'] ) ) {
			unset( $GLOBALS['syc_settings_should_purge'] );
			syc_purge_page_caches();
		}
	}
}
add_action( 'updated_option', 'syc_maybe_release_settings_lock', 10, 1 );
add_action( 'added_option', 'syc_maybe_release_settings_lock', 10, 1 );
add_action( 'shutdown', 'syc_release_pending_settings_lock' );

/**
 * Werk syc_settings atomair bij vanuit interne pluginacties.
 *
 * @param callable $mutator Ontvangt de actuele instellingen en retourneert de nieuwe instellingen.
 * @return array|WP_Error Nieuwe instellingen of fout.
 */
function syc_update_settings_locked( $mutator ) {
	$lock = syc_acquire_settings_lock();
	if ( false === $lock ) {
		return new WP_Error( 'syc_settings_locked', __( 'De instellingen worden momenteel elders bijgewerkt. Probeer het opnieuw.', 'scientias-youtube-carrousel' ) );
	}

	try {
		$current = syc_normalize_settings( get_option( 'syc_settings', array() ) );
		$updated = call_user_func( $mutator, $current );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$updated = syc_normalize_settings( $updated );
		if ( $updated === $current ) {
			return $current;
		}

		$GLOBALS['syc_internal_settings_update'] = true;
		$written                                 = update_option( 'syc_settings', $updated );
		unset( $GLOBALS['syc_internal_settings_update'] );
		$stored = syc_normalize_settings( get_option( 'syc_settings', array() ) );
		if ( ! $written || $stored !== $updated ) {
			return new WP_Error( 'syc_settings_write_failed', __( 'De instellingen konden niet betrouwbaar in de database worden opgeslagen.', 'scientias-youtube-carrousel' ) );
		}

		return $stored;
	} finally {
		unset( $GLOBALS['syc_internal_settings_update'] );
		syc_release_lock( SYC_SETTINGS_LOCK_KEY, $lock );
	}
}

/**
 * Verwijder de caches van playlists die niet meer geconfigureerd zijn.
 *
 * @param array $playlist_ids Playlist-ID's.
 */
function syc_cleanup_playlist_caches( $playlist_ids ) {
	$lock = syc_acquire_lock( SYC_FEED_REFRESH_LOCK_KEY, SYC_REFRESH_LOCK_TTL );
	if ( false === $lock ) {
		$pending = get_option( SYC_PENDING_PLAYLIST_CLEANUP_OPTION, array() );
		$pending = is_array( $pending ) ? $pending : array();
		update_option( SYC_PENDING_PLAYLIST_CLEANUP_OPTION, array_values( array_unique( array_merge( $pending, (array) $playlist_ids ) ) ), false );
		if ( ! wp_next_scheduled( SYC_PLAYLIST_CLEANUP_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, SYC_PLAYLIST_CLEANUP_HOOK );
		}
		return;
	}

	$registry = get_option( SYC_PLAYLIST_CACHE_REGISTRY_OPTION, array() );
	$registry = is_array( $registry ) ? $registry : array();
	$active   = syc_get_carrousel_playlist_ids( syc_get_settings()['custom_carrousels'] );

	try {
		foreach ( array_unique( (array) $playlist_ids ) as $playlist_id ) {
			$playlist_id = syc_extract_youtube_playlist_id( $playlist_id );
			if ( '' === $playlist_id || in_array( $playlist_id, $active, true ) ) {
				continue;
			}

			$hash = md5( $playlist_id );
			delete_transient( SYC_PLAYLIST_CACHE_PREFIX . $hash );
			delete_option( SYC_PLAYLIST_STALE_PREFIX . $hash );
			delete_option( SYC_PLAYLIST_META_PREFIX . $hash );
			unset( $registry[ $hash ] );
		}

		update_option( SYC_PLAYLIST_CACHE_REGISTRY_OPTION, $registry, false );
	} finally {
		syc_release_lock( SYC_FEED_REFRESH_LOCK_KEY, $lock );
	}
}

/**
 * Verwerk uitgestelde cachecleanup zodra geen refresh meer actief is.
 */
function syc_process_pending_playlist_cleanup() {
	$playlist_ids = get_option( SYC_PENDING_PLAYLIST_CLEANUP_OPTION, array() );
	delete_option( SYC_PENDING_PLAYLIST_CLEANUP_OPTION );
	if ( ! empty( $playlist_ids ) && is_array( $playlist_ids ) ) {
		syc_cleanup_playlist_caches( $playlist_ids );
	}
}
add_action( SYC_PLAYLIST_CLEANUP_HOOK, 'syc_process_pending_playlist_cleanup' );

/**
 * Register custom post type for carrousel items.
 */
function syc_register_video_post_type() {
	$labels = array(
		'name'               => __( 'Losse video-items', 'scientias-youtube-carrousel' ),
		'singular_name'      => __( 'Los video-item', 'scientias-youtube-carrousel' ),
		'add_new'            => __( 'Nieuw los video-item', 'scientias-youtube-carrousel' ),
		'add_new_item'       => __( 'Nieuw los video-item toevoegen', 'scientias-youtube-carrousel' ),
		'edit_item'          => __( 'Los video-item bewerken', 'scientias-youtube-carrousel' ),
		'new_item'           => __( 'Nieuw los video-item', 'scientias-youtube-carrousel' ),
		'all_items'          => __( 'Losse video-items', 'scientias-youtube-carrousel' ),
		'view_item'          => __( 'Los video-item bekijken', 'scientias-youtube-carrousel' ),
		'search_items'       => __( 'Losse video-items zoeken', 'scientias-youtube-carrousel' ),
		'not_found'          => __( 'Geen losse video-items gevonden', 'scientias-youtube-carrousel' ),
		'not_found_in_trash' => __( 'Geen losse video-items in de prullenbak', 'scientias-youtube-carrousel' ),
		'menu_name'          => __( 'YouTube carrousel', 'scientias-youtube-carrousel' ),
	);

	$args = array(
		'labels'          => $labels,
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => false,
		'supports'        => array( 'title', 'thumbnail' ),
		'menu_icon'       => 'dashicons-video-alt3',
		'show_in_rest'    => false,
		'capability_type' => 'post',
		'map_meta_cap'    => false,
		'capabilities'    => array(
			'edit_post'              => SYC_EDITORIAL_CAPABILITY,
			'read_post'              => SYC_EDITORIAL_CAPABILITY,
			'delete_post'            => SYC_EDITORIAL_CAPABILITY,
			'edit_posts'             => SYC_EDITORIAL_CAPABILITY,
			'edit_others_posts'      => SYC_EDITORIAL_CAPABILITY,
			'publish_posts'          => SYC_EDITORIAL_CAPABILITY,
			'read_private_posts'     => SYC_EDITORIAL_CAPABILITY,
			'delete_posts'           => SYC_EDITORIAL_CAPABILITY,
			'delete_private_posts'   => SYC_EDITORIAL_CAPABILITY,
			'delete_published_posts' => SYC_EDITORIAL_CAPABILITY,
			'delete_others_posts'    => SYC_EDITORIAL_CAPABILITY,
			'edit_private_posts'     => SYC_EDITORIAL_CAPABILITY,
			'edit_published_posts'   => SYC_EDITORIAL_CAPABILITY,
			'create_posts'           => SYC_EDITORIAL_CAPABILITY,
		),
	);

	register_post_type( 'syc_video', $args );

	// Zorg dat dit post type een uitgelichte afbeelding (thumbnail) kan gebruiken.
	add_theme_support( 'post-thumbnails', array( 'syc_video' ) );
}
add_action( 'init', 'syc_register_video_post_type' );

/**
 * Voeg meta box toe voor de YouTube video-URL.
 */
function syc_add_video_meta_box() {
	add_meta_box(
		'syc_video_url_meta',
		__( 'YouTube video-URL', 'scientias-youtube-carrousel' ),
		'syc_render_video_url_meta_box',
		'syc_video',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'syc_add_video_meta_box' );

/**
 * Render de meta box inhoud.
 *
 * @param WP_Post $post Post object.
 */
function syc_render_video_url_meta_box( $post ) {
	wp_nonce_field( 'syc_save_video_url', 'syc_video_url_nonce' );

	$value      = get_post_meta( $post->ID, '_syc_video_url', true );
	$link_value = get_post_meta( $post->ID, '_syc_link_url', true );

	echo '<p><strong>' . esc_html__( 'Let op: losse video-items worden alleen gebruikt als handmatige bron of fallback wanneer de automatische YouTube API-feed geen video\'s oplevert.', 'scientias-youtube-carrousel' ) . '</strong></p>';
	echo '<p>' . esc_html__( 'Plak hier de volledige YouTube-URL voor dit losse video-item.', 'scientias-youtube-carrousel' ) . '</p>';
	echo '<input type="url" style="width:100%;" id="syc_video_url" name="syc_video_url" value="' . esc_attr( $value ) . '" placeholder="https://www.youtube.com/watch?v=..." />';
	echo '<p style="margin-top:1rem;">' . esc_html__( 'Optionele link onder de video. Laat leeg om de titel als gewone tekst te tonen en de thumbnail fullscreen te openen.', 'scientias-youtube-carrousel' ) . '</p>';
	echo '<input type="url" style="width:100%;" id="syc_link_url" name="syc_link_url" value="' . esc_attr( $link_value ) . '" placeholder="https://www.scientias.nl/..." />';
}

/**
 * Sla de video-URL meta op.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function syc_save_video_url_meta( $post_id ) {
	$nonce = isset( $_POST['syc_video_url_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['syc_video_url_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'syc_save_video_url' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['syc_video_url'] ) ) {
		$url = esc_url_raw( wp_unslash( $_POST['syc_video_url'] ) );
		if ( ! empty( $url ) && syc_extract_youtube_video_id( $url ) ) {
			update_post_meta( $post_id, '_syc_video_url', $url );
		} else {
			delete_post_meta( $post_id, '_syc_video_url' );
		}
	}

	if ( isset( $_POST['syc_link_url'] ) ) {
		$link_url = esc_url_raw( wp_unslash( $_POST['syc_link_url'] ) );
		if ( ! empty( $link_url ) ) {
			update_post_meta( $post_id, '_syc_link_url', $link_url );
		} else {
			delete_post_meta( $post_id, '_syc_link_url' );
		}
	}
}
add_action( 'save_post', 'syc_save_video_url_meta' );

/**
 * Toon uitleg boven de lijst met losse video-items.
 */
function syc_render_manual_items_notice() {
	$screen = get_current_screen();

	if ( ! $screen || 'edit-syc_video' !== $screen->id ) {
		return;
	}

	?>
	<div class="notice notice-info">
		<p>
			<?php esc_html_e( 'Losse video-items zijn bedoeld als handmatige bron of fallback. Als de YouTube API-feed correct werkt, toont de carrousel standaard de automatische feed. Gebruik Link overrides om automatische feedvideo\'s aan pagina\'s te koppelen.', 'scientias-youtube-carrousel' ); ?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'syc_render_manual_items_notice' );

/**
 * Purge bekende page caches zonder de laatst opgehaalde feeddata te wissen.
 */
function syc_purge_page_caches() {
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}

	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
}

/**
 * Flush caches wanneer handmatige video-items wijzigen.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function syc_maybe_clear_manual_video_cache( $post_id, $post ) {
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	if ( 'syc_video' !== $post->post_type ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	syc_purge_page_caches();
}
add_action( 'save_post', 'syc_maybe_clear_manual_video_cache', 20, 2 );

/**
 * Flush caches wanneer handmatige video-items verwijderd of verplaatst worden.
 *
 * @param int $post_id Post ID.
 */
function syc_maybe_clear_deleted_video_cache( $post_id ) {
	if ( 'syc_video' === get_post_type( $post_id ) ) {
		syc_purge_page_caches();
	}
}
add_action( 'before_delete_post', 'syc_maybe_clear_deleted_video_cache' );
add_action( 'trashed_post', 'syc_maybe_clear_deleted_video_cache' );

/**
 * Registreer en laad scripts en styles.
 */
function syc_register_assets() {
	wp_register_style(
		'syc-carrousel-style',
		plugin_dir_url( __FILE__ ) . 'assets/css/syc-carrousel.css',
		array(),
		SYC_VERSION
	);

	wp_register_script(
		'syc-carrousel-script',
		plugin_dir_url( __FILE__ ) . 'assets/js/syc-carrousel.js',
		array(),
		SYC_VERSION,
		true
	);
}
add_action( 'init', 'syc_register_assets' );

/**
 * Laad de artikelzoeker uitsluitend op het redactionele video-overzicht.
 */
function syc_enqueue_video_overview_assets() {
	if ( 'syc-video-overview' !== syc_get_current_admin_page_slug() || ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		return;
	}

	wp_enqueue_script(
		'syc-admin-video-overview',
		plugin_dir_url( __FILE__ ) . 'assets/js/syc-admin-video-overview.js',
		array( 'jquery', 'jquery-ui-autocomplete', 'wp-util', 'wp-a11y' ),
		SYC_VERSION,
		true
	);
	wp_localize_script(
		'syc-admin-video-overview',
		'sycVideoOverview',
		array(
			'nonce'       => wp_create_nonce( 'syc_search_editorial_posts' ),
			'loading'     => __( 'Artikelen zoeken…', 'scientias-youtube-carrousel' ),
			'noResults'   => __( 'Geen bewerkbare artikelen gevonden.', 'scientias-youtube-carrousel' ),
			'selectFirst' => __( 'Kies eerst een artikel uit de zoekresultaten.', 'scientias-youtube-carrousel' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'syc_enqueue_video_overview_assets' );

/**
 * Laad beheeropmaak voor extra carrousels uitsluitend op die pluginpagina.
 */
function syc_enqueue_custom_carrousel_admin_assets() {
	if ( 'syc-custom-carrousels' !== syc_get_current_admin_page_slug() || ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		return;
	}

	wp_enqueue_style(
		'syc-carrousel-admin',
		plugin_dir_url( __FILE__ ) . 'assets/css/syc-carrousel-admin.css',
		array(),
		SYC_VERSION
	);
	wp_enqueue_script(
		'syc-carrousel-admin',
		plugin_dir_url( __FILE__ ) . 'assets/js/syc-carrousel-admin.js',
		array( 'jquery', 'jquery-ui-sortable' ),
		SYC_VERSION,
		true
	);
	wp_localize_script(
		'syc-carrousel-admin',
		'sycCarrouselAdmin',
		array(
			'copied'     => __( 'Shortcode gekopieerd.', 'scientias-youtube-carrousel' ),
			'copyFailed' => __( 'Kopiëren is niet gelukt; selecteer de shortcode handmatig.', 'scientias-youtube-carrousel' ),
		)
	);

	// Alleen navigatie; deze waarde wijzigt geen gegevens.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$preview_slug = isset( $_GET['syc_preview'] ) ? syc_sanitize_carrousel_slug( sanitize_text_field( wp_unslash( $_GET['syc_preview'] ) ) ) : '';
	if ( '' !== $preview_slug && isset( syc_get_settings()['custom_carrousels'][ $preview_slug ] ) ) {
		wp_enqueue_style( 'syc-carrousel-style' );
		wp_enqueue_script( 'syc-carrousel-script' );
	}
}
add_action( 'admin_enqueue_scripts', 'syc_enqueue_custom_carrousel_admin_assets' );

/**
 * Voorkom dat WordPress de block-level carrousel in een alinea wikkelt.
 *
 * @param string $content Post content.
 * @return string
 */
function syc_unwrap_shortcode_paragraphs( $content ) {
	$shortcodes = array( 'scientias_youtube_carrousel' );
	$pattern    = get_shortcode_regex( $shortcodes );

	return preg_replace( '/<p>\s*(' . $pattern . ')\s*<\/p>/s', '$1', $content );
}
add_filter( 'the_content', 'syc_unwrap_shortcode_paragraphs', 9 );

/**
 * Registreer instellingen voor YouTube Data API.
 */
function syc_register_settings() {
	$args = array(
		'type'              => 'array',
		'sanitize_callback' => 'syc_sanitize_settings',
		'default'           => array(
			'api_key'            => '',
			'channel_id'         => '',
			'max_items'          => 8,
			'auto_draft'         => 0,
			'draft_author_id'    => 0,
			'draft_category_ids' => array(),
			'draft_post_format'  => 'video',
			'draft_default_text' => syc_get_default_auto_draft_text(),
			'draft_post_status'  => 'draft',
			'link_overrides'     => array(),
			'custom_carrousels'  => array(),
		),
	);
	register_setting( 'syc_settings_group', 'syc_settings', $args );
	register_setting( 'syc_content_settings_group', 'syc_settings', $args );
}
add_action( 'admin_init', 'syc_register_settings' );

/**
 * Sta de redactionele settingsgroep toe voor editors.
 *
 * @return string
 */
function syc_content_settings_capability() {
	return SYC_EDITORIAL_CAPABILITY;
}
add_filter( 'option_page_capability_syc_content_settings_group', 'syc_content_settings_capability' );

/**
 * Sanitize een carrousel slug.
 *
 * @param mixed $slug Ruwe slug.
 * @return string
 */
function syc_sanitize_carrousel_slug( $slug ) {
	$slug = sanitize_title( (string) $slug );
	$slug = preg_replace( '/[^a-z0-9_-]/', '', $slug );

	return trim( $slug, '-_' );
}

/**
 * Sanitize een YouTube playlist-ID.
 *
 * @param mixed $playlist_id Ruwe playlist-ID.
 * @return string
 */
function syc_sanitize_youtube_playlist_id( $playlist_id ) {
	$playlist_id = trim( sanitize_text_field( (string) $playlist_id ) );

	if ( ! preg_match( '/^[A-Za-z0-9_-]{10,80}$/', $playlist_id ) ) {
		return '';
	}

	return $playlist_id;
}

/**
 * Haal een YouTube playlist-ID uit een ID of URL.
 *
 * @param mixed $playlist_input Playlist-ID of URL.
 * @return string
 */
function syc_extract_youtube_playlist_id( $playlist_input ) {
	$playlist_input = trim( (string) $playlist_input );

	if ( '' === $playlist_input ) {
		return '';
	}

	$direct_id = syc_sanitize_youtube_playlist_id( $playlist_input );
	if ( '' !== $direct_id ) {
		return $direct_id;
	}

	$parts = wp_parse_url( $playlist_input );
	if ( empty( $parts['host'] ) ) {
		return '';
	}

	$host = strtolower( $parts['host'] );
	if ( ! in_array( $host, array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be' ), true ) ) {
		return '';
	}

	if ( empty( $parts['query'] ) ) {
		return '';
	}

	parse_str( $parts['query'], $query );
	if ( empty( $query['list'] ) ) {
		return '';
	}

	return syc_sanitize_youtube_playlist_id( $query['list'] );
}

/**
 * Sanitize extra handmatige carrousels.
 *
 * @param mixed $raw_carrousels Ruwe input.
 * @param int   $max_carrousels Maximum aantal carrousels; 0 bewaart bestaande data.
 * @param int   $max_items      Maximum aantal items per carrousel; 0 bewaart bestaande data.
 * @return array
 */
function syc_sanitize_custom_carrousels( $raw_carrousels, $max_carrousels = 0, $max_items = 0 ) {
	if ( ! is_array( $raw_carrousels ) ) {
		return array();
	}

	$carrousels = array();

	foreach ( $raw_carrousels as $raw_slug => $raw_carrousel ) {
		if ( $max_carrousels > 0 && count( $carrousels ) >= $max_carrousels ) {
			break;
		}

		if ( ! is_array( $raw_carrousel ) ) {
			continue;
		}

		$name        = isset( $raw_carrousel['name'] ) ? sanitize_text_field( wp_unslash( $raw_carrousel['name'] ) ) : '';
		$slug        = isset( $raw_carrousel['slug'] ) ? syc_sanitize_carrousel_slug( wp_unslash( $raw_carrousel['slug'] ) ) : '';
		$playlist_id = isset( $raw_carrousel['playlist_id'] ) ? syc_extract_youtube_playlist_id( wp_unslash( $raw_carrousel['playlist_id'] ) ) : '';

		if ( '' === $slug && is_string( $raw_slug ) ) {
			$slug = syc_sanitize_carrousel_slug( $raw_slug );
		}

		if ( '' === $slug && '' !== $name ) {
			$slug = syc_sanitize_carrousel_slug( $name );
		}

		if ( '' === $slug ) {
			continue;
		}

		$items = array();
		if ( ! empty( $raw_carrousel['items'] ) && is_array( $raw_carrousel['items'] ) ) {
			foreach ( $raw_carrousel['items'] as $raw_item ) {
				if ( $max_items > 0 && count( $items ) >= $max_items ) {
					break;
				}

				if ( ! is_array( $raw_item ) ) {
					continue;
				}

				$title     = isset( $raw_item['title'] ) ? sanitize_text_field( wp_unslash( $raw_item['title'] ) ) : '';
				$video_url = isset( $raw_item['video_url'] ) ? esc_url_raw( wp_unslash( $raw_item['video_url'] ) ) : '';
				$link_url  = isset( $raw_item['link_url'] ) ? esc_url_raw( wp_unslash( $raw_item['link_url'] ) ) : '';

				if ( '' === $video_url || '' === syc_extract_youtube_video_id( $video_url ) ) {
					continue;
				}

				$items[] = array(
					'title'     => '' !== $title ? $title : __( 'Video', 'scientias-youtube-carrousel' ),
					'video_url' => $video_url,
					'link_url'  => $link_url,
				);
			}
		}

		if ( empty( $items ) && '' === $playlist_id ) {
			continue;
		}

		$carrousels[ $slug ] = array(
			'name'        => '' !== $name ? $name : $slug,
			'slug'        => $slug,
			'playlist_id' => $playlist_id,
			'items'       => $items,
		);
	}

	return $carrousels;
}

/**
 * Sanitize link overrides voor YouTube feed-items.
 *
 * @param mixed $raw_overrides Ruwe input.
 * @param int   $max_overrides Maximum aantal overrides; 0 bewaart bestaande data.
 * @return array
 */
function syc_sanitize_link_overrides( $raw_overrides, $max_overrides = 0 ) {
	if ( ! is_array( $raw_overrides ) ) {
		return array();
	}

	$overrides = array();

	foreach ( $raw_overrides as $raw_video_id => $row ) {
		if ( $max_overrides > 0 && count( $overrides ) >= $max_overrides ) {
			break;
		}

		if ( is_string( $row ) ) {
			$row = array(
				'video_id' => is_string( $raw_video_id ) ? $raw_video_id : '',
				'url'      => $row,
			);
		} elseif ( ! is_array( $row ) ) {
			continue;
		}

		$video_id = isset( $row['video_id'] ) ? syc_sanitize_youtube_video_id( wp_unslash( $row['video_id'] ) ) : '';
		$url      = isset( $row['url'] ) ? esc_url_raw( wp_unslash( $row['url'] ) ) : '';

		if ( '' === $video_id || '' === $url ) {
			continue;
		}

		$overrides[ $video_id ] = $url;
	}

	return $overrides;
}

/**
 * Geef een salted wijzigingshash voor één link override.
 *
 * @param string $video_id YouTube video-ID.
 * @param string $url      Huidige URL.
 * @return string
 */
function syc_get_link_override_hash( $video_id, $url ) {
	return wp_hash( $video_id . '|' . $url, 'nonce' );
}

/**
 * Sanitize een YouTube video-ID.
 *
 * @param mixed $video_id Ruwe video-ID.
 * @return string
 */
function syc_sanitize_youtube_video_id( $video_id ) {
	$video_id = trim( sanitize_text_field( (string) $video_id ) );

	if ( ! preg_match( '/^[A-Za-z0-9_-]{6,20}$/', $video_id ) ) {
		return '';
	}

	return $video_id;
}

/**
 * Parse een CSV-bestand met link overrides.
 *
 * Verwacht twee kolommen: YouTube video-ID en URL. Een header-rij is toegestaan.
 *
 * @param string $file_path Tijdelijk uploadpad.
 * @return array|WP_Error
 */
function syc_parse_link_overrides_csv( $file_path ) {
	// WP_Filesystem biedt geen streaming CSV-parser voor tijdelijke uploads.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$handle = fopen( $file_path, 'r' );
	if ( false === $handle ) {
		return new WP_Error( 'syc_csv_open_failed', __( 'Het CSV-bestand kon niet worden geopend.', 'scientias-youtube-carrousel' ) );
	}

	$overrides = array();
	$line      = 0;
	$data_rows = 0;
	$skipped   = 0;
	$delimiter = ',';

	$first_line = fgets( $handle );
	if ( false !== $first_line ) {
		$delimiter = substr_count( $first_line, ';' ) > substr_count( $first_line, ',' ) ? ';' : ',';
		rewind( $handle );
	}

	$truncated = false;

	while ( true ) {
		$row = fgetcsv( $handle, 0, $delimiter );
		if ( false === $row ) {
			break;
		}
		++$line;

		if ( 1 === $line && isset( $row[0], $row[1] ) ) {
			$first_header  = strtolower( trim( (string) $row[0] ) );
			$second_header = strtolower( trim( (string) $row[1] ) );

			if ( in_array( $first_header, array( 'youtube_video_id', 'video_id', 'youtube-id', 'youtube id' ), true ) && in_array( $second_header, array( 'url', 'link', 'short', 'pagina' ), true ) ) {
				continue;
			}
		}

		++$data_rows;
		if ( $data_rows > SYC_CSV_IMPORT_MAX_ROWS ) {
			$truncated = true;
			break;
		}

		if ( empty( $row ) || ( isset( $row[0] ) && '' === trim( (string) $row[0] ) ) ) {
			++$skipped;
			continue;
		}

		$video_id = isset( $row[0] ) ? syc_sanitize_youtube_video_id( $row[0] ) : '';
		$url      = isset( $row[1] ) ? esc_url_raw( trim( (string) $row[1] ) ) : '';

		if ( '' === $video_id || '' === $url ) {
			++$skipped;
			continue;
		}

		if ( isset( $overrides[ $video_id ] ) ) {
			++$skipped;
		}
		$overrides[ $video_id ] = $url;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $handle );

	if ( empty( $overrides ) ) {
		return new WP_Error( 'syc_csv_no_rows', __( 'Er zijn geen geldige video-ID/URL-koppelingen gevonden in het CSV-bestand.', 'scientias-youtube-carrousel' ) );
	}

	return array(
		'overrides' => $overrides,
		'imported'  => count( $overrides ),
		'skipped'   => $skipped,
		'truncated' => $truncated,
	);
}

/**
 * Verwerk CSV-import voor link overrides.
 *
 * @return array Importmeldingen.
 */
function syc_maybe_import_link_overrides_csv() {
	if ( empty( $_POST['syc_csv_import_submit'] ) ) {
		return array();
	}

	if ( ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		return array(
			'type'    => 'error',
			'message' => __( 'Je hebt geen rechten om link overrides te importeren.', 'scientias-youtube-carrousel' ),
		);
	}

	$nonce = isset( $_POST['syc_csv_import_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['syc_csv_import_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'syc_csv_import_action' ) ) {
		return array(
			'type'    => 'error',
			'message' => __( 'De CSV-import kon niet worden gevalideerd. Probeer het opnieuw.', 'scientias-youtube-carrousel' ),
		);
	}

	$upload_error = isset( $_FILES['syc_link_overrides_csv']['error'] ) ? (int) $_FILES['syc_link_overrides_csv']['error'] : UPLOAD_ERR_NO_FILE;
	$tmp_name     = isset( $_FILES['syc_link_overrides_csv']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['syc_link_overrides_csv']['tmp_name'] ) ) : '';

	if ( UPLOAD_ERR_OK !== $upload_error || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		return array(
			'type'    => 'error',
			'message' => __( 'Kies eerst een CSV-bestand om te uploaden.', 'scientias-youtube-carrousel' ),
		);
	}

	$file_size = isset( $_FILES['syc_link_overrides_csv']['size'] ) ? (int) $_FILES['syc_link_overrides_csv']['size'] : 0;
	if ( $file_size <= 0 || $file_size > SYC_CSV_IMPORT_MAX_FILE_SIZE ) {
		return array(
			'type'    => 'error',
			/* translators: %d: maximum file size in MB. */
			'message' => sprintf( __( 'Het CSV-bestand is leeg of groter dan de limiet van %d MB.', 'scientias-youtube-carrousel' ), SYC_CSV_IMPORT_MAX_FILE_SIZE / MB_IN_BYTES ),
		);
	}

	$file_name = isset( $_FILES['syc_link_overrides_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['syc_link_overrides_csv']['name'] ) ) : '';
	$filetype  = wp_check_filetype_and_ext( $tmp_name, $file_name, array( 'csv' => 'text/csv' ) );
	if ( empty( $filetype['ext'] ) || 'csv' !== $filetype['ext'] ) {
		return array(
			'type'    => 'error',
			'message' => __( 'Upload een bestand met de extensie .csv.', 'scientias-youtube-carrousel' ),
		);
	}

	$parsed = syc_parse_link_overrides_csv( $tmp_name );
	if ( is_wp_error( $parsed ) ) {
		return array(
			'type'    => 'error',
			'message' => $parsed->get_error_message(),
		);
	}
	if ( ! empty( $parsed['truncated'] ) ) {
		return array(
			'type'    => 'error',
			'message' => sprintf(
				/* translators: %d: maximum number of data rows. */
				__( 'De CSV is niet geïmporteerd omdat deze meer dan %d gegevensregels bevat. Splits het bestand en probeer opnieuw.', 'scientias-youtube-carrousel' ),
				SYC_CSV_IMPORT_MAX_ROWS
			),
		);
	}

	$mode               = isset( $_POST['syc_csv_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['syc_csv_import_mode'] ) ) : 'merge';
	$imported_overrides = $parsed['overrides'];
	$new_settings       = syc_update_settings_locked(
		function ( $current ) use ( $mode, $imported_overrides ) {
			$current_overrides = ! empty( $current['link_overrides'] ) && is_array( $current['link_overrides'] ) ? $current['link_overrides'] : array();
			$new_overrides     = 'replace' === $mode ? $imported_overrides : array_merge( $current_overrides, $imported_overrides );

			if ( count( $new_overrides ) > SYC_MAX_LINK_OVERRIDES ) {
				return new WP_Error( 'syc_link_limit', __( 'De import is niet opgeslagen omdat het maximumaantal link overrides zou worden overschreden.', 'scientias-youtube-carrousel' ) );
			}

			$current['link_overrides'] = syc_sanitize_link_overrides( $new_overrides, SYC_MAX_LINK_OVERRIDES );
			return $current;
		}
	);

	if ( is_wp_error( $new_settings ) ) {
		return array(
			'type'    => 'error',
			'message' => $new_settings->get_error_message(),
		);
	}

	syc_purge_page_caches();

	if ( 'replace' === $mode ) {
		$message = sprintf(
			/* translators: 1: imported row count, 2: skipped row count. */
			__( 'CSV geïmporteerd: %1$d koppelingen opgeslagen. Alle eerdere link overrides zijn overschreven. Overgeslagen regels: %2$d.', 'scientias-youtube-carrousel' ),
			(int) $parsed['imported'],
			(int) $parsed['skipped']
		);
	} else {
		$message = sprintf(
			/* translators: 1: imported row count, 2: total row count, 3: skipped row count. */
			__( 'CSV geïmporteerd: %1$d koppelingen toegevoegd of bijgewerkt. Totaal opgeslagen koppelingen: %2$d. Overgeslagen regels: %3$d.', 'scientias-youtube-carrousel' ),
			(int) $parsed['imported'],
			count( $new_settings['link_overrides'] ),
			(int) $parsed['skipped']
		);
	}

	return array(
		'type'    => 'success',
		'message' => $message,
	);
}

/**
 * Geef de standaardtekst voor automatisch aangemaakte berichten.
 *
 * @return string
 */
function syc_get_default_auto_draft_text() {
	return __( 'Dit concept is automatisch aangemaakt vanuit de YouTube-feed. Vul de tekst aan en publiceer het bericht; de link onder de carrouselvideo wordt bij publicatie automatisch gekoppeld.', 'scientias-youtube-carrousel' );
}

/**
 * Sanitize een lijst categorie-ID's voor automatische berichten.
 *
 * @param mixed $category_ids Ruwe categorie-ID's.
 * @return int[]
 */
function syc_sanitize_draft_category_ids( $category_ids ) {
	$sanitized = array();
	foreach ( array_slice( (array) $category_ids, 0, 100 ) as $category_id ) {
		$category_id = absint( $category_id );
		if ( $category_id > 0 && term_exists( $category_id, 'category' ) ) {
			$sanitized[ $category_id ] = $category_id;
		}
	}
	return array_values( $sanitized );
}

/**
 * Sanitize het berichtformaat voor automatische berichten.
 *
 * @param mixed $format Ruw berichtformaat.
 * @return string
 */
function syc_sanitize_draft_post_format( $format ) {
	$format  = sanitize_key( $format );
	$allowed = array_merge( array( 'standard' ), array_keys( get_post_format_strings() ) );
	return in_array( $format, $allowed, true ) ? $format : 'video';
}

/**
 * Sanitize de berichtstatus voor automatische berichten.
 *
 * @param mixed $status Ruwe berichtstatus.
 * @return string
 */
function syc_sanitize_draft_post_status( $status ) {
	$status = sanitize_key( $status );
	return in_array( $status, array( 'draft', 'pending', 'publish', 'private' ), true ) ? $status : 'draft';
}

/**
 * Sanitize de standaardtekst voor automatische berichten.
 *
 * @param mixed $text Ruwe standaardtekst.
 * @return string
 */
function syc_sanitize_draft_default_text( $text ) {
	$text = wp_kses_post( (string) $text );
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 5000 ) : substr( $text, 0, 5000 );
}

/**
 * Normalize opgeslagen instellingen zonder save-side effects.
 *
 * @param array $settings Ruwe opgeslagen settings.
 * @return array
 */
function syc_normalize_settings( $settings ) {
	$settings             = (array) $settings;
	$has_draft_author     = array_key_exists( 'draft_author_id', $settings );
	$has_draft_categories = array_key_exists( 'draft_category_ids', $settings );
	$has_draft_text       = array_key_exists( 'draft_default_text', $settings );
	$defaults             = array(
		'api_key'            => '',
		'channel_id'         => '',
		'max_items'          => 8,
		'auto_draft'         => 0,
		'draft_author_id'    => 0,
		'draft_category_ids' => array(),
		'draft_post_format'  => 'video',
		'draft_default_text' => syc_get_default_auto_draft_text(),
		'draft_post_status'  => 'draft',
		'link_overrides'     => array(),
		'custom_carrousels'  => array(),
	);

	$settings        = wp_parse_args( $settings, $defaults );
	$draft_author_id = $has_draft_author ? absint( $settings['draft_author_id'] ) : syc_get_default_draft_author_id();
	$draft_author    = $draft_author_id > 0 ? get_user_by( 'id', $draft_author_id ) : false;
	if ( ! $draft_author || ! user_can( $draft_author, 'edit_posts' ) ) {
		$draft_author_id = 0;
	}

	return array(
		'api_key'            => trim( sanitize_text_field( $settings['api_key'] ) ),
		'channel_id'         => trim( sanitize_text_field( $settings['channel_id'] ) ),
		'max_items'          => min( max( 1, absint( $settings['max_items'] ) ), 50 ),
		'auto_draft'         => ! empty( $settings['auto_draft'] ) ? 1 : 0,
		'draft_author_id'    => $draft_author_id,
		'draft_category_ids' => $has_draft_categories ? syc_sanitize_draft_category_ids( $settings['draft_category_ids'] ) : syc_get_default_draft_category_ids(),
		'draft_post_format'  => syc_sanitize_draft_post_format( $settings['draft_post_format'] ),
		'draft_default_text' => syc_sanitize_draft_default_text( $has_draft_text ? $settings['draft_default_text'] : syc_get_default_auto_draft_text() ),
		'draft_post_status'  => syc_sanitize_draft_post_status( $settings['draft_post_status'] ),
		'link_overrides'     => syc_sanitize_link_overrides( $settings['link_overrides'] ),
		'custom_carrousels'  => syc_sanitize_custom_carrousels( $settings['custom_carrousels'] ),
	);
}

/**
 * Geef een salted wijzigingshash voor de technische en auto-draftinstellingen.
 *
 * @param array $settings Genormaliseerde instellingen.
 * @return string
 */
function syc_get_general_settings_hash( $settings ) {
	$keys = array( 'api_key', 'channel_id', 'max_items', 'auto_draft', 'draft_author_id', 'draft_category_ids', 'draft_post_format', 'draft_default_text', 'draft_post_status' );
	$data = array_intersect_key( syc_normalize_settings( $settings ), array_flip( $keys ) );
	return wp_hash( wp_json_encode( $data ), 'nonce' );
}

/**
 * Controleer invoerlimieten zonder bestaande opgeslagen data af te kappen.
 *
 * @param mixed $raw_carrousels Ruwe formulierinvoer.
 * @return bool
 */
function syc_custom_carrousels_exceed_limits( $raw_carrousels ) {
	if ( ! is_array( $raw_carrousels ) ) {
		return false;
	}

	// Het formulier bevat één lege nieuwe carrousel en vijf lege itemrijen.
	if ( count( $raw_carrousels ) > SYC_MAX_CUSTOM_CARROUSELS + 1 ) {
		return true;
	}
	foreach ( $raw_carrousels as $carrousel ) {
		if ( is_array( $carrousel ) && ! empty( $carrousel['items'] ) && is_array( $carrousel['items'] ) && count( $carrousel['items'] ) > SYC_MAX_CUSTOM_CARROUSEL_ITEMS + SYC_CUSTOM_CARROUSEL_EMPTY_ROWS ) {
			return true;
		}
	}

	$sanitized = syc_sanitize_custom_carrousels( $raw_carrousels, SYC_MAX_CUSTOM_CARROUSELS + 1, SYC_MAX_CUSTOM_CARROUSEL_ITEMS + 1 );
	if ( count( $sanitized ) > SYC_MAX_CUSTOM_CARROUSELS ) {
		return true;
	}
	foreach ( $sanitized as $carrousel ) {
		if ( count( $carrousel['items'] ) > SYC_MAX_CUSTOM_CARROUSEL_ITEMS ) {
			return true;
		}
	}

	return false;
}

/**
 * Verzamel unieke playlist-ID's uit carrouselinstellingen.
 *
 * @param array $carrousels Carrouselinstellingen.
 * @return array
 */
function syc_get_carrousel_playlist_ids( $carrousels ) {
	$playlist_ids = array();
	foreach ( (array) $carrousels as $carrousel ) {
		if ( empty( $carrousel['playlist_id'] ) ) {
			continue;
		}
		$playlist_id = syc_extract_youtube_playlist_id( $carrousel['playlist_id'] );
		if ( '' !== $playlist_id ) {
			$playlist_ids[ $playlist_id ] = $playlist_id;
		}
	}

	return array_values( $playlist_ids );
}

/**
 * Sanitize instellingen.
 *
 * @param array $input Raw settings.
 * @return array
 */
function syc_sanitize_settings( $input ) {
	if ( ! empty( $GLOBALS['syc_internal_settings_update'] ) ) {
		return syc_normalize_settings( $input );
	}
	if ( ! empty( $GLOBALS['syc_pending_settings_lock'] ) ) {
		// Een nieuwe optie wordt door WordPress via update_option() en add_option()
		// tweemaal gesanitized. De eerste pass heeft de sectielogica al toegepast.
		return syc_normalize_settings( $input );
	}

	$lock = syc_acquire_settings_lock();
	if ( false === $lock ) {
		add_settings_error( 'syc_settings', 'syc_settings_locked', __( 'De instellingen zijn niet opgeslagen omdat een andere wijziging bezig is. Probeer het opnieuw.', 'scientias-youtube-carrousel' ), 'error' );
		return syc_normalize_settings( get_option( 'syc_settings', array() ) );
	}
	$GLOBALS['syc_pending_settings_lock'] = $lock;

	$old_settings = syc_normalize_settings( get_option( 'syc_settings', array() ) );
	$output       = $old_settings;
	// options.php controleert de register_setting-nonce voordat deze callback draait.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$section             = isset( $_POST['syc_settings_section'] ) ? sanitize_key( wp_unslash( $_POST['syc_settings_section'] ) ) : 'legacy';
	$content_sections    = array( 'link_add', 'link_edit', 'custom_carrousels' );
	$required_capability = in_array( $section, $content_sections, true ) ? SYC_EDITORIAL_CAPABILITY : 'manage_options';
	if ( ! current_user_can( $required_capability ) ) {
		add_settings_error( 'syc_settings', 'syc_settings_forbidden', __( 'Je hebt geen rechten om dit onderdeel te wijzigen.', 'scientias-youtube-carrousel' ), 'error' );
		return $old_settings;
	}
	if ( 'general' === $section ) {
		// options.php heeft de requestnonce al gevalideerd; deze hash voorkomt alleen stale writes.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$revision = isset( $_POST['syc_settings_revision'] ) ? sanitize_text_field( wp_unslash( $_POST['syc_settings_revision'] ) ) : '';
		if ( '' === $revision || ! hash_equals( syc_get_general_settings_hash( $old_settings ), $revision ) ) {
			add_settings_error( 'syc_settings', 'syc_settings_stale', __( 'De instellingen zijn ondertussen gewijzigd. Herlaad de pagina voordat je opnieuw opslaat.', 'scientias-youtube-carrousel' ), 'error' );
			return $old_settings;
		}
	}

	if ( in_array( $section, array( 'general', 'legacy' ), true ) ) {
		$new_api_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		if ( '' !== $new_api_key ) {
			$output['api_key'] = $new_api_key;
		}
		if ( isset( $input['channel_id'] ) ) {
			$output['channel_id'] = trim( sanitize_text_field( $input['channel_id'] ) );
		}
		if ( isset( $input['max_items'] ) ) {
			$output['max_items'] = min( max( 1, absint( $input['max_items'] ) ), 50 );
		}
		if ( isset( $input['auto_draft_present'] ) ) {
			$output['auto_draft'] = ! empty( $input['auto_draft'] ) ? 1 : 0;
		} elseif ( 'legacy' === $section && isset( $input['auto_draft'] ) ) {
			$output['auto_draft'] = ! empty( $input['auto_draft'] ) ? 1 : 0;
		}
		if ( isset( $input['draft_settings_present'] ) ) {
			$author_id = isset( $input['draft_author_id'] ) ? absint( $input['draft_author_id'] ) : 0;
			$author    = $author_id > 0 ? get_user_by( 'id', $author_id ) : false;
			if ( $author && user_can( $author, 'edit_posts' ) ) {
				$output['draft_author_id'] = $author_id;
			} elseif ( 0 === $author_id && ( ! isset( $input['draft_post_status'] ) || 'publish' !== syc_sanitize_draft_post_status( $input['draft_post_status'] ) ) ) {
				$output['draft_author_id'] = 0;
			} elseif ( $author_id > 0 ) {
				add_settings_error( 'syc_settings', 'syc_invalid_draft_author', __( 'De gekozen standaardauteur is ongeldig; de vorige auteur is behouden.', 'scientias-youtube-carrousel' ), 'error' );
			}

			if ( isset( $input['draft_category_ids_present'] ) ) {
				$output['draft_category_ids'] = syc_sanitize_draft_category_ids( isset( $input['draft_category_ids'] ) ? $input['draft_category_ids'] : array() );
			}
			if ( isset( $input['draft_post_format'] ) ) {
				$output['draft_post_format'] = syc_sanitize_draft_post_format( $input['draft_post_format'] );
			}
			if ( isset( $input['draft_default_text'] ) ) {
				$output['draft_default_text'] = syc_sanitize_draft_default_text( $input['draft_default_text'] );
			}
			if ( isset( $input['draft_post_status'] ) ) {
				$status = syc_sanitize_draft_post_status( $input['draft_post_status'] );
				if ( 'publish' === $status && ( ! $author || ! user_can( $author, 'publish_posts' ) ) ) {
					add_settings_error( 'syc_settings', 'syc_draft_author_cannot_publish', __( 'Automatisch publiceren vereist een standaardauteur die berichten mag publiceren; de vorige status is behouden.', 'scientias-youtube-carrousel' ), 'error' );
				} else {
					$output['draft_post_status'] = $status;
				}
			}
		}
	}

	if ( 'link_add' === $section && ! empty( $input['link_overrides']['new'] ) ) {
		$new_override = syc_sanitize_link_overrides( array( $input['link_overrides']['new'] ), 1 );
		if ( ! empty( $new_override ) ) {
			$video_id = key( $new_override );
			if ( ! isset( $output['link_overrides'][ $video_id ] ) && count( $output['link_overrides'] ) >= SYC_MAX_LINK_OVERRIDES ) {
				add_settings_error( 'syc_settings', 'syc_link_limit', __( 'De override is niet toegevoegd: het maximumaantal koppelingen is bereikt.', 'scientias-youtube-carrousel' ), 'error' );
			} else {
				$output['link_overrides'][ $video_id ] = current( $new_override );
			}
		}
	}

	if ( 'link_edit' === $section && ! empty( $input['link_overrides'] ) && is_array( $input['link_overrides'] ) ) {
		$initial_overrides = $output['link_overrides'];
		$operations        = array();
		foreach ( array_slice( $input['link_overrides'], 0, 50 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$original_id   = isset( $row['original_video_id'] ) ? syc_sanitize_youtube_video_id( wp_unslash( $row['original_video_id'] ) ) : '';
			$had_original  = '' !== $original_id && isset( $initial_overrides[ $original_id ] );
			$original_hash = isset( $row['original_hash'] ) ? sanitize_text_field( wp_unslash( $row['original_hash'] ) ) : '';
			if ( ! $had_original || '' === $original_hash || ! hash_equals( syc_get_link_override_hash( $original_id, $initial_overrides[ $original_id ] ), $original_hash ) ) {
				add_settings_error( 'syc_settings', 'syc_link_override_stale_' . md5( $original_id ), __( 'Een link override is ondertussen gewijzigd en is niet overschreven. Herlaad de pagina.', 'scientias-youtube-carrousel' ), 'error' );
				continue;
			}

			$override     = syc_sanitize_link_overrides( array( $row ), 1 );
			$operations[] = array(
				'original_id' => $original_id,
				'video_id'    => ! empty( $override ) ? key( $override ) : '',
				'url'         => ! empty( $override ) ? current( $override ) : '',
			);
		}

		$source_ids      = wp_list_pluck( $operations, 'original_id' );
		$destination_ids = array_values( array_filter( wp_list_pluck( $operations, 'video_id' ) ) );
		$has_collision   = count( $destination_ids ) !== count( array_unique( $destination_ids ) );
		foreach ( $destination_ids as $destination_id ) {
			if ( isset( $initial_overrides[ $destination_id ] ) && ! in_array( $destination_id, $source_ids, true ) ) {
				$has_collision = true;
				break;
			}
		}

		if ( $has_collision ) {
			add_settings_error( 'syc_settings', 'syc_link_override_collision', __( 'De wijzigingen zijn niet opgeslagen omdat meerdere regels dezelfde video-ID gebruiken of een nieuwere override zouden overschrijven.', 'scientias-youtube-carrousel' ), 'error' );
		} else {
			foreach ( $source_ids as $source_id ) {
				unset( $output['link_overrides'][ $source_id ] );
			}
			foreach ( $operations as $operation ) {
				if ( '' !== $operation['video_id'] ) {
					$output['link_overrides'][ $operation['video_id'] ] = $operation['url'];
				}
			}
		}
	}

	if ( 'custom_carrousels' === $section ) {
		if ( empty( $input['custom_carrousels_complete'] ) ) {
			add_settings_error( 'syc_settings', 'syc_carrousel_truncated', __( 'De carrousels zijn niet opgeslagen omdat het formulier onvolledig is ontvangen. Verhoog PHP max_input_vars of beheer minder carrousels tegelijk.', 'scientias-youtube-carrousel' ), 'error' );
		} elseif ( isset( $input['custom_carrousels'] ) && syc_custom_carrousels_exceed_limits( $input['custom_carrousels'] ) ) {
			add_settings_error( 'syc_settings', 'syc_carrousel_limit', __( 'De carrousels zijn niet opgeslagen omdat een ingestelde limiet is overschreden.', 'scientias-youtube-carrousel' ), 'error' );
		} else {
			$raw_carrousels              = isset( $input['custom_carrousels'] ) ? $input['custom_carrousels'] : array();
			$output['custom_carrousels'] = syc_sanitize_custom_carrousels( $raw_carrousels, SYC_MAX_CUSTOM_CARROUSELS, SYC_MAX_CUSTOM_CARROUSEL_ITEMS );
		}
	}

	if ( 'legacy' === $section ) {
		if ( isset( $input['link_overrides'] ) && count( (array) $input['link_overrides'] ) <= SYC_MAX_LINK_OVERRIDES ) {
			$output['link_overrides'] = syc_sanitize_link_overrides( $input['link_overrides'], SYC_MAX_LINK_OVERRIDES );
		}
		if ( isset( $input['custom_carrousels'] ) && ! syc_custom_carrousels_exceed_limits( $input['custom_carrousels'] ) ) {
			$output['custom_carrousels'] = syc_sanitize_custom_carrousels( $input['custom_carrousels'], SYC_MAX_CUSTOM_CARROUSELS, SYC_MAX_CUSTOM_CARROUSEL_ITEMS );
		}
	}

	if ( $old_settings !== $output ) {
		$GLOBALS['syc_settings_should_purge'] = true;
		$old_main_keys                        = syc_get_main_feed_storage_keys( $old_settings );
		$new_main_keys                        = syc_get_main_feed_storage_keys( $output );
		if ( $old_main_keys !== $new_main_keys ) {
			$GLOBALS['syc_removed_main_feed_keys'] = $old_main_keys;
		}
		if ( $old_settings['custom_carrousels'] !== $output['custom_carrousels'] ) {
			$old_playlist_ids                    = syc_get_carrousel_playlist_ids( $old_settings['custom_carrousels'] );
			$new_playlist_ids                    = syc_get_carrousel_playlist_ids( $output['custom_carrousels'] );
			$GLOBALS['syc_removed_playlist_ids'] = array_diff( $old_playlist_ids, $new_playlist_ids );
		}
	}

	return $output;
}

/**
 * Voeg instellingenpagina toe onder het YouTube carrousel menu.
 */
function syc_add_settings_page() {
	add_menu_page(
		__( 'YouTube carrousel', 'scientias-youtube-carrousel' ),
		__( 'YouTube carrousel', 'scientias-youtube-carrousel' ),
		SYC_EDITORIAL_CAPABILITY,
		'syc-settings',
		'syc_render_dashboard_page',
		'dashicons-video-alt3'
	);

	add_submenu_page(
		'syc-settings',
		__( 'YouTube carrousel dashboard', 'scientias-youtube-carrousel' ),
		__( 'Dashboard', 'scientias-youtube-carrousel' ),
		SYC_EDITORIAL_CAPABILITY,
		'syc-settings',
		'syc_render_dashboard_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'Aan de slag met YouTube carrousel', 'scientias-youtube-carrousel' ),
		__( 'Aan de slag', 'scientias-youtube-carrousel' ),
		'manage_options',
		'syc-onboarding',
		'syc_render_onboarding_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'Redactioneel video-overzicht', 'scientias-youtube-carrousel' ),
		__( 'Video-overzicht', 'scientias-youtube-carrousel' ),
		SYC_EDITORIAL_CAPABILITY,
		'syc-video-overview',
		'syc_render_video_overview_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'YouTube feed instellingen', 'scientias-youtube-carrousel' ),
		__( 'Feed instellingen', 'scientias-youtube-carrousel' ),
		'manage_options',
		'syc-feed-settings',
		'syc_render_settings_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'Link overrides', 'scientias-youtube-carrousel' ),
		__( 'Link overrides', 'scientias-youtube-carrousel' ),
		SYC_EDITORIAL_CAPABILITY,
		'syc-link-overrides',
		'syc_render_link_overrides_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'Extra carrousels', 'scientias-youtube-carrousel' ),
		__( 'Extra carrousels', 'scientias-youtube-carrousel' ),
		SYC_EDITORIAL_CAPABILITY,
		'syc-custom-carrousels',
		'syc_render_custom_carrousels_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'YouTube carrousel gereedschap', 'scientias-youtube-carrousel' ),
		__( 'Gereedschap', 'scientias-youtube-carrousel' ),
		'manage_options',
		'syc-tools',
		'syc_render_tools_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'Losse video-items', 'scientias-youtube-carrousel' ),
		__( 'Losse video-items', 'scientias-youtube-carrousel' ),
		SYC_EDITORIAL_CAPABILITY,
		'edit.php?post_type=syc_video'
	);
}
add_action( 'admin_menu', 'syc_add_settings_page' );

/**
 * Haal huidige instellingen op.
 *
 * @return array
 */
function syc_get_settings() {
	return syc_normalize_settings( get_option( 'syc_settings', array() ) );
}

/**
 * Controleer of de minimale YouTube-configuratie aanwezig is.
 *
 * @param array|null $settings Plugininstellingen.
 * @return bool
 */
function syc_is_feed_configured( $settings = null ) {
	$settings = is_array( $settings ) ? $settings : syc_get_settings();
	return ! empty( $settings['api_key'] ) && ! empty( $settings['channel_id'] );
}

/**
 * Controleer of de hoofdfeed minstens eenmaal succesvol is opgehaald.
 *
 * @param array|null $settings Plugininstellingen.
 * @return bool
 */
function syc_is_main_feed_ready( $settings = null ) {
	$settings = is_array( $settings ) ? $settings : syc_get_settings();
	if ( ! syc_is_feed_configured( $settings ) ) {
		return false;
	}

	$keys = syc_get_main_feed_storage_keys( $settings );
	$meta = get_option( 'syc_api_feed_meta', array() );
	if ( is_array( $meta ) && isset( $meta['status'] ) && ( empty( $meta['feed_key'] ) || $keys['cache'] === $meta['feed_key'] ) ) {
		return 'ok' === $meta['status'];
	}

	// Backward compatibility voor een bestaande 1.5.0/1.5.1-cache zonder meta.
	return is_array( get_transient( $keys['cache'] ) ) || is_array( get_option( $keys['stale'], null ) );
}

/**
 * Lees de persistente redactionele video-index.
 *
 * @return array
 */
function syc_get_editorial_video_index() {
	$index = get_option( SYC_EDITORIAL_VIDEO_INDEX_OPTION, array() );
	$index = is_array( $index ) ? $index : array();

	return array(
		'videos'  => isset( $index['videos'] ) && is_array( $index['videos'] ) ? $index['videos'] : array(),
		'sources' => isset( $index['sources'] ) && is_array( $index['sources'] ) ? $index['sources'] : array(),
		'ignored' => isset( $index['ignored'] ) && is_array( $index['ignored'] ) ? $index['ignored'] : array(),
	);
}

/**
 * Geef de stabiele indexsleutel voor de hoofdfeed.
 *
 * @param array|null $settings Plugininstellingen.
 * @return string
 */
function syc_get_editorial_main_source_key( $settings = null ) {
	$settings = is_array( $settings ) ? $settings : syc_get_settings();
	return 'main:' . md5( (string) $settings['channel_id'] );
}

/**
 * Geef de stabiele indexsleutel voor een playlistfeed.
 *
 * @param string $playlist_id YouTube playlist-ID.
 * @return string
 */
function syc_get_editorial_playlist_source_key( $playlist_id ) {
	return 'playlist:' . md5( syc_extract_youtube_playlist_id( $playlist_id ) );
}

/**
 * Geef een herkenbaar label aan een playlistbron.
 *
 * @param string     $playlist_id YouTube playlist-ID.
 * @param array|null $settings    Plugininstellingen.
 * @return string
 */
function syc_get_editorial_playlist_label( $playlist_id, $settings = null ) {
	$settings = is_array( $settings ) ? $settings : syc_get_settings();
	$names    = array();

	foreach ( $settings['custom_carrousels'] as $carrousel ) {
		if ( empty( $carrousel['playlist_id'] ) || syc_extract_youtube_playlist_id( $carrousel['playlist_id'] ) !== $playlist_id ) {
			continue;
		}
		if ( ! empty( $carrousel['name'] ) ) {
			$names[] = sanitize_text_field( $carrousel['name'] );
		}
	}

	if ( ! empty( $names ) ) {
		/* translators: %s: one or more carousel names. */
		return sprintf( __( 'Playlist: %s', 'scientias-youtube-carrousel' ), implode( ', ', array_unique( $names ) ) );
	}

	/* translators: %s: YouTube playlist ID. */
	return sprintf( __( 'Playlist: %s', 'scientias-youtube-carrousel' ), $playlist_id );
}

/**
 * Vervang na een geslaagde API-call de snapshot van één videobron.
 *
 * @param string $source_key Stabiele bronsleutel.
 * @param string $label      Leesbaar bronlabel.
 * @param array  $items      Genormaliseerde feeditems.
 */
function syc_update_editorial_source_snapshot( $source_key, $label, $items ) {
	$lock = syc_acquire_editorial_index_lock();
	if ( false === $lock ) {
		return;
	}

	try {
		$index     = syc_get_editorial_video_index();
		$video_ids = array();
		$now       = time();

		foreach ( (array) $items as $item ) {
			$video_id = isset( $item['video_id'] ) ? syc_sanitize_youtube_video_id( $item['video_id'] ) : '';
			if ( '' === $video_id ) {
				continue;
			}

			$existing               = isset( $index['videos'][ $video_id ] ) && is_array( $index['videos'][ $video_id ] ) ? $index['videos'][ $video_id ] : array();
			$sources                = isset( $existing['sources'] ) && is_array( $existing['sources'] ) ? $existing['sources'] : array();
			$sources[ $source_key ] = sanitize_text_field( $label );

			$title                        = isset( $item['title'] ) ? sanitize_text_field( wp_strip_all_tags( $item['title'] ) ) : '';
			$thumb                        = isset( $item['thumb'] ) ? esc_url_raw( $item['thumb'] ) : '';
			$index['videos'][ $video_id ] = array(
				'title'         => '' !== $title ? $title : ( isset( $existing['title'] ) ? $existing['title'] : '' ),
				'thumb'         => '' !== $thumb ? $thumb : ( isset( $existing['thumb'] ) ? $existing['thumb'] : '' ),
				'first_seen_at' => isset( $existing['first_seen_at'] ) ? (int) $existing['first_seen_at'] : $now,
				'last_seen_at'  => $now,
				'sources'       => $sources,
			);
			$video_ids[]                  = $video_id;
		}

		$index['sources'][ $source_key ] = array(
			'label'      => sanitize_text_field( $label ),
			'video_ids'  => array_values( array_unique( $video_ids ) ),
			'updated_at' => $now,
		);
		update_option( SYC_EDITORIAL_VIDEO_INDEX_OPTION, $index, false );
	} finally {
		syc_release_lock( SYC_EDITORIAL_INDEX_LOCK_KEY, $lock );
	}
}

/**
 * Vul de index bij een upgrade eenmalig vanuit bestaande geldige caches.
 */
function syc_bootstrap_editorial_video_index() {
	$settings = syc_get_settings();
	$index    = syc_get_editorial_video_index();
	$main_key = syc_get_editorial_main_source_key( $settings );

	if ( ! isset( $index['sources'][ $main_key ] ) ) {
		$items = syc_get_api_shorts_items();
		if ( ! is_wp_error( $items ) ) {
			syc_update_editorial_source_snapshot( $main_key, __( 'Hoofdfeed', 'scientias-youtube-carrousel' ), $items );
		}
	}

	foreach ( syc_get_carrousel_playlist_ids( $settings['custom_carrousels'] ) as $playlist_id ) {
		$source_key = syc_get_editorial_playlist_source_key( $playlist_id );
		if ( isset( $index['sources'][ $source_key ] ) ) {
			continue;
		}
		$items = syc_get_api_playlist_items( $playlist_id );
		if ( ! is_wp_error( $items ) ) {
			syc_update_editorial_source_snapshot( $source_key, syc_get_editorial_playlist_label( $playlist_id, $settings ), $items );
		}
	}
}

/**
 * Geef de momenteel geconfigureerde API-bronnen.
 *
 * @param array|null $settings Plugininstellingen.
 * @return array Bronsleutels als keys.
 */
function syc_get_active_editorial_source_keys( $settings = null ) {
	$settings = is_array( $settings ) ? $settings : syc_get_settings();
	$active   = array();

	if ( ! empty( $settings['channel_id'] ) ) {
		$active[ syc_get_editorial_main_source_key( $settings ) ] = true;
	}
	foreach ( syc_get_carrousel_playlist_ids( $settings['custom_carrousels'] ) as $playlist_id ) {
		$active[ syc_get_editorial_playlist_source_key( $playlist_id ) ] = true;
	}

	return $active;
}

/**
 * Controleer of een video in een actuele bronsnapshot staat.
 *
 * @param string $video_id YouTube video-ID.
 * @param array  $index    Redactionele index.
 * @param array  $active   Actieve bronsleutels.
 * @return bool
 */
function syc_is_editorial_video_current( $video_id, $index, $active ) {
	foreach ( array_keys( $active ) as $source_key ) {
		$source = isset( $index['sources'][ $source_key ] ) && is_array( $index['sources'][ $source_key ] ) ? $index['sources'][ $source_key ] : array();
		if ( ! empty( $source['video_ids'] ) && in_array( $video_id, $source['video_ids'], true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Lees de huidige pluginpagina uit de beheer-URL.
 *
 * @return string
 */
function syc_get_current_admin_page_slug() {
	// Alleen navigatie; deze waarde wijzigt geen gegevens.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
}

/**
 * Stuur een beheerder na een nieuwe activatie eenmaal naar de onboarding.
 */
function syc_maybe_redirect_to_onboarding() {
	if ( ! get_option( SYC_ONBOARDING_REDIRECT_OPTION, false ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() || is_network_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
	if ( 'get' !== strtolower( $request_method ) ) {
		return;
	}
	// Bulkactivatie eerst normaal afronden; de onboarding blijft via de
	// blijvende waarschuwing bereikbaar.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['activate-multi'] ) ) {
		delete_option( SYC_ONBOARDING_REDIRECT_OPTION );
		return;
	}
	if ( syc_is_feed_configured() ) {
		delete_option( SYC_ONBOARDING_REDIRECT_OPTION );
		return;
	}
	if ( 'syc-onboarding' === syc_get_current_admin_page_slug() ) {
		delete_option( SYC_ONBOARDING_REDIRECT_OPTION );
		return;
	}

	delete_option( SYC_ONBOARDING_REDIRECT_OPTION );
	wp_safe_redirect( admin_url( 'admin.php?page=syc-onboarding' ) );
	exit;
}
add_action( 'admin_init', 'syc_maybe_redirect_to_onboarding', 20 );

/**
 * Toon een blijvende waarschuwing zolang de hoofdfeed niet geconfigureerd is.
 */
function syc_render_onboarding_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) || syc_is_feed_configured() || 'syc-onboarding' === syc_get_current_admin_page_slug() ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'YouTube carrousel is nog niet geconfigureerd.', 'scientias-youtube-carrousel' ); ?></strong>
			<?php esc_html_e( 'Vul de API-key en het kanaal-ID in om de automatische Shorts-feed te activeren.', 'scientias-youtube-carrousel' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=syc-onboarding' ) ); ?>"><?php esc_html_e( 'Configuratie starten', 'scientias-youtube-carrousel' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'syc_render_onboarding_admin_notice', 5 );

/**
 * Verwerk de onboardinginstellingen en haal direct de eerste feed op.
 */
function syc_handle_onboarding_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Je hebt geen rechten om de onboarding af te ronden.', 'scientias-youtube-carrousel' ) );
	}
	check_admin_referer( 'syc_onboarding_action', 'syc_onboarding_nonce' );

	$raw         = isset( $_POST['syc_onboarding'] ) && is_array( $_POST['syc_onboarding'] )
		? map_deep( wp_unslash( $_POST['syc_onboarding'] ), 'sanitize_text_field' )
		: array();
	$new_api_key = isset( $raw['api_key'] ) ? trim( sanitize_text_field( $raw['api_key'] ) ) : '';
	$channel_id  = isset( $raw['channel_id'] ) ? trim( sanitize_text_field( $raw['channel_id'] ) ) : '';
	$max_items   = isset( $raw['max_items'] ) ? min( max( 1, absint( $raw['max_items'] ) ), 50 ) : 8;
	$auto_draft  = ! empty( $raw['auto_draft'] ) ? 1 : 0;

	if ( '' === $channel_id ) {
		syc_store_admin_notice(
			array(
				'type'    => 'error',
				'message' => __( 'Vul een YouTube-kanaal-ID in.', 'scientias-youtube-carrousel' ),
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=syc-onboarding' ) );
		exit;
	}
	if ( ! syc_get_shorts_playlist_id( $channel_id ) ) {
		syc_store_admin_notice(
			array(
				'type'    => 'error',
				'message' => __( 'Het kanaal-ID is ongeldig. Gebruik het ID dat met “UC” begint.', 'scientias-youtube-carrousel' ),
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=syc-onboarding' ) );
		exit;
	}

	$locked_before = array();
	$updated       = syc_update_settings_locked(
		function ( $settings ) use ( &$locked_before, $new_api_key, $channel_id, $max_items, $auto_draft ) {
			$locked_before = $settings;
			if ( '' !== $new_api_key ) {
				$settings['api_key'] = $new_api_key;
			} elseif ( empty( $settings['api_key'] ) ) {
				return new WP_Error( 'syc_missing_api_key', __( 'Vul een YouTube API-key in.', 'scientias-youtube-carrousel' ) );
			}
			$settings['channel_id'] = $channel_id;
			$settings['max_items']  = $max_items;
			$settings['auto_draft'] = $auto_draft;
			return $settings;
		}
	);

	if ( is_wp_error( $updated ) ) {
		syc_store_admin_notice(
			array(
				'type'    => 'error',
				'message' => $updated->get_error_message(),
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=syc-onboarding' ) );
		exit;
	}

	$old_keys = syc_get_main_feed_storage_keys( $locked_before );
	$new_keys = syc_get_main_feed_storage_keys( $updated );
	if ( $old_keys !== $new_keys ) {
		delete_transient( $old_keys['cache'] );
		delete_option( $old_keys['stale'] );
		delete_option( 'syc_api_feed_meta' );
	}
	delete_option( SYC_ONBOARDING_REDIRECT_OPTION );
	syc_purge_page_caches();

	$result = syc_refresh_main_feed_now();
	if ( is_wp_error( $result ) ) {
		$notice = array(
			'type'    => 'error',
			'message' => sprintf(
				/* translators: %s: first feed refresh error. */
				__( 'De instellingen zijn opgeslagen, maar de eerste feed kon niet worden opgehaald: %s', 'scientias-youtube-carrousel' ),
				$result->get_error_message()
			),
		);
	} else {
		$notice = array(
			'type'    => 'success',
			'message' => __( 'De configuratie is opgeslagen en de eerste feed is succesvol opgehaald.', 'scientias-youtube-carrousel' ),
		);
	}

	syc_store_admin_notice( $notice );
	wp_safe_redirect( admin_url( 'admin.php?page=syc-onboarding' ) );
	exit;
}
add_action( 'admin_post_syc_onboarding_save', 'syc_handle_onboarding_save' );

/**
 * Bewaar een eenmalige beheermelding voor de huidige gebruiker.
 *
 * @param array $notice Melding met type en message.
 */
function syc_store_admin_notice( $notice ) {
	set_transient( 'syc_admin_notice_' . get_current_user_id(), $notice, 2 * MINUTE_IN_SECONDS );
}

/**
 * Lees en verwijder de eenmalige beheermelding voor de huidige gebruiker.
 *
 * @return array
 */
function syc_take_admin_notice() {
	$key    = 'syc_admin_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	delete_transient( $key );

	// Compatibiliteit met de 1.1.4.1-refreshmelding.
	if ( ! is_array( $notice ) ) {
		$legacy_key = 'syc_manual_refresh_notice_' . get_current_user_id();
		$notice     = get_transient( $legacy_key );
		delete_transient( $legacy_key );
	}

	return is_array( $notice ) ? $notice : array();
}

/**
 * Formatteer een opgeslagen Unix-tijd voor het dashboard.
 *
 * @param int $timestamp Unix-tijd.
 * @return string
 */
function syc_format_dashboard_time( $timestamp ) {
	if ( $timestamp <= 0 ) {
		return __( 'Nog niet', 'scientias-youtube-carrousel' );
	}

	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/**
 * Haal een timestamp uit nieuwe of oude feedmetadata.
 *
 * @param array  $meta Metadata.
 * @param string $key  Gewenste sleutel.
 * @return int
 */
function syc_get_feed_meta_time( $meta, $key ) {
	if ( isset( $meta[ $key ] ) ) {
		return (int) $meta[ $key ];
	}

	if ( 'last_attempt_at' === $key && isset( $meta['updated_at'] ) ) {
		return (int) $meta['updated_at'];
	}

	if ( 'last_success_at' === $key && isset( $meta['status'], $meta['updated_at'] ) && 'ok' === $meta['status'] ) {
		return (int) $meta['updated_at'];
	}

	return 0;
}

/**
 * Maak een compacte statusbadge voor het dashboard.
 *
 * @param string $label Badge-tekst.
 * @param string $type  success, warning, error of neutral.
 * @return string
 */
function syc_get_dashboard_badge( $label, $type ) {
	$colors = array(
		'success' => array( '#edfaef', '#116329' ),
		'warning' => array( '#fff8e5', '#8a5a00' ),
		'error'   => array( '#fcf0f1', '#8a2424' ),
		'neutral' => array( '#f0f0f1', '#3c434a' ),
	);
	$color  = isset( $colors[ $type ] ) ? $colors[ $type ] : $colors['neutral'];

	return sprintf(
		'<span style="display:inline-block;padding:3px 9px;border-radius:999px;background:%1$s;color:%2$s;font-weight:600;">%3$s</span>',
		esc_attr( $color[0] ),
		esc_attr( $color[1] ),
		esc_html( $label )
	);
}

/**
 * Verzamel in batches de bruikbare post-ID's voor de hoofdfeedfallback.
 *
 * @param int $limit Maximumaantal items; -1 voor onbeperkt.
 * @return array
 */
function syc_get_manual_fallback_post_ids( $limit = -1 ) {
	if ( 0 === $limit ) {
		return array();
	}

	$eligible   = array();
	$offset     = 0;
	$batch_size = $limit > 0 ? max( 20, min( 100, $limit * 2 ) ) : 100;

	do {
		$post_ids    = get_posts(
			array(
				'post_type'              => 'syc_video',
				'post_status'            => 'publish',
				'posts_per_page'         => $batch_size,
				'offset'                 => $offset,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		$batch_count = count( $post_ids );

		foreach ( $post_ids as $post_id ) {
			$video_id = syc_extract_youtube_video_id( get_post_meta( $post_id, '_syc_video_url', true ) );
			if ( '' === $video_id ) {
				continue;
			}
			$eligible[] = $post_id;
			if ( $limit > 0 && count( $eligible ) >= $limit ) {
				return $eligible;
			}
		}

		$offset += $batch_size;
	} while ( $batch_count === $batch_size );

	return $eligible;
}

/**
 * Bouw zichtbare losse video-items voor de hoofdfeedfallback.
 *
 * @param int $limit Maximumaantal items; -1 voor onbeperkt.
 * @return array
 */
function syc_get_manual_fallback_items( $limit = -1 ) {
	$items = array();
	foreach ( syc_get_manual_fallback_post_ids( $limit ) as $post_id ) {
		$video_url = get_post_meta( $post_id, '_syc_video_url', true );
		$video_id  = syc_extract_youtube_video_id( $video_url );
		$thumb_url = get_the_post_thumbnail_url( $post_id, 'large' );
		$items[]   = array(
			'title'     => get_the_title( $post_id ),
			'video_url' => $video_url,
			'thumb_url' => $thumb_url ? $thumb_url : syc_get_youtube_thumbnail_url( $video_id ),
			'link_url'  => get_post_meta( $post_id, '_syc_link_url', true ),
		);
	}

	return $items;
}

/**
 * Bepaal de actuele dashboardstatus van de hoofdfeed.
 *
 * @param array $settings Plugininstellingen.
 * @return array
 */
function syc_get_main_dashboard_status( $settings ) {
	$keys  = syc_get_main_feed_storage_keys( $settings );
	$cache = get_transient( $keys['cache'] );
	$stale = get_option( $keys['stale'], null );
	$meta  = get_option( 'syc_api_feed_meta', array() );
	$meta  = is_array( $meta ) ? $meta : array();
	if ( ! empty( $meta['feed_key'] ) && $keys['cache'] !== $meta['feed_key'] ) {
		$meta = array();
	}
	$configured  = ! empty( $settings['api_key'] ) && ! empty( $settings['channel_id'] );
	$source      = __( 'Geen beschikbare bron', 'scientias-youtube-carrousel' );
	$source_type = 'error';
	$items       = 0;

	if ( is_array( $cache ) && ! empty( $cache ) ) {
		$source      = __( 'Actuele API-cache', 'scientias-youtube-carrousel' );
		$source_type = 'success';
		$items       = count( $cache );
	} elseif ( is_array( $stale ) && ! empty( $stale ) ) {
		$source      = __( 'Laatst bekende feed', 'scientias-youtube-carrousel' );
		$source_type = 'warning';
		$items       = count( $stale );
	} else {
		$manual_count = count( syc_get_manual_fallback_post_ids() );
		if ( $manual_count > 0 ) {
			$source      = __( 'Handmatige fallback', 'scientias-youtube-carrousel' );
			$source_type = 'warning';
			$items       = $manual_count;
		} elseif ( is_array( $cache ) || is_array( $stale ) ) {
			$source      = __( 'Lege YouTube-feed', 'scientias-youtube-carrousel' );
			$source_type = 'warning';
		}
	}

	$status      = __( 'Wacht op eerste verversing', 'scientias-youtube-carrousel' );
	$status_type = 'neutral';
	if ( ! $configured ) {
		$status      = __( 'Configuratie ontbreekt', 'scientias-youtube-carrousel' );
		$status_type = 'error';
	} elseif ( isset( $meta['status'] ) && 'error' === $meta['status'] ) {
		$status      = __( 'Laatste poging mislukt', 'scientias-youtube-carrousel' );
		$status_type = 'error';
	} elseif ( 'success' === $source_type ) {
		$status      = __( 'Gezond', 'scientias-youtube-carrousel' );
		$status_type = 'success';
	} elseif ( 'warning' === $source_type ) {
		$status      = __( 'Fallback actief', 'scientias-youtube-carrousel' );
		$status_type = 'warning';
	}

	return array(
		'status'          => $status,
		'status_type'     => $status_type,
		'source'          => $source,
		'source_type'     => $source_type,
		'items'           => $items,
		'last_attempt_at' => syc_get_feed_meta_time( $meta, 'last_attempt_at' ),
		'last_success_at' => syc_get_feed_meta_time( $meta, 'last_success_at' ),
		'message'         => isset( $meta['message'] ) ? (string) $meta['message'] : '',
	);
}

/**
 * Bepaal de actuele dashboardstatus van een playlistcarrousel.
 *
 * @param string $playlist_id YouTube playlist-ID.
 * @param array  $manual_items Handmatige fallbackitems.
 * @return array
 */
function syc_get_playlist_dashboard_status( $playlist_id, $manual_items ) {
	$playlist_id  = syc_extract_youtube_playlist_id( $playlist_id );
	$hash         = md5( $playlist_id );
	$cache        = get_transient( SYC_PLAYLIST_CACHE_PREFIX . $hash );
	$stale        = get_option( SYC_PLAYLIST_STALE_PREFIX . $hash, null );
	$meta         = get_option( SYC_PLAYLIST_META_PREFIX . $hash, array() );
	$meta         = is_array( $meta ) ? $meta : array();
	$manual_count = is_array( $manual_items ) ? count( $manual_items ) : 0;
	$source       = __( 'Geen beschikbare bron', 'scientias-youtube-carrousel' );
	$source_type  = 'error';
	$items        = 0;

	if ( is_array( $cache ) && ! empty( $cache ) ) {
		$source      = __( 'Actuele playlistcache', 'scientias-youtube-carrousel' );
		$source_type = 'success';
		$items       = count( $cache );
	} elseif ( is_array( $stale ) && ! empty( $stale ) ) {
		$source      = __( 'Laatst bekende playlist', 'scientias-youtube-carrousel' );
		$source_type = 'warning';
		$items       = count( $stale );
	} elseif ( $manual_count > 0 ) {
		$source      = __( 'Handmatige fallback', 'scientias-youtube-carrousel' );
		$source_type = 'warning';
		$items       = $manual_count;
	} elseif ( is_array( $cache ) || is_array( $stale ) ) {
		$source      = __( 'Lege playlist', 'scientias-youtube-carrousel' );
		$source_type = 'warning';
	}

	$status      = __( 'Wacht op eerste verversing', 'scientias-youtube-carrousel' );
	$status_type = 'neutral';
	if ( isset( $meta['status'] ) && 'error' === $meta['status'] ) {
		$status      = __( 'Laatste poging mislukt', 'scientias-youtube-carrousel' );
		$status_type = 'error';
	} elseif ( 'success' === $source_type ) {
		$status      = __( 'Gezond', 'scientias-youtube-carrousel' );
		$status_type = 'success';
	} elseif ( 'warning' === $source_type ) {
		$status      = __( 'Fallback actief', 'scientias-youtube-carrousel' );
		$status_type = 'warning';
	}

	return array(
		'status'          => $status,
		'status_type'     => $status_type,
		'source'          => $source,
		'source_type'     => $source_type,
		'items'           => $items,
		'last_attempt_at' => syc_get_feed_meta_time( $meta, 'last_attempt_at' ),
		'last_success_at' => syc_get_feed_meta_time( $meta, 'last_success_at' ),
		'message'         => isset( $meta['message'] ) ? (string) $meta['message'] : '',
	);
}

/**
 * Maak een veilige fout voor een onleesbaar YouTube-antwoord.
 *
 * @return WP_Error
 */
function syc_youtube_invalid_response_error() {
	return new WP_Error( 'syc_youtube_invalid_response', __( 'YouTube gaf een onleesbaar antwoord. De laatst bekende feed blijft zichtbaar.', 'scientias-youtube-carrousel' ) );
}

/**
 * Vertaal een transport- of HTTP-fout naar een gerichte, veilige melding.
 *
 * @param array|WP_Error $response WordPress HTTP-response.
 * @param string         $context  main, playlist of channel.
 * @return WP_Error
 */
function syc_youtube_error_from_response( $response, $context = 'main' ) {
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'syc_youtube_transport_error', __( 'WordPress kon YouTube niet bereiken. Controleer DNS, TLS, firewall en uitgaand HTTP-verkeer; de laatst bekende feed blijft zichtbaar.', 'scientias-youtube-carrousel' ) );
	}

	$http_code = (int) wp_remote_retrieve_response_code( $response );
	$body      = json_decode( wp_remote_retrieve_body( $response ), true );
	$reason    = '';
	$status    = '';
	if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
		$status = isset( $body['error']['status'] ) ? strtolower( sanitize_key( $body['error']['status'] ) ) : '';
		if ( ! empty( $body['error']['errors'][0]['reason'] ) ) {
			$reason = strtolower( sanitize_key( $body['error']['errors'][0]['reason'] ) );
		}
	}

	if ( 401 === $http_code || in_array( $reason, array( 'keyinvalid', 'api_key_invalid' ), true ) || 'unauthenticated' === $status ) {
		return new WP_Error( 'syc_youtube_invalid_key', __( 'De YouTube API-key is ongeldig. Maak of kopieer een geldige YouTube Data API v3-key.', 'scientias-youtube-carrousel' ) );
	}
	if ( in_array( $reason, array( 'quotaexceeded', 'dailylimitexceeded' ), true ) || 'resource_exhausted' === $status ) {
		return new WP_Error( 'syc_youtube_quota_exceeded', __( 'Het dagelijkse YouTube API-quotum is opgebruikt. De laatst bekende feed blijft zichtbaar.', 'scientias-youtube-carrousel' ) );
	}
	if ( 429 === $http_code || in_array( $reason, array( 'ratelimitexceeded', 'userratelimitexceeded' ), true ) ) {
		return new WP_Error( 'syc_youtube_rate_limited', __( 'YouTube ontvangt tijdelijk te veel aanvragen. Probeer het later opnieuw; de laatst bekende feed blijft zichtbaar.', 'scientias-youtube-carrousel' ) );
	}
	if ( in_array( $reason, array( 'accessnotconfigured', 'iprefererblocked', 'forbidden' ), true ) || ( 403 === $http_code && '' === $reason ) ) {
		return new WP_Error( 'syc_youtube_key_restricted', __( 'De API-key mag YouTube Data API v3 niet gebruiken of de keyrestricties weigeren deze server.', 'scientias-youtube-carrousel' ) );
	}
	if ( 'channelnotfound' === $reason || ( 'channel' === $context && in_array( $reason, array( 'notfound', 'playlistnotfound' ), true ) ) ) {
		return new WP_Error( 'syc_youtube_invalid_channel', __( 'Voor dit kanaal-ID bestaat geen openbaar YouTube-kanaal.', 'scientias-youtube-carrousel' ) );
	}
	if ( 'playlistnotfound' === $reason || ( 404 === $http_code && 'playlist' === $context ) ) {
		if ( 'main' === $context ) {
			$probe = syc_probe_youtube_channel();
			if ( is_wp_error( $probe ) ) {
				return $probe;
			}
			return new WP_Error( 'syc_youtube_shorts_unavailable', __( 'Het kanaal bestaat, maar de openbare Shorts-playlist is niet beschikbaar. Het kanaal bevat mogelijk geen toegankelijke Shorts of de playlist is privé.', 'scientias-youtube-carrousel' ) );
		}
		return new WP_Error( 'syc_youtube_playlist_unavailable', __( 'De YouTube-playlist bestaat niet, is privé of is verwijderd. De handmatige fallback blijft beschikbaar.', 'scientias-youtube-carrousel' ) );
	}
	if ( $http_code >= 500 ) {
		return new WP_Error( 'syc_youtube_temporary_error', __( 'YouTube is tijdelijk niet beschikbaar. De laatst bekende feed blijft zichtbaar.', 'scientias-youtube-carrousel' ) );
	}

	return new WP_Error( 'syc_youtube_request_failed', __( 'YouTube kon de aanvraag niet verwerken. Controleer de broninstellingen; bestaande feeddata blijft zichtbaar.', 'scientias-youtube-carrousel' ) );
}

/**
 * Controleer na een ontbrekende Shorts-playlist of het kanaal zelf bestaat.
 *
 * @return true|WP_Error
 */
function syc_probe_youtube_channel() {
	$settings = syc_get_settings();
	$response = wp_remote_get(
		add_query_arg(
			array(
				'part' => 'id',
				'id'   => $settings['channel_id'],
				'key'  => $settings['api_key'],
			),
			'https://www.googleapis.com/youtube/v3/channels'
		),
		array( 'timeout' => SYC_API_REQUEST_TIMEOUT )
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return syc_youtube_error_from_response( $response, 'channel' );
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
		return syc_youtube_invalid_response_error();
	}
	if ( empty( $data['items'] ) ) {
		return new WP_Error( 'syc_youtube_invalid_channel', __( 'Voor dit kanaal-ID bestaat geen openbaar YouTube-kanaal.', 'scientias-youtube-carrousel' ) );
	}
	return true;
}

/**
 * Test de YouTube-configuratie zonder caches of concepten te wijzigen.
 *
 * @return true|WP_Error
 */
function syc_test_youtube_connection() {
	$settings = syc_get_settings();
	if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) {
		return new WP_Error( 'syc_missing_settings', __( 'YouTube API sleutel of kanaal ID ontbreekt.', 'scientias-youtube-carrousel' ) );
	}

	$playlist_id = syc_get_shorts_playlist_id( $settings['channel_id'] );
	if ( ! $playlist_id ) {
		return new WP_Error( 'syc_invalid_channel_id', __( 'Het kanaal-ID is ongeldig.', 'scientias-youtube-carrousel' ) );
	}

	$response = wp_remote_get(
		add_query_arg(
			array(
				'part'       => 'snippet',
				'playlistId' => $playlist_id,
				'maxResults' => 1,
				'key'        => $settings['api_key'],
			),
			'https://www.googleapis.com/youtube/v3/playlistItems'
		),
		array( 'timeout' => SYC_API_REQUEST_TIMEOUT )
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return syc_youtube_error_from_response( $response, 'main' );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
		return syc_youtube_invalid_response_error();
	}

	return true;
}

/**
 * Verwerk de expliciete verbindingstest via Post/Redirect/Get.
 */
function syc_handle_connection_test() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Je hebt geen rechten om de verbinding te testen.', 'scientias-youtube-carrousel' ) );
	}
	check_admin_referer( 'syc_connection_test_action', 'syc_connection_test_nonce' );

	$result = syc_test_youtube_connection();
	$notice = is_wp_error( $result )
		? array(
			'type'    => 'error',
			'message' => sprintf(
				/* translators: %s: connection error. */
				__( 'Verbindingstest mislukt: %s', 'scientias-youtube-carrousel' ),
				$result->get_error_message()
			),
		)
		: array(
			'type'    => 'success',
			'message' => __( 'De verbinding met de YouTube Data API werkt.', 'scientias-youtube-carrousel' ),
		);

	syc_store_admin_notice( $notice );
	wp_safe_redirect( admin_url( 'admin.php?page=syc-settings' ) );
	exit;
}
add_action( 'admin_post_syc_connection_test', 'syc_handle_connection_test' );

/**
 * Render de onboarding voor een eerste configuratie of herstelconfiguratie.
 */
function syc_render_onboarding_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings    = syc_get_settings();
	$configured  = syc_is_feed_configured( $settings );
	$feed_ready  = syc_is_main_feed_ready( $settings );
	$cron_health = syc_get_cron_health();
	$notice      = syc_take_admin_notice();
	$meta        = get_option( 'syc_api_feed_meta', array() );
	$meta        = is_array( $meta ) ? $meta : array();
	$feed_keys   = syc_get_main_feed_storage_keys( $settings );
	$feed_error  = isset( $meta['status'], $meta['message'] )
		&& 'error' === $meta['status']
		&& ( empty( $meta['feed_key'] ) || $feed_keys['cache'] === $meta['feed_key'] )
		? (string) $meta['message']
		: '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Aan de slag met YouTube carrousel', 'scientias-youtube-carrousel' ); ?></h1>
		<p><?php esc_html_e( 'Doorloop deze stappen om de automatische Shorts-feed veilig te activeren.', 'scientias-youtube-carrousel' ); ?></p>

		<?php if ( ! empty( $notice ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php endif; ?>

		<div class="notice notice-info inline" style="max-width:900px;">
			<p><strong><?php esc_html_e( 'Belangrijk bij updates', 'scientias-youtube-carrousel' ); ?></strong></p>
			<p><?php esc_html_e( 'Verwijder of deactiveer de bestaande plugin niet. Upload de nieuwe zip en kies “Huidige vervangen door geüploade versie”. Verwijderen wist de instellingen, API-key, carrousels en link overrides.', 'scientias-youtube-carrousel' ); ?></p>
		</div>

		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:1000px;margin:20px 0;">
			<div class="postbox" style="margin:0;">
				<div class="inside">
					<h2><?php esc_html_e( '1. Plugin actief', 'scientias-youtube-carrousel' ); ?></h2>
					<?php echo wp_kses_post( syc_get_dashboard_badge( $cron_health['label'], $cron_health['type'] ) ); ?>
					<p><?php echo esc_html( $cron_health['message'] ); ?></p>
				</div>
			</div>
			<div class="postbox" style="margin:0;">
				<div class="inside">
					<h2><?php esc_html_e( '2. API configureren', 'scientias-youtube-carrousel' ); ?></h2>
					<?php echo wp_kses_post( syc_get_dashboard_badge( $configured ? __( 'Voltooid', 'scientias-youtube-carrousel' ) : __( 'Nog invullen', 'scientias-youtube-carrousel' ), $configured ? 'success' : 'warning' ) ); ?>
					<p><?php esc_html_e( 'Vul de YouTube Data API-key en het kanaal-ID in.', 'scientias-youtube-carrousel' ); ?></p>
				</div>
			</div>
			<div class="postbox" style="margin:0;">
				<div class="inside">
					<h2><?php esc_html_e( '3. Eerste feed ophalen', 'scientias-youtube-carrousel' ); ?></h2>
					<?php echo wp_kses_post( syc_get_dashboard_badge( $feed_ready ? __( 'Voltooid', 'scientias-youtube-carrousel' ) : ( '' !== $feed_error ? __( 'Mislukt', 'scientias-youtube-carrousel' ) : __( 'Nog uitvoeren', 'scientias-youtube-carrousel' ) ), $feed_ready ? 'success' : ( '' !== $feed_error ? 'error' : 'neutral' ) ) ); ?>
					<p><?php esc_html_e( 'Na opslaan haalt de plugin direct de eerste feed op.', 'scientias-youtube-carrousel' ); ?></p>
				</div>
			</div>
		</div>

		<?php if ( '' !== $feed_error && ! $feed_ready ) : ?>
			<div class="notice notice-error inline" style="max-width:900px;"><p><strong><?php esc_html_e( 'Laatste fout:', 'scientias-youtube-carrousel' ); ?></strong> <?php echo esc_html( $feed_error ); ?></p></div>
		<?php endif; ?>

		<div class="postbox" style="max-width:900px;">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'YouTube-feed instellen', 'scientias-youtube-carrousel' ); ?></h2></div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="syc_onboarding_save" />
					<?php wp_nonce_field( 'syc_onboarding_action', 'syc_onboarding_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="syc_onboarding_api_key"><?php esc_html_e( 'YouTube API-key', 'scientias-youtube-carrousel' ); ?></label></th>
							<td>
								<input type="password" id="syc_onboarding_api_key" name="syc_onboarding[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! empty( $settings['api_key'] ) ? esc_attr__( 'API-key opgeslagen; leeg laten om te behouden', 'scientias-youtube-carrousel' ) : ''; ?>" />
								<p class="description"><?php esc_html_e( 'De opgeslagen key wordt nooit leesbaar teruggetoond.', 'scientias-youtube-carrousel' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="syc_onboarding_channel_id"><?php esc_html_e( 'YouTube-kanaal-ID', 'scientias-youtube-carrousel' ); ?></label></th>
							<td><input type="text" id="syc_onboarding_channel_id" name="syc_onboarding[channel_id]" value="<?php echo esc_attr( $settings['channel_id'] ); ?>" class="regular-text" placeholder="UC…" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="syc_onboarding_max_items"><?php esc_html_e( 'Maximaal aantal Shorts', 'scientias-youtube-carrousel' ); ?></label></th>
							<td><input type="number" id="syc_onboarding_max_items" name="syc_onboarding[max_items]" value="<?php echo esc_attr( $settings['max_items'] ); ?>" min="1" max="50" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Automatische concepten', 'scientias-youtube-carrousel' ); ?></th>
							<td><label><input type="checkbox" name="syc_onboarding[auto_draft]" value="1" <?php checked( ! empty( $settings['auto_draft'] ) ); ?> /> <?php esc_html_e( 'Maak voor nieuwe Shorts automatisch een conceptbericht', 'scientias-youtube-carrousel' ); ?></label></td>
						</tr>
					</table>
					<?php submit_button( $configured ? __( 'Instellingen opslaan en feed opnieuw ophalen', 'scientias-youtube-carrousel' ) : __( 'Instellingen opslaan en eerste feed ophalen', 'scientias-youtube-carrousel' ) ); ?>
				</form>
			</div>
		</div>

		<?php if ( $feed_ready ) : ?>
			<div class="notice notice-success inline" style="max-width:900px;">
				<p><strong><?php esc_html_e( 'De basisconfiguratie is klaar.', 'scientias-youtube-carrousel' ); ?></strong> <?php esc_html_e( 'Plaats de shortcode op een pagina of ga naar het dashboard voor de actuele feedstatus.', 'scientias-youtube-carrousel' ); ?></p>
				<p><code>[scientias_youtube_carrousel]</code> <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=syc-settings' ) ); ?>"><?php esc_html_e( 'Naar dashboard', 'scientias-youtube-carrousel' ); ?></a></p>
			</div>
		<?php else : ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=syc-settings' ) ); ?>"><?php esc_html_e( 'Later instellen en naar het dashboard gaan', 'scientias-youtube-carrousel' ); ?></a></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Zoek het gekoppelde WordPress-bericht voor een video.
 *
 * @param string $video_id YouTube video-ID.
 * @param array  $map      Bestaande video-naar-postmapping.
 * @return WP_Post|null
 */
function syc_get_editorial_video_post( $video_id, $map ) {
	if ( isset( $map[ $video_id ] ) ) {
		$post = get_post( absint( $map[ $video_id ] ) );
		if ( $post instanceof WP_Post && 'post' === $post->post_type ) {
			return $post;
		}
	}

	$existing = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Begrensde redactionele lookup van één post.
			'meta_key'       => '_syc_video_id',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Hoort bij de begrensde lookup hierboven.
			'meta_value'     => $video_id,
		)
	);

	return ! empty( $existing ) ? get_post( (int) $existing[0] ) : null;
}

/**
 * Bepaal de redactionele status van een feedvideo.
 *
 * @param string $video_id YouTube video-ID.
 * @param array  $map      Video-naar-postmapping.
 * @param array  $settings Plugininstellingen.
 * @param array  $index    Redactionele video-index.
 * @return array
 */
function syc_get_editorial_video_state( $video_id, $map, $settings, $index ) {
	$post     = syc_get_editorial_video_post( $video_id, $map );
	$override = isset( $settings['link_overrides'][ $video_id ] ) ? $settings['link_overrides'][ $video_id ] : '';

	if ( $post instanceof WP_Post && 'trash' !== $post->post_status ) {
		if ( 'publish' === $post->post_status ) {
			return array(
				'key'      => 'published',
				'label'    => __( 'Gepubliceerd', 'scientias-youtube-carrousel' ),
				'tone'     => 'success',
				'post'     => $post,
				'override' => $override,
			);
		}

		$origin = get_post_meta( $post->ID, '_syc_video_origin', true );
		return array(
			'key'      => 'existing_article' === $origin ? 'linked' : 'draft',
			'label'    => 'existing_article' === $origin ? __( 'Artikel gekoppeld', 'scientias-youtube-carrousel' ) : __( 'Concept aangemaakt', 'scientias-youtube-carrousel' ),
			'tone'     => 'existing_article' === $origin ? 'neutral' : 'warning',
			'post'     => $post,
			'override' => $override,
		);
	}

	if ( '' !== $override ) {
		return array(
			'key'      => 'linked',
			'label'    => __( 'Artikel gekoppeld', 'scientias-youtube-carrousel' ),
			'tone'     => 'neutral',
			'post'     => null,
			'override' => $override,
		);
	}

	if ( isset( $index['ignored'][ $video_id ] ) || isset( $map[ $video_id ] ) ) {
		return array(
			'key'      => 'ignored',
			'label'    => __( 'Genegeerd', 'scientias-youtube-carrousel' ),
			'tone'     => 'neutral',
			'post'     => null,
			'override' => '',
		);
	}

	return array(
		'key'      => 'new',
		'label'    => __( 'Nieuw', 'scientias-youtube-carrousel' ),
		'tone'     => 'warning',
		'post'     => null,
		'override' => '',
	);
}

/**
 * Maak vanuit het video-overzicht één concept aan.
 *
 * @param string $video_id YouTube video-ID.
 * @return int|WP_Error Post-ID of fout.
 */
function syc_editorial_create_draft( $video_id ) {
	$lock = syc_acquire_lock( SYC_AUTODRAFT_LOCK_KEY, MINUTE_IN_SECONDS );
	if ( false === $lock ) {
		return new WP_Error( 'syc_autodraft_locked', __( 'Een andere videoactie is nog bezig. Probeer het opnieuw.', 'scientias-youtube-carrousel' ) );
	}

	try {
		$index = syc_get_editorial_video_index();
		$map   = get_option( SYC_AUTODRAFT_MAP_OPTION, array() );
		$map   = is_array( $map ) ? $map : array();

		if ( empty( $index['videos'][ $video_id ] ) ) {
			return new WP_Error( 'syc_video_unknown', __( 'Deze video staat niet in het redactionele overzicht.', 'scientias-youtube-carrousel' ) );
		}
		if ( isset( $index['ignored'][ $video_id ] ) || isset( $map[ $video_id ] ) || syc_get_editorial_video_post( $video_id, $map ) ) {
			return new WP_Error( 'syc_video_processed', __( 'Deze video is al verwerkt en krijgt geen nieuw concept.', 'scientias-youtube-carrousel' ) );
		}

		$item    = array(
			'video_id' => $video_id,
			'title'    => isset( $index['videos'][ $video_id ]['title'] ) ? $index['videos'][ $video_id ]['title'] : '',
		);
		$post_id = syc_create_video_draft( $item, 'editorial_concept' );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$map[ $video_id ] = (int) $post_id;
		update_option( SYC_AUTODRAFT_MAP_OPTION, $map, false );
		return (int) $post_id;
	} finally {
		syc_release_lock( SYC_AUTODRAFT_LOCK_KEY, $lock );
	}
}

/**
 * Koppel een bestaand WordPress-artikel aan een feedvideo.
 *
 * @param string $video_id YouTube video-ID.
 * @param int    $post_id  Artikel-ID.
 * @return int|WP_Error Gekoppeld artikel-ID of fout.
 */
function syc_editorial_link_post( $video_id, $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'trash' === $post->post_status ) {
		return new WP_Error( 'syc_invalid_post', __( 'Kies een bestaand WordPress-artikel dat niet in de prullenbak staat.', 'scientias-youtube-carrousel' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'syc_cannot_edit_post', __( 'Je mag dit artikel niet bewerken.', 'scientias-youtube-carrousel' ) );
	}

	$lock = syc_acquire_lock( SYC_AUTODRAFT_LOCK_KEY, MINUTE_IN_SECONDS );
	if ( false === $lock ) {
		return new WP_Error( 'syc_autodraft_locked', __( 'Een andere videoactie is nog bezig. Probeer het opnieuw.', 'scientias-youtube-carrousel' ) );
	}

	try {
		$index = syc_get_editorial_video_index();
		$map   = get_option( SYC_AUTODRAFT_MAP_OPTION, array() );
		$map   = is_array( $map ) ? $map : array();

		if ( empty( $index['videos'][ $video_id ] ) ) {
			return new WP_Error( 'syc_video_unknown', __( 'Deze video staat niet in het redactionele overzicht.', 'scientias-youtube-carrousel' ) );
		}
		if ( isset( $index['ignored'][ $video_id ] ) ) {
			return new WP_Error( 'syc_video_ignored', __( 'Deze video is al genegeerd en kan niet meer worden gekoppeld.', 'scientias-youtube-carrousel' ) );
		}

		$current_post = syc_get_editorial_video_post( $video_id, $map );
		if ( $current_post instanceof WP_Post && (int) $current_post->ID !== $post_id ) {
			return new WP_Error( 'syc_video_already_linked', __( 'Deze video is al aan een ander artikel gekoppeld.', 'scientias-youtube-carrousel' ) );
		}
		if ( isset( $map[ $video_id ] ) && ! $current_post ) {
			return new WP_Error( 'syc_video_processed', __( 'Deze video is eerder verwerkt en wordt niet opnieuw gekoppeld.', 'scientias-youtube-carrousel' ) );
		}

		$attached_video_id = syc_sanitize_youtube_video_id( get_post_meta( $post_id, '_syc_video_id', true ) );
		if ( '' !== $attached_video_id && $attached_video_id !== $video_id ) {
			return new WP_Error( 'syc_post_already_linked', __( 'Dit artikel is al aan een andere feedvideo gekoppeld.', 'scientias-youtube-carrousel' ) );
		}

		$settings  = syc_get_settings();
		$permalink = 'publish' === $post->post_status ? get_permalink( $post ) : '';
		if ( ! empty( $settings['link_overrides'][ $video_id ] ) && ( '' === $permalink || $settings['link_overrides'][ $video_id ] !== $permalink ) ) {
			return new WP_Error( 'syc_override_exists', __( 'Deze video heeft al een andere link override. Pas die eerst aan op de pagina Link overrides.', 'scientias-youtube-carrousel' ) );
		}

		if ( '' !== $permalink && empty( $settings['link_overrides'][ $video_id ] ) ) {
			$updated = syc_update_settings_locked(
				function ( $current ) use ( $video_id, $permalink ) {
					if ( empty( $current['link_overrides'][ $video_id ] ) && count( $current['link_overrides'] ) >= SYC_MAX_LINK_OVERRIDES ) {
						return new WP_Error( 'syc_link_limit', __( 'Het artikel kon niet worden gekoppeld omdat de override-limiet is bereikt.', 'scientias-youtube-carrousel' ) );
					}
					if ( ! empty( $current['link_overrides'][ $video_id ] ) && $current['link_overrides'][ $video_id ] !== $permalink ) {
						return new WP_Error( 'syc_override_changed', __( 'De link override is ondertussen gewijzigd. Probeer het opnieuw.', 'scientias-youtube-carrousel' ) );
					}
					$current['link_overrides'][ $video_id ] = esc_url_raw( $permalink );
					return $current;
				}
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		update_post_meta( $post_id, '_syc_video_id', $video_id );
		update_post_meta( $post_id, '_syc_video_origin', 'existing_article' );
		$map[ $video_id ] = $post_id;
		update_option( SYC_AUTODRAFT_MAP_OPTION, $map, false );
		if ( '' !== $permalink ) {
			syc_purge_page_caches();
		}

		return $post_id;
	} finally {
		syc_release_lock( SYC_AUTODRAFT_LOCK_KEY, $lock );
	}
}

/**
 * Markeer een nog niet verwerkte feedvideo als genegeerd.
 *
 * @param string $video_id YouTube video-ID.
 * @return true|WP_Error
 */
function syc_editorial_ignore_video( $video_id ) {
	$lock       = syc_acquire_lock( SYC_AUTODRAFT_LOCK_KEY, MINUTE_IN_SECONDS );
	$index_lock = false;
	if ( false === $lock ) {
		return new WP_Error( 'syc_autodraft_locked', __( 'Een andere videoactie is nog bezig. Probeer het opnieuw.', 'scientias-youtube-carrousel' ) );
	}

	try {
		$index_lock = syc_acquire_editorial_index_lock();
		if ( false === $index_lock ) {
			return new WP_Error( 'syc_editorial_index_locked', __( 'Het video-overzicht wordt momenteel bijgewerkt. Probeer het opnieuw.', 'scientias-youtube-carrousel' ) );
		}

		$index    = syc_get_editorial_video_index();
		$map      = get_option( SYC_AUTODRAFT_MAP_OPTION, array() );
		$map      = is_array( $map ) ? $map : array();
		$settings = syc_get_settings();

		if ( empty( $index['videos'][ $video_id ] ) ) {
			return new WP_Error( 'syc_video_unknown', __( 'Deze video staat niet in het redactionele overzicht.', 'scientias-youtube-carrousel' ) );
		}
		if ( isset( $map[ $video_id ] ) || syc_get_editorial_video_post( $video_id, $map ) || ! empty( $settings['link_overrides'][ $video_id ] ) ) {
			return new WP_Error( 'syc_video_processed', __( 'Deze video is al verwerkt en kan niet worden genegeerd.', 'scientias-youtube-carrousel' ) );
		}

		$index['ignored'][ $video_id ] = time();
		update_option( SYC_EDITORIAL_VIDEO_INDEX_OPTION, $index, false );
		return true;
	} finally {
		if ( false !== $index_lock ) {
			syc_release_lock( SYC_EDITORIAL_INDEX_LOCK_KEY, $index_lock );
		}
		syc_release_lock( SYC_AUTODRAFT_LOCK_KEY, $lock );
	}
}

/**
 * Zoek bewerkbare WordPress-artikelen voor de redactionele autocomplete.
 *
 * @param string $term Zoekterm.
 * @return array
 */
function syc_search_editorial_posts( $term ) {
	$term = sanitize_text_field( $term );
	if ( function_exists( 'mb_substr' ) ) {
		$term = mb_substr( $term, 0, 100 );
	} else {
		$term = substr( $term, 0, 100 );
	}
	if ( strlen( $term ) < 2 ) {
		return array();
	}

	$query   = new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			's'                   => $term,
			'posts_per_page'      => 20,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'orderby'             => 'relevance',
		)
	);
	$results = array();

	foreach ( $query->posts as $post ) {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		$status = get_post_status_object( $post->post_status );
		/* translators: 1: article title, 2: post ID, 3: post status. */
		$label     = sprintf( __( '%1$s — #%2$d (%3$s)', 'scientias-youtube-carrousel' ), get_the_title( $post ), $post->ID, $status ? $status->label : $post->post_status );
		$results[] = array(
			'label'   => $label,
			'value'   => get_the_title( $post ),
			'title'   => get_the_title( $post ),
			'post_id' => (int) $post->ID,
		);
	}

	return $results;
}

/**
 * Geef artikelzoekresultaten aan de redactionele autocomplete.
 */
function syc_ajax_search_editorial_posts() {
	if ( ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => __( 'Onvoldoende rechten.', 'scientias-youtube-carrousel' ) ), 403 );
	}
	check_ajax_referer( 'syc_search_editorial_posts', 'nonce' );

	$term = isset( $_REQUEST['term'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['term'] ) ) : '';
	wp_send_json_success( syc_search_editorial_posts( $term ) );
}
add_action( 'wp_ajax_syc_search_editorial_posts', 'syc_ajax_search_editorial_posts' );

/**
 * Verwerk een actie uit het redactionele video-overzicht.
 */
function syc_handle_editorial_video_action() {
	if ( ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		wp_die( esc_html__( 'Je hebt geen rechten om feedvideo’s te beheren.', 'scientias-youtube-carrousel' ) );
	}
	check_admin_referer( 'syc_editorial_video_action', 'syc_editorial_nonce' );

	$action   = isset( $_POST['editorial_action'] ) ? sanitize_key( wp_unslash( $_POST['editorial_action'] ) ) : '';
	$video_id = isset( $_POST['video_id'] ) ? syc_sanitize_youtube_video_id( sanitize_text_field( wp_unslash( $_POST['video_id'] ) ) ) : '';
	$result   = new WP_Error( 'syc_invalid_action', __( 'Ongeldige videoactie.', 'scientias-youtube-carrousel' ) );

	if ( '' !== $video_id && 'create_draft' === $action ) {
		$result = current_user_can( 'edit_posts' ) ? syc_editorial_create_draft( $video_id ) : new WP_Error( 'syc_cannot_create_posts', __( 'Je mag geen WordPress-concepten aanmaken.', 'scientias-youtube-carrousel' ) );
	} elseif ( '' !== $video_id && 'link_post' === $action ) {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$result  = syc_editorial_link_post( $video_id, $post_id );
	} elseif ( '' !== $video_id && 'ignore' === $action ) {
		$result = syc_editorial_ignore_video( $video_id );
	}

	if ( is_wp_error( $result ) ) {
		$notice = array(
			'type'    => 'error',
			'message' => $result->get_error_message(),
		);
	} else {
		$messages = array(
			'create_draft' => __( 'Het concept is aangemaakt.', 'scientias-youtube-carrousel' ),
			'link_post'    => __( 'Het artikel is aan de feedvideo gekoppeld.', 'scientias-youtube-carrousel' ),
			'ignore'       => __( 'De feedvideo is gemarkeerd als genegeerd.', 'scientias-youtube-carrousel' ),
		);
		$notice   = array(
			'type'    => 'success',
			'message' => isset( $messages[ $action ] ) ? $messages[ $action ] : __( 'De videoactie is uitgevoerd.', 'scientias-youtube-carrousel' ),
		);
	}

	syc_store_admin_notice( $notice );
	wp_safe_redirect( admin_url( 'admin.php?page=syc-video-overview' ) );
	exit;
}
add_action( 'admin_post_syc_editorial_video_action', 'syc_handle_editorial_video_action' );

/**
 * Render het redactionele overzicht van alle bekende feedvideo’s.
 */
function syc_render_video_overview_page() {
	if ( ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		return;
	}

	syc_bootstrap_editorial_video_index();
	$index         = syc_get_editorial_video_index();
	$settings      = syc_get_settings();
	$active        = syc_get_active_editorial_source_keys( $settings );
	$map           = get_option( SYC_AUTODRAFT_MAP_OPTION, array() );
	$map           = is_array( $map ) ? $map : array();
	$notice        = syc_take_admin_notice();
	$videos        = $index['videos'];
	$current_by_id = array();

	foreach ( $videos as $video_id => $video ) {
		$current_by_id[ $video_id ] = syc_is_editorial_video_current( $video_id, $index, $active );
	}

	// Zet actuele video’s vooraan en behoud daarbinnen de laatst-gezien-volgorde.
	uksort(
		$videos,
		function ( $left_id, $right_id ) use ( $current_by_id, $videos ) {
			$present_compare = (int) $current_by_id[ $right_id ] <=> (int) $current_by_id[ $left_id ];
			if ( 0 !== $present_compare ) {
				return $present_compare;
			}
			$left_seen    = isset( $videos[ $left_id ]['last_seen_at'] ) ? (int) $videos[ $left_id ]['last_seen_at'] : 0;
			$right_seen   = isset( $videos[ $right_id ]['last_seen_at'] ) ? (int) $videos[ $right_id ]['last_seen_at'] : 0;
			$seen_compare = $right_seen <=> $left_seen;
			return 0 !== $seen_compare ? $seen_compare : strcmp( $left_id, $right_id );
		}
	);

	// Alleen navigatie; deze waarde wijzigt geen gegevens.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page        = isset( $_GET['video_page'] ) ? max( 1, absint( $_GET['video_page'] ) ) : 1;
	$per_page    = 50;
	$total       = count( $videos );
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$page        = min( $page, $total_pages );
	$rows        = array_slice( $videos, ( $page - 1 ) * $per_page, $per_page, true );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Redactioneel video-overzicht', 'scientias-youtube-carrousel' ); ?></h1>
		<p><?php esc_html_e( 'Beheer alle bekende video’s uit de hoofdfeed en playlistcarrousels vanuit één overzicht.', 'scientias-youtube-carrousel' ); ?></p>

		<?php if ( ! empty( $notice ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Er zijn nog geen feedvideo’s geïndexeerd. Ververs eerst de feed via het dashboard.', 'scientias-youtube-carrousel' ); ?></p></div>
		<?php else : ?>
			<p>
				<?php
				/* translators: %d: number of known feed videos. */
				echo esc_html( sprintf( _n( '%d bekende feedvideo', '%d bekende feedvideo’s', $total, 'scientias-youtube-carrousel' ), $total ) );
				?>
			</p>
			<table class="widefat striped fixed">
				<thead>
					<tr>
						<th scope="col" style="width:110px;"><?php esc_html_e( 'Video', 'scientias-youtube-carrousel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Titel en bron', 'scientias-youtube-carrousel' ); ?></th>
						<th scope="col" style="width:150px;"><?php esc_html_e( 'Redactioneel', 'scientias-youtube-carrousel' ); ?></th>
						<th scope="col" style="width:170px;"><?php esc_html_e( 'Feedstatus', 'scientias-youtube-carrousel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Artikel', 'scientias-youtube-carrousel' ); ?></th>
						<th scope="col" style="width:300px;"><?php esc_html_e( 'Acties', 'scientias-youtube-carrousel' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $video_id => $video ) : ?>
						<?php
						$state      = syc_get_editorial_video_state( $video_id, $map, $settings, $index );
						$is_current = $current_by_id[ $video_id ];
						$thumb      = ! empty( $video['thumb'] ) ? $video['thumb'] : syc_get_youtube_thumbnail_url( $video_id );
						$sources    = isset( $video['sources'] ) && is_array( $video['sources'] ) ? array_values( array_unique( $video['sources'] ) ) : array();
						?>
						<tr>
							<td><a href="<?php echo esc_url( 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id ) ); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( $thumb ); ?>" alt="" style="display:block;width:96px;height:54px;object-fit:cover;" /></a></td>
							<td>
								<strong><?php echo esc_html( ! empty( $video['title'] ) ? $video['title'] : __( 'Video zonder titel', 'scientias-youtube-carrousel' ) ); ?></strong><br />
								<code><?php echo esc_html( $video_id ); ?></code>
								<?php
								if ( ! empty( $sources ) ) :
									?>
									<p class="description"><?php echo esc_html( implode( ' · ', $sources ) ); ?></p><?php endif; ?>
							</td>
							<td><?php echo wp_kses_post( syc_get_dashboard_badge( $state['label'], $state['tone'] ) ); ?></td>
							<td>
								<?php echo wp_kses_post( syc_get_dashboard_badge( $is_current ? __( 'Actueel', 'scientias-youtube-carrousel' ) : __( 'API-video verdwenen', 'scientias-youtube-carrousel' ), $is_current ? 'success' : 'warning' ) ); ?>
								<?php
								if ( ! $is_current ) :
									?>
									<p class="description"><?php esc_html_e( 'Niet meer aanwezig in de laatst opgehaalde actieve feedset.', 'scientias-youtube-carrousel' ); ?></p><?php endif; ?>
							</td>
							<td>
								<?php if ( $state['post'] instanceof WP_Post ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $state['post']->ID ) ); ?>"><?php echo esc_html( get_the_title( $state['post'] ) ); ?></a><br /><code>#<?php echo esc_html( $state['post']->ID ); ?></code>
									<?php
									if ( 'published' === $state['key'] && empty( $state['override'] ) ) :
										?>
										<p class="description"><?php esc_html_e( 'Link override ontbreekt.', 'scientias-youtube-carrousel' ); ?></p><?php endif; ?>
								<?php elseif ( ! empty( $state['override'] ) ) : ?>
									<a href="<?php echo esc_url( $state['override'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Gekoppelde link openen', 'scientias-youtube-carrousel' ); ?></a>
								<?php else : ?>
									<span aria-hidden="true">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'new' === $state['key'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 4px 6px 0;">
										<input type="hidden" name="action" value="syc_editorial_video_action" /><input type="hidden" name="editorial_action" value="create_draft" /><input type="hidden" name="video_id" value="<?php echo esc_attr( $video_id ); ?>" />
										<?php wp_nonce_field( 'syc_editorial_video_action', 'syc_editorial_nonce' ); ?>
										<button type="submit" class="button button-secondary"><?php esc_html_e( 'Concept maken', 'scientias-youtube-carrousel' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px;margin:0 0 6px;align-items:center;">
										<input type="hidden" name="action" value="syc_editorial_video_action" /><input type="hidden" name="editorial_action" value="link_post" /><input type="hidden" name="video_id" value="<?php echo esc_attr( $video_id ); ?>" />
										<?php wp_nonce_field( 'syc_editorial_video_action', 'syc_editorial_nonce' ); ?>
										<label class="screen-reader-text" for="syc_post_search_<?php echo esc_attr( $video_id ); ?>"><?php esc_html_e( 'WordPress-artikel zoeken', 'scientias-youtube-carrousel' ); ?></label>
										<input id="syc_post_search_<?php echo esc_attr( $video_id ); ?>" type="search" class="regular-text syc-article-search" placeholder="<?php esc_attr_e( 'Zoek op artikeltitel…', 'scientias-youtube-carrousel' ); ?>" autocomplete="off" />
										<input type="hidden" name="post_id" class="syc-article-post-id" value="" />
										<noscript><label><?php esc_html_e( 'Artikel-ID', 'scientias-youtube-carrousel' ); ?> <input type="number" name="post_id" min="1" class="small-text" required /></label></noscript>
										<button type="submit" class="button button-secondary"><?php esc_html_e( 'Artikel koppelen', 'scientias-youtube-carrousel' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0;">
										<input type="hidden" name="action" value="syc_editorial_video_action" /><input type="hidden" name="editorial_action" value="ignore" /><input type="hidden" name="video_id" value="<?php echo esc_attr( $video_id ); ?>" />
										<?php wp_nonce_field( 'syc_editorial_video_action', 'syc_editorial_nonce' ); ?>
										<button type="submit" class="button-link-delete"><?php esc_html_e( 'Negeren', 'scientias-youtube-carrousel' ); ?></button>
									</form>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'Geen actie nodig', 'scientias-youtube-carrousel' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'video_page', '%#%', admin_url( 'admin.php?page=syc-video-overview' ) ),
								'format'  => '',
								'current' => $page,
								'total'   => $total_pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render het redactionele feeddashboard.
 */
function syc_render_dashboard_page() {
	if ( ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		return;
	}

	$settings         = syc_get_settings();
	$main_status      = syc_get_main_dashboard_status( $settings );
	$notice           = syc_take_admin_notice();
	$next_refresh     = wp_next_scheduled( SYC_CRON_HOOK );
	$cron_health      = syc_get_cron_health();
	$playlist_sources = array();

	foreach ( $settings['custom_carrousels'] as $slug => $carrousel ) {
		if ( empty( $carrousel['playlist_id'] ) ) {
			continue;
		}
		$playlist_sources[] = array(
			'name'  => ! empty( $carrousel['name'] ) ? $carrousel['name'] : $slug,
			'id'    => $carrousel['playlist_id'],
			'items' => $carrousel['items'],
		);
	}

	$playlist_per_page = 20;
	$playlist_total    = count( $playlist_sources );
	$playlist_pages    = max( 1, (int) ceil( $playlist_total / $playlist_per_page ) );
	// Alleen dashboardnavigatie; deze waarde wijzigt geen gegevens.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$playlist_page = isset( $_GET['syc_playlist_page'] ) ? max( 1, absint( $_GET['syc_playlist_page'] ) ) : 1;
	$playlist_page = min( $playlist_page, $playlist_pages );
	$playlist_rows = array();
	foreach ( array_slice( $playlist_sources, ( $playlist_page - 1 ) * $playlist_per_page, $playlist_per_page ) as $carrousel ) {
		$playlist_rows[] = array(
			'name'   => $carrousel['name'],
			'id'     => $carrousel['id'],
			'status' => syc_get_playlist_dashboard_status( $carrousel['id'], $carrousel['items'] ),
		);
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'YouTube carrousel dashboard', 'scientias-youtube-carrousel' ); ?></h1>
		<p><?php esc_html_e( 'Controleer welke videobron bezoekers zien en of de automatische verversing gezond is.', 'scientias-youtube-carrousel' ); ?></p>

		<?php if ( ! empty( $notice ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) : ?>
			<div class="notice notice-error inline">
				<p>
					<?php esc_html_e( 'De automatische hoofdfeed is niet geconfigureerd. Bezoekers zien nu alleen beschikbare fallbackvideo’s.', 'scientias-youtube-carrousel' ); ?>
					<?php
					if ( current_user_can( 'manage_options' ) ) :
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=syc-onboarding' ) ); ?>"><?php esc_html_e( 'Configuratie starten', 'scientias-youtube-carrousel' ); ?></a><?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( in_array( $cron_health['type'], array( 'warning', 'error' ), true ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $cron_health['type'] ); ?> inline"><p><?php echo esc_html( $cron_health['message'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'warning' === $main_status['source_type'] ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %s: currently active fallback source. */ __( 'Let op: bezoekers zien momenteel de bron “%s” in plaats van een actuele API-feed.', 'scientias-youtube-carrousel' ), $main_status['source'] ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<div style="display:flex;gap:10px;align-items:center;margin:20px 0;flex-wrap:wrap;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="syc_connection_test" />
				<?php wp_nonce_field( 'syc_connection_test_action', 'syc_connection_test_nonce' ); ?>
				<?php submit_button( __( 'Verbinding testen', 'scientias-youtube-carrousel' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="syc_manual_refresh" />
				<?php wp_nonce_field( 'syc_manual_refresh_action', 'syc_manual_refresh_nonce' ); ?>
				<?php submit_button( __( 'Feeds direct verversen', 'scientias-youtube-carrousel' ), 'primary', 'submit', false ); ?>
			</form>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=syc-feed-settings' ) ); ?>"><?php esc_html_e( 'Feed instellingen', 'scientias-youtube-carrousel' ); ?></a>
		</div>
		<?php endif; ?>

		<div class="postbox" style="max-width:1000px;">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Hoofdfeed', 'scientias-youtube-carrousel' ); ?></h2></div>
			<div class="inside">
				<table class="widefat striped">
					<tbody>
						<tr><th scope="row" style="width:220px;"><?php esc_html_e( 'Gezondheid', 'scientias-youtube-carrousel' ); ?></th><td><?php echo wp_kses_post( syc_get_dashboard_badge( $main_status['status'], $main_status['status_type'] ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Actieve bron', 'scientias-youtube-carrousel' ); ?></th><td><?php echo wp_kses_post( syc_get_dashboard_badge( $main_status['source'], $main_status['source_type'] ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Zichtbare items', 'scientias-youtube-carrousel' ); ?></th><td><?php echo esc_html( (string) $main_status['items'] ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Laatste succesvolle verversing', 'scientias-youtube-carrousel' ); ?></th><td><?php echo esc_html( syc_format_dashboard_time( $main_status['last_success_at'] ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Laatste poging', 'scientias-youtube-carrousel' ); ?></th><td><?php echo esc_html( syc_format_dashboard_time( $main_status['last_attempt_at'] ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Volgende croncontrole', 'scientias-youtube-carrousel' ); ?></th><td><?php echo esc_html( syc_format_dashboard_time( (int) $next_refresh ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Crongezondheid', 'scientias-youtube-carrousel' ); ?></th><td><?php echo wp_kses_post( syc_get_dashboard_badge( $cron_health['label'], $cron_health['type'] ) ); ?> <?php echo esc_html( $cron_health['message'] ); ?></td></tr>
						<?php if ( '' !== $main_status['message'] ) : ?>
							<tr><th scope="row"><?php esc_html_e( 'Laatste fout', 'scientias-youtube-carrousel' ); ?></th><td><?php echo esc_html( $main_status['message'] ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<h2><?php esc_html_e( 'Playlistcarrousels', 'scientias-youtube-carrousel' ); ?></h2>
		<?php if ( empty( $playlist_rows ) ) : ?>
			<p><?php esc_html_e( 'Er zijn nog geen extra carrousels met een YouTube-playlist ingesteld.', 'scientias-youtube-carrousel' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=syc-custom-carrousels' ) ); ?>"><?php esc_html_e( 'Extra carrousel toevoegen', 'scientias-youtube-carrousel' ); ?></a></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1200px;">
				<thead><tr><th><?php esc_html_e( 'Carrousel', 'scientias-youtube-carrousel' ); ?></th><th><?php esc_html_e( 'Gezondheid', 'scientias-youtube-carrousel' ); ?></th><th><?php esc_html_e( 'Actieve bron', 'scientias-youtube-carrousel' ); ?></th><th><?php esc_html_e( 'Items', 'scientias-youtube-carrousel' ); ?></th><th><?php esc_html_e( 'Laatste succes', 'scientias-youtube-carrousel' ); ?></th><th><?php esc_html_e( 'Laatste poging', 'scientias-youtube-carrousel' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $playlist_rows as $row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $row['name'] ); ?></strong><br><code><?php echo esc_html( $row['id'] ); ?></code>
								<?php if ( '' !== $row['status']['message'] ) : ?>
									<br><span style="color:#b32d2e;"><?php echo esc_html( $row['status']['message'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo wp_kses_post( syc_get_dashboard_badge( $row['status']['status'], $row['status']['status_type'] ) ); ?></td>
							<td><?php echo wp_kses_post( syc_get_dashboard_badge( $row['status']['source'], $row['status']['source_type'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['status']['items'] ); ?></td>
							<td><?php echo esc_html( syc_format_dashboard_time( $row['status']['last_success_at'] ) ); ?></td>
							<td><?php echo esc_html( syc_format_dashboard_time( $row['status']['last_attempt_at'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $playlist_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					$pagination_base = str_replace(
						'999999999',
						'%#%',
						add_query_arg(
							array(
								'page'              => 'syc-settings',
								'syc_playlist_page' => 999999999,
							),
							admin_url( 'admin.php' )
						)
					);
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => $pagination_base,
								'format'    => '',
								'current'   => $playlist_page,
								'total'     => $playlist_pages,
								'prev_text' => __( 'Vorige', 'scientias-youtube-carrousel' ),
								'next_text' => __( 'Volgende', 'scientias-youtube-carrousel' ),
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Verwerk een handmatige feedverversing via Post/Redirect/Get.
 */
function syc_handle_manual_refresh() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Je hebt geen rechten om feeds te verversen.', 'scientias-youtube-carrousel' ) );
	}
	check_admin_referer( 'syc_manual_refresh_action', 'syc_manual_refresh_nonce' );

	$result = syc_refresh_all_feeds( true );
	if ( is_wp_error( $result ) ) {
		$notice = array(
			'type'    => 'error',
			'message' => $result->get_error_message(),
		);
	} elseif ( ! empty( $result['errors'] ) ) {
		$first_error = reset( $result['errors'] );
		$notice      = array(
			'type'    => $result['refreshed'] > 0 ? 'warning' : 'error',
			'message' => sprintf(
				/* translators: 1: successful refresh count, 2: error count, 3: first targeted error message. */
				__( '%1$d feeds zijn ververst; %2$d feeds konden niet worden opgehaald. Eerste fout: %3$s', 'scientias-youtube-carrousel' ),
				(int) $result['refreshed'],
				count( $result['errors'] ),
				(string) $first_error
			),
		);
	} else {
		$notice = array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %d: refreshed feed count. */
				__( '%d feeds zijn direct ververst. Eventuele overige playlists worden in volgende cronbatches verwerkt.', 'scientias-youtube-carrousel' ),
				(int) $result['refreshed']
			),
		);
	}

	syc_store_admin_notice( $notice );
	wp_safe_redirect( admin_url( 'admin.php?page=syc-settings' ) );
	exit;
}
add_action( 'admin_post_syc_manual_refresh', 'syc_handle_manual_refresh' );

/**
 * Render de instellingenpagina.
 */
function syc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$refresh_notice = syc_take_admin_notice();

	$settings = syc_get_settings();
	$status   = get_option( 'syc_api_feed_meta', array() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'YouTube feed instellingen', 'scientias-youtube-carrousel' ); ?></h1>
		<?php settings_errors( 'syc_settings' ); ?>

		<p><?php esc_html_e( 'Plaats de carrousel op een pagina of bericht met de shortcode:', 'scientias-youtube-carrousel' ); ?> <code>[scientias_youtube_carrousel]</code></p>

		<?php if ( ! empty( $refresh_notice ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $refresh_notice['type'] ); ?> is-dismissible">
				<p><?php echo esc_html( $refresh_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php
			settings_fields( 'syc_settings_group' );
			?>
			<input type="hidden" name="syc_settings_section" value="general" />
			<input type="hidden" name="syc_settings_revision" value="<?php echo esc_attr( syc_get_general_settings_hash( $settings ) ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="syc_settings_api_key"><?php esc_html_e( 'YouTube API sleutel', 'scientias-youtube-carrousel' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="syc_settings_api_key"
							name="syc_settings[api_key]"
							value=""
							class="regular-text"
							autocomplete="new-password"
							placeholder="<?php echo ! empty( $settings['api_key'] ) ? esc_attr__( 'API-key opgeslagen; leeg laten om te behouden', 'scientias-youtube-carrousel' ) : ''; ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Voer alleen een nieuwe YouTube Data API v3 key in als je de bestaande key wilt vervangen. Een opgeslagen key wordt hier niet zichtbaar getoond.', 'scientias-youtube-carrousel' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="syc_settings_channel_id"><?php esc_html_e( 'Kanaal ID', 'scientias-youtube-carrousel' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="syc_settings_channel_id"
							name="syc_settings[channel_id]"
							value="<?php echo esc_attr( $settings['channel_id'] ); ?>"
							class="regular-text"
						/>
						<p class="description">
							<?php esc_html_e( 'Bijvoorbeeld: UC... (YouTube kanaal ID). Wordt gebruikt om de Shorts-playlist af te leiden.', 'scientias-youtube-carrousel' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="syc_settings_max_items"><?php esc_html_e( 'Maximaal aantal items', 'scientias-youtube-carrousel' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							min="1"
							max="50"
							id="syc_settings_max_items"
							name="syc_settings[max_items]"
							value="<?php echo esc_attr( $settings['max_items'] ); ?>"
							class="small-text"
						/>
						<p class="description">
							<?php esc_html_e( 'Hoeveel Shorts maximaal in de carrousel getoond worden (1–50).', 'scientias-youtube-carrousel' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Automatische concept-berichten', 'scientias-youtube-carrousel' ); ?>
					</th>
					<td>
						<input type="hidden" name="syc_settings[auto_draft_present]" value="1" />
						<input type="hidden" name="syc_settings[draft_settings_present]" value="1" />
						<input type="hidden" name="syc_settings[auto_draft]" value="0" />
						<label for="syc_settings_auto_draft">
							<input
								type="checkbox"
								id="syc_settings_auto_draft"
								name="syc_settings[auto_draft]"
								value="1"
								<?php checked( ! empty( $settings['auto_draft'] ) ); ?>
							/>
							<?php esc_html_e( 'Maak automatisch een WordPress-bericht aan voor nieuwe shorts in de feed', 'scientias-youtube-carrousel' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Voor elke nieuwe short wordt één bericht aangemaakt met de ingestelde auteur, categorieën, status, tekst en het gekozen berichtformaat. Bij publicatie wordt de link-override automatisch gevuld. Verwijderde berichten worden niet opnieuw aangemaakt.', 'scientias-youtube-carrousel' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="syc_settings_draft_author_id"><?php esc_html_e( 'Standaardauteur', 'scientias-youtube-carrousel' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_users(
							array(
								'name'             => 'syc_settings[draft_author_id]',
								'id'               => 'syc_settings_draft_author_id',
								'who'              => 'authors',
								'selected'         => $settings['draft_author_id'],
								'show_option_none' => __( 'WordPress-standaard', 'scientias-youtube-carrousel' ),
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Standaardcategorieën', 'scientias-youtube-carrousel' ); ?></th>
					<td>
						<input type="hidden" name="syc_settings[draft_category_ids_present]" value="1" />
						<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $category ) : ?>
							<label style="display:block;"><input type="checkbox" name="syc_settings[draft_category_ids][]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, $settings['draft_category_ids'], true ) ); ?> /> <?php echo esc_html( $category->name ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="syc_settings_draft_post_format"><?php esc_html_e( 'Berichtformaat', 'scientias-youtube-carrousel' ); ?></label></th>
					<td><select id="syc_settings_draft_post_format" name="syc_settings[draft_post_format]">
						<option value="standard" <?php selected( 'standard', $settings['draft_post_format'] ); ?>><?php esc_html_e( 'Standaard', 'scientias-youtube-carrousel' ); ?></option>
						<?php
						foreach ( get_post_format_strings() as $format => $label ) :
							?>
							<option value="<?php echo esc_attr( $format ); ?>" <?php selected( $format, $settings['draft_post_format'] ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
					</select></td>
				</tr>
				<tr>
					<th scope="row"><label for="syc_settings_draft_post_status"><?php esc_html_e( 'Initiële berichtstatus', 'scientias-youtube-carrousel' ); ?></label></th>
					<td><select id="syc_settings_draft_post_status" name="syc_settings[draft_post_status]">
						<?php
						foreach ( array(
							'draft'   => __( 'Concept', 'scientias-youtube-carrousel' ),
							'pending' => __( 'Wachtend op beoordeling', 'scientias-youtube-carrousel' ),
							'publish' => __( 'Direct publiceren', 'scientias-youtube-carrousel' ),
							'private' => __( 'Privé', 'scientias-youtube-carrousel' ),
						) as $status_key => $status_label ) :
							?>
							<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_key, $settings['draft_post_status'] ); ?>><?php echo esc_html( $status_label ); ?></option>
						<?php endforeach; ?>
					</select><p class="description"><?php esc_html_e( 'Direct publiceren vereist een auteur die berichten mag publiceren.', 'scientias-youtube-carrousel' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="syc_settings_draft_default_text"><?php esc_html_e( 'Standaardtekst', 'scientias-youtube-carrousel' ); ?></label></th>
					<td><textarea id="syc_settings_draft_default_text" name="syc_settings[draft_default_text]" rows="5" class="large-text"><?php echo esc_textarea( $settings['draft_default_text'] ); ?></textarea><p class="description"><?php esc_html_e( 'Deze tekst wordt na de YouTube-URL in ieder automatisch aangemaakt bericht geplaatst.', 'scientias-youtube-carrousel' ); ?></p></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Feed cache', 'scientias-youtube-carrousel' ); ?></h2>
		<p><?php esc_html_e( 'De feed en playlists worden automatisch op de achtergrond in begrensde batches ververst. Je kunt hier direct een nieuwe verversing starten; bestaande feeddata blijft zichtbaar als een aanvraag mislukt.', 'scientias-youtube-carrousel' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="syc_manual_refresh" />
			<?php wp_nonce_field( 'syc_manual_refresh_action', 'syc_manual_refresh_nonce' ); ?>
			<?php submit_button( __( 'Feeds direct verversen', 'scientias-youtube-carrousel' ), 'secondary', 'syc_manual_refresh', false ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Feed status', 'scientias-youtube-carrousel' ); ?></h2>
		<?php if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) : ?>
			<p><?php esc_html_e( 'Configureer eerst je API sleutel en kanaal ID om de status van de YouTube feed te kunnen bekijken.', 'scientias-youtube-carrousel' ); ?></p>
		<?php else : ?>
			<?php if ( empty( $status ) ) : ?>
				<p><?php esc_html_e( 'Er is nog geen aanvraag naar de YouTube API gedaan. Deze wordt binnen 5 minuten automatisch gedaan; gebruik “Feeds direct verversen” om dit nu te forceren.', 'scientias-youtube-carrousel' ); ?></p>
			<?php else : ?>
				<?php
				$timestamp = isset( $status['updated_at'] ) ? (int) $status['updated_at'] : 0;
				$when      = $timestamp ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : __( 'onbekend', 'scientias-youtube-carrousel' );
				?>
				<table class="widefat striped" style="max-width: 600px;">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Laatste update', 'scientias-youtube-carrousel' ); ?></th>
							<td><?php echo esc_html( $when ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'scientias-youtube-carrousel' ); ?></th>
							<td>
								<?php
								if ( isset( $status['status'] ) && 'ok' === $status['status'] ) {
									printf(
										/* translators: %d: aantal items in feed. */
										esc_html__( 'OK – %d items ontvangen uit de YouTube Shorts feed.', 'scientias-youtube-carrousel' ),
										isset( $status['items'] ) ? (int) $status['items'] : 0
									);
								} elseif ( isset( $status['status'] ) && 'error' === $status['status'] ) {
									echo esc_html__( 'Fout bij het ophalen van de feed:', 'scientias-youtube-carrousel' ) . ' ';
									echo isset( $status['message'] ) ? esc_html( $status['message'] ) : esc_html__( 'Onbekende fout.', 'scientias-youtube-carrousel' );
								} else {
									esc_html_e( 'Onbekende status.', 'scientias-youtube-carrousel' );
								}
								?>
							</td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

	</div>
	<?php
}

/**
 * Render de link overrides pagina.
 */
function syc_render_link_overrides_page() {
	if ( ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		return;
	}

	$import_notice  = syc_maybe_import_link_overrides_csv();
	$settings       = syc_get_settings();
	$link_overrides = ! empty( $settings['link_overrides'] ) && is_array( $settings['link_overrides'] ) ? $settings['link_overrides'] : array();
	$per_page       = 50;
	$total_items    = count( $link_overrides );
	$total_pages    = max( 1, (int) ceil( $total_items / $per_page ) );
	// Alleen navigatie; deze waarde wijzigt geen gegevens.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$current_page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$current_page    = min( $current_page, $total_pages );
	$offset          = ( $current_page - 1 ) * $per_page;
	$paged_overrides = array_slice( $link_overrides, $offset, $per_page, true );
	$base_url        = menu_page_url( 'syc-link-overrides', false );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Link overrides', 'scientias-youtube-carrousel' ); ?></h1>
		<?php settings_errors( 'syc_settings' ); ?>
		<p><?php esc_html_e( 'Koppel automatische YouTube feed-video\'s optioneel aan een pagina op de site. Zonder override blijft de titel gewone tekst en opent de thumbnail fullscreen.', 'scientias-youtube-carrousel' ); ?></p>

		<?php if ( ! empty( $import_notice ) ) : ?>
			<div class="notice notice-<?php echo 'success' === $import_notice['type'] ? 'success' : 'error'; ?> is-dismissible">
				<p><?php echo esc_html( $import_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" style="max-width: 900px; margin: 1rem 0 2rem; padding: 1rem; background: #fff; border: 1px solid #c3c4c7;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'CSV importeren', 'scientias-youtube-carrousel' ); ?></h2>
			<p><?php esc_html_e( 'Upload een CSV met twee kolommen: YouTube video-ID en URL. Een header zoals youtube_video_id,url is toegestaan. Komma en puntkomma worden ondersteund.', 'scientias-youtube-carrousel' ); ?></p>

			<p>
				<input type="file" name="syc_link_overrides_csv" accept=".csv,text/csv" />
			</p>

			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Importmodus', 'scientias-youtube-carrousel' ); ?></legend>
				<p>
					<label>
						<input type="radio" name="syc_csv_import_mode" value="merge" checked="checked" />
						<?php esc_html_e( 'Toevoegen of bijwerken: bestaande links blijven behouden, behalve als dezelfde video-ID in de CSV staat.', 'scientias-youtube-carrousel' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="syc_csv_import_mode" value="replace" />
						<strong><?php esc_html_e( 'Alles overschrijven: alle eerdere link overrides worden verwijderd en vervangen door deze CSV.', 'scientias-youtube-carrousel' ); ?></strong>
					</label>
				</p>
			</fieldset>

			<p class="description">
				<?php esc_html_e( 'Let op: kies alleen “Alles overschrijven” als deze CSV de volledige gewenste lijst bevat.', 'scientias-youtube-carrousel' ); ?>
			</p>

			<?php wp_nonce_field( 'syc_csv_import_action', 'syc_csv_import_nonce' ); ?>
			<?php submit_button( __( 'CSV importeren', 'scientias-youtube-carrousel' ), 'secondary', 'syc_csv_import_submit', false ); ?>
		</form>

		<form method="post" action="options.php">
			<?php settings_fields( 'syc_content_settings_group' ); ?>
			<input type="hidden" name="syc_settings_section" value="link_add" />

			<h2><?php esc_html_e( 'Nieuwe link override toevoegen', 'scientias-youtube-carrousel' ); ?></h2>
			<table class="widefat striped" style="max-width: 900px; margin-top: 1rem;">
				<thead>
					<tr>
						<th style="width: 220px;"><?php esc_html_e( 'YouTube video-ID', 'scientias-youtube-carrousel' ); ?></th>
						<th><?php esc_html_e( 'Link naar pagina', 'scientias-youtube-carrousel' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>
							<input type="text" name="syc_settings[link_overrides][new][video_id]" value="" class="regular-text" placeholder="dQw4w9WgXcQ" />
						</td>
						<td>
							<input type="url" name="syc_settings[link_overrides][new][url]" value="" class="large-text" placeholder="https://www.scientias.nl/..." />
						</td>
					</tr>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Tip: de video-ID staat in de YouTube URL na ?v= of na /shorts/. Maak een URL-veld leeg om die override bij opslaan te verwijderen.', 'scientias-youtube-carrousel' ); ?>
			</p>

			<?php submit_button( __( 'Nieuwe override opslaan', 'scientias-youtube-carrousel' ) ); ?>
		</form>

		<?php if ( ! empty( $link_overrides ) ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Bestaande link overrides bewerken', 'scientias-youtube-carrousel' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: first item number, 2: last item number, 3: total item count. */
					esc_html__( 'Toont %1$d-%2$d van %3$d opgeslagen koppelingen.', 'scientias-youtube-carrousel' ),
					(int) ( $total_items ? $offset + 1 : 0 ),
					(int) min( $offset + $per_page, $total_items ),
					(int) $total_items
				);
				?>
			</p>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav top" style="max-width: 900px;">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%', $base_url ),
									'format'    => '',
									'current'   => $current_page,
									'total'     => $total_pages,
									'prev_text' => __( '&laquo;', 'scientias-youtube-carrousel' ),
									'next_text' => __( '&raquo;', 'scientias-youtube-carrousel' ),
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'syc_content_settings_group' ); ?>
				<input type="hidden" name="syc_settings_section" value="link_edit" />

				<table class="widefat striped" style="max-width: 900px;">
					<thead>
						<tr>
							<th style="width: 220px;"><?php esc_html_e( 'Video-ID', 'scientias-youtube-carrousel' ); ?></th>
							<th><?php esc_html_e( 'Gekoppelde pagina', 'scientias-youtube-carrousel' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $paged_overrides as $video_id => $url ) : ?>
							<?php $row_key = 'edit_' . md5( $video_id ); ?>
							<tr>
								<td>
									<input type="hidden" name="syc_settings[link_overrides][<?php echo esc_attr( $row_key ); ?>][original_video_id]" value="<?php echo esc_attr( $video_id ); ?>" />
									<input type="hidden" name="syc_settings[link_overrides][<?php echo esc_attr( $row_key ); ?>][original_hash]" value="<?php echo esc_attr( syc_get_link_override_hash( $video_id, $url ) ); ?>" />
									<input type="text" name="syc_settings[link_overrides][<?php echo esc_attr( $row_key ); ?>][video_id]" value="<?php echo esc_attr( $video_id ); ?>" class="regular-text" />
								</td>
								<td>
									<input type="url" name="syc_settings[link_overrides][<?php echo esc_attr( $row_key ); ?>][url]" value="<?php echo esc_url( $url ); ?>" class="large-text" placeholder="https://www.scientias.nl/..." />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Maak een URL-veld leeg en sla op om die override te verwijderen. Alleen de huidige pagina met overrides is bewerkbaar; andere pagina\'s blijven behouden.', 'scientias-youtube-carrousel' ); ?>
				</p>

				<?php submit_button( __( 'Wijzigingen opslaan', 'scientias-youtube-carrousel' ) ); ?>
			</form>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom" style="max-width: 900px;">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%', $base_url ),
									'format'    => '',
									'current'   => $current_page,
									'total'     => $total_pages,
									'prev_text' => __( '&laquo;', 'scientias-youtube-carrousel' ),
									'next_text' => __( '&raquo;', 'scientias-youtube-carrousel' ),
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php $cached_feed_items = syc_get_api_shorts_items(); ?>
		<?php if ( is_array( $cached_feed_items ) && ! empty( $cached_feed_items ) ) : ?>
			<h2><?php esc_html_e( 'Huidige feed-video\'s', 'scientias-youtube-carrousel' ); ?></h2>
			<p><?php esc_html_e( 'Gebruik deze video-ID\'s bij Link overrides. De status laat zien of er voor die feedvideo al een pagina is gekoppeld.', 'scientias-youtube-carrousel' ); ?></p>
			<table class="widefat striped" style="max-width: 900px;">
				<thead>
					<tr>
						<th style="width: 180px;"><?php esc_html_e( 'Video-ID', 'scientias-youtube-carrousel' ); ?></th>
						<th><?php esc_html_e( 'Titel', 'scientias-youtube-carrousel' ); ?></th>
						<th><?php esc_html_e( 'Override', 'scientias-youtube-carrousel' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cached_feed_items as $feed_item ) : ?>
						<?php
						$feed_video_id = isset( $feed_item['video_id'] ) ? $feed_item['video_id'] : '';
						$feed_title    = isset( $feed_item['title'] ) ? $feed_item['title'] : '';
						$override_url  = isset( $link_overrides[ $feed_video_id ] ) ? $link_overrides[ $feed_video_id ] : '';
						?>
						<tr>
							<td><code><?php echo esc_html( $feed_video_id ); ?></code></td>
							<td><?php echo esc_html( $feed_title ); ?></td>
							<td>
								<?php if ( ! empty( $override_url ) ) : ?>
									<a href="<?php echo esc_url( $override_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $override_url ); ?></a>
								<?php else : ?>
									<?php esc_html_e( 'Geen override', 'scientias-youtube-carrousel' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Bouw een exporteerbaar configuratiedocument zonder geheimen of runtimegegevens.
 *
 * @return array
 */
function syc_get_config_export_document() {
	$settings = syc_get_settings();
	$portable = $settings;
	unset( $portable['api_key'] );

	return array(
		'format'         => 'scientias-youtube-carrousel-config',
		'schema_version' => 1,
		'plugin_version' => SYC_VERSION,
		'exported_at'    => gmdate( 'c' ),
		'settings'       => $portable,
	);
}

/**
 * Valideer een volledig configuratiedocument zonder data stil te verwijderen.
 *
 * @param mixed $document Gedecodeerd JSON-document.
 * @return array|WP_Error Gevalideerde portable settings of fout.
 */
function syc_validate_config_document( $document ) {
	if ( ! is_array( $document ) || 'scientias-youtube-carrousel-config' !== ( $document['format'] ?? '' ) || 1 !== (int) ( $document['schema_version'] ?? 0 ) || ! isset( $document['settings'] ) || ! is_array( $document['settings'] ) ) {
		return new WP_Error( 'syc_config_format', __( 'Dit is geen ondersteund Scientias-carrouselconfiguratiebestand.', 'scientias-youtube-carrousel' ) );
	}

	$allowed_top = array( 'format', 'schema_version', 'plugin_version', 'exported_at', 'settings' );
	if ( array_diff( array_keys( $document ), $allowed_top ) ) {
		return new WP_Error( 'syc_config_unknown_top_field', __( 'Het configuratiebestand bevat onbekende hoofdvelden.', 'scientias-youtube-carrousel' ) );
	}

	$settings = $document['settings'];
	$allowed  = array( 'channel_id', 'max_items', 'auto_draft', 'draft_author_id', 'draft_category_ids', 'draft_post_format', 'draft_default_text', 'draft_post_status', 'link_overrides', 'custom_carrousels' );
	if ( isset( $settings['api_key'] ) || array_diff( array_keys( $settings ), $allowed ) ) {
		return new WP_Error( 'syc_config_unknown_setting', __( 'Het configuratiebestand bevat een geheim of onbekend instellingenveld.', 'scientias-youtube-carrousel' ) );
	}
	if ( array_diff( $allowed, array_keys( $settings ) ) ) {
		return new WP_Error( 'syc_config_incomplete', __( 'Het configuratiebestand mist verplichte instellingen.', 'scientias-youtube-carrousel' ) );
	}

	if ( ! is_string( $settings['channel_id'] ) || trim( sanitize_text_field( $settings['channel_id'] ) ) !== $settings['channel_id'] || ( '' !== $settings['channel_id'] && ! syc_get_shorts_playlist_id( $settings['channel_id'] ) ) ) {
		return new WP_Error( 'syc_config_channel', __( 'Het configuratiebestand bevat een ongeldig kanaal-ID.', 'scientias-youtube-carrousel' ) );
	}
	if ( ! is_int( $settings['max_items'] ) || $settings['max_items'] < 1 || $settings['max_items'] > 50 ) {
		return new WP_Error( 'syc_config_max_items', __( 'Het maximale aantal items in het configuratiebestand is ongeldig.', 'scientias-youtube-carrousel' ) );
	}
	if ( ! is_int( $settings['auto_draft'] ) || ! in_array( $settings['auto_draft'], array( 0, 1 ), true ) ) {
		return new WP_Error( 'syc_config_auto_draft', __( 'De automatische-berichtinstelling is ongeldig.', 'scientias-youtube-carrousel' ) );
	}
	if ( ! is_int( $settings['draft_author_id'] ) ) {
		return new WP_Error( 'syc_config_author', __( 'De standaardauteur uit het configuratiebestand bestaat niet op deze site.', 'scientias-youtube-carrousel' ) );
	}
	$draft_author = $settings['draft_author_id'] > 0 ? get_user_by( 'id', $settings['draft_author_id'] ) : false;
	if ( $settings['draft_author_id'] > 0 && ( ! $draft_author || ! user_can( $draft_author, 'edit_posts' ) ) ) {
		return new WP_Error( 'syc_config_author', __( 'De standaardauteur uit het configuratiebestand bestaat niet op deze site.', 'scientias-youtube-carrousel' ) );
	}
	if ( 'publish' === $settings['draft_post_status'] && ( ! $draft_author || ! user_can( $draft_author, 'publish_posts' ) ) ) {
		return new WP_Error( 'syc_config_author_publish', __( 'De standaardauteur uit het configuratiebestand mag geen berichten publiceren.', 'scientias-youtube-carrousel' ) );
	}
	$invalid_category_type = false;
	foreach ( (array) $settings['draft_category_ids'] as $category_id ) {
		if ( ! is_int( $category_id ) ) {
			$invalid_category_type = true;
			break;
		}
	}
	if ( ! is_array( $settings['draft_category_ids'] ) || $invalid_category_type || syc_sanitize_draft_category_ids( $settings['draft_category_ids'] ) !== array_values( $settings['draft_category_ids'] ) ) {
		return new WP_Error( 'syc_config_categories', __( 'Een of meer standaardcategorieën uit het configuratiebestand bestaan niet.', 'scientias-youtube-carrousel' ) );
	}
	if ( ! is_string( $settings['draft_post_format'] ) || ! is_string( $settings['draft_post_status'] ) || ! is_string( $settings['draft_default_text'] ) || syc_sanitize_draft_post_format( $settings['draft_post_format'] ) !== $settings['draft_post_format'] || syc_sanitize_draft_post_status( $settings['draft_post_status'] ) !== $settings['draft_post_status'] || syc_sanitize_draft_default_text( $settings['draft_default_text'] ) !== $settings['draft_default_text'] ) {
		return new WP_Error( 'syc_config_draft_defaults', __( 'De standaardinstellingen voor automatische berichten zijn ongeldig.', 'scientias-youtube-carrousel' ) );
	}

	if ( ! is_array( $settings['link_overrides'] ) || count( $settings['link_overrides'] ) > SYC_MAX_LINK_OVERRIDES ) {
		return new WP_Error( 'syc_config_overrides', __( 'De link overrides in het configuratiebestand zijn ongeldig of overschrijden de limiet.', 'scientias-youtube-carrousel' ) );
	}
	foreach ( $settings['link_overrides'] as $video_id => $url ) {
		if ( syc_sanitize_youtube_video_id( $video_id ) !== $video_id || ! is_string( $url ) || '' === $url || esc_url_raw( $url ) !== $url ) {
			return new WP_Error( 'syc_config_override_row', __( 'Het configuratiebestand bevat een ongeldige link override.', 'scientias-youtube-carrousel' ) );
		}
	}
	if ( syc_sanitize_link_overrides( $settings['link_overrides'], SYC_MAX_LINK_OVERRIDES ) !== $settings['link_overrides'] ) {
		return new WP_Error( 'syc_config_override_normalization', __( 'Een link override zou tijdens import worden gewijzigd en is daarom geweigerd.', 'scientias-youtube-carrousel' ) );
	}

	if ( ! is_array( $settings['custom_carrousels'] ) || count( $settings['custom_carrousels'] ) > SYC_MAX_CUSTOM_CARROUSELS ) {
		return new WP_Error( 'syc_config_carrousels', __( 'De carrousels in het configuratiebestand zijn ongeldig of overschrijden de limiet.', 'scientias-youtube-carrousel' ) );
	}
	foreach ( $settings['custom_carrousels'] as $slug => $carrousel ) {
		if ( ! is_array( $carrousel ) || array_diff( array_keys( $carrousel ), array( 'name', 'slug', 'playlist_id', 'items' ) ) || array_diff( array( 'name', 'slug', 'playlist_id', 'items' ), array_keys( $carrousel ) ) || syc_sanitize_carrousel_slug( $slug ) !== $slug || (string) ( $carrousel['slug'] ?? '' ) !== $slug || ! is_string( $carrousel['name'] ?? null ) ) {
			return new WP_Error( 'syc_config_carrousel_row', __( 'Het configuratiebestand bevat een ongeldige carrousel.', 'scientias-youtube-carrousel' ) );
		}
		$playlist_id = $carrousel['playlist_id'] ?? '';
		if ( ! is_string( $playlist_id ) || ( '' !== $playlist_id && syc_extract_youtube_playlist_id( $playlist_id ) !== $playlist_id ) || ! isset( $carrousel['items'] ) || ! is_array( $carrousel['items'] ) || count( $carrousel['items'] ) > SYC_MAX_CUSTOM_CARROUSEL_ITEMS ) {
			return new WP_Error( 'syc_config_carrousel_source', __( 'Een carrousel bevat een ongeldige playlist of te veel handmatige video’s.', 'scientias-youtube-carrousel' ) );
		}
		foreach ( $carrousel['items'] as $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'title', 'video_url', 'link_url' ) ) || array_diff( array( 'title', 'video_url', 'link_url' ), array_keys( $item ) ) || ! is_string( $item['title'] ?? null ) || ! is_string( $item['video_url'] ?? null ) || '' === syc_extract_youtube_video_id( $item['video_url'] ?? '' ) || ! is_string( $item['link_url'] ?? null ) || esc_url_raw( $item['link_url'] ) !== $item['link_url'] ) {
				return new WP_Error( 'syc_config_carrousel_item', __( 'Een carrousel bevat een ongeldige handmatige video.', 'scientias-youtube-carrousel' ) );
			}
		}
	}
	if ( syc_sanitize_custom_carrousels( $settings['custom_carrousels'], SYC_MAX_CUSTOM_CARROUSELS, SYC_MAX_CUSTOM_CARROUSEL_ITEMS ) !== $settings['custom_carrousels'] ) {
		return new WP_Error( 'syc_config_carrousel_normalization', __( 'Een carrousel zou tijdens import worden gewijzigd en is daarom geweigerd.', 'scientias-youtube-carrousel' ) );
	}

	return $settings;
}

/**
 * Pas een gevalideerd configuratiedocument atomair toe.
 *
 * @param array $document Gedecodeerd JSON-document.
 * @return array|WP_Error Nieuwe instellingen of fout.
 */
function syc_import_config_document( $document ) {
	$portable = syc_validate_config_document( $document );
	if ( is_wp_error( $portable ) ) {
		return $portable;
	}

	$old_settings = syc_get_settings();
	$updated      = syc_update_settings_locked(
		function ( $current ) use ( $portable ) {
			$api_key            = $current['api_key'];
			$current            = array_merge( $current, $portable );
			$current['api_key'] = $api_key;
			return $current;
		}
	);
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	$old_keys = syc_get_main_feed_storage_keys( $old_settings );
	$new_keys = syc_get_main_feed_storage_keys( $updated );
	if ( $old_keys !== $new_keys ) {
		delete_transient( $old_keys['cache'] );
		delete_option( $old_keys['stale'] );
		delete_option( 'syc_api_feed_meta' );
	}
	$removed_playlists = array_diff( syc_get_carrousel_playlist_ids( $old_settings['custom_carrousels'] ), syc_get_carrousel_playlist_ids( $updated['custom_carrousels'] ) );
	if ( ! empty( $removed_playlists ) ) {
		syc_cleanup_playlist_caches( $removed_playlists );
	}
	syc_purge_page_caches();
	return $updated;
}

/**
 * Download de portable configuratie als JSON.
 */
function syc_handle_export_config() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Je hebt geen rechten om configuraties te exporteren.', 'scientias-youtube-carrousel' ) );
	}
	check_admin_referer( 'syc_export_config_action', 'syc_export_config_nonce' );
	nocache_headers();
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="scientias-youtube-carrousel-config.json"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo wp_json_encode( syc_get_config_export_document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-download, geen HTML.
	exit;
}
add_action( 'admin_post_syc_export_config', 'syc_handle_export_config' );

/**
 * Importeer een volledig gevalideerde portable JSON-configuratie.
 */
function syc_handle_import_config() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Je hebt geen rechten om configuraties te importeren.', 'scientias-youtube-carrousel' ) );
	}
	check_admin_referer( 'syc_import_config_action', 'syc_import_config_nonce' );

	$error     = isset( $_FILES['syc_config_file']['error'] ) ? (int) $_FILES['syc_config_file']['error'] : UPLOAD_ERR_NO_FILE;
	$size      = isset( $_FILES['syc_config_file']['size'] ) ? (int) $_FILES['syc_config_file']['size'] : 0;
	$tmp_name  = isset( $_FILES['syc_config_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['syc_config_file']['tmp_name'] ) ) : '';
	$file_name = isset( $_FILES['syc_config_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['syc_config_file']['name'] ) ) : '';

	if ( UPLOAD_ERR_OK !== $error || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		$result = new WP_Error( 'syc_config_upload', __( 'Het configuratiebestand kon niet veilig worden ontvangen.', 'scientias-youtube-carrousel' ) );
	} elseif ( $size < 1 || $size > SYC_CONFIG_IMPORT_MAX_FILE_SIZE || 'json' !== strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) ) {
		$result = new WP_Error( 'syc_config_file', __( 'Upload een JSON-bestand van maximaal 2 MB.', 'scientias-youtube-carrousel' ) );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Beperkt, lokaal en door is_uploaded_file gevalideerd uploadbestand.
		$json     = file_get_contents( $tmp_name );
		$json     = is_string( $json ) ? preg_replace( '/^\xEF\xBB\xBF/', '', $json ) : '';
		$document = json_decode( $json, true );
		$result   = JSON_ERROR_NONE === json_last_error() ? syc_import_config_document( $document ) : new WP_Error( 'syc_config_json', __( 'Het JSON-bestand is niet leesbaar.', 'scientias-youtube-carrousel' ) );
	}

	syc_store_admin_notice(
		is_wp_error( $result )
			? array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			)
			: array(
				'type'    => 'success',
				'message' => __( 'De configuratie is geïmporteerd. Ververs de feeds om de nieuwe bronnen direct te controleren.', 'scientias-youtube-carrousel' ),
			)
	);
	wp_safe_redirect( admin_url( 'admin.php?page=syc-tools' ) );
	exit;
}
add_action( 'admin_post_syc_import_config', 'syc_handle_import_config' );

/**
 * Lees transientstatus voor diagnostiek zonder verlopen data te verwijderen.
 *
 * @param string $key Transientsleutel.
 * @return array
 */
function syc_get_transient_diagnostic( $key ) {
	if ( wp_using_ext_object_cache() ) {
		return array(
			'state' => 'external_object_cache',
			'items' => null,
		);
	}

	$value   = get_option( '_transient_' . $key, null );
	$timeout = (int) get_option( '_transient_timeout_' . $key, 0 );
	$state   = null === $value ? 'missing' : ( $timeout > 0 && $timeout < time() ? 'expired' : 'available' );
	return array(
		'state' => $state,
		'items' => is_array( $value ) ? count( $value ) : null,
	);
}

/**
 * Bouw een geheimvrij, read-only diagnostisch rapport.
 *
 * @return array
 */
function syc_get_diagnostic_report() {
	$settings    = syc_get_settings();
	$main_keys   = syc_get_main_feed_storage_keys( $settings );
	$main_meta   = get_option( 'syc_api_feed_meta', array() );
	$main_meta   = is_array( $main_meta ) ? $main_meta : array();
	$registry    = get_option( SYC_PLAYLIST_CACHE_REGISTRY_OPTION, array() );
	$registry    = is_array( $registry ) ? $registry : array();
	$playlists   = array();
	$playlist_no = 0;

	foreach ( syc_get_carrousel_playlist_ids( $settings['custom_carrousels'] ) as $playlist_id ) {
		++$playlist_no;
		$hash        = md5( $playlist_id );
		$meta        = get_option( SYC_PLAYLIST_META_PREFIX . $hash, array() );
		$meta        = is_array( $meta ) ? $meta : array();
		$playlists[] = array(
			'number'          => $playlist_no,
			'source_hash'     => substr( hash( 'sha256', $playlist_id ), 0, 12 ),
			'cache'           => syc_get_transient_diagnostic( SYC_PLAYLIST_CACHE_PREFIX . $hash ),
			'stale_items'     => is_array( get_option( SYC_PLAYLIST_STALE_PREFIX . $hash, null ) ) ? count( get_option( SYC_PLAYLIST_STALE_PREFIX . $hash, array() ) ) : null,
			'last_attempt_at' => syc_get_feed_meta_time( $meta, 'last_attempt_at' ),
			'last_success_at' => syc_get_feed_meta_time( $meta, 'last_success_at' ),
			'last_error_code' => isset( $meta['code'] ) ? sanitize_key( $meta['code'] ) : '',
		);
	}

	$locks = array();
	foreach ( array( SYC_AUTODRAFT_LOCK_KEY, SYC_EDITORIAL_INDEX_LOCK_KEY, SYC_FEED_REFRESH_LOCK_KEY, SYC_SETTINGS_LOCK_KEY, SYC_CRON_SCHEDULE_LOCK_KEY ) as $lock_key ) {
		$lock               = get_option( '_syc_lock_' . sanitize_key( $lock_key ), false );
		$locks[ $lock_key ] = array(
			'present'    => false !== $lock,
			'expires_at' => is_array( $lock ) && isset( $lock['expires'] ) ? (int) $lock['expires'] : ( is_numeric( $lock ) ? (int) $lock : 0 ),
		);
	}
	$cron_health = syc_get_cron_health();

	return array(
		'generated_at'  => gmdate( 'c' ),
		'environment'   => array(
			'plugin_version' => SYC_VERSION,
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'multisite'      => is_multisite(),
			'blog_id'        => get_current_blog_id(),
		),
		'configuration' => array(
			'api_key_configured' => ! empty( $settings['api_key'] ),
			'channel_configured' => ! empty( $settings['channel_id'] ),
			'max_items'          => $settings['max_items'],
			'auto_draft'         => (bool) $settings['auto_draft'],
			'link_overrides'     => count( $settings['link_overrides'] ),
			'carrousels'         => count( $settings['custom_carrousels'] ),
			'registered_caches'  => count( $registry ),
		),
		'main_feed'     => array(
			'cache'           => syc_get_transient_diagnostic( $main_keys['cache'] ),
			'stale_items'     => is_array( get_option( $main_keys['stale'], null ) ) ? count( get_option( $main_keys['stale'], array() ) ) : null,
			'last_attempt_at' => syc_get_feed_meta_time( $main_meta, 'last_attempt_at' ),
			'last_success_at' => syc_get_feed_meta_time( $main_meta, 'last_success_at' ),
			'last_error_code' => isset( $main_meta['code'] ) ? sanitize_key( $main_meta['code'] ) : '',
		),
		'playlists'     => $playlists,
		'cron'          => array(
			'health_code'       => $cron_health['code'],
			'next_run_at'       => $cron_health['next_run_at'],
			'last_run_at'       => $cron_health['last_run_at'],
			'last_completed_at' => $cron_health['last_completed_at'],
			'disable_wp_cron'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'interval_seconds'  => SYC_CRON_REFRESH_INTERVAL,
		),
		'locks'         => $locks,
	);
}

/**
 * Download het diagnostische rapport als JSON.
 */
function syc_handle_download_diagnostics() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Je hebt geen rechten om diagnostiek te downloaden.', 'scientias-youtube-carrousel' ) );
	}
	check_admin_referer( 'syc_download_diagnostics_action', 'syc_download_diagnostics_nonce' );
	nocache_headers();
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="scientias-youtube-carrousel-diagnostics.json"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo wp_json_encode( syc_get_diagnostic_report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-download, geen HTML.
	exit;
}
add_action( 'admin_post_syc_download_diagnostics', 'syc_handle_download_diagnostics' );

/**
 * Render administratorgereedschap voor configuratieoverdracht.
 */
function syc_render_tools_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = syc_take_admin_notice();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'YouTube carrousel gereedschap', 'scientias-youtube-carrousel' ); ?></h1>
		<?php
		if ( ! empty( $notice ) ) :
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div><?php endif; ?>
		<h2><?php esc_html_e( 'Configuratie exporteren', 'scientias-youtube-carrousel' ); ?></h2>
		<p><?php esc_html_e( 'Download instellingen, carrousels en link overrides als JSON. De API-key en runtimegegevens worden nooit opgenomen.', 'scientias-youtube-carrousel' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="syc_export_config" /><?php wp_nonce_field( 'syc_export_config_action', 'syc_export_config_nonce' ); ?><?php submit_button( __( 'Configuratie downloaden', 'scientias-youtube-carrousel' ), 'secondary', 'submit', false ); ?></form>
		<hr />
		<h2><?php esc_html_e( 'Configuratie importeren', 'scientias-youtube-carrousel' ); ?></h2>
		<p><?php esc_html_e( 'Importeren vervangt alle portable instellingen maar behoudt altijd de huidige API-key. Bij één ongeldige rij wordt niets gewijzigd.', 'scientias-youtube-carrousel' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="syc_import_config" /><?php wp_nonce_field( 'syc_import_config_action', 'syc_import_config_nonce' ); ?><input type="file" name="syc_config_file" accept=".json,application/json" required /><?php submit_button( __( 'Configuratie importeren', 'scientias-youtube-carrousel' ), 'primary', 'submit', false ); ?></form>
		<hr />
		<h2><?php esc_html_e( 'Diagnostisch rapport', 'scientias-youtube-carrousel' ); ?></h2>
		<p><?php esc_html_e( 'Download technische statusinformatie voor ondersteuning. Het rapport bevat geen API-key, artikelgegevens, ruwe API-antwoorden, gebruikersgegevens, nonces of locktokens.', 'scientias-youtube-carrousel' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="syc_download_diagnostics" /><?php wp_nonce_field( 'syc_download_diagnostics_action', 'syc_download_diagnostics_nonce' ); ?><?php submit_button( __( 'Diagnostiek downloaden', 'scientias-youtube-carrousel' ), 'secondary', 'submit', false ); ?></form>
	</div>
	<?php
}

/**
 * Geef een stabiele wijzigingshash voor één carrousel.
 *
 * @param array $carrousel Genormaliseerde carrousel.
 * @return string
 */
function syc_get_custom_carrousel_hash( $carrousel ) {
	return hash( 'sha256', wp_json_encode( $carrousel ) );
}

/**
 * Verwerk opslaan of verwijderen van één extra carrousel.
 */
function syc_handle_custom_carrousel_action() {
	if ( ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		wp_die( esc_html__( 'Je hebt geen rechten om carrousels te beheren.', 'scientias-youtube-carrousel' ) );
	}
	check_admin_referer( 'syc_custom_carrousel_action', 'syc_custom_carrousel_nonce' );

	$operation     = isset( $_POST['syc_operation'] ) ? sanitize_key( wp_unslash( $_POST['syc_operation'] ) ) : '';
	$original_slug = isset( $_POST['syc_original_slug'] ) ? syc_sanitize_carrousel_slug( sanitize_text_field( wp_unslash( $_POST['syc_original_slug'] ) ) ) : '';
	$original_hash = isset( $_POST['syc_original_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['syc_original_hash'] ) ) : '';
	$complete      = isset( $_POST['syc_custom_carrousel_complete'] ) ? absint( $_POST['syc_custom_carrousel_complete'] ) : 0;
	// De geneste carrouselvelden worden hieronder per type door de bestaande sanitizer verwerkt.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$raw_settings = isset( $_POST['syc_settings'] ) && is_array( $_POST['syc_settings'] ) ? wp_unslash( $_POST['syc_settings'] ) : array();
	$field_key    = '' !== $original_slug ? $original_slug : 'new';
	$raw          = isset( $raw_settings['custom_carrousels'][ $field_key ] ) && is_array( $raw_settings['custom_carrousels'][ $field_key ] ) ? $raw_settings['custom_carrousels'][ $field_key ] : array();
	$removed_id   = '';
	$order        = isset( $_POST['syc_carrousel_order'] ) && is_array( $_POST['syc_carrousel_order'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['syc_carrousel_order'] ) ) : array();

	if ( 1 !== $complete ) {
		$result = new WP_Error( 'syc_carrousel_truncated', __( 'De carrousel is niet opgeslagen omdat het formulier onvolledig is ontvangen. Verhoog PHP max_input_vars en probeer opnieuw.', 'scientias-youtube-carrousel' ) );
	} elseif ( ! in_array( $operation, array( 'save', 'delete', 'reorder', 'duplicate' ), true ) ) {
		$result = new WP_Error( 'syc_invalid_carrousel_action', __( 'Ongeldige carrouselactie.', 'scientias-youtube-carrousel' ) );
	} elseif ( 'reorder' === $operation ) {
		$result = syc_update_settings_locked(
			function ( $settings ) use ( $order ) {
				$reordered = array();
				foreach ( array_unique( $order ) as $slug ) {
					if ( isset( $settings['custom_carrousels'][ $slug ] ) ) {
						$reordered[ $slug ] = $settings['custom_carrousels'][ $slug ];
					}
				}
				foreach ( $settings['custom_carrousels'] as $slug => $carrousel ) {
					if ( ! isset( $reordered[ $slug ] ) ) {
						$reordered[ $slug ] = $carrousel;
					}
				}
				$settings['custom_carrousels'] = $reordered;
				return $settings;
			}
		);
	} elseif ( 'duplicate' === $operation ) {
		$result = syc_update_settings_locked(
			function ( $settings ) use ( $original_slug, $original_hash ) {
				$carrousels = $settings['custom_carrousels'];
				if ( '' === $original_slug || ! isset( $carrousels[ $original_slug ] ) ) {
					return new WP_Error( 'syc_carrousel_missing', __( 'De te dupliceren carrousel bestaat niet meer.', 'scientias-youtube-carrousel' ) );
				}
				if ( '' === $original_hash || ! hash_equals( syc_get_custom_carrousel_hash( $carrousels[ $original_slug ] ), $original_hash ) ) {
					return new WP_Error( 'syc_carrousel_stale', __( 'Deze carrousel is ondertussen gewijzigd. Herlaad de pagina voordat je haar dupliceert.', 'scientias-youtube-carrousel' ) );
				}
				if ( count( $carrousels ) >= SYC_MAX_CUSTOM_CARROUSELS ) {
					return new WP_Error( 'syc_carrousel_count_limit', __( 'Het maximumaantal extra carrousels is bereikt.', 'scientias-youtube-carrousel' ) );
				}

				$base_slug = syc_sanitize_carrousel_slug( $original_slug . '-kopie' );
				$new_slug  = $base_slug;
				$suffix    = 2;
				while ( isset( $carrousels[ $new_slug ] ) ) {
					$new_slug = syc_sanitize_carrousel_slug( $base_slug . '-' . $suffix );
					++$suffix;
				}

				$copy         = $carrousels[ $original_slug ];
				$copy['name'] = sprintf(
					/* translators: %s: original carousel name. */
					__( '%s (kopie)', 'scientias-youtube-carrousel' ),
					$copy['name']
				);
				$copy['slug'] = $new_slug;
				$duplicated   = array();
				foreach ( $carrousels as $slug => $carrousel ) {
					$duplicated[ $slug ] = $carrousel;
					if ( $slug === $original_slug ) {
						$duplicated[ $new_slug ] = $copy;
					}
				}
				$settings['custom_carrousels'] = $duplicated;
				return $settings;
			}
		);
	} elseif ( 'delete' === $operation && '' === $original_slug ) {
		$result = new WP_Error( 'syc_missing_carrousel', __( 'Een nog niet opgeslagen carrousel kan niet worden verwijderd.', 'scientias-youtube-carrousel' ) );
	} elseif ( 'save' === $operation && syc_custom_carrousels_exceed_limits( array( $field_key => $raw ) ) ) {
		$result = new WP_Error( 'syc_carrousel_limit', __( 'De carrousel is niet opgeslagen omdat het maximumaantal handmatige video’s is overschreden.', 'scientias-youtube-carrousel' ) );
	} else {
		$sanitized = 'save' === $operation ? syc_sanitize_custom_carrousels( array( $field_key => $raw ), 1, SYC_MAX_CUSTOM_CARROUSEL_ITEMS ) : array();
		if ( 'save' === $operation && empty( $sanitized ) ) {
			$result = new WP_Error( 'syc_invalid_carrousel', __( 'Vul een geldige naam/shortcode en een playlist of ten minste één geldige YouTube-video in.', 'scientias-youtube-carrousel' ) );
		} else {
			$result = syc_update_settings_locked(
				function ( $settings ) use ( $operation, $original_slug, $original_hash, $sanitized, &$removed_id ) {
					$carrousels = $settings['custom_carrousels'];
					if ( '' !== $original_slug ) {
						if ( ! isset( $carrousels[ $original_slug ] ) ) {
							return new WP_Error( 'syc_carrousel_missing', __( 'Deze carrousel bestaat niet meer. Herlaad de pagina.', 'scientias-youtube-carrousel' ) );
						}
						if ( '' === $original_hash || ! hash_equals( syc_get_custom_carrousel_hash( $carrousels[ $original_slug ] ), $original_hash ) ) {
							return new WP_Error( 'syc_carrousel_stale', __( 'Deze carrousel is ondertussen gewijzigd. Herlaad de pagina om de nieuwste versie te bewerken.', 'scientias-youtube-carrousel' ) );
						}
						$removed_id = $carrousels[ $original_slug ]['playlist_id'];
					}

					if ( 'delete' === $operation ) {
						unset( $carrousels[ $original_slug ] );
						$settings['custom_carrousels'] = $carrousels;
						return $settings;
					}

					$new_slug      = key( $sanitized );
					$new_carrousel = current( $sanitized );
					if ( '' === $original_slug && count( $carrousels ) >= SYC_MAX_CUSTOM_CARROUSELS ) {
						return new WP_Error( 'syc_carrousel_count_limit', __( 'Het maximumaantal extra carrousels is bereikt.', 'scientias-youtube-carrousel' ) );
					}
					if ( isset( $carrousels[ $new_slug ] ) && $new_slug !== $original_slug ) {
						return new WP_Error( 'syc_carrousel_slug_exists', __( 'Deze shortcode-naam wordt al door een andere carrousel gebruikt.', 'scientias-youtube-carrousel' ) );
					}

					if ( '' === $original_slug ) {
						$carrousels[ $new_slug ] = $new_carrousel;
					} else {
						$replaced = array();
						foreach ( $carrousels as $slug => $carrousel ) {
							$replaced[ $slug === $original_slug ? $new_slug : $slug ] = $slug === $original_slug ? $new_carrousel : $carrousel;
						}
						$carrousels = $replaced;
					}
					$settings['custom_carrousels'] = $carrousels;
					return $settings;
				}
			);
		}
	}

	if ( is_wp_error( $result ) ) {
		$notice = array(
			'type'    => 'error',
			'message' => $result->get_error_message(),
		);
	} else {
		if ( '' !== $removed_id ) {
			syc_cleanup_playlist_caches( array( $removed_id ) );
		}
		syc_purge_page_caches();
		$notice = array(
			'type'    => 'success',
			'message' => 'delete' === $operation ? __( 'De carrousel is verwijderd.', 'scientias-youtube-carrousel' ) : ( 'reorder' === $operation ? __( 'De carrouselvolgorde is opgeslagen.', 'scientias-youtube-carrousel' ) : ( 'duplicate' === $operation ? __( 'De carrousel is gedupliceerd.', 'scientias-youtube-carrousel' ) : __( 'De carrousel is opgeslagen.', 'scientias-youtube-carrousel' ) ) ),
		);
	}

	syc_store_admin_notice( $notice );
	wp_safe_redirect( admin_url( 'admin.php?page=syc-custom-carrousels' ) );
	exit;
}
add_action( 'admin_post_syc_custom_carrousel_action', 'syc_handle_custom_carrousel_action' );

/**
 * Render velden voor een extra handmatige carrousel.
 *
 * @param string $field_key Veldsleutel.
 * @param array  $carrousel Carrouseldata.
 * @param bool   $is_new Nieuwe carrousel.
 */
function syc_render_custom_carrousel_fields( $field_key, $carrousel, $is_new = false ) {
	$name        = isset( $carrousel['name'] ) ? $carrousel['name'] : '';
	$slug        = isset( $carrousel['slug'] ) ? $carrousel['slug'] : '';
	$playlist_id = isset( $carrousel['playlist_id'] ) ? $carrousel['playlist_id'] : '';
	$items       = ! empty( $carrousel['items'] ) && is_array( $carrousel['items'] ) ? array_values( $carrousel['items'] ) : array();
	$rows        = $is_new ? SYC_CUSTOM_CARROUSEL_EMPTY_ROWS : count( $items ) + SYC_CUSTOM_CARROUSEL_EMPTY_ROWS;
	$rows        = max( SYC_CUSTOM_CARROUSEL_EMPTY_ROWS, $rows );
	?>
	<table class="form-table" role="presentation" style="max-width: 900px;">
		<tr>
			<th scope="row"><label for="syc_custom_<?php echo esc_attr( $field_key ); ?>_name"><?php esc_html_e( 'Naam', 'scientias-youtube-carrousel' ); ?></label></th>
			<td>
				<input type="text" id="syc_custom_<?php echo esc_attr( $field_key ); ?>_name" name="syc_settings[custom_carrousels][<?php echo esc_attr( $field_key ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="Ruimtevaart" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="syc_custom_<?php echo esc_attr( $field_key ); ?>_slug"><?php esc_html_e( 'Shortcode-naam', 'scientias-youtube-carrousel' ); ?></label></th>
			<td>
				<input type="text" id="syc_custom_<?php echo esc_attr( $field_key ); ?>_slug" name="syc_settings[custom_carrousels][<?php echo esc_attr( $field_key ); ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" placeholder="ruimtevaart" />
				<p class="description"><?php esc_html_e( 'Gebruik kleine letters, cijfers en streepjes. Voorbeeld shortcode: [scientias_youtube_carrousel name="ruimtevaart"]', 'scientias-youtube-carrousel' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="syc_custom_<?php echo esc_attr( $field_key ); ?>_playlist"><?php esc_html_e( 'YouTube playlist', 'scientias-youtube-carrousel' ); ?></label></th>
			<td>
				<input type="text" id="syc_custom_<?php echo esc_attr( $field_key ); ?>_playlist" name="syc_settings[custom_carrousels][<?php echo esc_attr( $field_key ); ?>][playlist_id]" value="<?php echo esc_attr( $playlist_id ); ?>" class="regular-text" placeholder="PL... of https://www.youtube.com/playlist?list=..." />
				<p class="description"><?php esc_html_e( 'Optioneel. Als dit is ingevuld, toont deze extra carrousel video’s uit de playlist via de opgeslagen YouTube API-key. De handmatige rijen hieronder blijven fallback als de playlist niet geladen kan worden.', 'scientias-youtube-carrousel' ); ?></p>
			</td>
		</tr>
	</table>

	<h4><?php esc_html_e( 'Handmatige video’s', 'scientias-youtube-carrousel' ); ?></h4>

	<table class="widefat striped" style="max-width: 1200px; margin-top: 1rem;">
		<thead>
			<tr>
				<th style="width:44px;"><span class="screen-reader-text"><?php esc_html_e( 'Volgorde', 'scientias-youtube-carrousel' ); ?></span></th>
				<th style="width: 26%;"><?php esc_html_e( 'Titel', 'scientias-youtube-carrousel' ); ?></th>
				<th style="width: 37%;"><?php esc_html_e( 'YouTube video-URL', 'scientias-youtube-carrousel' ); ?></th>
				<th><?php esc_html_e( 'Pagina-URL', 'scientias-youtube-carrousel' ); ?></th>
			</tr>
		</thead>
		<tbody class="syc-sortable-items">
			<?php for ( $index = 0; $index < $rows; $index++ ) : ?>
				<?php
				$item = isset( $items[ $index ] ) ? $items[ $index ] : array(
					'title'     => '',
					'video_url' => '',
					'link_url'  => '',
				);
				?>
			<tr>
				<td><button type="button" class="button-link syc-drag-handle" aria-label="<?php esc_attr_e( 'Sleep om deze video te verplaatsen', 'scientias-youtube-carrousel' ); ?>">☰</button></td>
					<td>
						<input type="text" name="syc_settings[custom_carrousels][<?php echo esc_attr( $field_key ); ?>][items][<?php echo esc_attr( $index ); ?>][title]" value="<?php echo esc_attr( $item['title'] ); ?>" class="large-text" />
					</td>
					<td>
						<input type="url" name="syc_settings[custom_carrousels][<?php echo esc_attr( $field_key ); ?>][items][<?php echo esc_attr( $index ); ?>][video_url]" value="<?php echo esc_url( $item['video_url'] ); ?>" class="large-text" placeholder="https://www.youtube.com/watch?v=..." />
					</td>
					<td>
						<input type="url" name="syc_settings[custom_carrousels][<?php echo esc_attr( $field_key ); ?>][items][<?php echo esc_attr( $index ); ?>][link_url]" value="<?php echo esc_url( $item['link_url'] ); ?>" class="large-text" placeholder="https://scientias.nl/..." />
					</td>
				</tr>
			<?php endfor; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Rijen zonder geldige YouTube-URL worden genegeerd. Gebruik bij een bestaande carrousel de afzonderlijke verwijderknop om deze volledig te verwijderen.', 'scientias-youtube-carrousel' ); ?></p>
	<?php
}

/**
 * Render de extra carrousels pagina.
 */
function syc_render_custom_carrousels_page() {
	if ( ! current_user_can( SYC_EDITORIAL_CAPABILITY ) ) {
		return;
	}

	$settings          = syc_get_settings();
	$custom_carrousels = ! empty( $settings['custom_carrousels'] ) && is_array( $settings['custom_carrousels'] ) ? $settings['custom_carrousels'] : array();
	$notice            = syc_take_admin_notice();
	// Alleen navigatie; deze waarde wijzigt geen gegevens.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$preview_slug = isset( $_GET['syc_preview'] ) ? syc_sanitize_carrousel_slug( sanitize_text_field( wp_unslash( $_GET['syc_preview'] ) ) ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra carrousels', 'scientias-youtube-carrousel' ); ?></h1>
		<?php
		if ( ! empty( $notice ) ) :
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div><?php endif; ?>
		<p><?php esc_html_e( 'Maak video-carrousels voor losse onderwerpen. Vul een YouTube-playlist in of beheer zelf losse video-URL’s en pagina-URL’s.', 'scientias-youtube-carrousel' ); ?></p>

		<?php if ( '' !== $preview_slug && isset( $custom_carrousels[ $preview_slug ] ) ) : ?>
			<section id="syc-preview" class="syc-carrousel-card__body" style="margin:1rem 0 2rem;background:#fff;border:1px solid #c3c4c7;">
				<h2><?php esc_html_e( 'Voorbeeld van opgeslagen carrousel', 'scientias-youtube-carrousel' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Dit voorbeeld gebruikt de laatst opgeslagen instellingen en bestaande feedcache.', 'scientias-youtube-carrousel' ); ?></p>
				<?php echo syc_render_carrousel_shortcode( array( 'name' => $preview_slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- De shortcoderenderer escaped alle dynamische uitvoer. ?>
			</section>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="syc_custom_carrousel_action" />
			<input type="hidden" name="syc_original_slug" value="" />
			<input type="hidden" name="syc_original_hash" value="" />
			<?php wp_nonce_field( 'syc_custom_carrousel_action', 'syc_custom_carrousel_nonce' ); ?>
			<h2><?php esc_html_e( 'Nieuwe carrousel', 'scientias-youtube-carrousel' ); ?></h2>
			<?php syc_render_custom_carrousel_fields( 'new', array(), true ); ?>
			<input type="hidden" name="syc_custom_carrousel_complete" value="1" />
			<p class="submit"><button type="submit" name="syc_operation" value="save" class="button button-primary"><?php esc_html_e( 'Nieuwe carrousel opslaan', 'scientias-youtube-carrousel' ); ?></button></p>
		</form>

		<?php if ( ! empty( $custom_carrousels ) ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Bestaande carrousels', 'scientias-youtube-carrousel' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="syc_custom_carrousel_action" />
				<input type="hidden" name="syc_operation" value="reorder" />
				<input type="hidden" name="syc_custom_carrousel_complete" value="1" />
				<?php wp_nonce_field( 'syc_custom_carrousel_action', 'syc_custom_carrousel_nonce' ); ?>
				<ol class="syc-sortable-carrousels">
					<?php foreach ( $custom_carrousels as $order_slug => $order_carrousel ) : ?>
						<li>
							<button type="button" class="button-link syc-drag-handle" aria-label="<?php esc_attr_e( 'Sleep om deze carrousel te verplaatsen', 'scientias-youtube-carrousel' ); ?>">☰</button>
							<strong><?php echo esc_html( $order_carrousel['name'] ); ?></strong>
							<code><?php echo esc_html( $order_slug ); ?></code>
							<input type="hidden" name="syc_carrousel_order[]" value="<?php echo esc_attr( $order_slug ); ?>" />
							<button type="button" class="button syc-move-up" aria-label="<?php esc_attr_e( 'Omhoog', 'scientias-youtube-carrousel' ); ?>">↑</button>
							<button type="button" class="button syc-move-down" aria-label="<?php esc_attr_e( 'Omlaag', 'scientias-youtube-carrousel' ); ?>">↓</button>
						</li>
					<?php endforeach; ?>
				</ol>
				<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Volgorde opslaan', 'scientias-youtube-carrousel' ); ?></button></p>
			</form>
			<?php foreach ( $custom_carrousels as $slug => $carrousel ) : ?>
				<?php $playlist_status = ! empty( $carrousel['playlist_id'] ) ? syc_get_playlist_dashboard_status( $carrousel['playlist_id'], $carrousel['items'] ) : null; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="syc_custom_carrousel_action" />
					<input type="hidden" name="syc_original_slug" value="<?php echo esc_attr( $slug ); ?>" />
					<input type="hidden" name="syc_original_hash" value="<?php echo esc_attr( syc_get_custom_carrousel_hash( $carrousel ) ); ?>" />
					<?php wp_nonce_field( 'syc_custom_carrousel_action', 'syc_custom_carrousel_nonce' ); ?>
					<details class="syc-carrousel-card">
						<summary>
							<span class="syc-carrousel-card__name"><?php echo esc_html( $carrousel['name'] ); ?></span>
							<code>[scientias_youtube_carrousel name="<?php echo esc_attr( $slug ); ?>"]</code>
							<span class="syc-carrousel-card__meta"><?php echo ! empty( $carrousel['playlist_id'] ) ? esc_html__( 'Playlist', 'scientias-youtube-carrousel' ) : esc_html__( 'Handmatig', 'scientias-youtube-carrousel' ); ?> · <?php echo esc_html( count( $carrousel['items'] ) ); ?> <?php esc_html_e( 'video’s', 'scientias-youtube-carrousel' ); ?></span>
							<?php echo wp_kses_post( $playlist_status ? syc_get_dashboard_badge( $playlist_status['status'], $playlist_status['status_type'] ) : syc_get_dashboard_badge( __( 'Alleen handmatig', 'scientias-youtube-carrousel' ), 'neutral' ) ); ?>
						</summary>
						<div class="syc-carrousel-card__body">
						<?php if ( $playlist_status ) : ?>
							<h3><?php esc_html_e( 'Status van opgeslagen playlist', 'scientias-youtube-carrousel' ); ?></h3>
							<p>
								<?php echo wp_kses_post( syc_get_dashboard_badge( $playlist_status['source'], $playlist_status['source_type'] ) ); ?>
								<?php
								printf(
									/* translators: 1: visible item count, 2: last success time, 3: last attempt time. */
									esc_html__( '%1$d zichtbare items · laatste succes: %2$s · laatste poging: %3$s', 'scientias-youtube-carrousel' ),
									(int) $playlist_status['items'],
									esc_html( syc_format_dashboard_time( $playlist_status['last_success_at'] ) ),
									esc_html( syc_format_dashboard_time( $playlist_status['last_attempt_at'] ) )
								);
								?>
							</p>
							<?php
							if ( '' !== $playlist_status['message'] ) :
								?>
								<div class="notice notice-error inline"><p><?php echo esc_html( $playlist_status['message'] ); ?></p></div><?php endif; ?>
						<?php else : ?>
							<p><?php esc_html_e( 'Deze carrousel gebruikt alleen handmatig beheerde video’s.', 'scientias-youtube-carrousel' ); ?></p>
						<?php endif; ?>
						<p>
							<?php esc_html_e( 'Shortcode:', 'scientias-youtube-carrousel' ); ?>
							<code>[scientias_youtube_carrousel name="<?php echo esc_attr( $slug ); ?>"]</code>
						</p>
						<?php syc_render_custom_carrousel_fields( $slug, $carrousel ); ?>
						<input type="hidden" name="syc_custom_carrousel_complete" value="1" />
						<p class="submit"><button type="submit" name="syc_operation" value="save" class="button button-primary"><?php esc_html_e( 'Deze carrousel opslaan', 'scientias-youtube-carrousel' ); ?></button></p>
						<button type="submit" name="syc_operation" value="duplicate" class="button button-secondary"><?php esc_html_e( 'Dupliceren', 'scientias-youtube-carrousel' ); ?></button>
						<button type="button" class="button button-secondary syc-copy-shortcode" data-shortcode="[scientias_youtube_carrousel name=&quot;<?php echo esc_attr( $slug ); ?>&quot;]"><?php esc_html_e( 'Shortcode kopiëren', 'scientias-youtube-carrousel' ); ?></button>
						<a class="button button-secondary" href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'        => 'syc-custom-carrousels',
									'syc_preview' => $slug,
								),
								admin_url( 'admin.php' )
							) . '#syc-preview'
						);
						?>
																	"><?php esc_html_e( 'Voorbeeld tonen', 'scientias-youtube-carrousel' ); ?></a>
						<button type="submit" name="syc_operation" value="delete" class="button button-link-delete" onclick="return window.confirm('<?php echo esc_js( __( 'Weet je zeker dat je deze carrousel wilt verwijderen?', 'scientias-youtube-carrousel' ) ); ?>');"><?php esc_html_e( 'Carrousel verwijderen', 'scientias-youtube-carrousel' ); ?></button>
						</div>
					</details>
				</form>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Bepaal de Shorts playlist ID op basis van een kanaal ID.
 *
 * Niet officieel gedocumenteerd, maar gangbare pattern:
 * UCxxxx -> UUSHxxxx voor Shorts playlist.
 *
 * @param string $channel_id Kanaal ID.
 * @return string|false
 */
function syc_get_shorts_playlist_id( $channel_id ) {
	$channel_id = trim( $channel_id );

	if ( '' === $channel_id ) {
		return false;
	}

	if ( 0 === strpos( $channel_id, 'UC' ) && strlen( $channel_id ) > 2 ) {
		return 'UUSH' . substr( $channel_id, 2 );
	}

	return false;
}

/**
 * Bouw een publieke YouTube thumbnail URL als fallback.
 *
 * @param string $video_id YouTube video ID.
 * @return string
 */
function syc_get_youtube_thumbnail_url( $video_id ) {
	$video_id = syc_sanitize_youtube_video_id( $video_id );

	if ( '' === $video_id ) {
		return '';
	}

	return 'https://i.ytimg.com/vi/' . rawurlencode( $video_id ) . '/hqdefault.jpg';
}

/**
 * Haal een YouTube video ID uit gangbare YouTube URL-formaten.
 *
 * @param string $url Video URL.
 * @return string
 */
function syc_extract_youtube_video_id( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url ) {
		return '';
	}

	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return '';
	}

	$host = strtolower( $parts['host'] );
	$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

	if ( ! in_array( $host, array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be' ), true ) ) {
		return '';
	}

	if ( 'youtu.be' === $host && '' !== $path ) {
		$segments = explode( '/', $path );
		return syc_sanitize_youtube_video_id( $segments[0] );
	}

	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
		if ( ! empty( $query['v'] ) ) {
			return syc_sanitize_youtube_video_id( $query['v'] );
		}
	}

	$segments = '' !== $path ? explode( '/', $path ) : array();
	$shorts   = array_search( 'shorts', $segments, true );
	if ( false !== $shorts && ! empty( $segments[ $shorts + 1 ] ) ) {
		return syc_sanitize_youtube_video_id( $segments[ $shorts + 1 ] );
	}

	$embed = array_search( 'embed', $segments, true );
	if ( false !== $embed && ! empty( $segments[ $embed + 1 ] ) ) {
		return syc_sanitize_youtube_video_id( $segments[ $embed + 1 ] );
	}

	return '';
}

/**
 * Lees de gecachete Shorts items.
 *
 * Doet zelf nooit een YouTube API-call: de cache wordt uitsluitend op de
 * achtergrond gevuld door {@see syc_refresh_all_feeds()} (WP-Cron of een
 * handmatige refresh in de admin). Zo blokkeert een bezoekersrequest nooit
 * op een externe API-aanroep.
 *
 * @return array|WP_Error
 */
function syc_get_api_shorts_items() {
	$settings = syc_get_settings();

	if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) {
		return new WP_Error( 'syc_missing_settings', __( 'YouTube API sleutel of kanaal ID ontbreekt.', 'scientias-youtube-carrousel' ) );
	}

	$keys   = syc_get_main_feed_storage_keys( $settings );
	$cached = get_transient( $keys['cache'] );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$stale = get_option( $keys['stale'], null );
	if ( is_array( $stale ) ) {
		return $stale;
	}

	return new WP_Error( 'syc_feed_cache_empty', __( 'De YouTube feed cache is nog niet gevuld; deze wordt periodiek op de achtergrond ververst.', 'scientias-youtube-carrousel' ) );
}

/**
 * Haal Shorts items op via de YouTube Data API en cache het resultaat.
 *
 * Wordt uitsluitend aangeroepen vanuit {@see syc_refresh_all_feeds()}
 * (WP-Cron of een handmatige refresh in de admin), nooit tijdens het laden
 * van een bezoekerspagina.
 *
 * @return array|WP_Error
 */
function syc_fetch_and_cache_api_shorts_items() {
	$settings = syc_get_settings();
	$keys     = syc_get_main_feed_storage_keys( $settings );

	if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) {
		$error = new WP_Error( 'syc_missing_settings', __( 'YouTube API sleutel of kanaal ID ontbreekt.', 'scientias-youtube-carrousel' ) );
		syc_update_main_feed_meta( 'error', null, $error, $keys['cache'] );
		return $error;
	}

	$playlist_id = syc_get_shorts_playlist_id( $settings['channel_id'] );
	if ( ! $playlist_id ) {
		$error = new WP_Error( 'syc_youtube_invalid_channel', __( 'Het kanaal-ID is ongeldig. Gebruik het YouTube-ID dat met UC begint.', 'scientias-youtube-carrousel' ) );
		syc_update_main_feed_meta( 'error', null, $error, $keys['cache'] );
		return $error;
	}

	$max_results = min( max( 1, absint( $settings['max_items'] ) ), 50 );

	$url = add_query_arg(
		array(
			'part'       => 'snippet',
			'playlistId' => $playlist_id,
			'maxResults' => $max_results,
			'key'        => $settings['api_key'],
		),
		'https://www.googleapis.com/youtube/v3/playlistItems'
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => SYC_API_REQUEST_TIMEOUT,
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		$error = syc_youtube_error_from_response( $response, 'main' );
		syc_update_main_feed_meta( 'error', null, $error, $keys['cache'] );
		return $error;
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
		$error = syc_youtube_invalid_response_error();
		syc_update_main_feed_meta( 'error', null, $error, $keys['cache'] );
		return $error;
	}

	$items = array();

	foreach ( $data['items'] as $item ) {
		if ( empty( $item['snippet']['resourceId']['videoId'] ) ) {
			continue;
		}

		$video_id = syc_sanitize_youtube_video_id( $item['snippet']['resourceId']['videoId'] );
		if ( '' === $video_id ) {
			continue;
		}

		$title = isset( $item['snippet']['title'] ) ? sanitize_text_field( wp_strip_all_tags( $item['snippet']['title'] ) ) : '';
		$thumb = '';

		if ( ! empty( $item['snippet']['thumbnails']['maxres']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['maxres']['url'];
		} elseif ( ! empty( $item['snippet']['thumbnails']['high']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['high']['url'];
		} elseif ( ! empty( $item['snippet']['thumbnails']['medium']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['medium']['url'];
		}

		$thumb = '' !== $thumb ? esc_url_raw( $thumb ) : syc_get_youtube_thumbnail_url( $video_id );

		$items[] = array(
			'video_id' => $video_id,
			'title'    => $title,
			'thumb'    => $thumb,
		);
	}

	$current_keys = syc_get_main_feed_storage_keys();
	if ( $keys['cache'] !== $current_keys['cache'] ) {
		return new WP_Error( 'syc_feed_source_changed', __( 'De feedinstellingen zijn tijdens het ophalen gewijzigd; het verouderde resultaat is genegeerd.', 'scientias-youtube-carrousel' ) );
	}

	// TTL langer dan het cron-interval, zie SYC_API_FEED_CACHE_TTL.
	set_transient( $keys['cache'], $items, SYC_API_FEED_CACHE_TTL );
	update_option( $keys['stale'], $items, false );

	syc_update_main_feed_meta( 'ok', count( $items ), null, $keys['cache'] );
	syc_update_editorial_source_snapshot( syc_get_editorial_main_source_key( $settings ), __( 'Hoofdfeed', 'scientias-youtube-carrousel' ), $items );

	syc_sync_feed_drafts( $items );

	return $items;
}

/**
 * Bepaalt de standaard categorieën voor automatisch aangemaakte concept-berichten:
 * zowel "Video" als de daaronder vallende "Shorts". Andere categorieën bepaalt de
 * redactie zelf, aan de hand van het onderwerp.
 *
 * @return int[] Term-ID's, leeg als geen van beide categorieën gevonden is.
 */
function syc_get_default_draft_category_ids() {
	static $cached_ids = null;
	if ( null !== $cached_ids ) {
		return $cached_ids;
	}

	$category_ids = array();

	$video_parent = get_term_by( 'name', 'Video', 'category' );
	if ( $video_parent && ! is_wp_error( $video_parent ) ) {
		$category_ids[] = (int) $video_parent->term_id;

		$shorts = get_terms(
			array(
				'taxonomy'   => 'category',
				'name'       => 'Shorts',
				'parent'     => $video_parent->term_id,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( ! empty( $shorts ) && ! is_wp_error( $shorts ) ) {
			$category_ids[] = (int) $shorts[0]->term_id;
		}
	}

	if ( empty( $category_ids ) ) {
		// Vangnet: een categorie "Shorts" ongeacht bovenliggende categorie.
		$shorts = get_term_by( 'name', 'Shorts', 'category' );
		if ( $shorts && ! is_wp_error( $shorts ) ) {
			$category_ids[] = (int) $shorts->term_id;
		}
	}

	$cached_ids = $category_ids;
	return $cached_ids;
}

/**
 * Bepaal het gebruikers-ID voor de standaard-auteur van automatisch aangemaakte
 * concept-berichten, op naam (SYC_DEFAULT_DRAFT_AUTHOR_NAME).
 *
 * @return int Gebruikers-ID, of 0 als er geen match gevonden is.
 */
function syc_get_default_draft_author_id() {
	static $cached_id = null;

	if ( null !== $cached_id ) {
		return $cached_id;
	}

	$user = get_user_by( 'login', sanitize_user( SYC_DEFAULT_DRAFT_AUTHOR_NAME, true ) );

	if ( ! $user ) {
		$users = get_users(
			array(
				'search'         => SYC_DEFAULT_DRAFT_AUTHOR_NAME,
				'search_columns' => array( 'display_name' ),
				'number'         => 1,
			)
		);
		$user  = ! empty( $users ) ? $users[0] : false;
	}

	$cached_id = $user ? (int) $user->ID : 0;

	return $cached_id;
}

/**
 * Maak één conceptbericht voor een feedvideo.
 *
 * De aanroeper moet de autodraftlock bezitten en vooraf controleren dat de
 * video nog niet is verwerkt.
 *
 * @param array  $item   Feeditem met video_id en title.
 * @param string $origin Herkomst van de koppeling.
 * @return int|WP_Error Post-ID of fout.
 */
function syc_create_video_draft( $item, $origin = 'auto_draft' ) {
	$video_id = isset( $item['video_id'] ) ? syc_sanitize_youtube_video_id( $item['video_id'] ) : '';
	if ( '' === $video_id ) {
		return new WP_Error( 'syc_invalid_video_id', __( 'Ongeldig YouTube video-ID.', 'scientias-youtube-carrousel' ) );
	}

	$video_url = 'https://www.youtube.com/watch?v=' . $video_id;
	$title     = isset( $item['title'] ) ? sanitize_text_field( wp_strip_all_tags( $item['title'] ) ) : '';
	if ( '' === $title ) {
		$title = $video_url;
	}

	$is_auto = 'auto_draft' === $origin;
	if ( $is_auto ) {
		$settings          = syc_get_settings();
		$default_text      = $settings['draft_default_text'];
		$post_status       = $settings['draft_post_status'];
		$default_author_id = $settings['draft_author_id'];
		$category_ids      = $settings['draft_category_ids'];
		$post_format       = $settings['draft_post_format'];
	} else {
		$default_text      = __( 'Dit concept is vanuit het redactionele video-overzicht aangemaakt. Vul de tekst aan en publiceer het bericht; de link onder de carrouselvideo wordt bij publicatie automatisch gekoppeld.', 'scientias-youtube-carrousel' );
		$post_status       = 'draft';
		$default_author_id = syc_get_default_draft_author_id();
		$category_ids      = syc_get_default_draft_category_ids();
		$post_format       = 'video';
	}

	$content = $video_url . ( '' !== $default_text ? "\n\n" . $default_text : '' );

	$post_args = array(
		'post_type'    => 'post',
		'post_status'  => $post_status,
		'post_title'   => $title,
		'post_content' => $content,
		'meta_input'   => array(
			'_syc_video_id'     => $video_id,
			'_syc_video_origin' => sanitize_key( $origin ),
		),
	);

	if ( $default_author_id > 0 ) {
		$post_args['post_author'] = $default_author_id;
	}

	if ( ! empty( $category_ids ) ) {
		$post_args['post_category'] = $category_ids;
	}

	$post_id = wp_insert_post( $post_args, true );
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'syc_draft_failed', __( 'Het concept kon niet worden aangemaakt.', 'scientias-youtube-carrousel' ) );
	}

	set_post_format( $post_id, 'standard' === $post_format ? false : $post_format );
	return (int) $post_id;
}

/**
 * Maak automatisch concept-berichten aan voor nieuwe shorts in de feed.
 *
 * Elke video-ID wordt maar één keer verwerkt: een concept dat de redactie
 * verwijdert, wordt bewust niet opnieuw aangemaakt.
 *
 * @param array $items Feed-items met video_id en title.
 */
function syc_sync_feed_drafts( $items ) {
	$settings = syc_get_settings();

	if ( empty( $settings['auto_draft'] ) || empty( $items ) || ! is_array( $items ) ) {
		return;
	}

	// Voorkom dubbele runs bij parallelle cron- of beheerrequests.
	$lock = syc_acquire_lock( SYC_AUTODRAFT_LOCK_KEY, MINUTE_IN_SECONDS );
	if ( false === $lock ) {
		return;
	}

	try {
		$map     = get_option( SYC_AUTODRAFT_MAP_OPTION, array() );
		$map     = is_array( $map ) ? $map : array();
		$index   = syc_get_editorial_video_index();
		$changed = false;

		foreach ( $items as $item ) {
			$video_id = isset( $item['video_id'] ) ? syc_sanitize_youtube_video_id( $item['video_id'] ) : '';
			if ( '' === $video_id || isset( $map[ $video_id ] ) || isset( $index['ignored'][ $video_id ] ) ) {
				continue;
			}

			// Vangnet voor het geval de verwerkingsadministratie ooit verloren gaat.
			$existing = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Eenmalige vangnetlookup van één post.
					'meta_key'       => '_syc_video_id',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Nodig bij de begrensde metaquery hierboven.
					'meta_value'     => $video_id,
				)
			);

			if ( ! empty( $existing ) ) {
				$map[ $video_id ] = (int) $existing[0];
				$changed          = true;
				continue;
			}

			$post_id = syc_create_video_draft( $item );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			$map[ $video_id ] = (int) $post_id;
			$changed          = true;
		}

		if ( $changed ) {
			update_option( SYC_AUTODRAFT_MAP_OPTION, $map, false );
		}
	} finally {
		syc_release_lock( SYC_AUTODRAFT_LOCK_KEY, $lock );
	}
}

/**
 * Vul de link-override zodra een automatisch aangemaakt concept wordt gepubliceerd.
 *
 * @param string  $new_status Nieuwe status.
 * @param string  $old_status Oude status.
 * @param WP_Post $post       Post object.
 */
function syc_maybe_set_link_override_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
		return;
	}

	$video_id = syc_sanitize_youtube_video_id( get_post_meta( $post->ID, '_syc_video_id', true ) );
	if ( '' === $video_id ) {
		return;
	}

	$permalink = get_permalink( $post );
	if ( ! $permalink ) {
		return;
	}

	$updated = syc_update_settings_locked(
		function ( $settings ) use ( $video_id, $permalink ) {
			// Een handmatig gezette override van de redactie blijft staan.
			if ( ! empty( $settings['link_overrides'][ $video_id ] ) ) {
				return $settings;
			}

			if ( count( $settings['link_overrides'] ) >= SYC_MAX_LINK_OVERRIDES ) {
				return new WP_Error( 'syc_link_limit', __( 'De automatische link kon niet worden opgeslagen omdat de override-limiet is bereikt.', 'scientias-youtube-carrousel' ) );
			}

			$settings['link_overrides'][ $video_id ] = esc_url_raw( $permalink );
			return $settings;
		}
	);

	if ( ! is_wp_error( $updated ) ) {
		syc_purge_page_caches();
	}
}
add_action( 'transition_post_status', 'syc_maybe_set_link_override_on_publish', 10, 3 );

/**
 * Registreer playlistcaches voor gerichte cleanup bij verwijderen.
 *
 * @param string $playlist_id YouTube playlist-ID.
 */
function syc_track_playlist_cache( $playlist_id ) {
	$playlist_id = syc_extract_youtube_playlist_id( $playlist_id );
	if ( '' === $playlist_id ) {
		return;
	}

	$registry                        = get_option( SYC_PLAYLIST_CACHE_REGISTRY_OPTION, array() );
	$registry                        = is_array( $registry ) ? $registry : array();
	$registry[ md5( $playlist_id ) ] = $playlist_id;
	update_option( SYC_PLAYLIST_CACHE_REGISTRY_OPTION, $registry, false );
}

/**
 * Lees de gecachete items van een YouTube playlist.
 *
 * Doet zelf nooit een YouTube API-call: de cache wordt uitsluitend op de
 * achtergrond gevuld door {@see syc_refresh_all_feeds()}.
 *
 * @param string $playlist_id YouTube playlist-ID.
 * @return array|WP_Error
 */
function syc_get_api_playlist_items( $playlist_id ) {
	$playlist_id = syc_extract_youtube_playlist_id( $playlist_id );

	if ( '' === $playlist_id ) {
		return new WP_Error( 'syc_invalid_playlist_id', __( 'Ongeldige YouTube playlist-ID.', 'scientias-youtube-carrousel' ) );
	}

	$cache_key = SYC_PLAYLIST_CACHE_PREFIX . md5( $playlist_id );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$stale = get_option( SYC_PLAYLIST_STALE_PREFIX . md5( $playlist_id ), null );
	if ( is_array( $stale ) ) {
		return $stale;
	}

	return new WP_Error( 'syc_playlist_cache_empty', __( 'De playlist-cache is nog niet gevuld; deze wordt periodiek op de achtergrond ververst.', 'scientias-youtube-carrousel' ) );
}

/**
 * Haal items op uit een YouTube playlist via de YouTube Data API en cache
 * het resultaat.
 *
 * Wordt uitsluitend aangeroepen vanuit {@see syc_refresh_all_feeds()}, nooit
 * tijdens het laden van een bezoekerspagina.
 *
 * @param string $playlist_id YouTube playlist-ID.
 * @return array|WP_Error
 */
function syc_fetch_and_cache_api_playlist_items( $playlist_id ) {
	$settings    = syc_get_settings();
	$playlist_id = syc_extract_youtube_playlist_id( $playlist_id );

	if ( '' === $playlist_id ) {
		return new WP_Error( 'syc_invalid_playlist_id', __( 'Ongeldige YouTube playlist-ID.', 'scientias-youtube-carrousel' ) );
	}

	if ( empty( $settings['api_key'] ) ) {
		$error = new WP_Error( 'syc_missing_api_key', __( 'YouTube API sleutel ontbreekt.', 'scientias-youtube-carrousel' ) );
		syc_update_playlist_feed_meta( $playlist_id, 'error', null, $error );
		return $error;
	}

	$cache_key   = SYC_PLAYLIST_CACHE_PREFIX . md5( $playlist_id );
	$max_results = min( max( 1, absint( $settings['max_items'] ) ), 50 );
	$url         = add_query_arg(
		array(
			'part'       => 'snippet',
			'playlistId' => $playlist_id,
			'maxResults' => $max_results,
			'key'        => $settings['api_key'],
		),
		'https://www.googleapis.com/youtube/v3/playlistItems'
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => SYC_API_REQUEST_TIMEOUT,
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		$error = syc_youtube_error_from_response( $response, 'playlist' );
		syc_update_playlist_feed_meta( $playlist_id, 'error', null, $error );
		return $error;
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
		$error = syc_youtube_invalid_response_error();
		syc_update_playlist_feed_meta( $playlist_id, 'error', null, $error );
		return $error;
	}

	$items = array();

	foreach ( $data['items'] as $item ) {
		if ( empty( $item['snippet']['resourceId']['videoId'] ) ) {
			continue;
		}

		$video_id = syc_sanitize_youtube_video_id( $item['snippet']['resourceId']['videoId'] );
		if ( '' === $video_id ) {
			continue;
		}

		$title = isset( $item['snippet']['title'] ) ? sanitize_text_field( wp_strip_all_tags( $item['snippet']['title'] ) ) : '';
		$thumb = '';

		if ( ! empty( $item['snippet']['thumbnails']['maxres']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['maxres']['url'];
		} elseif ( ! empty( $item['snippet']['thumbnails']['high']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['high']['url'];
		} elseif ( ! empty( $item['snippet']['thumbnails']['medium']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['medium']['url'];
		}

		$thumb = '' !== $thumb ? esc_url_raw( $thumb ) : syc_get_youtube_thumbnail_url( $video_id );

		$items[] = array(
			'video_id' => $video_id,
			'title'    => $title,
			'thumb'    => $thumb,
		);
	}

	set_transient( $cache_key, $items, SYC_API_FEED_CACHE_TTL );
	update_option( SYC_PLAYLIST_STALE_PREFIX . md5( $playlist_id ), $items, false );
	syc_update_playlist_feed_meta( $playlist_id, 'ok', count( $items ) );
	syc_track_playlist_cache( $playlist_id );
	syc_update_editorial_source_snapshot( syc_get_editorial_playlist_source_key( $playlist_id ), syc_get_editorial_playlist_label( $playlist_id, $settings ), $items );

	return $items;
}

/**
 * Kort een titel af voor carrouselkaarten.
 *
 * @param string $title Titel.
 * @return string
 */
function syc_get_display_title( $title ) {
	$title = (string) $title;

	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		return mb_strlen( $title ) > 50 ? mb_substr( $title, 0, 50 ) . '…' : $title;
	}

	return strlen( $title ) > 50 ? substr( $title, 0, 50 ) . '…' : $title;
}

/**
 * Render carrousel markup voor genormaliseerde items.
 *
 * @param array  $items Items met title, video_url, thumb_url en link_url.
 * @param string $title Sectietitel.
 * @return string
 */
function syc_render_carrousel_items( $items, $title ) {
	if ( empty( $items ) ) {
		return '';
	}

	wp_enqueue_style( 'syc-carrousel-style' );
	wp_enqueue_script( 'syc-carrousel-script' );

	ob_start();

	$unique_id = uniqid( 'syc_', false );
	?>
	<div class="syc-carrousel syc-video-section" id="<?php echo esc_attr( $unique_id ); ?>">
		<div class="syc-carrousel-header syc-section-header">
			<?php if ( ! empty( $title ) ) : ?>
				<h2 class="syc-carrousel-title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
			<div class="syc-header-nav">
				<button type="button" class="syc-nav syc-nav-prev" aria-label="<?php esc_attr_e( 'Vorige video', 'scientias-youtube-carrousel' ); ?>">&lsaquo;</button>
				<button type="button" class="syc-nav syc-nav-next" aria-label="<?php esc_attr_e( 'Volgende video', 'scientias-youtube-carrousel' ); ?>">&rsaquo;</button>
			</div>
		</div>

		<div class="syc-carrousel-wrapper">
			<div class="syc-items" role="list" tabindex="0" aria-label="<?php esc_attr_e( 'Video carrousel', 'scientias-youtube-carrousel' ); ?>">
				<?php foreach ( $items as $item ) : ?>
					<?php
					$title_value   = isset( $item['title'] ) ? $item['title'] : __( 'Video', 'scientias-youtube-carrousel' );
					$video_url     = isset( $item['video_url'] ) ? $item['video_url'] : '';
					$thumb_url     = isset( $item['thumb_url'] ) ? $item['thumb_url'] : '';
					$link_url      = isset( $item['link_url'] ) ? $item['link_url'] : '';
					$display_title = syc_get_display_title( $title_value );
					?>
					<div class="syc-item" role="listitem">
						<button
							type="button"
							class="syc-video-button"
							data-video-url="<?php echo esc_url( $video_url ); ?>"
							data-thumb-url="<?php echo esc_url( $thumb_url ? $thumb_url : '' ); ?>"
							data-title="<?php echo esc_attr( $title_value ); ?>"
							aria-label="<?php echo esc_attr( $title_value ); ?>"
						>
							<div class="syc-media" <?php echo $thumb_url ? 'style="background-image:url(' . esc_url( $thumb_url ) . ');"' : ''; ?>>
								<?php if ( $thumb_url ) : ?>
									<img class="syc-img" src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy" decoding="async" />
								<?php endif; ?>
								<span class="syc-play" aria-hidden="true"></span>
							</div>
						</button>
						<?php if ( ! empty( $link_url ) ) : ?>
							<a class="syc-item-title syc-item-link" href="<?php echo esc_url( $link_url ); ?>"><?php echo esc_html( $display_title ); ?></a>
						<?php else : ?>
							<div class="syc-item-title"><?php echo esc_html( $display_title ); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php

	return ob_get_clean();
}

/**
 * Shortcode output.
 *
 * Gebruik: [scientias_youtube_carrousel] of [scientias_youtube_carrousel name="ruimtevaart"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function syc_render_carrousel_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title' => '',
			'limit' => -1,
			'name'  => '',
		),
		$atts,
		'scientias_youtube_carrousel'
	);

	$items          = array();
	$settings       = syc_get_settings();
	$link_overrides = ! empty( $settings['link_overrides'] ) && is_array( $settings['link_overrides'] ) ? $settings['link_overrides'] : array();
	$name           = syc_sanitize_carrousel_slug( $atts['name'] );

	if ( '' !== $name ) {
		if ( empty( $settings['custom_carrousels'][ $name ] ) ) {
			return '';
		}

		$carrousel = $settings['custom_carrousels'][ $name ];

		if ( ! empty( $carrousel['playlist_id'] ) ) {
			$playlist_items = syc_get_api_playlist_items( $carrousel['playlist_id'] );
			if ( ! is_wp_error( $playlist_items ) && ! empty( $playlist_items ) ) {
				foreach ( $playlist_items as $item ) {
					$video_id  = $item['video_id'];
					$thumb_url = ! empty( $item['thumb'] ) ? $item['thumb'] : syc_get_youtube_thumbnail_url( $video_id );

					$items[] = array(
						'title'     => $item['title'],
						'video_url' => 'https://www.youtube.com/watch?v=' . $video_id,
						'thumb_url' => $thumb_url,
						'link_url'  => isset( $link_overrides[ $video_id ] ) ? $link_overrides[ $video_id ] : '',
					);
				}
			}
		}

		if ( empty( $items ) && ! empty( $carrousel['items'] ) ) {
			foreach ( $carrousel['items'] as $item ) {
				$video_id = syc_extract_youtube_video_id( $item['video_url'] );
				if ( '' === $video_id ) {
					continue;
				}

				$items[] = array(
					'title'     => $item['title'],
					'video_url' => $item['video_url'],
					'thumb_url' => syc_get_youtube_thumbnail_url( $video_id ),
					'link_url'  => $item['link_url'],
				);
			}
		}

		$default_title = ! empty( $carrousel['name'] ) ? $carrousel['name'] : __( 'Video', 'scientias-youtube-carrousel' );
		$title         = '' !== $atts['title'] ? $atts['title'] : $default_title;

		return syc_render_carrousel_items( $items, $title );
	}

	$api_items = syc_get_api_shorts_items();
	if ( ! is_wp_error( $api_items ) && ! empty( $api_items ) ) {
		foreach ( $api_items as $item ) {
			$video_id  = $item['video_id'];
			$thumb_url = ! empty( $item['thumb'] ) ? $item['thumb'] : syc_get_youtube_thumbnail_url( $video_id );

			$items[] = array(
				'title'     => $item['title'],
				'video_url' => 'https://www.youtube.com/watch?v=' . $video_id,
				'thumb_url' => $thumb_url,
				'link_url'  => isset( $link_overrides[ $video_id ] ) ? $link_overrides[ $video_id ] : '',
			);
		}
	} else {
		$items = syc_get_manual_fallback_items( intval( $atts['limit'] ) );
	}

	$title = '' !== $atts['title'] ? $atts['title'] : __( 'Video', 'scientias-youtube-carrousel' );

	return syc_render_carrousel_items( $items, $title );
}
add_shortcode( 'scientias_youtube_carrousel', 'syc_render_carrousel_shortcode' );
