<?php
/*
Plugin Name: WooCommerce Subscriptions - Renewal Logger
Plugin URI: https://github.com/Prospress/woocommerce-subscriptions-renewal-logger
Description: Determine why automatic subscription renewal payments aren't being processed despite using an automatic gateway. Logs important data around renewal events to WooCommerce log file prefixed with 'wcs-renewal-log-'.
Author: Prospress, James Allan
Author URI:
Version: 1.0
*/
class WCS_Renewal_Logger {

	private static $logger = null;
	private static $request_from = 'scheduled';
	private static $processing_renewal_order = 0;

	public static function init() {
		add_action( 'woocommerce_scheduled_subscription_payment', __CLASS__ . '::start_scheduled_payment', -1000, 1 );
		add_action( 'woocommerce_scheduled_subscription_payment', __CLASS__ . '::end_scheduled_payment', 1000, 1 );

		add_action( 'woocommerce_generated_manual_renewal_order', __CLASS__ . '::manual_renewal_processed', 10, 1 );

		add_filter( 'wcs_renewal_order_created', __CLASS__ . '::renewal_order_created', -1000, 1 );
		add_filter( 'wcs_renewal_order_created', __CLASS__ . '::renewal_order_created_late', 1000, 1 );

		add_action( 'woocommerce_order_action_wcs_process_renewal', __CLASS__ .  '::admin_renewal_action_request', 1 );

		add_action( 'woocommerce_loaded',  __CLASS__ . '::load_logger' );
	}

	public static function start_scheduled_payment( $subscription_id ) {
		self::log( '**************' );
		self::log( 'Processing ' . self::$request_from . ' payment for: ' . $subscription_id );

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! is_object( $subscription ) ) {
			self::log( 'EXITING: ' . __METHOD__ . '() couldn\'t get subscription' );
			return;
		}

		$is_manual = $subscription->is_manual();

		self::log( 'Subscription is manual      = ' . var_export( $is_manual, true ) );

		if ( $is_manual ) {
			// add the more in-depth checks

			self::log( '--------- Is Manual... but why? -----------' );

			self::log( 'Duplicate site  = ' . var_export( WC_Subscriptions::is_duplicate_site(),true ) );
			self::log( 'Requires manual = ' . var_export( $subscription->get_requires_manual_renewal(), true ) );
			self::log( 'Payment gateway = ' . var_export( wc_get_payment_gateway_by_order( $subscription ),true ) );

			self::log( '-------------------------------------------' );
		}

		self::log( 'Subscription total          = ' . var_export( $subscription->get_total(), true ) );
		self::log( 'Subscription payment method = ' . var_export( $subscription->get_payment_method(), true ) );

		if ( ! did_action( 'woocommerce_init' ) ) {
			self::log( 'ERROR: woocommerce_init hasn\'t been called yet');
		}

		$payment_gateway_hook = 'woocommerce_scheduled_subscription_payment_' . $subscription->get_payment_method();

		self::log( 'Action hook: ' . $payment_gateway_hook . ' has hook? ' . var_export( has_action( $payment_gateway_hook ),true ) );

		add_action( 'wc_payment_gateway_' . $subscription->get_payment_method() . '_payment_processed', __CLASS__ . '::gateway_payment_complete', 10, 1 );

		add_action( $payment_gateway_hook, __CLASS__ . '::gateway_triggered', -1000, 2 );
		add_action( $payment_gateway_hook, __CLASS__ . '::gateway_triggered_after', 1000, 2 );

		add_filter( 'wc_payment_gateway_' . $subscription->get_payment_method() . '_process_payment', __CLASS__ . '::gateway_processing_payment', 1000, 2 );

