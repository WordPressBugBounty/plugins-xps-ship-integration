<?php
/**
Plugin Name: XPS Ship Integration
Description: The XPS Ship integration, a free integration for WooCommerce merchants, is the only integration that gives you all the necessary functionality for shipping
Version: 2.0.7
Author: XPS Ship - Descartes
WC requires at least: 2.4.8
WC tested up to: 8.5.1
 *
@package xpsship-integration
 */

// The requires.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once 'class-wc-webship-integration.php';
require_once 'class-webship-integrated-quoting-method.php';
require_once 'class-webship-shipment-tracking.php';

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

global $client_info;
$client_info = array(
	'clientCode'  => 'xps',
	'clientName'  => 'XPS Ship',
	'clientUrl'   => 'https://xpsshipper.com',
	// 'clientUrl'   => 'http://ship.xpsconnect.dev26.rsis.io',
	'logoUrl'     => 'https://xpsshipper.com/ec/static/images/client/xps/xps-cover-small.png',
	'buildNumber' => 'ec', // _002
	'_sentEmail'  => false,
);

global $allowed_html;
$allowed_html = array(
	'a'        => array(
		'href'   => array(),
		'target' => array(),
		'class'  => array(),
		'rel'    => array(),
	),
	'div'      => array(
		'class' => array(),
		'id'    => array(),
		'style' => array(
			'padding-bottom' => array(),
		),
	),
	'span'     => array(
		'class' => array(),
	),
	'button'   => array(
		'class' => array(),
		'type'  => array(),
	),
	'p'        => array(
		'class' => array(),
	),
	'b'        => array(),
	'i'        => array(),
	'strong'   => array(),
	'br'       => array(),
	'img'      => array(
		'src' => array(),
		'alt' => array(),
	),
	'ul'       => array(
		'class' => array(),
	),
	'li'       => array(
		'class' => array(),
	),
	'th'       => array(
		'class' => array(),
		'scope' => array(),
	),
	'tr'       => array(
		'class'  => array(),
		'valign' => array(),
	),
	'td'       => array(
		'class'  => array(),
		'valign' => array(),
	),
	'fieldset' => array(
		'class' => array(),
	),
	'label'    => array(
		'for'   => array(),
		'class' => array(),
	),
	'option'   => array(
		'value' => array(),
	),
	'select'   => array(
		'name'  => array(),
		'id'    => array(),
		'class' => array(),
		'type'  => array(),
	),
	'form'     => array(
		'action' => array(),
		'method' => array(),
	),
	'input'    => array(
		'type'  => array(),
		'name'  => array(),
		'value' => array(),
	),
	'table'    => array(
		'class'       => array(),
		'cellspacing' => array(),
		'cellpadding' => array(),
		'style'       => array(),
		'border'      => array(),
	),
	'thead'    => array(),
	'tbody'    => array(),
	'h2'       => array(),
	'hr'       => array(),
);
// Add more allowed styles.
add_filter(
	'safe_style_css',
	function ( $styles ) {
		$styles = array(
			'display',
			'text-align',
			'white-space',
			'width',
			'font-family',
			'color',
			'border',
			'padding',
			'padding-bottom',
			'padding-left',
			'padding-right',
			'padding-top',
		);
		return $styles;
	}
);



add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Check if webship client has the XPS Ship dedicated plugin.
 *
 * @return bool Returns true if the webship client is 'XPS Ship', false otherwise.
 */
function webship_client_has_dedicated_plugin() {
	if ( 'XPS Ship' === $GLOBALS['client_info']['clientName'] ) {
		return true;
	}
	return false;
}

register_uninstall_hook( __FILE__, 'webship_uninstall_fn' );

/**
 * Delete some options
 */
function webship_uninstall_fn() {
	delete_option( 'last_request_received_from_webship_timestamp' );
	delete_option( 'woocommerce_webship_api_key' );
}

/**
 * Initialize woocommerce webship, it loads on this page:
 * http://woocommerce.shoppingcarts.rocksolidinternet.com/wp-admin/admin.php?page=wc-settings&tab=integration
 *
 * @throws \Exception This throws in some cases.
 */
