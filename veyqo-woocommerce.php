<?php
/**
 * Plugin Name:       Veyqo Payments for WooCommerce
 * Plugin URI:        https://veyqo.org/docs
 * Description:       Take payments through Paystack, Flutterwave, Stripe and more with Veyqo's routing, failover and one integration.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Veyqo
 * Author URI:        https://veyqo.org
 * License:           MIT
 * Text Domain:       veyqo-woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 */

defined( 'ABSPATH' ) || exit;

define( 'VEYQO_WC_VERSION', '0.1.0' );

// High-Performance Order Storage compatible.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-veyqo-api.php';
	require_once __DIR__ . '/includes/class-wc-gateway-veyqo.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_Gateway_Veyqo';
		return $gateways;
	} );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=veyqo' ) ) . '">' . esc_html__( 'Settings', 'veyqo-woocommerce' ) . '</a>' );
	return $links;
} );
