<?php declare( strict_types=1 );

namespace Client\Integrations\Controllers;

use Client\Exceptions\Clt_Exception;
use Client\Integrations\Exceptions\RevCat_Webhook_Product_Not_Found;
use Client\Integrations\Exceptions\RevCat_Webhook_Subscription_Not_Found;
use Client\Integrations\Exceptions\RevCat_Webhook_User_Not_Found;
use Client\WC\Controllers\Subscription_Controller;
use Client\WC\Models\Annual_Subscription;
use Client\WC\Models\Clt_Subscription;
use Client\WC\Models\Monthly_Subscription;
use WC_Subscriptions_Product;
use WP_User;

class RevenueCat_Controller {

	// Product IDs.
	public const MONTHLY_PRODUCT_ID = 'com.client.clientmobile.monthlyplan';
	public const ANNUAL_PRODUCT_ID  = 'com.client.clientmobile.annualplan';

	// Events.
	// @see https://www.revenuecat.com/docs/webhooks#sample-webhook-events
	public const EVENT_INITIAL_PURCHASE = 'INITIAL_PURCHASE';
	public const EVENT_BILLING_ISSUE    = 'BILLING_ISSUE';
	public const EVENT_RENEWAL          = 'RENEWAL';
	public const EVENT_PRODUCT_CHANGE   = 'PRODUCT_CHANGE';
	public const EVENT_CANCELLATION     = 'CANCELLATION';
	public const EVENT_UNCANCELLATION   = 'UNCANCELLATION';
	public const EVENT_PAUSED           = 'SUBSCRIPTION_PAUSED';

	// Meta.
	public const CREATED_WITH_RC_EVT_PAYLOAD = 'created_with_rc_event';
	public const CREATED_WITH_RC_EVT_ID      = 'created_with_rc_id';
	public const CANCELLED_WITH_RC_EVT_ID    = 'cancelled_with_rc_id';
	public const HELD_WITH_RC_EVT_ID         = 'held_with_rc_id';
	public const RENEWED_WITH_RC_EVT_ID      = 'renewed_with_rc_id';
	public const UPDATED_WITH_RC_EVT_ID      = 'updated_with_rc_id';

	private Subscription_Controller $controller;

	/**
	 * @param \Client\WC\Controllers\Subscription_Controller $controller
	 */
	public function __construct( Subscription_Controller $controller ) {
		$this->controller = $controller;
	}

	/**
	 * Receives the webhook and updates the subscription.
	 *
	 * @param array $event
	 *
	 * @return void
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_User_Not_Found
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_Product_Not_Found
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_Subscription_Not_Found
	 */
	public function receive_webhook( array $event ): void {
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wcs_create_subscription' ) || ! class_exists( 'WC_Subscriptions_Product' ) ) {
			throw new Clt_Exception( 'WooCommerce or WooCommerce Subscriptions not installed' );
		}