function webship_woocommerce_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				$woocommerce_link = esc_url( 'https://woocommerce.com/' );
				echo wp_kses(
					<<<EOF
					<div class="error"><p><b>The {$GLOBALS['client_info']['clientName']} plugin requires WooCommerce to be installed and activated - Download <a href="$woocommerce_link" target="_blank">WooCommerce</a> here</b></p></div>
					EOF,
					$GLOBALS['allowed_html']
				);
			}
		);

	} else {
		/**
		 * Description - do not copy this.
		 *
		 * @param string $order_meta_query is a query.
		 * @param string $original_order_id is the order id.
		 * @param string $renewal_order_id is the renewal order id.
		 */
		function do_not_copy_meta_data( $order_meta_query, $original_order_id, $renewal_order_id ) {

				$order_meta_query .= ' AND `meta_key` NOT IN ('
								. "'_tracking_provider', "
								. "'_tracking_number', "
								. "'_date_shipped', "
								. "'_order_custtrackurl', "
								. "'_order_custcompname', "
								. "'_order_trackno', "
								. "'_order_trackurl')";

			$order_meta_query .= " AND `meta_key` NOT IN ('_wc_shipment_tracking_items')";

			return $order_meta_query;
		}

		// This must be declared in global scope here for the shipment tracking box to display in edit orders.
		$webship_shipment_tracking = new Webship_Shipment_Tracking();

		/**
		 * Global.
		 * Adds a tracking entry for an order.
		 *
		 * @param int   $order_id The ID of the order.
		 * @param array $params   The tracking parameters.
		 *                        - tracking_provider        : The tracking provider.
		 *                        - custom_tracking_provider : The custom tracking provider.
		 *                        - custom_tracking_link     : The custom tracking link.
		 *                        - tracking_number          : The tracking number.
		 *                        - date_shipped             : The date shipped.
		 */
		function add_tracking_entry( $order_id, $params ) {
			$webship_shipment_tracking = new Webship_Shipment_Tracking();

			$webship_shipment_tracking->add_tracking_entry(
				$order_id,
				array(
					'tracking_provider'        => $params['tracking_provider'],
					'custom_tracking_provider' => $params['custom_tracking_provider'],
					'custom_tracking_link'     => $params['custom_tracking_link'],
					'tracking_number'          => $params['tracking_number'],
					'date_shipped'             => $params['date_shipped'],
				)
			);
		}
	}
}

add_action( 'plugins_loaded', 'webship_woocommerce_init' );

add_filter(
	'woocommerce_integrations',
	function ( $integrations ) {
		$integrations[] = 'WC_Webship_Integration';

		return $integrations;
	}
);

/**
 * Adds a settings link to the WooCommerce integration settings page.
 *
 * @param array $links An array of existing action links.
 * @return array The modified array of action links.
 */
