<?php
/**
 * Plugin Name: WooCommerce Subscriptions - Renewal Logger
 * Plugin URI: https://github.com/woocommerce/woocommerce-subscriptions-renewal-logger
 * Description: Logs diagnostic data around subscription renewal events to WooCommerce > Status > Logs (prefixed 'wcs-renewal-log').
 * Version: 2.0.0
 * Author: WooCommerce
 * Author URI: https://woocommerce.com
 * License: GPLv3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * GitHub Plugin URI: woocommerce/woocommerce-subscriptions-renewal-logger
 * GitHub Branch: master
 *
 * Copyright 2017 Prospress, Inc.  (email : freedoms@prospress.com)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package WooCommerce Subscriptions Renewal Logger
 * @author  WooCommerce
 * @since   1.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-pp-dependencies.php';

if ( false === PP_Dependencies::is_subscriptions_active( '2.3' ) ) {
	PP_Dependencies::enqueue_admin_notice( 'WooCommerce Subscriptions - Renewal Logger', 'WooCommerce Subscriptions', '2.3' );
	return;
}

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

class WCS_Renewal_Logger {

	/**
	 * Log source identifier used by WC_Logger.
	 *
	 * @var string
	 */
	const LOG_SOURCE = 'wcs-renewal-log';

	/**
	 * @var string
	 */
	private static $request_from = 'scheduled';

	/**
	 * @var int
	 */
	private static $processing_renewal_order = 0;

	/**
	 * Register hooks.
	 *
	 * @since 1.0
	 */
	public static function init() {
		add_action( 'woocommerce_scheduled_subscription_payment', __CLASS__ . '::start_scheduled_payment', -1000, 1 );
		add_action( 'woocommerce_scheduled_subscription_payment', __CLASS__ . '::end_scheduled_payment', 1000, 1 );

		add_action( 'woocommerce_generated_manual_renewal_order', __CLASS__ . '::manual_renewal_processed', 10, 1 );

		add_filter( 'wcs_renewal_order_created', __CLASS__ . '::renewal_order_created', -1000, 2 );
		add_filter( 'wcs_renewal_order_created', __CLASS__ . '::renewal_order_created_late', 1000, 2 );

		add_action( 'woocommerce_order_action_wcs_process_renewal', __CLASS__ . '::admin_renewal_action_request', 1 );
	}

	/**
	 * Log environment and subscription data at the start of a scheduled payment.
	 *
	 * @since 1.0
	 *
	 * @param int $subscription_id The subscription ID being renewed.
	 */
	public static function start_scheduled_payment( $subscription_id ) {
		self::log( '**************' );
		self::log( 'Processing ' . self::$request_from . ' payment for: ' . $subscription_id );
		self::log_hpos_environment();

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof WC_Subscription ) {
			self::log( 'EXITING: ' . __METHOD__ . '() could not get subscription' );
			return;
		}

		$is_manual = $subscription->is_manual();

		self::log( 'Subscription is manual      = ' . ( $is_manual ? 'yes' : 'no' ) );

		if ( $is_manual ) {
			self::log( '--------- Is Manual... but why? -----------' );

			self::log( 'Duplicate site  = ' . ( WC_Subscriptions::is_duplicate_site() ? 'yes' : 'no' ) );
			self::log( 'Requires manual = ' . ( $subscription->get_requires_manual_renewal() ? 'yes' : 'no' ) );

			$gateway = wc_get_payment_gateway_by_order( $subscription );
			self::log( 'Payment gateway = ' . ( $gateway ? get_class( $gateway ) . ' (' . $gateway->id . ')' : 'none' ) );

			self::log( '-------------------------------------------' );
		}

		self::log( 'Subscription total          = ' . $subscription->get_total() );
		self::log( 'Subscription payment method = ' . $subscription->get_payment_method() );
		self::log( 'Subscription customer_id    = ' . $subscription->get_customer_id() );
		self::log( 'Subscription billing email  = ' . $subscription->get_billing_email() );

		if ( ! did_action( 'woocommerce_init' ) ) {
			self::log( 'ERROR: woocommerce_init has not been called yet' );
		}

		$payment_gateway_hook = 'woocommerce_scheduled_subscription_payment_' . $subscription->get_payment_method();

		self::log( 'Action hook: ' . $payment_gateway_hook . ' has hook? ' . ( has_action( $payment_gateway_hook ) ? 'yes' : 'no' ) );

		WC()->payment_gateways();

		self::log( 'NOW Action hook: ' . $payment_gateway_hook . ' has hook? ' . ( has_action( $payment_gateway_hook ) ? 'yes' : 'no' ) );

		add_action( 'wc_payment_gateway_' . $subscription->get_payment_method() . '_payment_processed', __CLASS__ . '::gateway_payment_complete', 10, 1 );

		add_action( $payment_gateway_hook, __CLASS__ . '::gateway_triggered', -1000, 2 );
		add_action( $payment_gateway_hook, __CLASS__ . '::gateway_triggered_after', 1000, 2 );

		add_action( 'deleted_transient', __CLASS__ . '::deleted_transient', 10, 1 );
	}

	/**
	 * Log when the gateway fires its payment complete action.
	 *
	 * @since 1.0
	 *
	 * @param WC_Order $order The renewal order.
	 */
	public static function gateway_payment_complete( $order ) {
		self::log( 'In ' . __METHOD__ . '(), processed payment on: ' . current_filter() );
		self::log( 'In ' . __METHOD__ . '(), order id: ' . $order->get_id() );
	}

	/**
	 * Log renewal order data at the end of a scheduled payment.
	 *
	 * @since 1.0
	 *
	 * @param int $subscription_id The subscription ID that was renewed.
	 */
	public static function end_scheduled_payment( $subscription_id ) {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof WC_Subscription ) {
			self::log( 'EXITING: ' . __METHOD__ . '() could not get subscription: ' . $subscription_id );
			return;
		}

		$last_order = $subscription->get_last_order( 'all' );

		if ( ! $last_order instanceof WC_Order ) {
			self::log( 'EXITING: ' . __METHOD__ . '() could not get last order - got: ' . gettype( $last_order ) );
			return;
		}

		$last_order_id = $last_order->get_id();

		self::log( 'Renewal order id: ' . $last_order_id . ' Does it match?' );
		self::log( 'Renewal order payment method: ' . $last_order->get_payment_method() );
		self::log( 'Renewal order payment method title: ' . $last_order->get_payment_method_title() );
		self::log( 'Renewal order customer_id: ' . $last_order->get_customer_id() );
		self::log( 'Renewal order billing email: ' . $last_order->get_billing_email() );

		if ( 0 === $last_order->get_customer_id() && 0 !== $subscription->get_customer_id() ) {
			self::log( 'WARNING: Renewal order has customer_id=0 but subscription has customer_id=' . $subscription->get_customer_id() . '. Possible HPOS sync data corruption.' );
		}

		self::log( 'Ended processing scheduled payment for: ' . $subscription_id );
		self::log( '-------------------------------------------------------------' );

		remove_action( 'deleted_transient', __CLASS__ . '::deleted_transient', 10 );
	}

	/**
	 * Log when a manual renewal order is generated.
	 *
	 * @since 1.0
	 *
	 * @param int $renewal_order_id The renewal order ID.
	 */
	public static function manual_renewal_processed( $renewal_order_id ) {
		self::log( 'Manual renewal order processed: ' . $renewal_order_id );
	}

	/**
	 * Log renewal order data before the gateway processes payment.
	 *
	 * @since 1.0
	 *
	 * @param float    $total         The renewal total.
	 * @param WC_Order $renewal_order The renewal order.
	 */
	public static function gateway_triggered( $total, $renewal_order ) {
		self::log( '--------------------' );
		self::log( 'Gateway specific hook' );

		if ( ! $renewal_order instanceof WC_Order ) {
			self::log( 'EXITING: gateway_triggered got non-object renewal order: ' . gettype( $renewal_order ) );
			return;
		}
		self::log( 'Renewal order ID: ' . $renewal_order->get_id() );
		self::log( 'Renewal order status before: ' . $renewal_order->get_status() );
		self::log( 'Renewal order customer_id before gateway: ' . $renewal_order->get_customer_id() );

		remove_filter( 'woocommerce_subscription_last_order', __CLASS__ . '::get_subscription_last_order', 10 );
	}

	/**
	 * Log renewal order data after the gateway processes payment.
	 *
	 * @since 1.0
	 *
	 * @param float    $total         The renewal total.
	 * @param WC_Order $renewal_order The renewal order.
	 */
	public static function gateway_triggered_after( $total, $renewal_order ) {

		if ( ! $renewal_order instanceof WC_Order ) {
			self::log( 'EXITING: gateway_triggered_after got non-object renewal order: ' . gettype( $renewal_order ) );
			return;
		}
		self::log( 'Renewal order status after: ' . $renewal_order->get_status() );
		self::log( 'Renewal order customer_id after gateway: ' . $renewal_order->get_customer_id() );
		self::log( '--------------------' );
	}

	/**
	 * Mark the request as admin-initiated.
	 *
	 * @since 1.0
	 */
	public static function admin_renewal_action_request() {
		self::$request_from = 'admin requested renewal';
	}

	/**
	 * Log the renewal order immediately after creation (early priority).
	 *
	 * Hooked at priority -1000 on wcs_renewal_order_created.
	 *
	 * @since 1.0
	 *
	 * @param WC_Order        $renewal_order The newly created renewal order.
	 * @param WC_Subscription $subscription  The parent subscription.
	 * @return WC_Order
	 */
	public static function renewal_order_created( $renewal_order, $subscription ) {

		if ( $renewal_order instanceof WC_Order ) {
			self::log( 'Renewal order id: ' . $renewal_order->get_id() );
			self::log( 'Renewal order customer_id at creation: ' . $renewal_order->get_customer_id() );
			self::$processing_renewal_order = $renewal_order->get_id();

			if ( $subscription instanceof WC_Subscription ) {
				self::log( 'Parent subscription customer_id: ' . $subscription->get_customer_id() );

				if ( $renewal_order->get_customer_id() !== $subscription->get_customer_id() ) {
					self::log( 'WARNING: customer_id mismatch at creation. Renewal=' . $renewal_order->get_customer_id() . ' Subscription=' . $subscription->get_customer_id() );
				}
			}
		} else {
			self::log( 'ERROR: Renewal order passed is non-object: ' . gettype( $renewal_order ) );
		}

		add_filter( 'woocommerce_subscription_last_order', __CLASS__ . '::get_subscription_last_order', 10, 2 );

		return $renewal_order;
	}

	/**
	 * Log the renewal order after all filters have run (late priority).
	 *
	 * Hooked at priority 1000 on wcs_renewal_order_created.
	 *
	 * @since 1.0
	 *
	 * @param WC_Order        $renewal_order The renewal order after all filters.
	 * @param WC_Subscription $subscription  The parent subscription.
	 * @return WC_Order
	 */
	public static function renewal_order_created_late( $renewal_order, $subscription ) {

		if ( $renewal_order instanceof WC_Order ) {
			self::log( 'Renewal order id (filtered): ' . $renewal_order->get_id() );
			self::log( 'Renewal order customer_id (filtered): ' . $renewal_order->get_customer_id() );
		} else {
			self::log( 'ERROR: Renewal order passed is non-object (filtered): ' . gettype( $renewal_order ) );
		}

		return $renewal_order;
	}

	/**
	 * Log last order mismatches that could indicate a caching or relationship issue.
	 *
	 * @since 1.0
	 *
	 * @param WC_Order|int    $last_order   The last order object or ID.
	 * @param WC_Subscription $subscription The subscription.
	 * @return WC_Order|int
	 */
	public static function get_subscription_last_order( $last_order, $subscription ) {
		$last_order_id = null;

		if ( is_object( $last_order ) ) {
			self::log( 'Last order id (obj): ' . $last_order->get_id() );
			$last_order_id = $last_order->get_id();
		} elseif ( is_numeric( $last_order ) ) {
			self::log( 'Last order id (num): ' . $last_order );
			$last_order_id = $last_order;
		} else {
			self::log( 'ERROR: Last order is unexpected type: ' . gettype( $last_order ) );
		}

		if ( null !== $last_order_id && $last_order_id != self::$processing_renewal_order ) {
			$renewal_order_ids = WCS_Related_Order_Store::instance()->get_related_order_ids( $subscription, 'renewal' );
			self::log( 'ERROR: Processing renewal order: ' . self::$processing_renewal_order . ' but got sent ' . $last_order_id . ' calling WCS_Related_Order_Store::instance()->get_related_order_ids() got ' . wp_json_encode( $renewal_order_ids ) );
		}

		return $last_order;
	}

	/**
	 * Log transient deletions during renewal processing.
	 *
	 * @since 1.0
	 *
	 * @param string $transient The transient name.
	 */
	public static function deleted_transient( $transient ) {
		self::log( 'Deleting transient: ' . $transient );

		if ( wp_using_ext_object_cache() ) {
			self::log( '! Using ext object cache' );
		} else {
			self::log( '! Not using ext object cache' );
		}
	}

	/**
	 * Log the HPOS and sync environment at the start of each renewal.
	 *
	 * @since 2.0.0
	 */
	private static function log_hpos_environment() {
		$hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$sync_enabled = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' );

		self::log( 'HPOS enabled: ' . ( $hpos_enabled ? 'yes' : 'no' ) );
		self::log( 'HPOS sync (compatibility mode) enabled: ' . ( $sync_enabled ? 'yes' : 'no' ) );
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * @since 1.0
	 *
	 * @param string $message The message to log.
	 */
	private static function log( $message ) {
		$logger = wc_get_logger();
		$logger->info( $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
WCS_Renewal_Logger::init();
