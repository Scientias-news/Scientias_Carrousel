<?php
/**
 * Plugin Name: Scientias YouTube Carousel
 * Description: Voegt een shortcode toe voor een YouTube-video carrousel met titel, thumbnail en video-URL.
 * Version:     1.0.0
 * Author:      Scientias
 * Text Domain: scientias-youtube-carousel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register custom post type for carousel items.
 */
function syc_register_video_post_type() {
	$labels = array(
		'name'               => __( 'Video items', 'scientias-youtube-carousel' ),
		'singular_name'      => __( 'Video item', 'scientias-youtube-carousel' ),
		'add_new'            => __( 'Nieuw video-item', 'scientias-youtube-carousel' ),
		'add_new_item'       => __( 'Nieuw video-item toevoegen', 'scientias-youtube-carousel' ),
		'edit_item'          => __( 'Video-item bewerken', 'scientias-youtube-carousel' ),
		'new_item'           => __( 'Nieuw video-item', 'scientias-youtube-carousel' ),
		'all_items'          => __( 'Alle video-items', 'scientias-youtube-carousel' ),
		'view_item'          => __( 'Video-item bekijken', 'scientias-youtube-carousel' ),
		'search_items'       => __( 'Video-items zoeken', 'scientias-youtube-carousel' ),
		'not_found'          => __( 'Geen video-items gevonden', 'scientias-youtube-carousel' ),
		'not_found_in_trash' => __( 'Geen video-items in de prullenbak', 'scientias-youtube-carousel' ),
		'menu_name'          => __( 'YouTube carrousel', 'scientias-youtube-carousel' ),
	);

	$args = array(
		'labels'             => $labels,
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => true,
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
		__( 'YouTube video-URL', 'scientias-youtube-carousel' ),
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

	$value = get_post_meta( $post->ID, '_syc_video_url', true );

	echo '<p>' . esc_html__( 'Plak hier de volledige YouTube-URL (bijvoorbeeld een YouTube Short of reguliere video).', 'scientias-youtube-carousel' ) . '</p>';
	echo '<input type="url" style="width:100%;" id="syc_video_url" name="syc_video_url" value="' . esc_attr( $value ) . '" placeholder="https://www.youtube.com/watch?v=..." />';
}

/**
 * Sla de video-URL meta op.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function syc_save_video_url_meta( $post_id ) {
	if ( ! isset( $_POST['syc_video_url_nonce'] ) || ! wp_verify_nonce( $_POST['syc_video_url_nonce'], 'syc_save_video_url' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( isset( $_POST['post_type'] ) && 'syc_video' === $_POST['post_type'] ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
	}

	if ( isset( $_POST['syc_video_url'] ) ) {
		$url = esc_url_raw( wp_unslash( $_POST['syc_video_url'] ) );
		if ( ! empty( $url ) ) {
			update_post_meta( $post_id, '_syc_video_url', $url );
		} else {
			delete_post_meta( $post_id, '_syc_video_url' );
		}
	}
}
add_action( 'save_post', 'syc_save_video_url_meta' );

/**
 * Registreer en laad scripts en styles.
 */
function syc_register_assets() {
	$version = '1.0.0';

	wp_register_style(
		'syc-carousel-style',
		plugin_dir_url( __FILE__ ) . 'assets/css/syc-carousel.css',
		array(),
		$version
	);

	wp_register_script(
		'syc-carousel-script',
		plugin_dir_url( __FILE__ ) . 'assets/js/syc-carousel.js',
		array(),
		$version,
		true
	);
}
add_action( 'init', 'syc_register_assets' );

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
				'api_key'    => '',
				'channel_id' => '',
				'max_items'  => 8,
			),
		)
	);
}
add_action( 'admin_init', 'syc_register_settings' );

/**
 * Sanitize instellingen.
 *
 * @param array $input Raw settings.
 * @return array
 */
function syc_sanitize_settings( $input ) {
	$output = array();

	$output['api_key']    = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
	$output['channel_id'] = isset( $input['channel_id'] ) ? trim( sanitize_text_field( $input['channel_id'] ) ) : '';
	$output['max_items']  = isset( $input['max_items'] ) ? max( 1, absint( $input['max_items'] ) ) : 8;

	return $output;
}

/**
 * Voeg instellingenpagina toe onder het YouTube carrousel menu.
 */
function syc_add_settings_page() {
	add_submenu_page(
		'edit.php?post_type=syc_video',
		__( 'YouTube feed instellingen', 'scientias-youtube-carousel' ),
		__( 'Feed instellingen', 'scientias-youtube-carousel' ),
		'manage_options',
		'syc-settings',
		'syc_render_settings_page'
	);
}
add_action( 'admin_menu', 'syc_add_settings_page' );

/**
 * Haal huidige instellingen op.
 *
 * @return array
 */