function woocommerce_webship_api_plugin_action_links( $links ) {
	$link = admin_url( 'admin.php?page=wc-settings&tab=integration&section=webship' );

	return array_merge( array( '<a href="' . esc_url( $link ) . '">' . __( 'Settings', 'woocommerce-webship' ) . '</a>' ), $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_webship_api_plugin_action_links' );


/*
	General integration defining endpoints for pulling and updating orders when they are shipped
	accessible via: GET http://woocommerce.shoppingcarts.rocksolidinternet.com/?wc-api=wc_webship
	https://docs.woocommerce.com/document/wc_api-the-woocommerce-api-callback/

	example for getOrders:
	GET http://woocommerce.shoppingcarts.rocksolidinternet.com/?wc-api=wc_webship&apiKey=8982e6b0f57c7830dae51f4bbdb1e451&action=getOrders&page=1&statuses=wc-processing

	example for updateOrder
	POST http://woocommerce.shoppingcarts.rocksolidinternet.com/?wc-api=wc_webship&apiKey=8982e6b0f57c7830dae51f4bbdb1e451&action=updateOrder&orderId=141&trackingNumber=TEST123&carrier=USPS
	{
		"items": [
		{
			"lineId": "83",
			"quantity": 1
		}
		]
	}
*/
/**
 * Description - Transform an woocommerce order into a webship order.
 *
 * @param object $order - the order to transform into a webship order.
 */
function transform_woocommerce_order_into_webship_order( $order ) {
	$get                                    = array_map( 'sanitize_text_field', $_GET );
	$include_orders_with_only_virtual_items = isset( $get['includeOrdersWithOnlyVirtualItems'] ) ? $get['includeOrdersWithOnlyVirtualItems'] : null;

	$on_woocommerce_v3_or_newer = version_compare( WC_VERSION, '3.0', '>=' );

	if ( $on_woocommerce_v3_or_newer ) {
		$timestamp = $order->get_date_paid() ? $order->get_date_paid() : ( $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created() );
		$timestamp = $timestamp->getTimestamp();
	} else {
		$order_date = $order->order_date;
		$date       = new \DateTime( $order_date );
		$timestamp  = $date->getTimestamp();
	}

	$shipping_country = $on_woocommerce_v3_or_newer ? $order->get_shipping_country() : $order->shipping_country;
	$shipping_address = $on_woocommerce_v3_or_newer ? $order->get_shipping_address_1() : $order->shipping_address_1;
	if ( empty( $shipping_country ) && empty( $shipping_address ) ) {
		$name        = ( $on_woocommerce_v3_or_newer ? $order->get_billing_first_name() : $order->billing_first_name ) . ' ' . ( $on_woocommerce_v3_or_newer ? $order->get_billing_last_name() : $order->billing_last_name );
		$destination = array(
			'company'  => $on_woocommerce_v3_or_newer ? $order->get_billing_company() : $order->billing_company,
			'name'     => $name,
			'address1' => $on_woocommerce_v3_or_newer ? $order->get_billing_address_1() : $order->billing_address_1,
			'address2' => $on_woocommerce_v3_or_newer ? $order->get_billing_address_2() : $order->billing_address_2,
			'city'     => $on_woocommerce_v3_or_newer ? $order->get_billing_city() : $order->billing_city,
			'state'    => $on_woocommerce_v3_or_newer ? $order->get_billing_state() : $order->billing_state,
			'zip'      => $on_woocommerce_v3_or_newer ? $order->get_billing_postcode() : $order->billing_postcode,
			'country'  => $on_woocommerce_v3_or_newer ? $order->get_billing_country() : $order->billing_country,
			'phone'    => $on_woocommerce_v3_or_newer ? $order->get_billing_phone() : $order->billing_phone,
			'email'    => $on_woocommerce_v3_or_newer ? $order->get_billing_email() : $order->billing_email,
		);
	} else {
		$name        = ( $on_woocommerce_v3_or_newer ? $order->get_shipping_first_name() : $order->shipping_first_name ) . ' ' . ( $on_woocommerce_v3_or_newer ? $order->get_shipping_last_name() : $order->shipping_last_name );
		$destination = array(
			'company'  => $on_woocommerce_v3_or_newer ? $order->get_shipping_company() : $order->shipping_company,
			'name'     => $name,
			'address1' => $on_woocommerce_v3_or_newer ? $order->get_shipping_address_1() : $order->shipping_address_1,
			'address2' => $on_woocommerce_v3_or_newer ? $order->get_shipping_address_2() : $order->shipping_address_2,
			'city'     => $on_woocommerce_v3_or_newer ? $order->get_shipping_city() : $order->shipping_city,
			'state'    => $on_woocommerce_v3_or_newer ? $order->get_shipping_state() : $order->shipping_state,
			'zip'      => $on_woocommerce_v3_or_newer ? $order->get_shipping_postcode() : $order->shipping_postcode,
			'country'  => $on_woocommerce_v3_or_newer ? $order->get_shipping_country() : $order->shipping_country,
			'phone'    => $on_woocommerce_v3_or_newer ? $order->get_billing_phone() : $order->billing_phone,
			'email'    => $on_woocommerce_v3_or_newer ? $order->get_billing_email() : $order->billing_email,
		);
	}

	$shipping_methods      = $order->get_shipping_methods();
	$shipping_method_names = array();

	foreach ( $shipping_methods as $shipping_method ) {
		$method_name = preg_replace( '/[^A-Za-z0-9 \-\.\_,]/', '', $shipping_method['name'] );
		array_push( $shipping_method_names, $method_name );
	}

	$webship_order = array(
		'orderNumber'     => ltrim( $order->get_order_number(), '#' ),
		'id'              => is_callable( array( $order, 'get_id' ) ) ? $order->get_id() : $order->id,
		'created'         => $timestamp,
		'shippingService' => implode( ', ', $shipping_method_names ),
		'shipping_total'  => sprintf( '%01.2f', $on_woocommerce_v3_or_newer ? $order->get_shipping_total() : $order->get_total_shipping() ),
		'customerNotes'   => $on_woocommerce_v3_or_newer ? $order->get_customer_note() : $order->customer_note,
		'destination'     => $destination,
		'items'           => array(),
		'weightUnit'      => 'lb',
	);

	$order_has_atleast_one_item = false;

	$order_items = $order->get_items() + $order->get_items( 'fee' );
	foreach ( $order_items as $item_id => $item ) {
		if ( $on_woocommerce_v3_or_newer ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
		} else {
			$product = $order->get_product_from_item( $item );
		}

		if ( $include_orders_with_only_virtual_items ) {
			$order_has_atleast_one_item = true;
			// skip items that don't require shipping.
		} elseif ( ( ! $product || ! $product->needs_shipping() ) && 'fee' !== $item['type'] ) {
			continue;
		}

		$order_has_atleast_one_item = true;

		$webship_item = array(
			'lineId' => (string) $item_id,
		);

		if ( 'fee' === $item['type'] ) {
			$webship_item = array_merge(
				$webship_item,
				array(
					'title'    => $on_woocommerce_v3_or_newer ? $item->get_name() : $item['name'],
					'quantity' => (string) 1,
					'price'    => $order->get_item_total( $item, false, true ),
				)
			);
		}

		if ( $product && $product->needs_shipping() ) {
			$image_id = $product->get_image_id();
			$img_url  = $image_id ? current( wp_get_attachment_image_src( $image_id, 'shop_thumbnail' ) ) : '';

			if ( method_exists( $product, 'get_id' ) ) {
				$product_id = $product->get_id();
			} else {
				$product_id = $product->id;
			}

			$webship_item = array_merge(
				$webship_item,
				array(
					'productId'      => (string) $product_id,
					'sku'            => $product->get_sku(),
					'title'          => $product->get_title(),
					'imgUrl'         => $img_url,
					'shippingWeight' => (string) wc_get_weight( $product->get_weight(), 'lbs' ),
					'quantity'       => (string) $item['qty'],
					'price'          => $order->get_item_subtotal( $item, false, true ),
					'productLength'  => $product->get_length(),
					'productWidth'   => $product->get_width(),
					'productHeight'  => $product->get_height(),
					'url'            => $product->get_permalink(),
				)
			);
		}

		if ( $item['item_meta'] ) {
			if ( version_compare( WC_VERSION, '3.0.0', '<' ) ) {
				$item_meta      = new WC_Order_Item_Meta( $item, $product );
				$formatted_meta = $item_meta->get_formatted( '_' );
			} else {
				add_filter( 'woocommerce_is_attribute_in_product_name', '__return_false' );
				$formatted_meta = $item->get_formatted_meta_data();
			}

			if ( ! empty( $formatted_meta ) ) {
				$attributes = array();

				foreach ( $formatted_meta as $meta_key => $meta ) {
					if ( version_compare( WC_VERSION, '3.0.0', '<' ) ) {
						array_push(
							$attributes,
							array(
								'name'  => $meta['label'],
								'value' => $meta['value'],
							)
						);
					} else {
						array_push(
							$attributes,
							array(
								'name'  => $meta->display_key,
								'value' => wp_strip_all_tags( $meta->display_value ),
							)
						);
					}
				}

				$webship_item['attributes'] = $attributes;
			}
		}

		array_push( $webship_order['items'], $webship_item );
	}

	if ( ! $order_has_atleast_one_item ) {
		return false;
	}

	return $webship_order;
}

/**
 * Description - This is the main function for the webship api.
 */
function woocommerce_webship_api() {
	global $wpdb;

	$on_woocommerce_v3_or_newer = version_compare( WC_VERSION, '3.0', '>=' );

	$get     = array_map( 'sanitize_text_field', $_GET );
	$api_key = isset( $get['apiKey'] ) ? $get['apiKey'] : null;
	// getOrders, getOrder, updateOrder.
	$action = isset( $get['action'] ) ? $get['action'] : null;

	// action: getOrders.
	$page = isset( $get['page'] ) ? $get['page'] : null;
	// 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled'.
	// invalid statuses will return all statuses, but we allow any status in case they have custom statuses.
	$statuses = isset( $get['statuses'] ) ? explode( ',', $get['statuses'] ) : null;
	if ( $statuses && version_compare( WC_VERSION, '3.1', '<' ) ) {
		$statuses = array_map(
			function ( $status ) {
				return "wc-$status";
			},
			$statuses
		);
	}

	// action: updateOrder.
	$order_id        = isset( $get['orderId'] ) ? $get['orderId'] : null;
	$tracking_number = isset( $get['trackingNumber'] ) ? $get['trackingNumber'] : null;
	$carrier         = isset( $get['carrier'] ) ? $get['carrier'] : null;

	if ( empty( $api_key ) ) {
		wp_send_json_error( __( 'API Key is required', 'woocommerce-webship' ) );
	}

	if ( ! hash_equals( sanitize_text_field( $api_key ), WC_Webship_Integration::$api_key ) ) {
		wp_send_json_error( __( 'Invalid API Key', 'woocommerce-webship' ) );
	}

	$last_request_received_from_webship_timestamp = get_option( 'last_request_received_from_webship_timestamp' );
	$timestamp                                    = time();
	if ( $last_request_received_from_webship_timestamp ) {
		update_option( 'last_request_received_from_webship_timestamp', $timestamp );
	} else {
		add_option( 'last_request_received_from_webship_timestamp', $timestamp );
	}

	nocache_headers();

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', 'true' );
	}

	if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
		define( 'DONOTCACHEOBJECT', 'true' );
	}

	if ( ! defined( 'DONOTCACHEDB' ) ) {
		define( 'DONOTCACHEDB', 'true' );
	}

	if ( ! $action ) {
		wp_send_json_error( __( "You must provide a 'action' parameter", 'woocommerce-webship' ) );
	} elseif ( 'getOrder' === $action ) {
		$order_id = $get['orderId'];
		if ( ! $order_id ) {
			// Translators: Sub the action into the error message.
			wp_send_json_error( esc_html( sprintf( __( "You must provide an 'orderId' parameter with action %s", 'woocommerce-webship' ), $action ) ) );
		}
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			header( 'Content-Type: application/json' );
			echo json_encode( array( 'error' => 'no order found' ) );
			exit;
		}

		$webship_order = transform_woocommerce_order_into_webship_order( $order );

		if ( $webship_order ) {
			header( 'Content-Type: application/json' );
			echo json_encode(
				array(
					'webshipOrder'     => $webship_order,
					'woocommerceOrder' => $order->get_data(),
				)
			);
			exit;
		} else {
			header( 'Content-Type: application/json' );
			echo json_encode( array( 'error' => 'no order found' ) );
			exit;
		}
	} elseif ( 'getOrders' === $action ) {
		if ( ! $page ) {
			// Translators: Sub the action into the error message.
			wp_send_json_error( esc_html( sprintf( __( "You must provide a 'page' parameter with action %s", 'woocommerce-webship' ), $action ) ) );
		}

		if ( ! $statuses ) {
			// Translators: Sub the action into the error message.
			wp_send_json_error( esc_html( sprintf( __( "You must provide a comma separated string 'statuses' parameter with action %s", 'woocommerce-webship' ), $action ) ) );
		}

		$page  = isset( $page ) ? absint( $page ) : 1;
		$limit = 300;

		if ( version_compare( WC_VERSION, '3.1', '>=' ) ) {
			$order_ids = wc_get_orders(
				array(
					// 'date_modified' => $start_date . '...' . $end_date,
					'type'    => 'shop_order',
					'status'  => $statuses,
					'return'  => 'ids',
					'orderby' => 'date_modified',
					'order'   => 'DESC',
					'paged'   => $page,
					'limit'   => $limit,
				)
			);

			$order_ids = array_map(
				function ( $order_or_id ) {
					return is_a( $order_or_id, 'WC_Order' ) ? $order_or_id->get_id() : $order_or_id;
				},
				$order_ids
			);
		} else {
			$order_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %s
						WHERE post_type = \'shop_order\'
						AND post_status IN (\'%s\')
						ORDER BY post_modified_gmt DESC
						LIMIT %d, %d',
					$wpdb->posts,
					implode( "','", $statuses ),
					$limit * ( $page - 1 ),
					$limit
				)
			);
		}

		$webship_orders = array();
		foreach ( $order_ids as $order_id ) {
			$order         = wc_get_order( $order_id );
			$webship_order = transform_woocommerce_order_into_webship_order( $order );
			if ( $webship_order ) {
				array_push( $webship_orders, $webship_order );
			}
		}

		if ( version_compare( WC_VERSION, '3.1', '>=' ) ) {
			$order_ids = wc_get_orders(
				array(
					// 'date_modified' => $start_date . '...' . $end_date,
					'type'   => 'shop_order',
					// 'pending', 'processing', 'on-hold', 'completed', 'refunded, 'failed', 'cancelled', or a custom order status
					'status' => $statuses,
					'return' => 'ids',
					'limit'  => -1,
				)
			);

			$total_orders = count( $order_ids );
		} else {
			$total_orders = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(ID) FROM %s
						WHERE post_type = \'shop_order\'
						AND post_status IN (\'%s\')
						LIMIT %d',
					$wpdb->posts,
					implode( "','", $statuses ),
					1
				)
			);
		}

		header( 'Content-Type: application/json' );
		// total may differ from actual orders shown since we do some filtering after the fact.
		echo json_encode(
			array(
				'orders'             => $webship_orders,
				'totalOrders'        => $total_orders,
				'statuses'           => $statuses,
				'woocommerceVersion' => WC_VERSION,
			)
		);
		exit;
	} elseif ( 'updateOrder' === $action ) {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_send_json_error( __( 'updateOrder requires POST request method', 'woocommerce-webship' ) );
		}

		$body = file_get_contents( 'php://input' );

		if ( $body ) {
			$request_body = json_decode( $body, true );
		}

		if ( ! $order_id ) {
			// Translators: Sub the action into the error message.
			wp_send_json_error( esc_html( sprintf( __( 'You must provide a \'orderId\' parameter with action %s', 'woocommerce-webship' ), $action ) ) );
		}

		if ( ! $tracking_number ) {
			// Translators: Sub the action into the error message.
			wp_send_json_error( esc_html( sprintf( __( 'You must provide a \'trackingNumber\' parameter with action %s', 'woocommerce-webship' ), $action ) ) );
		}

		if ( ! $carrier ) {
			// Translators: Sub the action into the error message.
			wp_send_json_error( esc_html( sprintf( __( 'You must provide a \'carrier\' parameter with action %s', 'woocommerce-webship' ), $action ) ) );
		}

		preg_match( '/\((.*?)\)/', $order_id, $matches );
		if ( is_array( $matches ) && isset( $matches[1] ) ) {
			$internal_order_id = $matches[1];
		} elseif ( function_exists( 'wc_sequential_order_numbers' ) ) {
			$order             = wc_get_order( $order_id );
			$internal_order_id = wc_sequential_order_numbers()->find_order_by_order_number( $order->get_order_number() );
		} elseif ( function_exists( 'wc_seq_order_number_pro' ) ) {
			$order             = wc_get_order( $order_id );
			$internal_order_id = wc_seq_order_number_pro()->find_order_by_order_number( $order->get_order_number() );
		} else {
			$internal_order_id = $order_id;
		}

		if ( 0 === $internal_order_id ) {
			$internal_order_id = $order_id;
		}

		$order = wc_get_order( $internal_order_id );

		if ( ! $order ) {
			// Translators: Sub the order_id into the error message.
			wp_send_json_error( esc_html( sprintf( __( 'No order found with ID %s', 'woocommerce-webship' ), $internal_order_id ) ) );
		}

		$order_fulfillment_complete = false;
		$has_shippable_items        = false;

		if ( ! empty( $request_body ) && $request_body['items'] ) {
			$total_item_qty_to_ship = 0;
			$shipment_notes         = array();
			foreach ( $request_body['items'] as $item ) {
				if ( version_compare( WC_VERSION, '3.0', '>=' ) ) {
					$line_item = $order->get_item( $item['lineId'] );
					if ( is_callable( array( $line_item, 'get_product' ) ) ) {
						$product = $line_item->get_product();
						$title   = $product->get_title();
						$sku     = $product->get_sku();
					}
				} else {
					$items = $order->get_items();
					if ( isset( $items[ $item['lineId'] ] ) ) {
						$product = $order->get_product_from_item( $items[ $item['lineId'] ] );
						$title   = $product->get_title();
						$sku     = $product->get_sku();
					}
				}

				$shippable = $product && $product->needs_shipping();

				if ( ! $shippable ) {
					$logger = new WC_Logger();
					$logger->add( $GLOBALS['client_info']['clientCode'], "Skipping {$title} {$sku} since it is not a shippable item" );

					continue;
				}

				$total_item_qty_to_ship += intval( $item['quantity'] );

				array_push( $shipment_notes, "{$title} {$sku} x {$item['quantity']}" );

				$has_shippable_items = true;
			}
		}

		$total_item_qty = 0;
		foreach ( $order->get_items() as $line_id => $line_item ) {

			if ( $on_woocommerce_v3_or_newer ) {
				$product = is_callable( array( $line_item, 'get_product' ) ) ? $line_item->get_product() : null;
			} else {
				$product = $order->get_product_from_item( $line_item );
			}

			if ( is_a( $product, 'WC_Product' ) && $product->needs_shipping() ) {
				$total_item_qty += $line_item['qty'];
			}
		}

		if ( version_compare( WC_VERSION, '3.0', '>=' ) ) {
			$wc_date   = new WC_DateTime();
			$timestamp = $wc_date->getOffsetTimestamp();
			$ymd_date  = $wc_date->format( 'Y-m-d' );
		} else {
			$date      = new \DateTime();
			$timestamp = $date->getTimestamp();
			$ymd_date  = $date->format( 'Y-m-d' );
		}

		$formatted_date = date_i18n( get_option( 'date_format' ), $timestamp );
		if ( $has_shippable_items ) {

			$order_note = implode( ', ', $shipment_notes ) . " shipped on $formatted_date via $carrier - $tracking_number ({$GLOBALS['client_info']['clientName']})";

			if ( version_compare( WC_VERSION, '2.6', '>=' ) ) {
				$total_item_qty_already_shipped_meta = $order->get_meta( '_webship_total_item_qty_already_shipped' );
			} else {
				$total_item_qty_already_shipped_meta = get_post_meta( $order_id, '_webship_total_item_qty_already_shipped', true );
			}

			$total_item_qty_already_shipped = max( (int) $total_item_qty_already_shipped_meta, 0 );

			if ( ( $total_item_qty_already_shipped + $total_item_qty_to_ship ) >= $total_item_qty ) {
				$order_fulfillment_complete = true;
			}

			$logger = new WC_Logger();
			$logger->add( $GLOBALS['client_info']['clientCode'], "Shipped $total_item_qty_to_ship out of $total_item_qty items in order $order_id" );

			if ( version_compare( WC_VERSION, '2.6', '>=' ) ) {
				$order->update_meta_data( '_webship_total_item_qty_already_shipped', $total_item_qty_already_shipped + $total_item_qty_to_ship );
				$order->save_meta_data();
			} else {
				update_post_meta( $order_id, '_webship_total_item_qty_already_shipped', $total_item_qty_already_shipped + $total_item_qty_to_ship );
			}
		} elseif ( 0 === $total_item_qty ) {
			$order_fulfillment_complete = true;

			$order_note = "Shipped items on $formatted_date via $carrier - $tracking_number ({$GLOBALS['client_info']['clientName']})";
		} else {
			$logger = new WC_Logger();

			$logger->add( $GLOBALS['client_info']['clientCode'], 'No items found but order has items to fulfill, ignoring request' );
		}

		if ( class_exists( 'WC_Shipment_Tracking' ) ) {
			if ( function_exists( 'wc_st_add_tracking_number' ) ) {
				wc_st_add_tracking_number( $order_id, $tracking_number, strtolower( $carrier ), $timestamp );
			} else {
				// for shipment tracking < 1.4.0 .
				update_post_meta( $order_id, '_tracking_provider', strtolower( $carrier ) );
				update_post_meta( $order_id, '_tracking_number', $tracking_number );
				update_post_meta( $order_id, '_date_shipped', $timestamp );
			}
		} else {
			// otherwise use built in tracking.
			add_tracking_entry(
				$order_id,
				array(
					'tracking_provider'        => strtolower( $carrier ),
					'custom_tracking_provider' => '',
					'custom_tracking_link'     => '',
					'tracking_number'          => $tracking_number,
					'date_shipped'             => $ymd_date,
				)
			);
		}

		if ( $order_note ) {
			$order->add_order_note( $order_note, 0 );
		}

		if ( $order_fulfillment_complete ) {
			$order->update_status( 'completed' );

			$logger = new WC_Logger();
			$logger->add( $GLOBALS['client_info']['clientCode'], "Updated order $order_id to status 'completed'" );
		}

		status_header( 200 );
		header( 'Content-Type: application/json' );
		echo json_encode( array( 'ok' => true ) );
		exit;
	} else {
		// Translators: Sub the action into the error message.
		wp_send_json_error( esc_html( sprintf( __( 'No such action %s', 'woocommerce-webship' ), $action ) ) );
	}
}

