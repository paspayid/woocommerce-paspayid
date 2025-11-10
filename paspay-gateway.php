<?php
/*
Plugin Name: Paspay Payment Gateway
Plugin URI: https://paspay.id
Description: Payment Gateway kustom untuk integrasi Paspay API.
Version: 1.0.0
Author: ARIF ABDUL ROHIM
Author URI: https://paspay.id
*/

// Pastikan WooCommerce sudah aktif
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Tambahkan class Paspay Gateway ke WooCommerce
add_filter( 'woocommerce_payment_gateways', 'add_paspay_gateway_class' );
function add_paspay_gateway_class( $gateways ) {
    $gateways[] = 'WC_Paspay_Gateway';
    return $gateways;
}

// Inisialisasi Class Paspay Gateway
add_action( 'plugins_loaded', 'init_paspay_gateway_class' );
function init_paspay_gateway_class() {
    
    // --- Definisikan Class Gateway Utama ---
    require_once plugin_dir_path( __FILE__ ) . 'class-wc-paspay-gateway.php';

    // --- Definisikan Class Webhook Handler ---
    if ( ! class_exists( 'WC_Paspay_Webhook_Handler' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'class-wc-paspay-webhook-handler.php';
    }

    // --- Tambahkan Rute Webhook (Callback) ---
    add_action( 'woocommerce_api_paspay_webhook', 'paspay_process_webhook' );
}

/**
 * Fungsi untuk memproses Webhook
 */
function paspay_process_webhook() {
    $handler = new WC_Paspay_Webhook_Handler();
    $handler->process_webhook();
}
