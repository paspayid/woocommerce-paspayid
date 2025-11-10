<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Paspay Payment Gateway
 */
class WC_Paspay_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'paspay';
        $this->icon               = ''; 
        $this->has_fields         = false;
        $this->method_title       = 'Paspay Payment Gateway';
        $this->method_description = 'Integrasi pembayaran Virtual Account, QRIS, dan lainnya melalui Paspay API.';

        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );
        
        // Simpan pengaturan dari halaman admin
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        
        // Tambahkan AJAX untuk Test Connection
        add_action( 'wp_ajax_paspay_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    /**
     * Definisi Field Pengaturan Admin
     */
    public function init_form_fields() {
        // Mendapatkan URL Webhook untuk info
        $webhook_url = get_site_url(null, 'wc-api/paspay_webhook');

        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Aktifkan/Nonaktifkan',
                'type'    => 'checkbox',
                'label'   => 'Aktifkan Paspay Payment Gateway',
                'default' => 'no',
            ),
            'title' => array(
                'title'   => 'Judul di Checkout',
                'type'    => 'text',
                'default' => 'Bayar via Paspay (VA/QRIS)',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Deskripsi',
                'type' => 'textarea',
                'default' => 'Anda akan diarahkan ke halaman pembayaran Paspay setelah membuat pesanan.',
            ),
            
            // --- Bagian Credential ---
            'credentials_title' => array(
                'title' => '🔑 Kredensial API',
                'type' => 'title',
                'description' => 'Masukkan kunci dan ID yang Anda dapatkan dari Dashboard Paspay.',
            ),
            'api_key' => array(
                'title'       => 'Paspay API Key (Bearer)',
                'type'        => 'text',
                'description' => 'API Key Rahasia yang digunakan untuk membuat transaksi (Header Authorization: Bearer).',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'project_id' => array(
                'title'       => 'Project ID',
                'type'        => 'text',
                'description' => 'ID Proyek Paspay Anda (Integer).',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'callback_token' => array(
                'title'       => 'Callback Token',
                'type'        => 'text',
                'description' => 'Token yang digunakan untuk memverifikasi Webhook Callback dari Paspay (Header Authorization: Bearer).',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'payment_channel_ids' => array(
                'title'       => 'Default Channel IDs',
                'type'        => 'text',
                'description' => 'Daftar ID Channel Pembayaran yang diaktifkan, dipisahkan koma (e.g., 3,4,5).',
                'default'     => '3,4',
                'desc_tip'    => true,
            ),

            // --- Bagian Test Connection & Webhook Info ---
            'test_info_title' => array(
                'title' => '🌐 Pengujian Koneksi & Webhook',
                'type' => 'title',
                'description' => 'Gunakan tombol di bawah untuk menguji apakah kredensial API Anda terhubung dengan benar.',
            ),
            'test_connection' => array(
                'title' => 'Uji Koneksi API',
                'type' => 'test_connection_button', // Custom field type
            ),
            'webhook_info' => array(
                'title'       => 'Webhook URL Anda',
                'type'        => 'title',
                'description' => 'URL ini harus Anda daftarkan di pengaturan proyek Paspay Anda untuk menerima notifikasi pembayaran: <br><code style="background: #e5e7eb; padding: 3px 6px; border-radius: 4px; font-weight: bold; color: #1f2937;">' . esc_url($webhook_url) . '</code>',
            ),
        );
    }
    
    /**
     * Render Custom Field Type: test_connection_button
     */
    public function generate_test_connection_button_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc">' . $data['title'] . '</th>';
        $html .= '<td class="forminp forminp-' . sanitize_title( $data['type'] ) . '">';
        
        // Kontainer untuk output hasil tes
        $html .= '<div id="paspay_test_result" style="margin-bottom: 10px; padding: 10px; border-radius: 5px; background: #374151; color: #d1d5db; display: none;"></div>';

        // Tombol Test Connection dengan styling modern
        $html .= '<button type="button" class="button-primary wc-paspay-test-btn" id="' . esc_attr( $field_key ) . '" style="background-color: #3b82f6; border-color: #2563eb; color: #ffffff; text-shadow: none; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: background-color 0.15s ease;">';
        $html .= 'Test Koneksi';
        $html .= '</button>';
        
        // Script AJAX
        $html .= '<script>
            jQuery(document).ready(function($) {
                $("#' . esc_attr( $field_key ) . '").on("click", function() {
                    var button = $(this);
                    var resultDiv = $("#paspay_test_result");
                    
                    var apiKey = $("#woocommerce_paspay_api_key").val();
                    var projectId = $("#woocommerce_paspay_project_id").val();
                    
                    // Style loading
                    button.text("Menguji...").prop("disabled", true).css("opacity", 0.7);
                    resultDiv.hide();

                    $.ajax({
                        url: ajaxurl,
                        method: "POST",
                        data: {
                            action: "paspay_test_connection",
                            api_key: apiKey,
                            project_id: projectId,
                            security: "' . wp_create_nonce( 'paspay-test-connection-nonce' ) . '"
                        },
                        success: function(response) {
                            var statusText, bgColor;
                            if (response.success) {
                                statusText = "✅ Koneksi Berhasil! ID User: " + response.user_id + " | Role: " + response.role;
                                bgColor = "#10b981"; // Emerald Green
                            } else {
                                statusText = "❌ Koneksi Gagal: " + (response.data || "Kesalahan tak dikenal.");
                                bgColor = "#ef4444"; // Red
                            }

                            resultDiv.html(statusText).css({
                                "display": "block",
                                "background-color": bgColor,
                                "color": "#ffffff",
                                "font-weight": "bold",
                            }).fadeIn();

                        },
                        error: function(xhr) {
                            var errorMsg = "❌ Gagal: Error Jaringan (" + xhr.status + "). Cek URL API Host.";
                            resultDiv.html(errorMsg).css({
                                "display": "block",
                                "background-color": "#f97316", // Orange
                                "color": "#ffffff",
                                "font-weight": "bold",
                            }).fadeIn();
                        },
                        complete: function() {
                            button.text("Test Koneksi").prop("disabled", false).css("opacity", 1);
                        }
                    });
                });
            });
        </script>';

        $html .= '</td></tr>';
        return $html;
    }
    
    /**
     * Handler AJAX untuk Test Connection
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'paspay-test-connection-nonce', 'security' );

        $api_key    = sanitize_text_field( $_POST['api_key'] );
        $project_id = sanitize_text_field( $_POST['project_id'] );
        
        // GANTI DENGAN URL API ASLI ANDA!
        $test_endpoint = 'https://payment-5a1.pages.dev/api/app/user_info'; 

        if ( empty( $api_key ) || empty( $project_id ) ) {
            wp_send_json_error( array( 'data' => 'API Key dan Project ID tidak boleh kosong.' ) );
            return;
        }

        $response = wp_remote_get( $test_endpoint, array(
            'headers'   => array(
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'timeout'   => 15,
        ));

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'data' => $response->get_error_message() ) );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $http_code === 200 && isset( $body['user_id'] ) ) {
            // Koneksi berhasil dan API Key valid
            if ( $body['project_id'] != $project_id ) {
                 wp_send_json_error( array( 'data' => 'API Key valid, namun Project ID yang terhubung di API (' . $body['project_id'] . ') tidak sesuai dengan yang Anda masukkan (' . $project_id . ').' ) );
                 return;
            }

            wp_send_json_success( array(
                'user_id' => $body['user_id'],
                'role' => $body['role'] ?? 'User',
            ) );
        } else {
            // Koneksi gagal (401, 500, atau respon tidak terduga)
            $error_data = $body['error'] ?? 'Gagal Terhubung. Cek API Key atau Project ID Anda.';
            wp_send_json_error( array( 'data' => $error_data ) );
        }
        
        wp_die();
    }
    
    // ... (FUNGSI process_payment dan thankyou_page TIDAK BERUBAH dari sebelumnya,
    // kecuali Anda ingin saya mengubahnya secara eksplisit) ...
    
    /**
     * Memproses Pembayaran (TETAP SAMA)
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        
        $api_key        = $this->get_option( 'api_key' );
        $project_id     = $this->get_option( 'project_id' );
        $channel_ids    = array_map('intval', explode(',', $this->get_option('payment_channel_ids')));
        $api_host       = 'https://payment-5a1.pages.dev'; // GANTI DENGAN API HOST ASLI ANDA!
        $api_endpoint   = $api_host . '/api/v1/transactions';

        if ( empty($api_key) || empty($project_id) || empty($channel_ids) ) {
             wc_add_notice( 'Paspay Gateway Error: API Key atau Project ID belum dikonfigurasi.', 'error' );
             return;
        }

        // 1. Siapkan data untuk dikirim ke API Paspay
        $payload = array(
            'project_id'         => intval($project_id),
            'payment_channel_id' => $channel_ids,
            'amount'             => intval($order->get_total()), // Harga dasar tanpa kode unik
            'internal_ref_id'    => $order->get_order_key(),
            'description'        => 'Pembayaran Order #' . $order_id,
            'customer_name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email'     => $order->get_billing_email(),
            'customer_phone'     => $order->get_billing_phone(),
        );

        // 2. Kirim Request ke Paspay API
        $response = wp_remote_post( $api_endpoint, array(
            'method'    => 'POST',
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'      => json_encode( $payload ),
            'timeout'   => 45,
        ));

        if ( is_wp_error( $response ) ) {
            wc_add_notice( 'Paspay API Error: ' . $response->get_error_message(), 'error' );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $http_code = wp_remote_retrieve_response_code( $response );

        if ( $http_code === 201 && isset( $body['success'] ) && $body['success'] === true ) {
            
            // 3. Simpan data transaksi (ref ID, total amount, unique code) ke Order Metadata
            $order->update_meta_data( '_paspay_reference_id', $body['reference_id'] );
            $order->update_meta_data( '_paspay_total_amount', $body['total_amount_expected'] );
            $order->update_meta_data( '_paspay_unique_code', $body['unique_code'] );
            $order->update_meta_data( '_paspay_payment_details', $body['payment_channels'] );
            $order->save();

            // 4. Ubah status Order menjadi "Pending Payment"
            $order->update_status( 'pending', 'Menunggu pembayaran dari Paspay API. Ref ID: ' . $body['reference_id'] );
            
            // 5. Redirect ke halaman 'Order Received'
            return array(
                'result'    => 'success',
                'redirect'  => $this->get_return_url( $order ),
            );

        } else {
            $error_message = $body['error'] ?? 'Terjadi kesalahan saat membuat transaksi di Paspay API.';
            wc_add_notice( 'Paspay API Error: ' . $error_message, 'error' );
            return;
        }
    }

    /**
     * Tambahkan detail pembayaran di halaman "Order Received" (DIBUAT LEBIH RAPI)
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $ref_id = $order->get_meta( '_paspay_reference_id' );
        $total_amount = $order->get_meta( '_paspay_total_amount' );
        $unique_code = $order->get_meta( '_paspay_unique_code' );
        $channels = $order->get_meta( '_paspay_payment_details' );

        if ( $order->get_status() == 'pending' && $ref_id && $channels ) {
            echo '&lt;div style="background: #e0f2f1; border-left: 5px solid #0d9488; padding: 15px; margin-bottom: 20px; border-radius: 5px; font-size: 1.1em;"&gt;';
            echo '&lt;p style="margin: 0;"&gt;Silakan selesaikan pembayaran sesuai detail di bawah. &lt;strong style="color: #0d9488;"&gt;Total yang harus dibayar adalah: ' . wc_price( $total_amount ) . '&lt;/strong&gt; (Sudah termasuk Kode Unik &lt;strong&gt;' . $unique_code . '&lt;/strong&gt;).&lt;/p&gt;';
            echo '&lt;/div&gt;';
            
            if ( $channels ) {
                echo '&lt;h3 style="border-bottom: 2px solid #ccc; padding-bottom: 5px; margin-top: 20px;"&gt;Pilih Metode Pembayaran&lt;/h3&gt;';
                
                foreach ( $channels as $channel ) {
                    // Menggunakan tag details (spoiler) untuk tampilan yang lebih rapi
                    echo '&lt;details style="border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; border-radius: 5px;"&gt;';
                    echo '&lt;summary style="font-weight: bold; cursor: pointer; color: #3b82f6;"&gt;';
                    echo 'Bayar via ' . esc_html( $channel['name'] ) . ' &lt;/summary&gt;';
                    echo '&lt;div style="padding: 10px 0; border-top: 1px dashed #eee; margin-top: 5px;"&gt;';
                    
                    if ( $channel['is_qris'] == 1 && $channel['payment_details']['qris_raw'] ) {
                        // Tampilkan QR Code (disarankan menggunakan gambar)
                        echo '&lt;p&gt;&lt;strong&gt;QRIS Payment:&lt;/strong&gt; Scan kode QR di bawah (atau gunakan string mentah untuk debugging):&lt;/p&gt;';
                        // Anda dapat mengintegrasikan library QR Code Generator di sini untuk menghasilkan gambar QR
                        echo '&lt;p style="background: #f1f5f9; padding: 10px; border-radius: 4px; word-break: break-all;"&gt;&lt;code&gt;' . esc_html( $channel['payment_details']['qris_raw'] ) . '&lt;/code&gt;&lt;/p&gt;';
                    } else if ( $channel['payment_details']['bank_data'] ) {
                        echo '&lt;p&gt;&lt;strong&gt;Detail Virtual Account / Bank Transfer:&lt;/strong&gt;&lt;/p&gt;';
                        echo '&lt;p style="white-space: pre-wrap; background: #f1f5f9; padding: 10px; border-radius: 4px; font-weight: bold;"&gt;' . nl2br( esc_html( $channel['payment_details']['bank_data'] ) ) . '&lt;/p&gt;';
                    }
                    echo '&lt;/div&gt;';
                    echo '&lt;/details&gt;';
                }
            }
        }
    }
}