		add_action( 'deleted_transient', __CLASS__ . '::deleted_transient', 10, 1 );
	}

	public static function gateway_payment_complete( $order ) {
		self::log( 'In ' . __METHOD__ . '(), processed payment on: ' . current_filter() );
		self::log( 'In ' . __METHOD__ . '(), order id: ' . $order->get_id() );
	}

	public static function end_scheduled_payment( $subscription_id ) {

		// After checks
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! is_object( $subscription ) ) {
			self::log( 'EXITING: ' . __METHOD__ . '() couldn\'t get subscription: ' . $subscription_id );
			return;
		}

		$last_order = $subscription->get_last_order( 'all' );

		if ( ! is_object( $last_order ) ) {
			self::log( 'EXITING: ' . __METHOD__ . '() couldn\'t get last order - got: ' . var_export( $last_order, true ) );
			return;
		}

		$last_order_id = $last_order->get_id();

		self::log( 'Renewal order id: ' . $last_order_id . ' Does it match?' );

		self::log( 'Renewal Order payment method id: ' . get_post_meta( $last_order_id, '_payment_method', true ) );
		self::log( 'Renewal Order payment method title: ' . get_post_meta( $last_order_id, '_payment_method_title', true ) );

		self::log( 'Ended processing scheduled payment for: ' . $subscription_id );
		self::log( '-------------------------------------------------------------' );

		remove_action( 'woocommerce_order_status_changed', __CLASS__ . '::log_order_status_changes', 10 );
		remove_filter( 'woocommerce_default_order_status', __CLASS__ . '::log_default_order_status', 0 );
		remove_filter( 'woocommerce_default_order_status', __CLASS__ . '::log_default_order_status_late', 1000 );
		remove_filter( 'wc_payment_gateway_' . $subscription->get_payment_method() . '_process_payment', __CLASS__ . '::gateway_processing_payment', 1000 );

		remove_action( 'deleted_transient', __CLASS__ . '::deleted_transient', 10 );
	}

	public static function manual_renewal_processed( $renewal_order_id ) {
		self::log( 'Manual renewal order processed: ' . $renewal_order_id );
	}

	public static function gateway_triggered( $total, $renewal_order ){
		self::log( '--------------------' );
		self::log( 'Gateway specific hook' );

		if ( ! is_object( $renewal_order ) ) {
			self::log( 'EXITING: gateway_triggered got non-object renewal order: ' . print_r( $renewal_order, true ) );
			return;
		}
		self::log( 'Renewal order ID: ' . $renewal_order->get_id() );
		self::log( 'Renewal order status before: ' . $renewal_order->get_status() );

		remove_filter( 'woocommerce_subscription_last_order', __CLASS__ . '::get_subscription_last_order', 10 );
	}

	public static function gateway_triggered_after( $total, $renewal_order ) {

		if ( ! is_object( $renewal_order ) ) {
			self::log( 'EXITING: gateway_triggered_after got non-object renewal order: ' . print_r( $renewal_order, true ) );
			return;
		}
		self::log( 'Renewal order status after: ' . $renewal_order->get_status() );
		self::log( '--------------------' );
	}

	public static function admin_renewal_action_request() {
		self::$request_from = 'admin requested renewal';
	}

	public static function renewal_order_created( $renewal_order ) {

		if ( is_object( $renewal_order ) ) {
			self::log( 'Renewal order id: ' . $renewal_order->get_id() );
			self::$processing_renewal_order = $renewal_order->get_id();
		} else {
			self::log( 'ERROR: Renewal order passed is non-object: ' . var_export( $renewal_order, true ) );
		}

		add_filter( 'woocommerce_subscription_last_order', __CLASS__ . '::get_subscription_last_order', 10, 2 );

		return $renewal_order;
	}

	public static function renewal_order_created_late( $renewal_order ) {

		if ( is_object( $renewal_order ) ) {
			self::log( 'Renewal order id (filtered): ' . $renewal_order->get_id() );
		} else {
			self::log( 'ERROR: Renewal order passed is non-object (filtered): ' . var_export( $renewal_order, true ) );
		}

		return $renewal_order;
	}

	public static function get_subscription_last_order( $last_order, $subscription ) {
		$last_order_id = null;

		if ( is_object( $last_order ) ) {
			self::log( 'Last order id (obj): ' . $last_order->get_id() );
			$last_order_id = $last_order->get_id();
		} else if ( is_numeric( $last_order ) ) {
			self::log( 'Last order id (num): ' . $last_order );
			$last_order_id = $last_order;
		} else {
			self::log( 'ERROR: Renewal order passed is non-object (filtered): ' . var_export( $renewal_order, true ) );
		}

		// if we got a last order which does not match, log what is returned by get_related_orders_query
		if ( $last_order_id != self::$processing_renewal_order ) {
			$renewal_orders = $subscription->get_related_orders_query( $subscription->get_id() );
			self::log( 'ERROR: Processing renewal order: ' . self::$processing_renewal_order . ' but got sent ' . $last_order_id . ' calling get_related_orders_query got ' . print_r( $renewal_orders, true ) );
		}

		return $last_order;
	}

	public static function deleted_transient( $transient ) {
		self::log( 'Deleting transient: ' . $transient );

		if ( wp_using_ext_object_cache() ) {
			self::log( '! Using ext object cache');
		} else {
			self::log( '! Not using ext object cache');
		}
	}

	public static function deleted_individual_transient( $transient ) {
		self::log( 'Requested transient delete: ' . $transient . ' Did it delete?' );
		self::log( 'Did it delete?');
	}

	public static function load_logger() {
		self::$logger = new WC_Logger();
	}

	private static function log( $message ) {
		if ( is_object( self::$logger ) ) {
			self::$logger->add( 'wcs-renewal-log-', $message );
		} else {
			error_log( 'FAILED TO LOG MESSAGE: ' . $message );
		}
	}
}
WCS_Renewal_Logger::init();