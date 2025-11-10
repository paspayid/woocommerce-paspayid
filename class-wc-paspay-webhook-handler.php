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
        $settings = get_option( 'woocommerce_paspay_settings' ) ?? [];

        // 1. Verifikasi Token Keamanan (Callback Token)
        $expected_token = $settings['callback_token'] ?? '';
        $auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        $incoming_token = str_replace( 'Bearer ', '', $auth_header );
        
        // *******************************************************************
        // WAJIB AKTIFKAN VERIFIKASI INI SAAT GO LIVE UNTUK KEAMANAN
        // if (empty($expected_token) || $incoming_token !== $expected_token) {
        //     status_header( 401 );
        //     echo 'Unauthorized access';
        //     exit;
        // }
        // *******************************************************************
        
        if ( ! isset( $data['event'] ) || $data['event'] !== 'payment.success' || ! isset( $data['data'] ) ) {
            status_header( 400 );
            echo 'Invalid payload';
            exit;
        }

        $transaction_data = $data['data'];
        $paspay_ref_id = $transaction_data['reference_id'] ?? null;
        $total_amount_paid = $transaction_data['total_amount'] ?? 0;

        if ( ! $paspay_ref_id ) {
            status_header( 400 );
            echo 'Missing reference_id';
            exit;
        }

        // 2. Cari Order di WooCommerce berdasarkan Paspay Reference ID
        $orders = wc_get_orders( array(
            'meta_key'   => '_paspay_reference_id',
            'meta_value' => $paspay_ref_id,
            'limit'      => 1,
        ) );

        if ( empty( $orders ) ) {
            status_header( 404 );
            echo 'Order not found for reference_id: ' . $paspay_ref_id;
            exit;
        }
        
        $order = current( $orders );

        // 3. Validasi dan Proses Pembayaran
        if ( $order->is_paid() ) {
            // Sudah dibayar, kirim 200 OK
            status_header( 200 );
            echo 'Order already paid';
            exit;
        }

        // Ambil total yang diharapkan dari Order Metadata
        $expected_total = $order->get_meta( '_paspay_total_amount' ) ?? 0;

        if ( intval($total_amount_paid) >= intval($expected_total) ) {
            // Pembayaran valid
            $order->payment_complete( $paspay_ref_id ); // Set status ke Processing/Completed
            $order->add_order_note( 
                'Pembayaran Paspay sukses diterima melalui webhook. Total: ' . wc_price( $total_amount_paid ) 
            );
            
            status_header( 200 );
            echo 'OK';

        } else {
            // Nominal tidak cocok
            $order->update_status( 'on-hold', 'Paspay Webhook: Nominal pembayaran (' . wc_price( $total_amount_paid ) . ') tidak sesuai dengan yang diharapkan (' . wc_price( $expected_total ) . '). Perlu pemeriksaan manual.' );
            
            status_header( 200 ); // Tetap kembalikan 200 agar Paspay tidak coba kirim ulang
            echo 'Amount mismatch, status set to On Hold';
        }

        exit;
    }
}