add_action( 'woocommerce_api_wc_webship', 'woocommerce_webship_api' );

/**
 *  Webship integrated quoting
 *  http://woocommerce.shoppingcarts.rocksolidinternet.com/wp-admin/admin.php?page=wc-settings&tab=shipping&section=webship
 */
if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	add_action( 'woocommerce_shipping_init', 'webship_integrated_quoting_shipping_method' );

	/**
	 * Description - method that knows how to quote shipping rates from Webship.
	 * This function uses class-wc-webship-quoting-method.php.
	 */
	function webship_integrated_quoting_shipping_method() {

			add_filter( 'woocommerce_shipping_methods', 'add_webship_integrated_qutoing_shipping_method' );

			/**
			 * Description - add webship integrated quoting shipping method.
			 *
			 * @param string $methods - list of methods to embed.
			 */
		function add_webship_integrated_qutoing_shipping_method( $methods ) {
			$methods[] = 'Webship_Integrated_Quoting_Method';
			return $methods;
		}

			add_action( 'woocommerce_settings_saved', 'check_for_admin_fields' );

			/**
			 * Description - check if admin fields are present.
			 *
			 * @param string $args - list of arguments.
			 */
		function check_for_admin_fields( $args ) {
			$webship_integrated_quoting_method = new Webship_Integrated_Quoting_Method();

			$url     = (string) $GLOBALS['client_info']['clientUrl'] ? (string) $GLOBALS['client_info']['clientUrl'] : $webship_integrated_quoting_method->settings['url'];
			$api_key = (string) $webship_integrated_quoting_method->settings['apiKey'];

			if ( empty( $url ) ) {
				WC_Admin_Settings::add_error( "{$GLOBALS['client_info']['clientName']} URL is a required field" );
			}

			if ( empty( $api_key ) ) {
				WC_Admin_Settings::add_error( 'API Key is a required field' );
			}
		}
	}
}
