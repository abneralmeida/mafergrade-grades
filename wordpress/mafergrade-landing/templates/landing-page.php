<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$landing_file = MAFERGRADE_LANDING_DIR . 'landing.html';
$html         = file_get_contents( $landing_file );

if ( false === $html ) {
	status_header( 500 );
	exit( 'Não foi possível carregar a landing page.' );
}

$campaign_url = home_url( '/gradis-e-cercamentos/' );
$assets_url   = MAFERGRADE_LANDING_URL . 'assets/';
$lead_endpoint = add_query_arg( 'action', 'mafergrade_capture_lead', admin_url( 'admin-ajax.php' ) );
$base_tag     = '<base href="' . esc_url( MAFERGRADE_LANDING_URL ) . '">';
$campaign_anchor = 'href="' . esc_url( $campaign_url ) . '#';

$html = str_replace(
	array(
		'https://mafergrade.com.br/wp-content/plugins/mafergrade-landing/assets/',
		'__MAFERGRADE_LEAD_ENDPOINT__',
		'href="#',
		'<head>',
		'<body>',
	),
	array(
		esc_url( $assets_url ),
		esc_url( $lead_endpoint ),
		$campaign_anchor,
		'<head>' . $base_tag,
		'<body class="mafergrade-campaign-landing">',
	),
	$html
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted, bundled landing HTML with escaped dynamic URLs.
echo $html;
