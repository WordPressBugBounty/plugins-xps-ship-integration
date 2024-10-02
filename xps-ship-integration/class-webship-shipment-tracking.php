<?php
/**
 * Description - Webship Shipment Tracking

@package xpsship-integration
 */

// The requires.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Description - Webship Shipment Tracking
 */
class Webship_Shipment_Tracking {
	/**
	 * Constructor that adds the actions we need for the class to function
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		add_action( 'wp_ajax_webship_add_tracking_entry', array( $this, 'http_add_tracking_entry' ) );

		add_action( 'wp_ajax_webship_delete_tracking_entry', array( $this, 'http_delete_tracking_entry' ) );

		if ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'render_shipment_tracking_column_header_in_order_list_view' ) );

			add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_shipment_tracking_field_in_order_list_view' ), 10, 2 );
		} else {
			// order list view column heading.
			add_filter( 'manage_shop_order_posts_columns', array( $this, 'render_shipment_tracking_column_header_in_order_list_view' ), 99 );

			// order list view field contents.
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_shipment_tracking_field_in_order_list_view' ) );
		}

		// Customer / Order CSV Export column headers/data.
		add_filter( 'wc_customer_order_csv_export_order_headers', array( $this, 'add_shipment_tracking_column_header_to_csv_export' ) );
		add_filter( 'wc_customer_order_csv_export_order_row', array( $this, 'add_shipment_tracking_field_to_csv_export' ), 10, 3 );

		// Order Update Email.
		add_action( 'woocommerce_email_before_order_table', array( $this, 'render_email' ), 0, 3 );

		// Customer "My Account" view.
		// http://woocommerce.shoppingcarts2.rocksolidinternet.com/my-account/view-order/[order id]/ .
		add_action( 'woocommerce_view_order', array( $this, 'render_tracking_info' ) );

		$woocommerce_subscription_plugin_version = class_exists( 'WC_Subscriptions' ) && ! empty( WC_Subscriptions::$version ) ? WC_Subscriptions::$version : null;

		// Prevent data being copied to subscriptions.
		if ( null !== $woocommerce_subscription_plugin_version && version_compare( $woocommerce_subscription_plugin_version, '2.0.0', '>=' ) ) {
			add_filter( 'wcs_renewal_order_meta_query', 'do_not_copy_meta_data', 10, 4 );
		} else {
			add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', 'do_not_copy_meta_data', 10, 4 );
		}
	}
	/**
	 * Function to generate the html to show the tracking info
	 *
	 * @param string $order_id - the id of the order.
	 */
	public function render_tracking_info( $order_id ) {
		$tracking_entries = $this->get_tracking_entries( $order_id );

		if ( count( $tracking_entries ) > 0 ) {
			?>
			<h2>Tracking Information</h2>

			<table class="shop_table shop_table_responsive my_account_tracking">
				<thead>
					<tr>
						<th class="tracking-provider"><span class="nobr"><?php esc_html( __( 'Provider', 'webship-shipment-tracking' ) ); ?></span></th>
						<th class="tracking-number"><span class="nobr"><?php esc_html( __( 'Tracking Number', 'webship-shipment-tracking' ) ); ?></span></th>
						<th class="date-shipped"><span class="nobr"><?php esc_html( __( 'Date', 'webship-shipment-tracking' ) ); ?></span></th>
						<th class="order-actions">&nbsp;</th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $tracking_entries as $tracking_entry ) {
					?>
					<tr class="tracking">
						<td class="tracking-provider" data-title="<?php esc_html( __( 'Provider', 'webship-shipment-tracking' ) ); ?>">
							<?php echo esc_html( $this->get_formatted_shipping_provider( $tracking_entry['tracking_provider'] ) ? $this->get_formatted_shipping_provider( $tracking_entry['tracking_provider'] ) : $tracking_entry['custom_tracking_provider'] ); ?>
						</td>
						<td class="tracking-number" data-title="<?php esc_html( __( 'Tracking Number', 'webship-shipment-tracking' ) ); ?>">
							<?php echo esc_html( $tracking_entry['tracking_number'] ); ?>
						</td>
						<td class="date-shipped" data-title="<?php esc_html( __( 'Status', 'webship-shipment-tracking' ) ); ?>" style="text-align:left; white-space:nowrap;">
							<time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $tracking_entry['date_shipped'] ) ); ?>" title="<?php echo esc_attr( gmdate( 'Y-m-d', $tracking_entry['date_shipped'] ) ); ?>"><?php echo esc_attr( date_i18n( get_option( 'date_format' ), $tracking_entry['date_shipped'] ) ); ?></time>
						</td>
						<td class="order-actions" style="text-align: center;">
								<a href="<?php echo esc_url( $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] ) ? $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] ) : $tracking_entry['custom_tracking_link'] ); ?>" target="_blank" class="button"><?php esc_html( __( 'Track', 'webship-shipment-tracking' ) ); ?></a>
						</td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
			<?php
		}
	}

	/**
	 * This function renders the html of an email
	 *
	 * @param string  $order this is the order.
	 * @param string  $sent_to_admin this is the sent to admin.
	 * @param boolean $plain_text this is a boolean of whether to render plain text or not.
	 */
	public function render_email( $order, $sent_to_admin, $plain_text = null ) {
		if ( ! $GLOBALS['client_info']['_sentEmail'] ) {
			$order_id = is_callable( array( $order, 'get_id' ) ) ? $order->get_id() : $order->id;

			$tracking_entries = $this->get_tracking_entries( $order_id );

			if ( count( $tracking_entries ) > 0 ) {
				if ( $plain_text ) {
					/**
					 * This is a hook that demands a comment
					 * woocommerce_shipment_tracking_my_orders_title
					 *
					 * @since <6.3.1>
					 */
					echo esc_attr( apply_filters( 'woocommerce_shipment_tracking_my_orders_title', __( 'TRACKING INFORMATION', 'webship-shipment-tracking' ) ) );

					echo "\n";

					foreach ( $tracking_entries as $tracking_entry ) {
						echo esc_html( $this->get_formatted_shipping_provider( $tracking_entry['tracking_provider'] ) ? $this->get_formatted_shipping_provider( $tracking_entry['tracking_provider'] ) : $tracking_entry['custom_tracking_provider'] ) . "\n";
						echo esc_html( $tracking_entry['tracking_number'] ) . "\n";
						echo esc_url( $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] ) ? $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] ) : $tracking_entry['custom_tracking_link'] ) . "\n\n";
					}

					echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-= \n\n";
				} else {
					?>
					<h2>
					<?php
					/**
					 * This is another hook
					 * woocomerce_shipment_tracking_my_orders_title
					 *
					 * @since <6.3.1>
					 */
					echo esc_attr( apply_filters( 'woocommerce_shipment_tracking_my_orders_title', __( 'Tracking Information', 'webship-shipment-tracking' ) ) );
					?>
					</h2>

						<table class="td" cellspacing="0" cellpadding="6" style="width: 100%;" border="1">

							<thead>
								<tr>
									<th class="tracking-provider" scope="col" class="td" style="text-align: left; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; color: #737373; border: 1px solid #e4e4e4; padding: 12px;"><?php echo esc_html( __( 'Provider', 'webship-shipment-tracking' ) ); ?></th>
									<th class="tracking-number" scope="col" class="td" style="text-align: left; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; color: #737373; border: 1px solid #e4e4e4; padding: 12px;"><?php echo esc_html( __( 'Tracking Number', 'webship-shipment-tracking' ) ); ?></th>
									<th class="date-shipped" scope="col" class="td" style="text-align: left; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; color: #737373; border: 1px solid #e4e4e4; padding: 12px;"><?php echo esc_html( __( 'Date', 'webship-shipment-tracking' ) ); ?></th>
									<th class="order-actions" scope="col" class="td" style="text-align: left; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; color: #737373; border: 1px solid #e4e4e4; padding: 12px;">&nbsp;</th>
								</tr>
							</thead>

							<tbody>
							<?php
							foreach ( $tracking_entries as $tracking_entry ) {

								$formatted_tracking_link = $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] ) ? $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] ) : $tracking_entry['custom_tracking_link'];
								?>
								<tr class="tracking">
									<td class="tracking-provider" data-title="<?php echo esc_html( __( 'Provider', 'webship-shipment-tracking' ) ); ?>" style="text-align: left; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; color: #737373; border: 1px solid #e4e4e4; padding: 12px;">
										<?php echo esc_html( $this->get_formatted_shipping_provider( $tracking_entry['tracking_provider'] ) ? $this->get_formatted_shipping_provider( $tracking_entry['tracking_provider'] ) : $tracking_entry['custom_tracking_provider'] ); ?>
									</td>
									<td class="tracking-number" data-title="<?php echo esc_html( __( 'Tracking Number', 'webship-shipment-tracking' ) ); ?>" style="text-align: left; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; color: #737373; border: 1px solid #e4e4e4; padding: 12px;">
										<?php echo esc_html( $tracking_entry['tracking_number'] ); ?>
									</td>
									<td class="date-shipped" data-title="<?php echo esc_html( __( 'Status', 'webship-shipment-tracking' ) ); ?>" style="text-align: left; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; color: #737373; border: 1px solid #e4e4e4; padding: 12px;">
										<time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', intval( $tracking_entry['date_shipped'] ) ) ); ?>" title="<?php echo esc_attr( gmdate( 'Y-m-d', intval( $tracking_entry['date_shipped'] ) ) ); ?>"><?php echo esc_attr( date_i18n( get_option( 'date_format' ), $tracking_entry['date_shipped'] ) ); ?></time>
									</td>
									<td class="order-actions" style="text-align: center; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; color: #737373; border: 1px solid #e4e4e4; padding: 12px;">
											<a href="<?php echo esc_url( $formatted_tracking_link ); ?>" target="_blank"><?php echo esc_html( __( 'Track', 'webship-shipment-tracking' ) ); ?></a>
									</td>
								</tr>
								<?php
							}
							?>
							</tbody>
						</table>
						<br />
						<br />
					<?php
				}
			}

			$GLOBALS['client_info']['_sentEmail'] = true;
		}
	}

	/**
	 * Adds a header to the csv that will be exported.
	 *
	 * @param string $headers - the headers for this export.
	 */
	public function add_shipment_tracking_column_header_to_csv_export( $headers ) {
		$headers['shipment_tracking'] = 'shipment_tracking';
		return $headers;
	}

	/**
	 * Description - add shipment tracking fields.
	 *
	 * @param string $order_data - data that is part of the order.
	 * @param string $order - the actual order.
	 * @param string $csv_generator - what csv generation style should be used.
	 */
	public function add_shipment_tracking_field_to_csv_export( $order_data, $order, $csv_generator ) {
		$order_id                     = is_callable( array( $order, 'get_id' ) ) ? $order->get_id() : $order->id;
		$tracking_entries             = $this->get_tracking_entries( $order_id );
		$new_order_data               = array();
		$one_row_per_item             = false;
		$shipment_tracking_csv_output = '';

		if ( count( $tracking_entries ) > 0 ) {
			foreach ( $tracking_entries as $item ) {
				$pipe = null;
				foreach ( $item as $key => $value ) {
					if ( 'date_shipped' === $key ) {
						$value = gmdate( 'Y-m-d', $value );
					}

					$shipment_tracking_csv_output .= "$pipe$key:$value";

					if ( ! $pipe ) {
						$pipe = '|';
					}
				}

				$shipment_tracking_csv_output .= ';';
			}
		}

		if ( version_compare( wc_customer_order_csv_export()->get_version(), '4.0.0', '<' ) ) {
			$one_row_per_item = ( 'default_one_row_per_item' === $csv_generator->order_format || 'legacy_one_row_per_item' === $csv_generator->order_format );
		} elseif ( isset( $csv_generator->format_definition ) ) {
			$one_row_per_item = 'item' === $csv_generator->format_definition['row_type'];
		}

		if ( $one_row_per_item ) {
			foreach ( $order_data as $data ) {
				$new_order_data[] = array_merge( (array) $data, array( 'shipment_tracking' => $shipment_tracking_csv_output ) );
			}
		} else {
			$new_order_data = array_merge( $order_data, array( 'shipment_tracking' => $shipment_tracking_csv_output ) );
		}

		return $new_order_data;
	}

	/**
	 * Description - renders the header.
	 *
	 * @param string $columns - the columns to render.
	 */
	public function render_shipment_tracking_column_header_in_order_list_view( $columns ) {
		$columns['shipment_tracking'] = __( 'Shipment Tracking', 'webship' );
		return $columns;
	}

	/**
	 * Descriptions - renders the tracking field.
	 *
	 * @param string $column - which column to render.
	 * @param string $order - WooCommerce HPOS compatible order.
	 */
	public function render_shipment_tracking_field_in_order_list_view( $column, $order = null ) {
		global $post;

		if ( 'shipment_tracking' === $column ) {
			if ( ! empty( $order ) ) {
				$order_id = $order->ID;
			} else {
				$order_id = $post->ID;
			}
			$tracking_entries = $this->get_tracking_entries( $order_id );

			if ( count( $tracking_entries ) > 0 ) {
				echo '<ul>';

				foreach ( $tracking_entries as $tracking_entry ) {
					$formatted_tracking_link =
						$this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] )
						? $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] )
						: $tracking_entry['custom_tracking_link'];
					printf(
						'<li><a href="%s" target="_blank">%s</a></li>',
						esc_url( $formatted_tracking_link ),
						esc_html( $tracking_entry['tracking_number'] )
					);
				}
				echo '</ul>';
			} else {
				echo '–';
			}
		}
	}

	/**
	 * URL: http://woocommerce.shoppingcarts2.rocksolidinternet.com/wp-admin/post.php?post=15&action=edit .
	 */
	public function add_meta_box() {
		$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';
		add_meta_box( 'webship-shipment-tracking', __( 'Shipment Tracking', 'webship-shipment-tracking' ), array( $this, 'meta_box' ), $screen, 'side', 'high' );
	}

	/**
	 * Description - delete an entry.
	 *
	 * @param string $order_id - the order id to delete.
	 * @param string $tracking_id - the tracking id to delete.
	 */
	public function delete_tracking_entry( $order_id, $tracking_id ) {
		$tracking_entries = $this->get_tracking_entries( $order_id );

		$is_deleted = false;

		if ( count( $tracking_entries ) > 0 ) {
			foreach ( $tracking_entries as $key => $item ) {
				if ( $item['tracking_id'] === $tracking_id ) {
					unset( $tracking_entries[ $key ] );
					$is_deleted = true;
					break;
				}
			}
			$this->save_tracking_entries( $order_id, $tracking_entries );
		}

		return $is_deleted;
	}

	/**
	 * Description - adda new tracking entry.
	 *
	 * @param string $order_id - the order id to add.
	 * @param string $params - the params to add to the entry.
	 * @throws \Exception - throws an exception.
	 */
	public function add_tracking_entry( $order_id, $params ) {
		$order_id = wc_clean( $order_id );
		// $error_message = RsisValidationUtils::applyValidators(
		// $params,
		// array(
		// 'tracking_provider'        => array( 'type' => 'string' ),
		// 'custom_tracking_provider' => array( 'type' => 'string' ),
		// 'custom_tracking_link'     => array( 'type' => 'string' ),
		// 'tracking_number'          => array( 'type' => 'string' ),
		// 'date_shipped'             => array(),
		// )
		// );

		// if ( $error_message ) {
		// throw new \Exception( esc_html( $error_message ) );
		// } //.

		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			$postcode = get_post_meta( $order_id, '_shipping_postcode', true );
		} else {
			$order    = new WC_Order( $order_id );
			$postcode = $order->get_shipping_postcode();
		}

		if ( empty( $postcode ) ) {
			$postcode = get_post_meta( $order_id, '_shipping_postcode', true );
		}

		$tracking_entry = array(
			'tracking_provider'        => wc_clean( $params['tracking_provider'] ),
			'custom_tracking_provider' => wc_clean( $params['custom_tracking_provider'] ),
			'custom_tracking_link'     => wc_clean( $params['custom_tracking_link'] ),
			'tracking_number'          => wc_clean( $params['tracking_number'] ),
			'date_shipped'             => wc_clean( strtotime( $params['date_shipped'] ) ),
			'postcode'                 => $postcode,
		);

		if ( $tracking_entry['custom_tracking_provider'] ) {
			$tracking_entry['tracking_id'] = md5( "{$tracking_entry['custom_tracking_provider']}-{$tracking_entry['tracking_number']}" . microtime() );
		} else {
			$tracking_entry['tracking_id'] = md5( "{$tracking_entry['tracking_provider']}-{$tracking_entry['tracking_number']}" . microtime() );
		}

		$tracking_entries   = $this->get_tracking_entries( $order_id );
		$tracking_entries[] = $tracking_entry;

		$this->save_tracking_entries( $order_id, $tracking_entries );

		return $tracking_entry;
	}

	/**
	 * Description - save some tracking entries.
	 *
	 * @param string $order_id - order_id to modify.
	 * @param string $tracking_entries - entries to save to order.
	 */
	public function save_tracking_entries( $order_id, $tracking_entries ) {
		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			update_post_meta( $order_id, '_wc_shipment_tracking_items', $tracking_entries );
		} else {
			$order = new WC_Order( $order_id );
			$order->update_meta_data( '_wc_shipment_tracking_items', $tracking_entries );
			$order->save_meta_data();
		}
	}

	/**
	 * Description - get some tracking entries.
	 *
	 * @param string $order_id - which order to retrieve.
	 */
	public function get_tracking_entries( $order_id ) {
		global $wpdb;

		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			$tracking_entries = get_post_meta( $order_id, '_wc_shipment_tracking_items', true );
		} else {
			$order            = new WC_Order( $order_id );
			$tracking_entries = $order->get_meta( '_wc_shipment_tracking_items', true );
		}

		$tracking_entries = $tracking_entries ? $tracking_entries : array();
		foreach ( $tracking_entries as $key => $tracking_entry ) {
			$tracking_entries[ $key ]['postcode']          ??= '';
			$tracking_entries[ $key ]['tracking_provider'] ??= '';
			$tracking_entries[ $key ]['tracking_number']   ??= '';
		}

		return $tracking_entries;
	}

	/**
	 * Description - Add info to http tracking entry.
	 */
	public function http_add_tracking_entry() {
		check_ajax_referer( 'create-tracking-entry', 'security', true );

		$post = wp_unslash( $_POST );
		if ( isset( $post['tracking_number'] ) && null !== wc_clean( $post['tracking_number'] ) && strlen( wc_clean( $post['tracking_number'] ) ) > 0 ) {
			$tracking_entry = $this->add_tracking_entry(
				wc_clean( $post['order_id'] ),
				array(
					'tracking_provider'        => wc_clean( $post['tracking_provider'] ),
					'custom_tracking_provider' => wc_clean( $post['custom_tracking_provider'] ),
					'custom_tracking_link'     => wc_clean( $post['custom_tracking_link'] ),
					'tracking_number'          => wc_clean( $post['tracking_number'] ),
					'date_shipped'             => wc_clean( $post['date_shipped'] ), // submitted like 2022-02-26 .
				)
			);

			$this->render_single_tracking_entry( $tracking_entry );
		}

		die();
	}

	/**
	 * Description - delete info to http tracking entry.
	 */
	public function http_delete_tracking_entry() {
		check_ajax_referer( 'delete-tracking-entry', 'security', true );
		$post        = wp_unslash( $_POST );
		$order_id    = wc_clean( $post['order_id'] );
		$tracking_id = wc_clean( $post['tracking_id'] );

		$this->delete_tracking_entry( $order_id, $tracking_id );
	}

	/**
	 * Description - render just one entry.
	 *
	 * @param string $tracking_entry - the entry used in tracking.
	 */
	public function render_single_tracking_entry( $tracking_entry ) {
		$formatted_tracking_link = esc_url( $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] ) ? $this->get_formatted_tracking_link( $tracking_entry['postcode'], $tracking_entry['tracking_provider'], $tracking_entry['tracking_number'] ) : $tracking_entry['custom_tracking_link'] );
		?>
		<div class="tracking-entry" id="tracking-entry-<?php echo esc_attr( $tracking_entry['tracking_id'] ); ?>">
			<p class="tracking-content">
				<strong><?php echo esc_html( $this->get_formatted_shipping_provider( $tracking_entry['tracking_provider'] ) ? $this->get_formatted_shipping_provider( $tracking_entry['tracking_provider'] ) : $tracking_entry['custom_tracking_provider'] ); ?></strong>
				<?php if ( strlen( $formatted_tracking_link ) > 0 ) : ?>
					- <?php printf( '<a href="%s" target="_blank" title="' . esc_html( __( 'Click here to track your shipment', 'webship-shipment-tracking' ) ) . '">' . esc_html( __( 'Track', 'webship-shipment-tracking' ) ) . '</a>', esc_url( $formatted_tracking_link ) ); ?>
				<?php endif; ?>
				<br/>
				<em><?php echo esc_html( $tracking_entry['tracking_number'] ); ?></em>
			</p>
			<p class="meta">
				<?php
				// translators: %s is getting the date in the sprintf.
				echo esc_html( sprintf( __( 'Shipped on %s', 'webship-shipment-tracking' ), date_i18n( 'Y-m-d', $tracking_entry['date_shipped'] ) ) );
				?>
				<a href="#" class="delete-tracking" rel="<?php echo esc_attr( $tracking_entry['tracking_id'] ); ?>"><?php echo esc_html( __( 'Delete', 'webship-shipment-tracking' ) ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Description - Get formatted shipping provider.
	 *
	 * @param string $tracking_provider - name of tracking provider.
	 */
	public function get_formatted_shipping_provider( $tracking_provider ) {
		foreach ( $this->get_shipping_providers() as $country => $shipping_provider_group ) {
			foreach ( $shipping_provider_group as $formatted_carrier_name => $formatted_link ) {
				if ( sanitize_title( $formatted_carrier_name ) === sanitize_title( $tracking_provider ) ) {
					return $formatted_carrier_name;
				}
			}
		}

		return $tracking_provider;
	}

	/**
	 * Description - get a formatted tracking link.
	 *
	 * @param string $postcode - the postal or zipcode.
	 * @param string $tracking_provider - the shipping provider.
	 * @param string $tracking_number - the tracking number.
	 */
	public function get_formatted_tracking_link( $postcode, $tracking_provider, $tracking_number ) {
		foreach ( $this->get_shipping_providers() as $country => $shipping_provider_group ) {
			foreach ( $shipping_provider_group as $formatted_carrier_name => $formatted_link ) {
				if ( sanitize_title( $formatted_carrier_name ) === sanitize_title( $tracking_provider ) ) {
					return sprintf( $formatted_link, $tracking_number, urlencode( $postcode ) );
				}
			}
		}
	}

	/**
	 * Description - Get a list of countries and their shipping providers
	 */
	public function get_shipping_providers() {
		return array(
			'Australia'      => array(
				'Australia Post'   => 'http://auspost.com.au/track/track.html?id=%1$s',
				'Fastway Couriers' => 'http://www.fastway.com.au/courier-services/track-your-parcel?l=%1$s',
			),
			'Austria'        => array(
				'post.at' => 'http://www.post.at/sendungsverfolgung.php?pnum1=%1$s',
				'dhl.at'  => 'http://www.dhl.at/content/at/de/express/sendungsverfolgung.html?brand=DHL&AWB=%1$s',
				'DPD.at'  => 'https://tracking.dpd.de/parcelstatus?locale=de_AT&query=%1$s',
			),
			'Brazil'         => array(
				'Correios' => 'http://websro.correios.com.br/sro_bin/txect01$.QueryList?P_LINGUA=001&P_TIPO=001&P_COD_UNI=%1$s',
			),
			'Belgium'        => array(
				'bpost' => 'http://track.bpost.be/etr/light/showSearchPage.do?oss_language=EN',
			),
			'Canada'         => array(
				'Canada Post' => 'http://www.canadapost.ca/cpotools/apps/track/personal/findByTrackNumber?trackingNumber=%1$s',
			),
			'Czech Republic' => array(
				'PPL.cz'      => 'http://www.ppl.cz/main2.aspx?cls=Package&idSearch=%1$s',
				'Česká pošta' => 'https://www.postaonline.cz/trackandtrace/-/zasilka/cislo?parcelNumbers=%1$s',
				'DHL.cz'      => 'http://www.dhl.cz/cs/express/sledovani_zasilek.html?AWB=%1$s',
				'DPD.cz'      => 'https://tracking.dpd.de/parcelstatus?locale=cs_CZ&query=%1$s',
			),
			'Finland'        => array(
				'Itella' => 'http://www.posti.fi/itemtracking/posti/search_by_shipment_id?lang=en&ShipmentId=%1$s',
			),
			'France'         => array(
				'Colissimo' => 'http://www.colissimo.fr/portail_colissimo/suivre.do?language=fr_FR&colispart=%1$s',
			),
			'Germany'        => array(
				'DHL Intraship (DE)' => 'http://nolp.dhl.de/nextt-online-public/set_identcodes.do?lang=de&idc=%1$s&rfn=&extendedSearch=true',
				'Hermes'             => 'https://tracking.hermesworld.com/?TrackID=%1$s',
				'Deutsche Post DHL'  => 'http://nolp.dhl.de/nextt-online-public/set_identcodes.do?lang=de&idc=%1$s',
				'UPS Germany'        => 'http://wwwapps.ups.com/WebTracking/processInputRequest?sort_by=status&tracknums_displayed=1&TypeOfInquiryNumber=T&loc=de_DE&InquiryNumber1=%1$s',
				'DPD.de'             => 'https://tracking.dpd.de/parcelstatus?query=%1$s&locale=en_DE',
			),
			'Ireland'        => array(
				'DPD.ie'  => 'http://www2.dpd.ie/Services/QuickTrack/tabid/222/ConsignmentID/%1$s/Default.aspx',
				'An Post' => 'https://track.anpost.ie/TrackingResults.aspx?rtt=1&items=%1$s',
			),
			'Italy'          => array(
				'BRT (Bartolini)' => 'http://as777.brt.it/vas/sped_det_show.hsm?referer=sped_numspe_par.htm&Nspediz=%1$s',
				'DHL Express'     => 'http://www.dhl.it/it/express/ricerca.html?AWB=%1$s&brand=DHL',
			),
			'India'          => array(
				'DTDC' => 'http://www.dtdc.in/dtdcTrack/Tracking/consignInfo.asp?strCnno=%1$s',
			),
			'Netherlands'    => array(
				'PostNL' => 'https://mijnpakket.postnl.nl/Claim?Barcode=%1$s&Postalcode=%2$s&Foreign=False&ShowAnonymousLayover=False&CustomerServiceClaim=False',
				'DPD.NL' => 'http://track.dpdnl.nl/?parcelnumber=%1$s',
			),
			'New Zealand'    => array(
				'Courier Post' => 'http://trackandtrace.courierpost.co.nz/Search/%1$s',
				'NZ Post'      => 'http://www.nzpost.co.nz/tools/tracking?trackid=%1$s',
				'Fastways'     => 'http://www.fastway.co.nz/courier-services/track-your-parcel?l=%1$s',
				'PBT Couriers' => 'http://www.pbt.com/nick/results.cfm?ticketNo=%1$s',
			),
			'South African'  => array(
				'SAPO' => 'http://sms.postoffice.co.za/TrackingParcels/Parcel.aspx?id=%1$s',
			),
			'Sweden'         => array(
				'PostNord Sverige AB' => 'http://www.postnord.se/sv/verktyg/sok/Sidor/spara-brev-paket-och-pall.aspx?search=%1$s',
				// 'DHL.se'            => 'http://www.dhl.se/content/se/sv/express/godssoekning.shtml?brand=DHL&AWB=%1$s',
				'Bring.se'            => 'http://tracking.bring.se/tracking.html?q=%1$s',
				'UPS.se'              => 'http://wwwapps.ups.com/WebTracking/track?track=yes&loc=sv_SE&trackNums=%1$s',
				'DB Schenker'         => 'http://privpakportal.schenker.nu/TrackAndTrace/packagesearch.aspx?packageId=%1$s',
			),
			'United Kingdom' => array(
				// 'DHL'                    => 'http://www.dhl.com/content/g0/en/express/tracking.shtml?brand=DHL&AWB=%1$s',
				'DPD.co.uk'                 => 'http://www.dpd.co.uk/tracking/trackingSearch.do?search.searchType=0&search.parcelNumber=%1$s',
				'InterLink'                 => 'http://www.interlinkexpress.com/apps/tracking/?reference=%1$s&postcode=%2$s#results',
				'ParcelForce'               => 'http://www.parcelforce.com/portal/pw/track?trackNumber=%1$s',
				'Royal Mail'                => 'https://www.royalmail.com/track-your-item/?trackNumber=%1$s',
				'TNT Express (consignment)' => 'http://www.tnt.com/webtracker/tracking.do?requestType=GEN&searchType=CON&respLang=en&respCountry=GENERIC&sourceID=1&sourceCountry=ww&cons=%1$s&navigation=1&g
enericSiteIdent=',
				'TNT Express (reference)'   => 'http://www.tnt.com/webtracker/tracking.do?requestType=GEN&searchType=REF&respLang=en&respCountry=GENERIC&sourceID=1&sourceCountry=ww&cons=%1$s&navigation=1&genericSiteIdent=',
				'UK Mail'                   => 'https://old.ukmail.com/ConsignmentStatus/ConsignmentSearchResults.aspx?SearchType=Reference&SearchString=%1$s',
			),
			'United States'  => array(
				'Asendia'       => 'http://apps.asendiausa.com/tracking/packagetracking.html?pid=%1$s',
				'Fedex'         => 'https://www.fedex.com/fedextrack/?trknbr=%1$s',
				'FedEx Sameday' => 'https://www.fedexsameday.com/fdx_dotracking_ua.aspx?tracknum=%1$s',
				'OnTrac'        => 'http://www.ontrac.com/trackingdetail.asp?tracking=%1$s',
				'UPS'           => 'http://wwwapps.ups.com/WebTracking/track?track=yes&trackNums=%1$s',
				'USPS'          => 'https://tools.usps.com/go/TrackConfirmAction_input?qtc_tLabels1=%1$s',
				'DHL'           => 'https://www.logistics.dhl/us-en/home/tracking/tracking-ecommerce.html?tracking-id=%1$s',
			),
		);
	}

	/**
	 * Description - Get a box
	 *
	 * @param string $post_or_order_object - one or the other depending on whether hpos is enabled.
	 */
	public function meta_box( $post_or_order_object ) {
		$tracking_entries = $this->get_tracking_entries( $post_or_order_object->ID );

		echo '<div id="tracking-entries">';

		if ( count( $tracking_entries ) > 0 ) {
			foreach ( $tracking_entries as $tracking_entry ) {
				$this->render_single_tracking_entry( $tracking_entry );
			}
		}

		echo wp_kses( '</div>', $GLOBALS['allowed_html'] );

		echo wp_kses( '<button class="button button-show-form" type="button">', $GLOBALS['allowed_html'] ) . esc_html( __( 'Add Tracking Number', 'webship-shipment-tracking' ) ) . wp_kses( '</button>', $GLOBALS['allowed_html'] );

		echo wp_kses( '<div id="shipment-tracking-form" style="display:none;">', $GLOBALS['allowed_html'] );
		// Providers.
		echo wp_kses( '<p class="form-field tracking_provider_field"><label for="tracking_provider">', $GLOBALS['allowed_html'] ) . esc_html( __( 'Provider:', 'webship-shipment-tracking' ) ) . wp_kses( '</label><br/><select id="tracking_provider" name="tracking_provider" class="chosen_select" style="width:100%;">', $GLOBALS['allowed_html'] );

		echo wp_kses( '<option value="">', $GLOBALS['allowed_html'] ) . esc_html( __( 'Custom Provider', 'webship-shipment-tracking' ) ) . wp_kses( '</option>', $GLOBALS['allowed_html'] );

		$selected_provider = '';

		if ( ! $selected_provider ) {
			/**
			 * Hook.
			 *
			 * @since <6.3.1>
			 */
			$selected_provider = sanitize_title( apply_filters( 'woocommerce_shipment_tracking_default_provider', '' ) );
		}

		$shipping_providers = $this->get_shipping_providers();

		foreach ( $shipping_providers as $provider_group => $providers ) {
			echo '<optgroup label="' . esc_attr( $provider_group ) . '">';
			foreach ( $providers as $provider => $url ) {
				echo '<option value="' . esc_attr( sanitize_title( $provider ) ) . '" ' . selected( sanitize_title( $provider ), $selected_provider, true ) . '>' . esc_html( $provider ) . '</option>';
			}
			echo '</optgroup>';
		}

		echo '</select> ';

		woocommerce_wp_hidden_input(
			array(
				'id'    => 'webship_shipment_tracking_delete_nonce',
				'value' => wp_create_nonce( 'delete-tracking-entry' ),
			)
		);

		woocommerce_wp_hidden_input(
			array(
				'id'    => 'webship_shipment_tracking_create_nonce',
				'value' => wp_create_nonce( 'create-tracking-entry' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => 'custom_tracking_provider',
				'label'       => __( 'Provider Name:', 'webship-shipment-tracking' ),
				'placeholder' => '',
				'description' => '',
				'value'       => '',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => 'tracking_number',
				'label'       => __( 'Tracking number:', 'webship-shipment-tracking' ),
				'placeholder' => '',
				'description' => '',
				'value'       => '',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => 'custom_tracking_link',
				'label'       => __( 'Tracking link:', 'webship-shipment-tracking' ),
				'placeholder' => 'http://',
				'description' => '',
				'value'       => '',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => 'date_shipped',
				'label'       => __( 'Date shipped:', 'webship-shipment-tracking' ),
				'placeholder' => date_i18n( __( 'Y-m-d', 'webship-shipment-tracking' ), time() ),
				'description' => '',
				'class'       => 'date-picker-field',
				'value'       => date_i18n( __( 'Y-m-d', 'webship-shipment-tracking' ), current_time( 'timestamp' ) ),
			)
		);

		echo wp_kses( '<button class="button button-primary button-save-form">', $GLOBALS['allowed_html'] ) . esc_html( __( 'Save Tracking', 'webship-shipment-tracking' ) ) . wp_kses( '</button>', $GLOBALS['allowed_html'] );

		// Live preview.
		echo wp_kses( '<p class="preview_tracking_link">', $GLOBALS['allowed_html'] ) . esc_html( __( 'Preview:', 'webship-shipment-tracking' ) ) . wp_kses( ' <a href="" target="_blank">', $GLOBALS['allowed_html'] ) . esc_html( __( 'Click here to track your shipment', 'webship-shipment-tracking' ) ) . wp_kses( '</a></p>', $GLOBALS['allowed_html'] );

		echo wp_kses( '</div>', $GLOBALS['allowed_html'] );

		$providers_js_array = array();

		foreach ( $shipping_providers as $providers ) {
			foreach ( $providers as $provider => $link ) {
				$providers_js_array[ sanitize_title( $provider ) ] = urlencode( $link );
			}
		}

		$js = "
			jQuery('p.custom_tracking_link_field, p.custom_tracking_provider_field ').hide()

			jQuery('input#custom_tracking_link, input#tracking_number, #tracking_provider').change(function() {

				var tracking  = jQuery('input#tracking_number').val()
				var provider  = jQuery('#tracking_provider').val()
				var providers = jQuery.parseJSON('" . json_encode( $providers_js_array ) . "')

				var postcode = jQuery('#_shipping_postcode').val() || jQuery('#_billing_postcode').val()

				postcode = encodeURIComponent(postcode)

				var link = ''

				if (providers[provider]) {
					link = providers[provider]
					link = link.replace('%251%24s', tracking)
					link = link.replace('%252%24s', postcode)
					link = decodeURIComponent(link)

					jQuery('p.custom_tracking_link_field, p.custom_tracking_provider_field').hide()
				}
				else {
					jQuery('p.custom_tracking_link_field, p.custom_tracking_provider_field').show()

					link = jQuery('input#custom_tracking_link').val()
				}

				if (link) {
					jQuery('p.preview_tracking_link a').attr('href', link)
					jQuery('p.preview_tracking_link').show()
				}
				else {
					jQuery('p.preview_tracking_link').hide()
				}

			} ).change()

			$('#webship-shipment-tracking')
				.on('click', 'a.delete-tracking', function() {
					var trackingId = $(this).attr('rel')

					$('#tracking-entry-' + trackingId).block({
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6
						}
					})

					var data = {
						action:	  'webship_delete_tracking_entry',
						order_id:	woocommerce_admin_meta_boxes.post_id,
						tracking_id: trackingId,
						security:	$('#webship_shipment_tracking_delete_nonce').val()
					}

					$.post(woocommerce_admin_meta_boxes.ajax_url, data, function(response) {
						$('#tracking-entry-' + trackingId).unblock()
						if (response != '-1') {
							$('#tracking-entry-' + trackingId).remove()
						}
					})

					return false
				})
				.on('click', 'button.button-show-form', function() {
					$('#shipment-tracking-form').show()
					$('#webship-shipment-tracking button.button-show-form').hide()
				})
				.on('click', 'button.button-save-form', function() {
					if (!$('input#tracking_number').val()) {
						return false
					}

					$('#shipment-tracking-form').block({
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6
						}
					})

					var data = {
						action:				   'webship_add_tracking_entry',
						order_id:				 woocommerce_admin_meta_boxes.post_id,
						tracking_provider:		$('#tracking_provider').val(),
						custom_tracking_provider: $('#custom_tracking_provider').val(),
						custom_tracking_link:	 $('input#custom_tracking_link').val(),
						tracking_number:		  $('input#tracking_number').val(),
						date_shipped:			 $('input#date_shipped').val(),
						security:				 $('#webship_shipment_tracking_create_nonce').val()
					}

					$.post( woocommerce_admin_meta_boxes.ajax_url, data, function( response ) {
						$('#shipment-tracking-form').unblock()
						if (response != '-1') {
							$('#shipment-tracking-form').hide()
							$('#webship-shipment-tracking #tracking-entries').append(response)
							$('#webship-shipment-tracking button.button-show-form').show()
							$('#tracking_provider').selectedIndex = 0
							$('#custom_tracking_provider').val('')
							$('input#custom_tracking_link').val('')
							$('input#tracking_number').val('')
							$('input#date_shipped').val('')
							$('p.preview_tracking_link').hide()
						}
					})

					return false
				})
		";

		if ( function_exists( 'wc_enqueue_js' ) ) {
			wc_enqueue_js( $js );
		} else {
			WC()->add_inline_js( $js );
		}
	}
}
