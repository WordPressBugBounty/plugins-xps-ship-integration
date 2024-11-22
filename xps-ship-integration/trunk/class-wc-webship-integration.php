<?php
/**
 * Description - This file is for the integration with webship.

@package xpsship-integration
 */

// The requires.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}


/**
 * Description - this class is for the integration with webship.
 *
 * @member string $api_key - the api key to use.
 */
class WC_Webship_Integration extends WC_Integration {
	/**
	 * The key that the api will use.
	 *
	 * @var public static $api_key.
	 */
	public static $api_key = null;

	/**
	 * Description - This is a constructor for a class.
	 */
	public function __construct() {
		$this->id = esc_attr( 'webship' );
		// Translators: put the client name in as %s.
		$this->method_title = esc_attr( $GLOBALS['client_info']['clientName'] );
		// Translators: put the client name in as %s.
		$this->method_description = esc_attr( sprintf( __( '%s allows you ship through numerous carriers', 'woocommerce-webship' ), $GLOBALS['client_info']['clientName'] ) );

		if ( ! get_option( 'woocommerce_webship_api_key', false ) ) {
			// generate an api key.
			$to_hash = get_current_user_id() . gmdate( 'U' ) . mt_rand();
			$key     = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );

			update_option( 'woocommerce_webship_api_key', $key );
		}

		$this->init_form_fields();
		$this->init_settings();

		self::$api_key = get_option( 'woocommerce_webship_api_key', false );

		// save the api key we generated.
		$this->settings['apiKey'] = self::$api_key;

		add_action( 'woocommerce_update_options_integration_webship', array( $this, 'process_admin_options' ) );