		// Route by event type.
		switch ( $event['type'] ) {
			case self::EVENT_INITIAL_PURCHASE:
				$this->create_subscription( $event );
				break;
			case self::EVENT_CANCELLATION:
				$this->cancel_subscription( $event );
				break;
			case self::EVENT_RENEWAL:
				$this->renew_subscription( $event );
				break;
			case self::EVENT_PRODUCT_CHANGE:
				$this->change_subscription( $event );
				break;
			case self::EVENT_BILLING_ISSUE:
			case self::EVENT_PAUSED:
				$this->hold_subscription( $event );
				break;
			case self::EVENT_UNCANCELLATION:
				$this->uncancel_subscription( $event );
				break;
			default:
				throw new Clt_Exception( sprintf( "Unknown event type: %s", $event['type'] ) );
		}
	}

	/**
	 * Gets a user by their email address.
	 *
	 * @param string $email
	 *
	 * @return \WP_User|null
	 */
	private function get_user_by_email( string $email ): ?WP_User {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			$user = get_user_by( 'login', $email );
		}

		return $user ?: null;
	}

	/**
	 * Gets the Clt subscription product based on the RevCat product ID.
	 *
	 * @param string $identifier
	 *
	 * @return \Client\WC\Models\Clt_Subscription|null
	 */
	private function get_sub_product_by_revcat_name( string $identifier ): ?Clt_Subscription {
		if ( self::ANNUAL_PRODUCT_ID === $identifier ) {
			return $this->controller->get_sub_product( Annual_Subscription::NAME );
		}
		if ( self::MONTHLY_PRODUCT_ID === $identifier ) {
			return $this->controller->get_sub_product( Monthly_Subscription::NAME );
		}

		return null;
	}

	/**
	 * Creates a subscription for a user from the RevCat webhook.
	 *
	 * @param array $event The RevCat event v1.0.
	 *
	 * @return void
	 * @url https://www.revenuecat.com/docs/webhooks
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_User_Not_Found|\Client\Integrations\Exceptions\RevCat_Webhook_Product_Not_Found
	 * @throws \Exception
	 */
	private function create_subscription( array $event ): void {
		if ( empty( $event['app_user_id'] ) ) {
			throw new Clt_Exception( 'No email address in subscriber attributes' );
		}
		// Get user.
		$user = $this->get_user_by_email( sanitize_email( $event['app_user_id'] ) );

		if ( ! $user ) {
			throw new RevCat_Webhook_User_Not_Found();
		}

		// Get product.
		$product = $this->get_sub_product_by_revcat_name( $event['product_id'] );

		if ( ! $product ) {
			throw new RevCat_Webhook_Product_Not_Found();
		}

		// Create a WooCommerce subscription order.
		$order = wc_create_order( [
			'customer_id' => $user->ID,
		] );

		// Add meta to the order to quickly identify it as a RevenueCat order.
		update_post_meta( $order->get_id(), self::CREATED_WITH_RC_EVT_ID, $event['id'] );
		update_post_meta( $order->get_id(), self::CREATED_WITH_RC_EVT_PAYLOAD, $event );

		$sub = wcs_create_subscription( [
			'order_id'         => $order->get_id(),
			'status'           => 'pending',
			'billing_period'   => WC_Subscriptions_Product::get_period( $product->get_product() ),
			'billing_interval' => WC_Subscriptions_Product::get_interval( $product->get_product() ),
		] );

		if ( is_wp_error( $sub ) ) {
			throw new Clt_Exception( sprintf( "Error creating subscription: %s", $sub->get_error_message() ) );
		}

		// Add product to subscription
		$sub->add_product( $product->get_product() );

		// Update dates.
		$dates = [
			'start' => gmdate( 'Y-m-d H:i:s', $event['purchased_at_ms'] / 1000 ),
			'end'   => gmdate( 'Y-m-d H:i:s', $event['expiration_at_ms'] / 1000 ),
		];

		$sub->update_dates( $dates );
		$sub->update_meta_data( '_created_via', 'revenuecat' );
		$sub->calculate_totals();

		// Add note with event ID.
		$order->update_status( 'completed', sprintf( __( "Added a subscription through RevCat event ID%s", 'client' ), $event['id'] ), true );

		// Also update subscription status to active from pending (and add note)
		$sub->update_status( 'active' );

		// Last step here, otherwise this is overwritten.
		$sub->set_date_paid( $dates['start'] );
	}

	/**
	 * Cancels a RevCat subscription.
	 *
	 * @param array $event The RevCat event v1.0.
	 *
	 * @return void
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_Subscription_Not_Found
	 */
	private function cancel_subscription( array $event ): void {
		// Get subscriptions by user email.
		$subscriptions = wcs_get_subscriptions( [
			'customer_email' => $event['app_user_id'],
			'status'         => 'active',
		] );

		if ( empty( $subscriptions ) ) {
			throw new RevCat_Webhook_Subscription_Not_Found();
		}

		$subscription = array_values( $subscriptions )[0];
		$subscription->update_status( 'cancelled' );
		update_post_meta( $subscription->get_id(), self::CANCELLED_WITH_RC_EVT_ID, $event['id'] );
	}

	/**
	 * Sets a subscription to on-hold.
	 *
	 * @param array $event
	 *
	 * @return void
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_Subscription_Not_Found
	 */
	private function hold_subscription( array $event ): void {
		// Get subscriptions by user email.
		$subscriptions = wcs_get_subscriptions( [
			'customer_email' => $event['app_user_id'],
			'status'         => 'active',
		] );

		if ( empty( $subscriptions ) ) {
			throw new RevCat_Webhook_Subscription_Not_Found();
		}

		// Renew subscription.
		$subscription = array_values( $subscriptions )[0];
		$subscription->update_status( 'on-hold' );
		update_post_meta( $subscription->get_id(), self::HELD_WITH_RC_EVT_ID, $event['id'] );
	}

	/**
	 * Renew a subscription (extend the end date).
	 *
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_Subscription_Not_Found
	 */
	private function renew_subscription( array $event ): void {
		// Get subscriptions by user email.
		$subscriptions = wcs_get_subscriptions( [
			'customer_email' => $event['app_user_id'],
			'status'         => 'active',
		] );

		if ( empty( $subscriptions ) ) {
			throw new RevCat_Webhook_Subscription_Not_Found();
		}

		// Renew subscription.
		$subscription = array_values( $subscriptions )[0];
		$subscription->update_dates( [
			'end' => gmdate( 'Y-m-d H:i:s', $event['expiration_at_ms'] / 1000 ),
		] );
		// Ensure it's active.
		$subscription->update_status( 'active' );

		update_post_meta( $subscription->get_id(), self::RENEWED_WITH_RC_EVT_ID, $event['id'] );
	}

	/**
	 * Change the product on a subscription.
	 *
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_Subscription_Not_Found
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_Product_Not_Found
	 * @throws \Exception
	 */
	private function change_subscription( array $event ): void {
		// Get subscriptions by user email.
		$subscriptions = wcs_get_subscriptions( [
			'customer_email' => $event['app_user_id'],
			'status'         => 'active',
		] );

		if ( empty( $subscriptions ) ) {
			throw new RevCat_Webhook_Subscription_Not_Found();
		}

		// Get product.
		$product = $this->get_sub_product_by_revcat_name( $event['product_id'] );

		if ( empty( $product ) ) {
			throw new RevCat_Webhook_Product_Not_Found();
		}

		// Change product subscription.
		$subscription = array_values( $subscriptions )[0];

		// Delete old sub item.
		$order = wc_get_order( $subscription->get_id() );
		foreach ( $order->get_items() as $item_id => $item ) { //phpcs:ignore
			wc_delete_order_item( $item_id );
		}

		$subscription->add_product( $product->get_product(), 1 );

		// Update dates.
		$dates = [
			'end' => gmdate( 'Y-m-d H:i:s', $event['expiration_at_ms'] / 1000 ),
		];

		$subscription->update_dates( $dates );
		$subscription->calculate_totals();

		// Add note with event ID.
		$order->add_order_note( sprintf( __( "Update product on a subscription through RevCat event ID%s", 'client' ), $event['id'] ) );

		update_post_meta( $subscription->get_id(), self::UPDATED_WITH_RC_EVT_ID, $event['id'] );
	}

	/**
	 * Uncancel an existing subscription.
	 *
	 * Since WCS doesn't support transitioning a sub from cancelled to active, we need to create a new one.
	 *
	 * @param array $event
	 *
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_Product_Not_Found
	 * @throws \Client\Integrations\Exceptions\RevCat_Webhook_User_Not_Found
	 */
	private function uncancel_subscription( array $event ): void {
		$this->create_subscription( $event );
	}
}
