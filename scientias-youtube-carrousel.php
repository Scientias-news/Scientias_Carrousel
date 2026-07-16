<?php
/**
 * Plugin Name: Scientias YouTube Carrousel
 * Description: Voegt een shortcode toe voor een YouTube-video carrousel met titel, thumbnail en video-URL.
 * Version:     1.1.0
 * Author:      Scientias
 * Text Domain: scientias-youtube-carrousel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SYC_VERSION', '1.1.0' );
define( 'SYC_API_FEED_CACHE_KEY', 'syc_api_feed_cache' );
define( 'SYC_PLAYLIST_CACHE_PREFIX', 'syc_playlist_cache_' );
define( 'SYC_API_FEED_CACHE_TTL', 5 * MINUTE_IN_SECONDS );
define( 'SYC_AUTODRAFT_MAP_OPTION', 'syc_autodraft_map' );
define( 'SYC_AUTODRAFT_LOCK_KEY', 'syc_autodraft_lock' );

/**
 * Aantal lege invoerrijen op het extra carrousels-scherm.
 */
define( 'SYC_CUSTOM_CARROUSEL_EMPTY_ROWS', 5 );

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
		'labels'             => $labels,
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => false,
		'supports'           => array( 'title', 'thumbnail' ),
		'menu_icon'          => 'dashicons-video-alt3',
		'show_in_rest'       => false,
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
 * Leeg de interne feed-cache en probeer bekende page caches te purgen.
 */
function syc_clear_feed_caches() {
	delete_transient( SYC_API_FEED_CACHE_KEY );

	$settings = get_option( 'syc_settings', array() );
	if ( ! empty( $settings['custom_carrousels'] ) && is_array( $settings['custom_carrousels'] ) ) {
		foreach ( $settings['custom_carrousels'] as $carrousel ) {
			if ( empty( $carrousel['playlist_id'] ) ) {
				continue;
			}

			$playlist_id = syc_extract_youtube_playlist_id( $carrousel['playlist_id'] );
			if ( '' !== $playlist_id ) {
				delete_transient( SYC_PLAYLIST_CACHE_PREFIX . md5( $playlist_id ) );
			}
		}
	}

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

	syc_clear_feed_caches();
}
add_action( 'save_post', 'syc_maybe_clear_manual_video_cache', 20, 2 );

/**
 * Flush caches wanneer handmatige video-items verwijderd of verplaatst worden.
 *
 * @param int $post_id Post ID.
 */
function syc_maybe_clear_deleted_video_cache( $post_id ) {
	if ( 'syc_video' === get_post_type( $post_id ) ) {
		syc_clear_feed_caches();
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
	register_setting(
		'syc_settings_group',
		'syc_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'syc_sanitize_settings',
			'default'           => array(
				'api_key'           => '',
				'channel_id'        => '',
				'max_items'         => 8,
				'link_overrides'    => array(),
				'custom_carrousels' => array(),
			),
		)
	);
}
add_action( 'admin_init', 'syc_register_settings' );

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
 * @return array
 */
