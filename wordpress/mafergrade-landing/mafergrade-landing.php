<?php
/**
 * Plugin Name: Mafer Grade - Landing Page de Soluções em Aço
 * Description: Publica a landing page de soluções metálicas em uma página isolada do tema institucional.
 * Version: 2.0.5
 * Author: Grupo Mafer
 * License: GPL-2.0-or-later
 * Text Domain: mafergrade-landing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAFERGRADE_LANDING_VERSION', '2.0.5' );
define( 'MAFERGRADE_LANDING_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAFERGRADE_LANDING_URL', plugin_dir_url( __FILE__ ) );
define( 'MAFERGRADE_LANDING_SLUG', 'gradis-e-cercamentos' );
define( 'MAFERGRADE_LEADS_DB_VERSION', '1.0.0' );
define( 'MAFERGRADE_LEADS_WEBHOOK_URL', 'https://script.google.com/macros/s/AKfycbxt6zmmdfmiVsXuPH83IjtjFtedABd3oYV9mC44PGaDVVvS5bDTf6aLzqvhvqmlN2TD0w/exec' );

function mafergrade_leads_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'mafergrade_leads';
}

function mafergrade_cleanup_20260812_test_lead() {
	if ( '1' === get_option( 'mafergrade_cleanup_20260812_test_lead' ) ) {
		return;
	}

	global $wpdb;
	$wpdb->delete(
		mafergrade_leads_table_name(),
		array( 'submission_id' => 'CODEX-QA-20260812-7F3A' ),
		array( '%s' )
	);
	update_option( 'mafergrade_cleanup_20260812_test_lead', '1', false );
}
add_action( 'init', 'mafergrade_cleanup_20260812_test_lead', 1 );

/**
 * Keep a first-party copy of each lead before the visitor leaves for WhatsApp.
 */
function mafergrade_leads_maybe_install() {
	if ( MAFERGRADE_LEADS_DB_VERSION === get_option( 'mafergrade_leads_db_version' ) ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table_name      = mafergrade_leads_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id varchar(80) NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at datetime NOT NULL,
			synced_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY submission_id (submission_id),
			KEY status (status)
		) {$charset_collate};"
	);

	update_option( 'mafergrade_leads_db_version', MAFERGRADE_LEADS_DB_VERSION, false );
	if ( ! wp_next_scheduled( 'mafergrade_leads_retry_queue' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', 'mafergrade_leads_retry_queue' );
	}
}
add_action( 'plugins_loaded', 'mafergrade_leads_maybe_install', 5 );

/**
 * Reuse the campaign page when it already exists, or create it on activation.
 */