function syc_get_settings() {
	$defaults  = array(
		'api_key'    => '',
		'channel_id' => '',
		'max_items'  => 8,
	);
	$settings  = get_option( 'syc_settings', array() );
	$sanitized = syc_sanitize_settings( wp_parse_args( $settings, $defaults ) );

	return wp_parse_args( $sanitized, $defaults );
}

/**
 * Render de instellingenpagina.
 */
function syc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['syc_manual_refresh'] ) && check_admin_referer( 'syc_manual_refresh_action', 'syc_manual_refresh_nonce' ) ) {
		delete_transient( 'syc_api_feed_cache' );
		$refreshed = true;
	}

	$settings = syc_get_settings();
	$status   = get_option( 'syc_api_feed_meta', array() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'YouTube feed instellingen', 'scientias-youtube-carousel' ); ?></h1>

		<?php if ( ! empty( $refreshed ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'De YouTube feed cache is geleegd. De volgende paginaweergave bouwt de feed opnieuw op.', 'scientias-youtube-carousel' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php
			settings_fields( 'syc_settings_group' );
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="syc_settings_api_key"><?php esc_html_e( 'YouTube API sleutel', 'scientias-youtube-carousel' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="syc_settings_api_key"
							name="syc_settings[api_key]"
							value="<?php echo esc_attr( $settings['api_key'] ); ?>"
							class="regular-text"
						/>
						<p class="description">
							<?php esc_html_e( 'Voer hier je YouTube Data API v3 key in.', 'scientias-youtube-carousel' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="syc_settings_channel_id"><?php esc_html_e( 'Kanaal ID', 'scientias-youtube-carousel' ); ?></label>
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
							<?php esc_html_e( 'Bijvoorbeeld: UC... (YouTube kanaal ID). Wordt gebruikt om de Shorts-playlist af te leiden.', 'scientias-youtube-carousel' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="syc_settings_max_items"><?php esc_html_e( 'Maximaal aantal items', 'scientias-youtube-carousel' ); ?></label>
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
							<?php esc_html_e( 'Hoeveel Shorts maximaal in de carrousel getoond worden (1–50).', 'scientias-youtube-carousel' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Feed cache', 'scientias-youtube-carousel' ); ?></h2>
		<p><?php esc_html_e( 'De feed wordt automatisch periodiek ververst. Je kunt de cache hier ook handmatig legen.', 'scientias-youtube-carousel' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'syc_manual_refresh_action', 'syc_manual_refresh_nonce' ); ?>
			<?php submit_button( __( 'Cache legen', 'scientias-youtube-carousel' ), 'secondary', 'syc_manual_refresh', false ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Feed status', 'scientias-youtube-carousel' ); ?></h2>
		<?php if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) : ?>
			<p><?php esc_html_e( 'Configureer eerst je API sleutel en kanaal ID om de status van de YouTube feed te kunnen bekijken.', 'scientias-youtube-carousel' ); ?></p>
		<?php else : ?>
			<?php if ( empty( $status ) ) : ?>
				<p><?php esc_html_e( 'Er is nog geen aanvraag naar de YouTube API gedaan. Bezoek een pagina met de carrousel of leeg de cache om een eerste fetch te forceren.', 'scientias-youtube-carousel' ); ?></p>
			<?php else : ?>
				<?php
				$timestamp = isset( $status['updated_at'] ) ? (int) $status['updated_at'] : 0;
				$when      = $timestamp ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : __( 'onbekend', 'scientias-youtube-carousel' );
				?>
				<table class="widefat striped" style="max-width: 600px;">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Laatste update', 'scientias-youtube-carousel' ); ?></th>
							<td><?php echo esc_html( $when ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'scientias-youtube-carousel' ); ?></th>
							<td>
								<?php
								if ( isset( $status['status'] ) && 'ok' === $status['status'] ) {
									printf(
										/* translators: %d: aantal items in feed. */
										esc_html__( 'OK – %d items ontvangen uit de YouTube Shorts feed.', 'scientias-youtube-carousel' ),
										isset( $status['items'] ) ? (int) $status['items'] : 0
									);
								} elseif ( isset( $status['status'] ) && 'error' === $status['status'] ) {
									echo esc_html__( 'Fout bij het ophalen van de feed:', 'scientias-youtube-carousel' ) . ' ';
									echo isset( $status['message'] ) ? esc_html( $status['message'] ) : esc_html__( 'Onbekende fout.', 'scientias-youtube-carousel' );
								} else {
									esc_html_e( 'Onbekende status.', 'scientias-youtube-carousel' );
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
 * Haal Shorts items op via de YouTube Data API.
 *
 * Resultaat wordt gecached in een transient.
 *
 * @return array|WP_Error
 */
function syc_get_api_shorts_items() {
	$settings = syc_get_settings();

	if ( empty( $settings['api_key'] ) || empty( $settings['channel_id'] ) ) {
		return new WP_Error( 'syc_missing_settings', __( 'YouTube API sleutel of kanaal ID ontbreekt.', 'scientias-youtube-carousel' ) );
	}

	$playlist_id = syc_get_shorts_playlist_id( $settings['channel_id'] );
	if ( ! $playlist_id ) {
		$error = new WP_Error( 'syc_invalid_channel_id', __( 'Ongeldig kanaal ID voor Shorts playlist.', 'scientias-youtube-carousel' ) );
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

	$cached = get_transient( 'syc_api_feed_cache' );
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
		$error = new WP_Error( 'syc_api_http_error', sprintf( __( 'YouTube API fout: HTTP %d', 'scientias-youtube-carousel' ), (int) $code ) );
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
		$error = new WP_Error( 'syc_api_empty', __( 'Geen items gevonden in de YouTube Shorts feed.', 'scientias-youtube-carousel' ) );
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

		$items[] = array(
			'video_id' => $video_id,
			'title'    => $title,
			'thumb'    => $thumb,
		);
	}

	if ( empty( $items ) ) {
		$error = new WP_Error( 'syc_api_empty_items', __( 'Er zijn geen geldige Shorts items gevonden.', 'scientias-youtube-carousel' ) );
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

	// Cache de items voor 30 minuten.
	set_transient( 'syc_api_feed_cache', $items, 30 * MINUTE_IN_SECONDS );

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
 * Gebruik: [scientias_youtube_carousel title="Our work in two minutes"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function syc_render_carousel_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title' => '',
			'limit' => -1,
		),
		$atts,
		'scientias_youtube_carousel'
	);

	wp_enqueue_style( 'syc-carousel-style' );
	wp_enqueue_script( 'syc-carousel-script' );

	$use_api = false;
	$items   = array();

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
	<div class="syc-carousel" id="<?php echo esc_attr( $unique_id ); ?>">
		<div class="syc-carousel-header">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h2 class="syc-carousel-title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<div class="syc-header-nav">
				<button type="button" class="syc-nav syc-nav-prev" aria-label="<?php esc_attr_e( 'Vorige video', 'scientias-youtube-carousel' ); ?>">&lsaquo;</button>
				<button type="button" class="syc-nav syc-nav-next" aria-label="<?php esc_attr_e( 'Volgende video', 'scientias-youtube-carousel' ); ?>">&rsaquo;</button>
			</div>
		</div>

		<div class="syc-carousel-wrapper">
			<div class="syc-items" role="list">
				<?php if ( $use_api ) : ?>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$video_id  = $item['video_id'];
						$title     = $item['title'];
						$thumb_url = $item['thumb'];
						$video_url = 'https://www.youtube.com/watch?v=' . $video_id;

						if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
							$display_title = mb_strlen( $title ) > 50 ? mb_substr( $title, 0, 50 ) . '…' : $title;
						} else {
							$display_title = strlen( $title ) > 50 ? substr( $title, 0, 50 ) . '…' : $title;
						}
						?>
						<button
							type="button"
							class="syc-item"
							data-video-url="<?php echo esc_url( $video_url ); ?>"
							data-thumb-url="<?php echo esc_url( $thumb_url ? $thumb_url : '' ); ?>"
							role="listitem"
							aria-label="<?php echo esc_attr( $title ); ?>"
						>
							<div class="syc-media" <?php echo $thumb_url ? 'style="background-image:url(' . esc_url( $thumb_url ) . ');"' : ''; ?>>
								<span class="syc-play" aria-hidden="true"></span>
							</div>
							<div class="syc-item-title"><?php echo esc_html( $display_title ); ?></div>
						</button>
					<?php endforeach; ?>
				<?php else : ?>
					<?php
					while ( $items->have_posts() ) :
						$items->the_post();

						$video_url = get_post_meta( get_the_ID(), '_syc_video_url', true );
						if ( empty( $video_url ) ) {
							continue;
						}

						$thumb_url = get_the_post_thumbnail_url( get_the_ID(), 'large' );

						$title        = get_the_title();
						if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
							$display_title = mb_strlen( $title ) > 50 ? mb_substr( $title, 0, 50 ) . '…' : $title;
						} else {
							$display_title = strlen( $title ) > 50 ? substr( $title, 0, 50 ) . '…' : $title;
						}
						?>
						<button
							type="button"
							class="syc-item"
							data-video-url="<?php echo esc_url( $video_url ); ?>"
							data-thumb-url="<?php echo esc_url( $thumb_url ? $thumb_url : '' ); ?>"
							role="listitem"
							aria-label="<?php echo esc_attr( $title ); ?>"
						>
							<div class="syc-media" <?php echo $thumb_url ? 'style="background-image:url(' . esc_url( $thumb_url ) . ');"' : ''; ?>>
								<span class="syc-play" aria-hidden="true"></span>
							</div>
							<div class="syc-item-title"><?php echo esc_html( $display_title ); ?></div>
						</button>
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
add_shortcode( 'scientias_youtube_carousel', 'syc_render_carousel_shortcode' );

