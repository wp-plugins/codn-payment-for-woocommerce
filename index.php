<?php

/*
  Plugin Name: CoDN Payment Gateway for Woocommerce
  Plugin URI: http://codnusantara.com
  Description: CoDN Payment Gateway
  Version: 1.0
  Author: CoDN Development Team
  Author URI: http://codnusantara.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; 

add_action('plugins_loaded', 'woocommerce_codn_init', 0);

function woocommerce_codn_init() {

    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_Gateway_codn extends WC_Payment_Gateway {

        public function __construct() {
            
            //plugin id
            $this->id = 'codn';
            //Payment Gateway title
            $this->method_title = 'CoDN Payment Gateway';
            //true only in case of direct payment method, false in our case
            $this->has_fields = false;
            //payment gateway logo
            $this->icon = plugins_url('/codn_badge.png', __FILE__);
            
            //redirect URL
            $this->redirect_url = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_codn', home_url( '/' ) ) );
            
            //Load settings
            $this->init_form_fields();
            $this->init_settings();
            
            // Define user set variables
            $this->enabled      = $this->settings['enabled'];
            $this->title        = "CoDN Payment";
            $this->description  = $this->settings['description'];
            $this->apikey       = $this->settings['apikey'];
            $this->secretkey    = $this->settings['secretkey'];
            $this->password     = $this->settings['password'];
            $this->processor_id = $this->settings['processor_id'];
            $this->salemethod   = $this->settings['salemethod'];
            $this->gatewayurl   = $this->settings['gatewayurl'];
            $this->order_prefix = $this->settings['order_prefix'];
            $this->debugon      = $this->settings['debugon'];
            $this->debugrecip   = $this->settings['debugrecip'];
            $this->cvv          = $this->settings['cvv'];
            
            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            add_action('woocommerce_receipt_codn', array(&$this, 'receipt_page'));
            
            // Payment listener/API hook
            add_action( 'woocommerce_api_wc_gateway_codn', array( $this, 'check_codn_response' ) );
        }

        function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                                'title' => __( 'Enable/Disable', 'woothemes' ), 
                                'label' => __( 'Enable CoDN', 'woothemes' ), 
                                'type' => 'checkbox', 
                                'description' => '', 
                                'default' => 'no'
                            ), 
                'title' => array(
                                'title' => __( 'Title', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( '', 'woothemes' ), 
                                'default' => __( 'Pembayaran CoDN', 'woothemes' )
                            ), 
                'description' => array(
                                'title' => __( 'Description', 'woothemes' ), 
                                'type' => 'textarea', 
                                'description' => __( '', 'woothemes' ), 
                                'default' => 'Sistem pembayaran menggunakan CoDN.'
                            ),  
                'publickey' => array(
                                'title' => __( 'Public Key', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( '', 'woothemes' ), 
                                'default' => ''
                            ),
                'privatekey' => array(
                                'title' => __( 'Private Key', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( '', 'woothemes' ), 
                                'default' => ''
                            ),
                'returnurl' => array(
                                'title' => __( 'Return Url', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( '', 'woothemes' ), 
                                'default' => esc_url(home_url('/'))
                            ),
                'notifyurl' => array(
                                'title' => __( 'Notify Url', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( '', 'woothemes' ), 
                                'default' => esc_url(home_url('/'))
                            ),
                /*'debugrecip' => array(
                                'title' => __( 'Debugging Email', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( 'Who should receive the debugging emails.', 'woothemes' ), 
                                'default' =>  get_option('admin_email')
                            ),*/
            );
        }

        public function admin_options() {
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        function payment_fields() {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }

        
        function receipt_page($order) {
            echo $this->generate_codn_form($order);
        }

        
        public function generate_codn_form($order_id) {

            global $woocommerce;
            
            $order = new WC_Order($order_id);
            $items = $order->get_items();
            $ordermeta = get_post_meta($order_id);

            // Prepare Parameters
            $chart = array();
            foreach ( $items as $item ) {
                $metas = get_post_meta($item['product_id']);
                $weight = $metas['_weight'][0];
                $qty = $item['qty'];
                $total = $item['line_total']/$qty;
                $chart[] = array($item['name'],$total,$qty,1,1,1,$qty);//array(nama,harga, quantity, panjang, lebar, tinggi, berat) 
            }
            
            $params = array(
                'public_key'                => $this->settings['publickey'],                                   
                'buyer_name'                => $ordermeta['_billing_first_name'][0].' '.$ordermeta['_billing_last_name'][0] ,                             
                'buyer_email'               => $ordermeta['_billing_email'][0],                        
                'buyer_phone'               => $ordermeta['_billing_phone'][0],                                   
                'order_id'                  => $order_id,
                'product'                   => $chart,
                'return_url'                => $this->settings['returnurl'], //akan di arahkan kehalaman ini setelah proses di payment page COD selesai           
                'notify_url'                => $this->settings['notifyurl'], //tujuan callback CODN (method POST) parameter bisa dilihat di file notify.php             
            );

            $data = base64_encode(json_encode($params));
            $signature = base64_encode( sha1( $this->settings['privatekey'] . $data . $this->settings['privatekey'] ) );

            add_action('wp_footer', function() { ?>
                <script>
                var $Url="http://app.codnusantara.com/payment";
                jQuery(function($){
                        var object=$('.codn_button');
                    $('head').append('<link rel="stylesheet" href="http://app.codnusantara.com/pay/codn.css" type="text/css" />');
                    $("body").append('<div style="display: none;margin:auto;" id="codn_panel"><div id="close"><img src="http://app.codnusantara.com/pay/close.png"></div><iframe id="codn_iframe"></iframe></div>');
                    $('.codn_button').click(function(event) {
                        var url=$Url+'?&d='+encodeURIComponent(object.attr("codn_data"))+"&s="+encodeURIComponent(object.attr("codn_signature"));
                        $("#codn_panel").css("display","block");
                        $("#codn_iframe").attr("src",url);
                    });
                    $('#close').click(function(event) {
                        $("#codn_panel").css("display","none");
                    });
                })
                </script>
            <?php }, 20);
            
            echo '<img src="img.png" style="cursor:pointer;margin:auto;display:block;" class="codn_button" codn_data="'.$data.'" codn_signature="'.$signature.'">';
        }

        
        function process_payment($order_id) {
            global $woocommerce;
            $order = new WC_Order($order_id);

			$order->reduce_order_stock();

			WC()->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url( true ));
        }

  
        function check_codn_response() {
            
            global $woocommerce;
            $order = new WC_Order($_REQUEST['id_order']);

            if($_REQUEST['status'] == 'berhasil') {
            	$order->add_order_note( __( 'Pembayaran telah dilakukan melalui ipaymu dengan id transaksi '.$_REQUEST['trx_id'], 'woocommerce' ) );
            	$order->payment_complete();
            } else {
            	$order->add_order_note( __( 'Menunggu pembayaran melalui non-member ipaymu dengan id transaksi '.$_REQUEST['trx_id'], 'woocommerce' ) );
            }

            $redirect = add_query_arg('key', $order->order_key, add_query_arg('order', $_REQUEST['id_order'], get_permalink(woocommerce_get_page_id('thanks'))));
            wp_redirect($redirect);
            exit;
            
        }

    }

    function add_codn_gateway($methods) {
        $methods[] = 'WC_Gateway_codn';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_codn_gateway');
}