function mafergrade_landing_activate() {
	mafergrade_leads_maybe_install();
	$page = get_page_by_path( MAFERGRADE_LANDING_SLUG, OBJECT, 'page' );

	if ( $page instanceof WP_Post ) {
		update_option( 'mafergrade_landing_page_id', (int) $page->ID );
		flush_rewrite_rules();
		return;
	}

	$page_id = wp_insert_post(
		array(
			'post_title'   => 'Gradis, Cercamentos e Soluções Metálicas',
			'post_name'    => MAFERGRADE_LANDING_SLUG,
			'post_content' => 'Landing page comercial gerenciada pelo plugin Mafer Grade.',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		),
		true
	);

	if ( ! is_wp_error( $page_id ) ) {
		update_option( 'mafergrade_landing_page_id', (int) $page_id );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mafergrade_landing_activate' );

function mafergrade_landing_deactivate() {
	wp_clear_scheduled_hook( 'mafergrade_leads_retry_queue' );
	wp_clear_scheduled_hook( 'mafergrade_leads_sync_one' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mafergrade_landing_deactivate' );

/**
 * Keep the standalone template restricted to the campaign page.
 */
function mafergrade_landing_is_campaign_page() {
	$page_id = (int) get_option( 'mafergrade_landing_page_id', 0 );

	return ( $page_id && is_page( $page_id ) ) || is_page( MAFERGRADE_LANDING_SLUG );
}

function mafergrade_landing_template_include( $template ) {
	if ( mafergrade_landing_is_campaign_page() ) {
		return MAFERGRADE_LANDING_DIR . 'templates/landing-page.php';
	}

	return $template;
}
add_filter( 'template_include', 'mafergrade_landing_template_include', PHP_INT_MAX );

function mafergrade_leads_clean( $value, $max_length = 255, $textarea = false ) {
	$value = $textarea ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
}

function mafergrade_leads_ajax_capture() {
	$raw  = file_get_contents( 'php://input' );
	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) || ! empty( $data['website'] ) ) {
		wp_send_json_error( array( 'error' => 'invalid_request' ), 400 );
	}

	$allowed_forms = array( 'mafergrade_lead_gate', 'mafergrade_full_form', 'mafergrade_short_quote_v2' );
	$form_type     = mafergrade_leads_clean( $data['formulario'] ?? '', 40 );
	$page          = mafergrade_leads_clean( $data['pagina'] ?? '', 120 );
	$submission_id = mafergrade_leads_clean( $data['submission_id'] ?? '', 80 );
	$name          = mafergrade_leads_clean( $data['nome'] ?? '', 120 );
	$phone         = mafergrade_leads_clean( $data['whatsapp'] ?? '', 30 );
	$email         = sanitize_email( (string) ( $data['email'] ?? '' ) );
	$phone_digits  = preg_replace( '/\D+/', '', $phone );
	$email_required = 'mafergrade_short_quote_v2' !== $form_type;

	if (
		! in_array( $form_type, $allowed_forms, true ) ||
		! in_array( $page, array( '/mafergrade-grades/', '/gradis-e-cercamentos/' ), true ) ||
		! preg_match( '/^[A-Za-z0-9-]{16,80}$/', $submission_id ) ||
		'' === $name ||
		( $email_required && ! is_email( $email ) ) ||
		( '' !== $email && ! is_email( $email ) ) ||
		strlen( $phone_digits ) < 10 || strlen( $phone_digits ) > 13
	) {
		wp_send_json_error( array( 'error' => 'invalid_lead' ), 422 );
	}

	$remote_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$rate_key       = 'mafergrade_lead_rate_' . md5( wp_salt( 'nonce' ) . $remote_address );
	$rate_count     = (int) get_transient( $rate_key );
	if ( $rate_count >= 30 ) {
		wp_send_json_error( array( 'error' => 'rate_limited' ), 429 );
	}
	set_transient( $rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS );

	$payload = array(
		'website'      => '',
		'origem'       => 'Mafer Grade',
		'pagina'       => '/gradis-e-cercamentos/',
		'pagina_real'  => '/gradis-e-cercamentos/',
		'landing_url'  => esc_url_raw( (string) ( $data['landing_url'] ?? '' ) ),
		'gclid'        => mafergrade_leads_clean( $data['gclid'] ?? '', 255 ),
		'gbraid'       => mafergrade_leads_clean( $data['gbraid'] ?? '', 255 ),
		'wbraid'       => mafergrade_leads_clean( $data['wbraid'] ?? '', 255 ),
		'utm_source'   => mafergrade_leads_clean( $data['utm_source'] ?? '', 120 ),
		'utm_medium'   => mafergrade_leads_clean( $data['utm_medium'] ?? '', 120 ),
		'utm_campaign' => mafergrade_leads_clean( $data['utm_campaign'] ?? '', 180 ),
		'utm_id'       => mafergrade_leads_clean( $data['utm_id'] ?? '', 120 ),
		'utm_content'  => mafergrade_leads_clean( $data['utm_content'] ?? '', 180 ),
		'utm_term'     => mafergrade_leads_clean( $data['utm_term'] ?? '', 180 ),
		'utm_matchtype'=> mafergrade_leads_clean( $data['utm_matchtype'] ?? '', 30 ),
		'utm_device'   => mafergrade_leads_clean( $data['utm_device'] ?? '', 30 ),
		'utm_network'  => mafergrade_leads_clean( $data['utm_network'] ?? '', 30 ),
		'formulario'   => $form_type,
		'nome'         => $name,
		'whatsapp'     => $phone,
		'email'        => $email,
		'empresa'      => mafergrade_leads_clean( $data['empresa'] ?? '', 160 ),
		'produto'      => mafergrade_leads_clean( $data['produto'] ?? '', 180 ),
		'etapa'        => mafergrade_leads_clean( $data['etapa'] ?? '', 120 ),
		'uf'           => mafergrade_leads_clean( $data['uf'] ?? '', 2 ),
		'quantidade'   => mafergrade_leads_clean( $data['quantidade'] ?? '', 80 ),
		'detalhes'     => mafergrade_leads_clean( $data['detalhes'] ?? '', 2000, true ),
		'cta'          => mafergrade_leads_clean( $data['cta'] ?? '', 100 ),
		'submission_id'=> $submission_id,
	);

	global $wpdb;
	$table_name = mafergrade_leads_table_name();
	$existing = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, status FROM {$table_name} WHERE submission_id = %s", $submission_id )
	);
	if ( $existing ) {
		$existing_id = (int) $existing->id;
		if ( 'synced' !== $existing->status ) {
			$wpdb->update(
				$table_name,
				array( 'status' => 'pending', 'attempts' => 0, 'last_error' => '' ),
				array( 'id' => $existing_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			if ( ! wp_next_scheduled( 'mafergrade_leads_sync_one', array( $existing_id ) ) ) {
				wp_schedule_single_event( time(), 'mafergrade_leads_sync_one', array( $existing_id ) );
			}
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
		wp_send_json_success( array( 'saved' => true, 'submission_id' => $submission_id ) );
	}

	$inserted = $wpdb->insert(
		$table_name,
		array(
			'submission_id' => $submission_id,
			'payload'       => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'status'        => 'pending',
			'attempts'      => 0,
			'created_at'    => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%d', '%s' )
	);

	if ( false === $inserted ) {
		wp_send_json_error( array( 'error' => 'local_save_failed' ), 500 );
	}

	$lead_id = (int) $wpdb->insert_id;
	wp_schedule_single_event( time(), 'mafergrade_leads_sync_one', array( $lead_id ) );
	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}

	wp_send_json_success( array( 'saved' => true, 'submission_id' => $submission_id ) );
}
add_action( 'wp_ajax_nopriv_mafergrade_capture_lead', 'mafergrade_leads_ajax_capture' );
add_action( 'wp_ajax_mafergrade_capture_lead', 'mafergrade_leads_ajax_capture' );

function mafergrade_leads_sync_one( $lead_id ) {
	global $wpdb;
	$table_name = mafergrade_leads_table_name();
	$lead       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", (int) $lead_id ) );

	if ( ! $lead || 'synced' === $lead->status ) {
		return;
	}

	$attempts = (int) $lead->attempts + 1;
	$response = wp_remote_post(
		MAFERGRADE_LEADS_WEBHOOK_URL,
		array(
			'timeout'     => 20,
			'redirection' => 5,
			'headers'     => array( 'Content-Type' => 'text/plain; charset=utf-8' ),
			'body'        => $lead->payload,
		)
	);

	$response_code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$body              = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
	$explicit_rejection = is_array( $body ) && array_key_exists( 'ok', $body ) && false === $body['ok'];
	$synced            = ! is_wp_error( $response ) && $response_code >= 200 && $response_code < 300 && ! $explicit_rejection;

	if ( $synced ) {
		$wpdb->update(
			$table_name,
			array( 'status' => 'synced', 'attempts' => $attempts, 'last_error' => '', 'synced_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $lead_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		return;
	}

	$error     = is_wp_error( $response ) ? $response->get_error_message() : 'webhook_response_' . $response_code;
	$retryable = is_wp_error( $response ) || 0 === $response_code || 429 === $response_code || $response_code >= 500;
	$wpdb->update(
		$table_name,
		array( 'status' => ( $attempts >= 12 || ! $retryable ) ? 'failed' : 'retry', 'attempts' => $attempts, 'last_error' => mafergrade_leads_clean( $error, 1000 ) ),
		array( 'id' => (int) $lead_id ),
		array( '%s', '%d', '%s' ),
		array( '%d' )
	);

	if ( $attempts < 12 && $retryable ) {
		$delays = array( 60, 300, 900, 1800, 3600, 7200, 14400, 21600 );
		$delay  = $delays[ min( $attempts - 1, count( $delays ) - 1 ) ];
		wp_schedule_single_event( time() + $delay, 'mafergrade_leads_sync_one', array( (int) $lead_id ) );
	}
}
add_action( 'mafergrade_leads_sync_one', 'mafergrade_leads_sync_one', 10, 1 );

function mafergrade_leads_retry_queue() {
	global $wpdb;
	$table_name = mafergrade_leads_table_name();
	$lead_ids   = $wpdb->get_col( "SELECT id FROM {$table_name} WHERE status IN ('pending','retry') AND attempts < 12 ORDER BY id ASC LIMIT 20" );
	foreach ( $lead_ids as $lead_id ) {
		if ( ! wp_next_scheduled( 'mafergrade_leads_sync_one', array( (int) $lead_id ) ) ) {
			wp_schedule_single_event( time(), 'mafergrade_leads_sync_one', array( (int) $lead_id ) );
		}
	}
}
add_action( 'mafergrade_leads_retry_queue', 'mafergrade_leads_retry_queue' );