function syc_sanitize_custom_carrousels( $raw_carrousels ) {
	if ( ! is_array( $raw_carrousels ) ) {
		return array();
	}

	$carrousels = array();

	foreach ( $raw_carrousels as $raw_slug => $raw_carrousel ) {
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
 * @return array
 */
function syc_sanitize_link_overrides( $raw_overrides ) {
	if ( ! is_array( $raw_overrides ) ) {
		return array();
	}

	$overrides = array();

	foreach ( $raw_overrides as $raw_video_id => $row ) {
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
	$handle = fopen( $file_path, 'r' );
	if ( false === $handle ) {
		return new WP_Error( 'syc_csv_open_failed', __( 'Het CSV-bestand kon niet worden geopend.', 'scientias-youtube-carrousel' ) );
	}

	$overrides = array();
	$line      = 0;
	$skipped   = 0;
	$delimiter = ',';

	$first_line = fgets( $handle );
	if ( false !== $first_line ) {
		$delimiter = substr_count( $first_line, ';' ) > substr_count( $first_line, ',' ) ? ';' : ',';
		rewind( $handle );
	}

	while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
		$line++;

		if ( 1 === $line && isset( $row[0], $row[1] ) ) {
			$first_header  = strtolower( trim( (string) $row[0] ) );
			$second_header = strtolower( trim( (string) $row[1] ) );

			if ( in_array( $first_header, array( 'youtube_video_id', 'video_id', 'youtube-id', 'youtube id' ), true ) && in_array( $second_header, array( 'url', 'link', 'short', 'pagina' ), true ) ) {
				continue;
			}
		}

		if ( empty( $row ) || ( isset( $row[0] ) && '' === trim( (string) $row[0] ) ) ) {
			continue;
		}

		$video_id = isset( $row[0] ) ? syc_sanitize_youtube_video_id( $row[0] ) : '';
		$url      = isset( $row[1] ) ? esc_url_raw( trim( (string) $row[1] ) ) : '';

		if ( '' === $video_id || '' === $url ) {
			$skipped++;
			continue;
		}

		$overrides[ $video_id ] = $url;
	}

	fclose( $handle );

	if ( empty( $overrides ) ) {
		return new WP_Error( 'syc_csv_no_rows', __( 'Er zijn geen geldige video-ID/URL-koppelingen gevonden in het CSV-bestand.', 'scientias-youtube-carrousel' ) );
	}

	return array(
		'overrides' => $overrides,
		'imported'  => count( $overrides ),
		'skipped'   => $skipped,
	);
}

/**
 * Verwerk CSV-import voor link overrides.
 *
 * @param array $settings Huidige plugininstellingen.
 * @return array Importmeldingen.
 */
function syc_maybe_import_link_overrides_csv( $settings ) {
	if ( empty( $_POST['syc_csv_import_submit'] ) ) {
		return array();
	}

	if ( ! current_user_can( 'manage_options' ) ) {
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

	if ( empty( $_FILES['syc_link_overrides_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['syc_link_overrides_csv']['tmp_name'] ) ) {
		return array(
			'type'    => 'error',
			'message' => __( 'Kies eerst een CSV-bestand om te uploaden.', 'scientias-youtube-carrousel' ),
		);
	}

	$file_name = isset( $_FILES['syc_link_overrides_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['syc_link_overrides_csv']['name'] ) ) : '';
	$file_ext  = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
	if ( 'csv' !== $file_ext ) {
		return array(
			'type'    => 'error',
			'message' => __( 'Upload een bestand met de extensie .csv.', 'scientias-youtube-carrousel' ),
		);
	}

	$parsed = syc_parse_link_overrides_csv( $_FILES['syc_link_overrides_csv']['tmp_name'] );
	if ( is_wp_error( $parsed ) ) {
		return array(
			'type'    => 'error',
			'message' => $parsed->get_error_message(),
		);
	}

	$mode               = isset( $_POST['syc_csv_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['syc_csv_import_mode'] ) ) : 'merge';
	$current_overrides  = ! empty( $settings['link_overrides'] ) && is_array( $settings['link_overrides'] ) ? $settings['link_overrides'] : array();
	$imported_overrides = $parsed['overrides'];
	$new_overrides      = 'replace' === $mode ? $imported_overrides : array_merge( $current_overrides, $imported_overrides );

	$new_settings                   = $settings;
	$new_settings['link_overrides'] = syc_sanitize_link_overrides( $new_overrides );
	update_option( 'syc_settings', $new_settings );
	syc_clear_feed_caches();

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
 * Normalize opgeslagen instellingen zonder save-side effects.
 *
 * @param array $settings Ruwe opgeslagen settings.
 * @return array
 */
function syc_normalize_settings( $settings ) {
	$defaults = array(
		'api_key'           => '',
		'channel_id'        => '',
		'max_items'         => 8,
		'auto_draft'        => 0,
		'link_overrides'    => array(),
		'custom_carrousels' => array(),
	);

	$settings = wp_parse_args( (array) $settings, $defaults );

	return array(
		'api_key'           => trim( sanitize_text_field( $settings['api_key'] ) ),
		'channel_id'        => trim( sanitize_text_field( $settings['channel_id'] ) ),
		'max_items'         => max( 1, absint( $settings['max_items'] ) ),
		'auto_draft'        => ! empty( $settings['auto_draft'] ) ? 1 : 0,
		'link_overrides'    => syc_sanitize_link_overrides( $settings['link_overrides'] ),
		'custom_carrousels' => syc_sanitize_custom_carrousels( $settings['custom_carrousels'] ),
	);
}

/**
 * Sanitize instellingen.
 *
 * @param array $input Raw settings.
 * @return array
 */
function syc_sanitize_settings( $input ) {
	$old_settings = syc_normalize_settings( get_option( 'syc_settings', array() ) );
	$output = array();

	$new_api_key              = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
	$output['api_key']        = '' !== $new_api_key ? $new_api_key : $old_settings['api_key'];
	$output['channel_id']     = isset( $input['channel_id'] ) ? trim( sanitize_text_field( $input['channel_id'] ) ) : '';
	$output['max_items']      = isset( $input['max_items'] ) ? max( 1, absint( $input['max_items'] ) ) : 8;
	$output['auto_draft']     = isset( $input['auto_draft'] ) ? ( ! empty( $input['auto_draft'] ) ? 1 : 0 ) : $old_settings['auto_draft'];
	$output['link_overrides']    = isset( $input['link_overrides'] ) ? syc_sanitize_link_overrides( $input['link_overrides'] ) : $old_settings['link_overrides'];
	$output['custom_carrousels'] = isset( $input['custom_carrousels'] ) ? syc_sanitize_custom_carrousels( $input['custom_carrousels'] ) : $old_settings['custom_carrousels'];

	if ( $old_settings !== $output ) {
		syc_clear_feed_caches();
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
		'manage_options',
		'syc-settings',
		'syc_render_settings_page',
		'dashicons-video-alt3'
	);

	add_submenu_page(
		'syc-settings',
		__( 'YouTube feed instellingen', 'scientias-youtube-carrousel' ),
		__( 'Feed instellingen', 'scientias-youtube-carrousel' ),
		'manage_options',
		'syc-settings',
		'syc_render_settings_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'Link overrides', 'scientias-youtube-carrousel' ),
		__( 'Link overrides', 'scientias-youtube-carrousel' ),
		'manage_options',
		'syc-link-overrides',
		'syc_render_link_overrides_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'Extra carrousels', 'scientias-youtube-carrousel' ),
		__( 'Extra carrousels', 'scientias-youtube-carrousel' ),
		'manage_options',
		'syc-custom-carrousels',
		'syc_render_custom_carrousels_page'
	);

	add_submenu_page(
		'syc-settings',
		__( 'Losse video-items', 'scientias-youtube-carrousel' ),
		__( 'Losse video-items', 'scientias-youtube-carrousel' ),
		'edit_posts',
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
 * Render verborgen velden om extra carrousels te behouden op andere settings-formulieren.
 *
 * @param array $custom_carrousels Extra carrousels.
 */
function syc_render_hidden_custom_carrousels_fields( $custom_carrousels ) {
	if ( empty( $custom_carrousels ) || ! is_array( $custom_carrousels ) ) {
		return;
	}

	foreach ( $custom_carrousels as $slug => $carrousel ) :
		$items       = ! empty( $carrousel['items'] ) && is_array( $carrousel['items'] ) ? $carrousel['items'] : array();
		$playlist_id = isset( $carrousel['playlist_id'] ) ? $carrousel['playlist_id'] : '';
		?>
		<input type="hidden" name="syc_settings[custom_carrousels][<?php echo esc_attr( $slug ); ?>][name]" value="<?php echo esc_attr( $carrousel['name'] ); ?>" />
		<input type="hidden" name="syc_settings[custom_carrousels][<?php echo esc_attr( $slug ); ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" />
		<input type="hidden" name="syc_settings[custom_carrousels][<?php echo esc_attr( $slug ); ?>][playlist_id]" value="<?php echo esc_attr( $playlist_id ); ?>" />
		<?php foreach ( $items as $index => $item ) : ?>
			<input type="hidden" name="syc_settings[custom_carrousels][<?php echo esc_attr( $slug ); ?>][items][<?php echo esc_attr( $index ); ?>][title]" value="<?php echo esc_attr( $item['title'] ); ?>" />
			<input type="hidden" name="syc_settings[custom_carrousels][<?php echo esc_attr( $slug ); ?>][items][<?php echo esc_attr( $index ); ?>][video_url]" value="<?php echo esc_url( $item['video_url'] ); ?>" />
			<input type="hidden" name="syc_settings[custom_carrousels][<?php echo esc_attr( $slug ); ?>][items][<?php echo esc_attr( $index ); ?>][link_url]" value="<?php echo esc_url( $item['link_url'] ); ?>" />
		<?php endforeach; ?>
		<?php
	endforeach;
}

/**
 * Render de instellingenpagina.
 */
function syc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['syc_manual_refresh'] ) && check_admin_referer( 'syc_manual_refresh_action', 'syc_manual_refresh_nonce' ) ) {
		syc_clear_feed_caches();
		$refreshed = true;
	}

	$settings = syc_get_settings();
	$status   = get_option( 'syc_api_feed_meta', array() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'YouTube feed instellingen', 'scientias-youtube-carrousel' ); ?></h1>

		<?php if ( ! empty( $refreshed ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'De YouTube feed cache is geleegd. De volgende paginaweergave bouwt de feed opnieuw op.', 'scientias-youtube-carrousel' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php
			settings_fields( 'syc_settings_group' );
			?>
			<?php foreach ( $settings['link_overrides'] as $video_id => $url ) : ?>
				<input type="hidden" name="syc_settings[link_overrides][<?php echo esc_attr( $video_id ); ?>]" value="<?php echo esc_url( $url ); ?>" />
			<?php endforeach; ?>
			<?php syc_render_hidden_custom_carrousels_fields( $settings['custom_carrousels'] ); ?>
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
						<input type="hidden" name="syc_settings[auto_draft]" value="0" />
						<label for="syc_settings_auto_draft">
							<input
								type="checkbox"
								id="syc_settings_auto_draft"
								name="syc_settings[auto_draft]"
								value="1"
								<?php checked( ! empty( $settings['auto_draft'] ) ); ?>
							/>
							<?php esc_html_e( 'Maak automatisch een concept-bericht aan voor nieuwe shorts in de feed', 'scientias-youtube-carrousel' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Voor elke nieuwe short in de YouTube-feed wordt een concept-bericht aangemaakt met de videotitel en een embed. Zodra de redacteur het bericht publiceert, wordt de link-override automatisch gevuld met de artikel-URL. Verwijderde concepten worden niet opnieuw aangemaakt.', 'scientias-youtube-carrousel' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Feed cache', 'scientias-youtube-carrousel' ); ?></h2>
		<p><?php esc_html_e( 'De feed wordt automatisch periodiek ververst. Je kunt de cache hier ook handmatig legen.', 'scientias-youtube-carrousel' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'syc_manual_refresh_action', 'syc_manual_refresh_nonce' ); ?>
			<?php submit_button( __( 'Cache legen', 'scientias-youtube-carrousel' ), 'secondary', 'syc_manual_refresh', false ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Feed status', 'scientias-youtube-carrousel' ); ?></h2>
		<?php if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) : ?>
			<p><?php esc_html_e( 'Configureer eerst je API sleutel en kanaal ID om de status van de YouTube feed te kunnen bekijken.', 'scientias-youtube-carrousel' ); ?></p>
		<?php else : ?>
			<?php if ( empty( $status ) ) : ?>
				<p><?php esc_html_e( 'Er is nog geen aanvraag naar de YouTube API gedaan. Bezoek een pagina met de carrousel of leeg de cache om een eerste fetch te forceren.', 'scientias-youtube-carrousel' ); ?></p>
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
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings       = syc_get_settings();
	$import_notice  = syc_maybe_import_link_overrides_csv( $settings );
	$settings       = syc_get_settings();
	$link_overrides = ! empty( $settings['link_overrides'] ) && is_array( $settings['link_overrides'] ) ? $settings['link_overrides'] : array();
	$per_page       = 50;
	$total_items    = count( $link_overrides );
	$total_pages    = max( 1, (int) ceil( $total_items / $per_page ) );
	$current_page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$current_page   = min( $current_page, $total_pages );
	$offset         = ( $current_page - 1 ) * $per_page;
	$paged_overrides = array_slice( $link_overrides, $offset, $per_page, true );
	$base_url       = menu_page_url( 'syc-link-overrides', false );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Link overrides', 'scientias-youtube-carrousel' ); ?></h1>
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
			<?php settings_fields( 'syc_settings_group' ); ?>
			<input type="hidden" name="syc_settings[api_key]" value="" />
			<input type="hidden" name="syc_settings[channel_id]" value="<?php echo esc_attr( $settings['channel_id'] ); ?>" />
			<input type="hidden" name="syc_settings[max_items]" value="<?php echo esc_attr( $settings['max_items'] ); ?>" />
			<input type="hidden" name="syc_settings[auto_draft]" value="<?php echo esc_attr( ! empty( $settings['auto_draft'] ) ? 1 : 0 ); ?>" />
			<?php syc_render_hidden_custom_carrousels_fields( $settings['custom_carrousels'] ); ?>
			<?php foreach ( $link_overrides as $video_id => $url ) : ?>
				<input type="hidden" name="syc_settings[link_overrides][existing_<?php echo esc_attr( md5( $video_id ) ); ?>][video_id]" value="<?php echo esc_attr( $video_id ); ?>" />
				<input type="hidden" name="syc_settings[link_overrides][existing_<?php echo esc_attr( md5( $video_id ) ); ?>][url]" value="<?php echo esc_url( $url ); ?>" />
			<?php endforeach; ?>

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
					$total_items ? $offset + 1 : 0,
					min( $offset + $per_page, $total_items ),
					$total_items
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
				<?php settings_fields( 'syc_settings_group' ); ?>
				<input type="hidden" name="syc_settings[api_key]" value="" />
				<input type="hidden" name="syc_settings[channel_id]" value="<?php echo esc_attr( $settings['channel_id'] ); ?>" />
				<input type="hidden" name="syc_settings[max_items]" value="<?php echo esc_attr( $settings['max_items'] ); ?>" />
				<input type="hidden" name="syc_settings[auto_draft]" value="<?php echo esc_attr( ! empty( $settings['auto_draft'] ) ? 1 : 0 ); ?>" />
				<?php syc_render_hidden_custom_carrousels_fields( $settings['custom_carrousels'] ); ?>
				<?php foreach ( $link_overrides as $video_id => $url ) : ?>
					<?php if ( isset( $paged_overrides[ $video_id ] ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<input type="hidden" name="syc_settings[link_overrides][keep_<?php echo esc_attr( md5( $video_id ) ); ?>][video_id]" value="<?php echo esc_attr( $video_id ); ?>" />
					<input type="hidden" name="syc_settings[link_overrides][keep_<?php echo esc_attr( md5( $video_id ) ); ?>][url]" value="<?php echo esc_url( $url ); ?>" />
				<?php endforeach; ?>

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

		<?php $cached_feed_items = get_transient( SYC_API_FEED_CACHE_KEY ); ?>
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
				<th style="width: 26%;"><?php esc_html_e( 'Titel', 'scientias-youtube-carrousel' ); ?></th>
				<th style="width: 37%;"><?php esc_html_e( 'YouTube video-URL', 'scientias-youtube-carrousel' ); ?></th>
				<th><?php esc_html_e( 'Pagina-URL', 'scientias-youtube-carrousel' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php for ( $index = 0; $index < $rows; $index++ ) : ?>
				<?php $item = isset( $items[ $index ] ) ? $items[ $index ] : array( 'title' => '', 'video_url' => '', 'link_url' => '' ); ?>
				<tr>
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
	<p class="description"><?php esc_html_e( 'Rijen zonder geldige YouTube-URL worden genegeerd. Maak de playlist én alle video-URL’s leeg om deze carrousel te verwijderen.', 'scientias-youtube-carrousel' ); ?></p>
	<?php
}

/**
 * Render de extra carrousels pagina.
 */
function syc_render_custom_carrousels_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings          = syc_get_settings();
	$custom_carrousels = ! empty( $settings['custom_carrousels'] ) && is_array( $settings['custom_carrousels'] ) ? $settings['custom_carrousels'] : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra carrousels', 'scientias-youtube-carrousel' ); ?></h1>
		<p><?php esc_html_e( 'Maak video-carrousels voor losse onderwerpen. Vul een YouTube-playlist in of beheer zelf losse video-URL’s en pagina-URL’s.', 'scientias-youtube-carrousel' ); ?></p>

		<form method="post" action="options.php">
			<?php settings_fields( 'syc_settings_group' ); ?>
			<input type="hidden" name="syc_settings[api_key]" value="" />
			<input type="hidden" name="syc_settings[channel_id]" value="<?php echo esc_attr( $settings['channel_id'] ); ?>" />
			<input type="hidden" name="syc_settings[max_items]" value="<?php echo esc_attr( $settings['max_items'] ); ?>" />
			<input type="hidden" name="syc_settings[auto_draft]" value="<?php echo esc_attr( ! empty( $settings['auto_draft'] ) ? 1 : 0 ); ?>" />
			<?php foreach ( $settings['link_overrides'] as $video_id => $url ) : ?>
				<input type="hidden" name="syc_settings[link_overrides][<?php echo esc_attr( $video_id ); ?>]" value="<?php echo esc_url( $url ); ?>" />
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Nieuwe carrousel', 'scientias-youtube-carrousel' ); ?></h2>
			<?php syc_render_custom_carrousel_fields( 'new', array(), true ); ?>

			<?php if ( ! empty( $custom_carrousels ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Bestaande carrousels', 'scientias-youtube-carrousel' ); ?></h2>
				<?php foreach ( $custom_carrousels as $slug => $carrousel ) : ?>
					<div style="margin: 1.5rem 0 2rem; padding: 1rem; background: #fff; border: 1px solid #c3c4c7;">
						<h3 style="margin-top: 0;"><?php echo esc_html( $carrousel['name'] ); ?></h3>
						<p>
							<?php esc_html_e( 'Shortcode:', 'scientias-youtube-carrousel' ); ?>
							<code>[scientias_youtube_carrousel name="<?php echo esc_attr( $slug ); ?>"]</code>
						</p>
						<?php syc_render_custom_carrousel_fields( $slug, $carrousel ); ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php submit_button( __( 'Carrousels opslaan', 'scientias-youtube-carrousel' ) ); ?>
		</form>
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
	$shorts  = array_search( 'shorts', $segments, true );
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
 * Haal Shorts items op via de YouTube Data API.
 *
 * Resultaat wordt gecached in een transient.
 *
 * @return array|WP_Error
 */
function syc_get_api_shorts_items() {
	$settings = syc_get_settings();

	if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) {
		return new WP_Error( 'syc_missing_settings', __( 'YouTube API sleutel of kanaal ID ontbreekt.', 'scientias-youtube-carrousel' ) );
	}

	$playlist_id = syc_get_shorts_playlist_id( $settings['channel_id'] );
	if ( ! $playlist_id ) {
		$error = new WP_Error( 'syc_invalid_channel_id', __( 'Ongeldig kanaal ID voor Shorts playlist.', 'scientias-youtube-carrousel' ) );
		update_option(
			'syc_api_feed_meta',
			array(
				'status'     => 'error',
				'message'    => $error->get_error_message(),
				'code'       => $error->get_error_code(),
				'updated_at' => time(),
			)
		);
		return $error;
	}

	$cached = get_transient( SYC_API_FEED_CACHE_KEY );
	if ( is_array( $cached ) ) {
		return $cached;
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
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		update_option(
			'syc_api_feed_meta',
			array(
				'status'     => 'error',
				'message'    => $response->get_error_message(),
				'code'       => $response->get_error_code(),
				'updated_at' => time(),
			)
		);
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$error = new WP_Error( 'syc_api_http_error', sprintf( __( 'YouTube API fout: HTTP %d', 'scientias-youtube-carrousel' ), (int) $code ) );
		update_option(
			'syc_api_feed_meta',
			array(
				'status'     => 'error',
				'message'    => $error->get_error_message(),
				'code'       => $error->get_error_code(),
				'updated_at' => time(),
			)
		);
		return $error;
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! is_array( $data ) || empty( $data['items'] ) ) {
		$error = new WP_Error( 'syc_api_empty', __( 'Geen items gevonden in de YouTube Shorts feed.', 'scientias-youtube-carrousel' ) );
		update_option(
			'syc_api_feed_meta',
			array(
				'status'     => 'error',
				'message'    => $error->get_error_message(),
				'code'       => $error->get_error_code(),
				'updated_at' => time(),
			)
		);
		return $error;
	}

	$items = array();

	foreach ( $data['items'] as $item ) {
		if ( empty( $item['snippet']['resourceId']['videoId'] ) ) {
			continue;
		}

		$video_id = $item['snippet']['resourceId']['videoId'];
		$title    = isset( $item['snippet']['title'] ) ? $item['snippet']['title'] : '';
		$thumb    = '';

		if ( ! empty( $item['snippet']['thumbnails']['maxres']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['maxres']['url'];
		} elseif ( ! empty( $item['snippet']['thumbnails']['high']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['high']['url'];
		} elseif ( ! empty( $item['snippet']['thumbnails']['medium']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['medium']['url'];
		}

		if ( '' === $thumb ) {
			$thumb = syc_get_youtube_thumbnail_url( $video_id );
		}

		$items[] = array(
			'video_id' => $video_id,
			'title'    => $title,
			'thumb'    => $thumb,
		);
	}

	if ( empty( $items ) ) {
		$error = new WP_Error( 'syc_api_empty_items', __( 'Er zijn geen geldige Shorts items gevonden.', 'scientias-youtube-carrousel' ) );
		update_option(
			'syc_api_feed_meta',
			array(
				'status'     => 'error',
				'message'    => $error->get_error_message(),
				'code'       => $error->get_error_code(),
				'updated_at' => time(),
			)
		);
		return $error;
	}

	// Cache kort: YouTube-feeds kunnen na publicatie vertragen en page caches staan hier vaak nog voor.
	set_transient( SYC_API_FEED_CACHE_KEY, $items, SYC_API_FEED_CACHE_TTL );

	update_option(
		'syc_api_feed_meta',
		array(
			'status'     => 'ok',
			'items'      => count( $items ),
			'updated_at' => time(),
		)
	);

	syc_sync_feed_drafts( $items );

	return $items;
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

	// Voorkom dubbele runs bij parallelle front-end requests.
	if ( get_transient( SYC_AUTODRAFT_LOCK_KEY ) ) {
		return;
	}
	set_transient( SYC_AUTODRAFT_LOCK_KEY, 1, MINUTE_IN_SECONDS );

	$map = get_option( SYC_AUTODRAFT_MAP_OPTION, array() );
	$map = is_array( $map ) ? $map : array();

	$changed = false;

	foreach ( $items as $item ) {
		$video_id = isset( $item['video_id'] ) ? syc_sanitize_youtube_video_id( $item['video_id'] ) : '';
		if ( '' === $video_id || isset( $map[ $video_id ] ) ) {
			continue;
		}

		// Vangnet voor het geval de verwerkingsadministratie ooit verloren gaat.
		$existing = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_syc_video_id',
				'meta_value'     => $video_id,
			)
		);

		if ( ! empty( $existing ) ) {
			$map[ $video_id ] = (int) $existing[0];
			$changed          = true;
			continue;
		}

		$video_url = 'https://www.youtube.com/watch?v=' . $video_id;
		$title     = isset( $item['title'] ) ? wp_strip_all_tags( $item['title'] ) : '';
		if ( '' === $title ) {
			$title = $video_url;
		}

		$content  = $video_url . "\n\n";
		$content .= __( 'Dit concept is automatisch aangemaakt vanuit de YouTube-feed. Vul de tekst aan en publiceer het bericht; de link onder de carrouselvideo wordt bij publicatie automatisch gekoppeld.', 'scientias-youtube-carrousel' );

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
				'meta_input'   => array(
					'_syc_video_id' => $video_id,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}

		$map[ $video_id ] = (int) $post_id;
		$changed          = true;
	}

	if ( $changed ) {
		update_option( SYC_AUTODRAFT_MAP_OPTION, $map, false );
	}

	delete_transient( SYC_AUTODRAFT_LOCK_KEY );
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

	$settings = syc_normalize_settings( get_option( 'syc_settings', array() ) );

	// Een handmatig gezette override van de redactie blijft staan.
	if ( ! empty( $settings['link_overrides'][ $video_id ] ) ) {
		return;
	}

	$settings['link_overrides'][ $video_id ] = esc_url_raw( $permalink );

	update_option( 'syc_settings', $settings );

	syc_clear_feed_caches();
}
add_action( 'transition_post_status', 'syc_maybe_set_link_override_on_publish', 10, 3 );

/**
 * Haal items op uit een YouTube playlist via de YouTube Data API.
 *
 * @param string $playlist_id YouTube playlist-ID.
 * @return array|WP_Error
 */
function syc_get_api_playlist_items( $playlist_id ) {
	$settings    = syc_get_settings();
	$playlist_id = syc_extract_youtube_playlist_id( $playlist_id );

	if ( '' === $playlist_id ) {
		return new WP_Error( 'syc_invalid_playlist_id', __( 'Ongeldige YouTube playlist-ID.', 'scientias-youtube-carrousel' ) );
	}

	if ( empty( $settings['api_key'] ) ) {
		return new WP_Error( 'syc_missing_api_key', __( 'YouTube API sleutel ontbreekt.', 'scientias-youtube-carrousel' ) );
	}

	$cache_key = SYC_PLAYLIST_CACHE_PREFIX . md5( $playlist_id );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

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
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error( 'syc_playlist_api_http_error', sprintf( __( 'YouTube API fout: HTTP %d', 'scientias-youtube-carrousel' ), (int) $code ) );
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! is_array( $data ) || empty( $data['items'] ) ) {
		return new WP_Error( 'syc_playlist_api_empty', __( 'Geen items gevonden in de YouTube playlist.', 'scientias-youtube-carrousel' ) );
	}

	$items = array();

	foreach ( $data['items'] as $item ) {
		if ( empty( $item['snippet']['resourceId']['videoId'] ) ) {
			continue;
		}

		$video_id = $item['snippet']['resourceId']['videoId'];
		$title    = isset( $item['snippet']['title'] ) ? $item['snippet']['title'] : '';
		$thumb    = '';

		if ( ! empty( $item['snippet']['thumbnails']['maxres']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['maxres']['url'];
		} elseif ( ! empty( $item['snippet']['thumbnails']['high']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['high']['url'];
		} elseif ( ! empty( $item['snippet']['thumbnails']['medium']['url'] ) ) {
			$thumb = $item['snippet']['thumbnails']['medium']['url'];
		}

		if ( '' === $thumb ) {
			$thumb = syc_get_youtube_thumbnail_url( $video_id );
		}

		$items[] = array(
			'video_id' => $video_id,
			'title'    => $title,
			'thumb'    => $thumb,
		);
	}

	if ( empty( $items ) ) {
		return new WP_Error( 'syc_playlist_api_empty_items', __( 'Er zijn geen geldige playlist-items gevonden.', 'scientias-youtube-carrousel' ) );
	}

	set_transient( $cache_key, $items, SYC_API_FEED_CACHE_TTL );

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
			'title' => __( 'Video', 'scientias-youtube-carrousel' ),
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
						'link_url'  => '',
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
		$title         = __( 'Video', 'scientias-youtube-carrousel' ) === $atts['title'] ? $default_title : $atts['title'];

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
		// Vallen terug op handmatig beheerde items.
		$query_args = array(
			'post_type'      => 'syc_video',
			'post_status'    => 'publish',
			'posts_per_page' => intval( $atts['limit'] ),
			'orderby'        => 'date',
			'order'          => 'DESC', // Nieuw naar oud.
		);

		$videos = new WP_Query( $query_args );

		if ( ! $videos->have_posts() ) {
			return '';
		}

		while ( $videos->have_posts() ) {
			$videos->the_post();

			$video_url = get_post_meta( get_the_ID(), '_syc_video_url', true );
			if ( empty( $video_url ) ) {
				continue;
			}

			$link_url = get_post_meta( get_the_ID(), '_syc_link_url', true );

			$thumb_url = get_the_post_thumbnail_url( get_the_ID(), 'large' );
			if ( ! $thumb_url ) {
				$video_id  = syc_extract_youtube_video_id( $video_url );
				$thumb_url = syc_get_youtube_thumbnail_url( $video_id );
			}

			$items[] = array(
				'title'     => get_the_title(),
				'video_url' => $video_url,
				'thumb_url' => $thumb_url,
				'link_url'  => $link_url,
			);
		}

		wp_reset_postdata();
	}

	return syc_render_carrousel_items( $items, $atts['title'] );
}
add_shortcode( 'scientias_youtube_carrousel', 'syc_render_carrousel_shortcode' );
