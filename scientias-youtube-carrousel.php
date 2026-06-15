<?php
/**
 * Plugin Name: Scientias YouTube Carrousel
 * Description: Voegt een shortcode toe voor een YouTube-video carrousel met titel, thumbnail en video-URL.
 * Version:     1.0.7
 * Author:      Scientias
 * Text Domain: scientias-youtube-carrousel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SYC_VERSION', '1.0.7' );
define( 'SYC_API_FEED_CACHE_KEY', 'syc_api_feed_cache' );
define( 'SYC_API_FEED_CACHE_TTL', 5 * MINUTE_IN_SECONDS );

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
				'api_key'        => '',
				'channel_id'     => '',
				'max_items'      => 8,
				'link_overrides' => array(),
			),
		)
	);
}
add_action( 'admin_init', 'syc_register_settings' );

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

		$video_id = isset( $row['video_id'] ) ? syc_sanitize_youtube_video_id( $row['video_id'] ) : '';
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
	$video_id = trim( sanitize_text_field( wp_unslash( $video_id ) ) );

	if ( ! preg_match( '/^[A-Za-z0-9_-]{6,20}$/', $video_id ) ) {
		return '';
	}

	return $video_id;
}

/**
 * Normalize opgeslagen instellingen zonder save-side effects.
 *
 * @param array $settings Ruwe opgeslagen settings.
 * @return array
 */
function syc_normalize_settings( $settings ) {
	$defaults = array(
		'api_key'        => '',
		'channel_id'     => '',
		'max_items'      => 8,
		'link_overrides' => array(),
	);

	$settings = wp_parse_args( (array) $settings, $defaults );

	return array(
		'api_key'        => trim( sanitize_text_field( $settings['api_key'] ) ),
		'channel_id'     => trim( sanitize_text_field( $settings['channel_id'] ) ),
		'max_items'      => max( 1, absint( $settings['max_items'] ) ),
		'link_overrides' => syc_sanitize_link_overrides( $settings['link_overrides'] ),
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
	$output['link_overrides'] = isset( $input['link_overrides'] ) ? syc_sanitize_link_overrides( $input['link_overrides'] ) : array();

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

		<form method="post" action="options.php">
			<?php settings_fields( 'syc_settings_group' ); ?>
			<input type="hidden" name="syc_settings[api_key]" value="" />
			<input type="hidden" name="syc_settings[channel_id]" value="<?php echo esc_attr( $settings['channel_id'] ); ?>" />
			<input type="hidden" name="syc_settings[max_items]" value="<?php echo esc_attr( $settings['max_items'] ); ?>" />
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

	return $items;
}

/**
 * Shortcode output.
 *
 * Gebruik: [scientias_youtube_carrousel title="Our work in two minutes"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function syc_render_carrousel_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title' => __( 'Video', 'scientias-youtube-carrousel' ),
			'limit' => -1,
		),
		$atts,
		'scientias_youtube_carrousel'
	);

	wp_enqueue_style( 'syc-carrousel-style' );
	wp_enqueue_script( 'syc-carrousel-script' );

	$use_api        = false;
	$items          = array();
	$settings       = syc_get_settings();
	$link_overrides = ! empty( $settings['link_overrides'] ) && is_array( $settings['link_overrides'] ) ? $settings['link_overrides'] : array();

	$api_items = syc_get_api_shorts_items();
	if ( ! is_wp_error( $api_items ) && ! empty( $api_items ) ) {
		$use_api = true;
		$items   = $api_items;
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

		$items = $videos;
	}

	ob_start();

	$unique_id = uniqid( 'syc_', false );
	?>
	<div class="syc-carrousel syc-video-section" id="<?php echo esc_attr( $unique_id ); ?>">
		<div class="syc-carrousel-header syc-section-header">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h2 class="syc-carrousel-title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<div class="syc-header-nav">
				<button type="button" class="syc-nav syc-nav-prev" aria-label="<?php esc_attr_e( 'Vorige video', 'scientias-youtube-carrousel' ); ?>">&lsaquo;</button>
				<button type="button" class="syc-nav syc-nav-next" aria-label="<?php esc_attr_e( 'Volgende video', 'scientias-youtube-carrousel' ); ?>">&rsaquo;</button>
			</div>
		</div>

		<div class="syc-carrousel-wrapper">
			<div class="syc-items" role="list" tabindex="0" aria-label="<?php esc_attr_e( 'Video carrousel', 'scientias-youtube-carrousel' ); ?>">
				<?php if ( $use_api ) : ?>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$video_id  = $item['video_id'];
						$title     = $item['title'];
						$thumb_url = ! empty( $item['thumb'] ) ? $item['thumb'] : syc_get_youtube_thumbnail_url( $video_id );
						$video_url = 'https://www.youtube.com/watch?v=' . $video_id;
						$link_url  = isset( $link_overrides[ $video_id ] ) ? $link_overrides[ $video_id ] : '';

						if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
							$display_title = mb_strlen( $title ) > 50 ? mb_substr( $title, 0, 50 ) . '…' : $title;
						} else {
							$display_title = strlen( $title ) > 50 ? substr( $title, 0, 50 ) . '…' : $title;
						}
						?>
						<div class="syc-item" role="listitem">
							<button
								type="button"
								class="syc-video-button"
								data-video-url="<?php echo esc_url( $video_url ); ?>"
								data-thumb-url="<?php echo esc_url( $thumb_url ? $thumb_url : '' ); ?>"
								data-title="<?php echo esc_attr( $title ); ?>"
								aria-label="<?php echo esc_attr( $title ); ?>"
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
				<?php else : ?>
					<?php
					while ( $items->have_posts() ) :
						$items->the_post();

						$video_url = get_post_meta( get_the_ID(), '_syc_video_url', true );
						if ( empty( $video_url ) ) {
							continue;
						}

						$link_url = get_post_meta( get_the_ID(), '_syc_link_url', true );

						$thumb_url = get_the_post_thumbnail_url( get_the_ID(), 'large' );
						if ( ! $thumb_url ) {
							$video_id = syc_extract_youtube_video_id( $video_url );
							$thumb_url = syc_get_youtube_thumbnail_url( $video_id );
						}

						$title        = get_the_title();
						if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
							$display_title = mb_strlen( $title ) > 50 ? mb_substr( $title, 0, 50 ) . '…' : $title;
						} else {
							$display_title = strlen( $title ) > 50 ? substr( $title, 0, 50 ) . '…' : $title;
						}
						?>
						<div class="syc-item" role="listitem">
							<button
								type="button"
								class="syc-video-button"
								data-video-url="<?php echo esc_url( $video_url ); ?>"
								data-thumb-url="<?php echo esc_url( $thumb_url ? $thumb_url : '' ); ?>"
								data-title="<?php echo esc_attr( $title ); ?>"
								aria-label="<?php echo esc_attr( $title ); ?>"
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
						<?php
					endwhile;
					wp_reset_postdata();
					?>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php

	return ob_get_clean();
}
add_shortcode( 'scientias_youtube_carrousel', 'syc_render_carrousel_shortcode' );
