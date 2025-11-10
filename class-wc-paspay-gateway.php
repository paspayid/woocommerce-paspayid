<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Paspay_Webhook_Handler {

    /**
     * Memproses Payload Webhook dari Paspay API
     */
    public function process_webhook() {
        // Ambil payload mentah
        $body = file_get_contents( 'php://input' );
        $data = json_decode( $body, true );

        // 1. Verifikasi Callback Token dari Header Authorization
        $settings = get_option( 'woocommerce_paspay_settings' );
        $expected_token = $settings['callback_token'] ?? ''; 

        $auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        $incoming_token = str_replace( 'Bearer ', '', $auth_header );
        
        // Cek jika token tidak diset atau tidak cocok
        if ( empty($expected_token) || $incoming_token !== $expected_token ) {
            status_header( 401 );
            echo 'Unauthorized: Invalid or missing token';
            exit;
        }
        
        if ( ! isset( $data['event'] ) || $data['event'] !== 'payment.success' || ! isset( $data['data'] ) ) {
            status_header( 400 );
            echo 'Invalid payload format or event type';
            exit;
        }

        $transaction_data = $data['data'];
        $paspay_ref_id = $transaction_data['reference_id'] ?? null;
        $total_amount_paid = $transaction_data['total_amount'] ?? 0;

        if ( ! $paspay_ref_id ) {
            status_header( 400 );
            echo 'Missing reference_id in transaction data';
            exit;
        }

        // 2. Cari Order di WooCommerce berdasarkan Paspay Reference ID
        $orders = wc_get_orders( array(
            'meta_key'   => '_paspay_reference_id',
            'meta_value' => $paspay_ref_id,
            'limit'      => 1,
            'status'     => array('pending', 'on-hold') // Cari yang statusnya masih menunggu
        ) );

        if ( empty( $orders ) ) {
            status_header( 404 );
            echo 'Order not found or already completed for ref_id: ' . $paspay_ref_id;
            exit;
        }
        
        $order = current( $orders );

        // 3. Validasi dan Proses Pembayaran
        if ( $order->is_paid() ) {
            status_header( 200 );
            echo 'Order already paid';
            exit;
        }

        $expected_total = $order->get_meta( '_paspay_total_amount' ) ?? 0;

        if ( intval($total_amount_paid) >= intval($expected_total) ) {
            // Pembayaran valid
            $order->payment_complete( $paspay_ref_id ); 
            $order->add_order_note( 
                'Pembayaran Paspay sukses diterima melalui webhook. Total dibayar: ' . wc_price( $total_amount_paid ) 
            );
            
            status_header( 200 );
            echo 'OK';

        } else {
            // Nominal tidak cocok
            $order->update_status( 'on-hold', 'Paspay Webhook: Nominal pembayaran (' . wc_price( $total_amount_paid ) . ') tidak sesuai dengan yang diharapkan (' . wc_price( $expected_total ) . '). Perlu pemeriksaan manual.' );
            
            status_header( 200 ); 
            echo 'Amount mismatch, status set to On Hold';
        }

        exit;
    }
}