		if ( webship_client_has_dedicated_plugin() ) {
			if ( ! function_exists( 'add_external_link_admin_submenu' ) ) {
				/**
				 * Description - Add a link to the admin submenu
				 */
				function add_external_link_admin_submenu() {
					$permalink = admin_url( 'admin.php' ) . '?page=wc-settings&tab=integration&section=webship';
					add_submenu_page( 'woocommerce', $GLOBALS['client_info']['clientName'], $GLOBALS['client_info']['clientName'], 'manage_options', $permalink, '' );
				}
				add_action( 'admin_menu', 'add_external_link_admin_submenu' );
			}

			$dismissed_setup_notice = get_user_meta( get_current_user_id(), 'dismissed_webship-setup_notice' );

			if ( ! $dismissed_setup_notice ) {
				add_action( 'admin_notices', array( $this, 'setup_notice' ) );
			}
		}
	}

	/**
	 * Description - notices to customers about their new integrations
	 */
	public function setup_notice() {
		$get         = wp_unslash( $_GET );
		$current_tab = isset( $get['tab'] ) ? esc_attr( $get['tab'] ) : '';
		$last_request_received_from_webship_timestamp = get_option( 'last_request_received_from_webship_timestamp' );
		if ( ! empty( wc_clean( $current_tab ) ) && 'integration' === wc_clean( $current_tab ) || $last_request_received_from_webship_timestamp ) {
			return;
		}

		$logo      = plugins_url( 'assets/images/webship.png', __DIR__ );
		$admin_url = admin_url( 'admin.php?page=wc-settings&tab=integration&section=webship' );

		$interpolatable_api_key_string = self::$api_key;

		$hide_notice_url = esc_url( wp_nonce_url( add_query_arg( 'wc-hide-notice', 'webship-setup' ), 'woocommerce_hide_notices_nonce', '_wc_notice_nonce' ) );

		$wordpress_url = isset( $_SERVER['HTTP_HOST'] ) ? filter_var( wp_unslash( $_SERVER['HTTP_HOST'] ), FILTER_SANITIZE_URL ) : '';
		$wordpress_url = esc_url_raw( ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http' ) . "://$wordpress_url" );

		$logo_url = esc_url( $GLOBALS['client_info']['logoUrl'] );

		$create_integration_url = esc_url( "{$GLOBALS['client_info']['clientUrl']}/{$GLOBALS['client_info']['buildNumber']}/#/settings/integrations/new/woocommerce?woocommerceApiKey={$interpolatable_api_key_string}&woocommerceSite_url=$wordpress_url" );

		$html = <<<EOF
					<div id="message" class="updated woocommerce-message webship-setup" style="padding:20px;">
						<img alt="{$GLOBALS['client_info']['clientName']}" title="{$GLOBALS['client_info']['clientName']}" src="{$logo_url}" style="width:140px" />
						<a class="woocommerce-message-close notice-dismiss" href="{$hide_notice_url}">Dismiss</a>
						<p>To start printing shipping labels with {$GLOBALS['client_info']['clientName']} navigate to <a class="external-link" href="$create_integration_url" target="_blank">{$GLOBALS['client_info']['clientUrl']}</a> and log in or sign up for a new account.</p>

						<p>After logging in, configure your WooCommerce integration to initiate communication between {$GLOBALS['client_info']['clientName']} and WooCommerce.</p>

						<p>Once you've connected your integrations, you can begin booking shipments for those orders</p>
					</div>
EOF;

		echo wp_kses( $html, $GLOBALS['allowed_html'] );
	}

	/**
	 * Description - options to use for admins
	 */
	public function admin_options() {
		$interpolatable_api_key_string = self::$api_key;

		$wordpress_filter_url = isset( $_SERVER['HTTP_HOST'] ) ? filter_var( wp_unslash( $_SERVER['HTTP_HOST'] ), FILTER_SANITIZE_URL ) : '';
		$wordpress_url        = esc_url_raw( ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http' ) . "://$wordpress_filter_url" );

		$last_request_received_from_webship_timestamp = get_option( 'last_request_received_from_webship_timestamp' );
		$successful_connection_html                   = '';
		if ( $last_request_received_from_webship_timestamp ) {
			$readable_date              = gmdate( 'F j, Y, g:i a', $last_request_received_from_webship_timestamp );
			$successful_connection_html = <<<EOF
						<div id="connected-message" class="notice updated" style="padding-bottom: 20px; padding-left: 20px;">
							<h2><span class="dashicons dashicons-yes"></span> Connection Successful</h2>
							{$GLOBALS['client_info']['clientName']} was able to successfully retrieve your WooCommerce orders on $readable_date
						</div>
					EOF;
		}

		echo wp_kses(
			<<<EOF
					$successful_connection_html

					<h2>{$GLOBALS['client_info']['clientName']} Plugin</h2>

					<table class="form-table">
					EOF,
			$GLOBALS['allowed_html']
		);
		$this->generate_settings_html();

		$permalink = esc_url( admin_url( 'admin.php' ) . '?page=wc-settings&tab=shipping&section=webship' );

		$button_class       = $last_request_received_from_webship_timestamp ? '' : 'button-primary';
		$button_text        = $last_request_received_from_webship_timestamp ? 'Reconnect' : 'Connect';
		$go_to_webship_html = '';
		if ( webship_client_has_dedicated_plugin() ) {
			if ( $last_request_received_from_webship_timestamp ) {
				$connect_url = "{$GLOBALS['client_info']['clientUrl']}/{$GLOBALS['client_info']['buildNumber']}/#/settings/integrations/new/woocommerce?woocommerceApiKey=$interpolatable_api_key_string&woocommerceSite_url=$wordpress_url";

				$go_to_webship_href = esc_url( "{$GLOBALS['client_info']['clientUrl']}/{$GLOBALS['client_info']['buildNumber']}/#/ship" );

				$go_to_webship_html = <<<EOF
							<a class="external-link button button-primary" href="{$go_to_webship_href}">
								Start Shipping on <b>{$GLOBALS['client_info']['clientName']}</b>
							</a>
EOF;
			} else {
				$redirect_to = urlencode( "{$GLOBALS['client_info']['clientUrl']}/{$GLOBALS['client_info']['buildNumber']}/#/settings/integrations/new/woocommerce?woocommerceApiKey=$interpolatable_api_key_string&woocommerceSite_url=$wordpress_url" );
				$connect_url = esc_url( "{$GLOBALS['client_info']['clientUrl']}/{$GLOBALS['client_info']['buildNumber']}/signup/114?redirectTo=$redirect_to" );
			}

			echo wp_kses(
				<<<EOF
							<tr valign="top">
								<th scope="row" class="titledesc">
									Connect
								</th>
								<td class="forminp">
									<fieldset>
										$go_to_webship_html
										<a class="external-link button $button_class" href="$connect_url">
											$button_text my WooCommerce Store to {$GLOBALS['client_info']['clientName']}
										</a>

										<p class="description">Click the "$button_text" button to signup for a new account or login to {$GLOBALS['client_info']['clientName']}. Once you're logged in, your WooCommerce store will automatically be connected to {$GLOBALS['client_info']['clientName']} and orders will automatically start to appear</p>
									</fieldset>
								</td>
							</tr>
						EOF,
				$GLOBALS['allowed_html']
			);
		}

		echo wp_kses(
			<<<EOF
							<tr>
								<td></td>
								<td><hr /></td>
							</tr>
							<tr>
								<th></th>
								<td><i>Enable and configure live shipping rates for your customers during checkout from {$GLOBALS['client_info']['clientName']} on the <a href="$permalink">integrated quoting settings page</a></i></td>
							</tr>
						</table>
					EOF,
			$GLOBALS['allowed_html']
		);
	}

	/**
	 * Description - This creates a form field for the API key and stores it in itself
	 */
	public function init_form_fields() {
		$form_fields = array();

		if ( ! webship_client_has_dedicated_plugin() ) {
			$form_fields['apiKey'] = array(
				'title'             => esc_html( __( 'API Key', 'woocommerce-webship' ) ),
				// Translators: stick the clientName in the text.
				'description'       => esc_html( sprintf( __( 'Copy this text and paste it into the corresponding field on your WooCommerce settings page within %s', 'woocommerce-webship' ), $GLOBALS['client_info']['clientName'] ) ),
				'default'           => '',
				'type'              => 'text',
				// Translators: Stick the clientName in the text.
				'desc_tip'          => esc_attr( sprintf( __( 'This is the <code>API Key</code> we generated for you in WooCommerce that allows %s to retrieve and upate orders', 'woocommerce-webship' ), $GLOBALS['client_info']['clientName'] ) ),
				'custom_attributes' => array(
					'readonly' => 'readonly',
				),
				'value'             => self::$api_key,
			);
		}

		$this->form_fields = $form_fields;
	}
}
