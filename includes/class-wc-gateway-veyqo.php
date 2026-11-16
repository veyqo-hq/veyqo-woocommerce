<?php
/**
 * The Veyqo payment gateway.
 *
 * Checkout creates a Veyqo payment and sends the customer to the provider's
 * page. The order is only marked paid after Veyqo confirms it, on the
 * customer's return (server-side verify) or by a signed webhook, whichever
 * comes first; the other is a harmless no-op.
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Veyqo extends WC_Payment_Gateway {

	const META_PAYMENT = '_veyqo_payment_id';

	public function __construct() {
		$this->id                 = 'veyqo';
		$this->method_title       = __( 'Veyqo', 'veyqo-woocommerce' );
		$this->method_description = __( 'Cards, bank transfer and USSD through your Paystack, Flutterwave or Stripe accounts, with Veyqo routing and failover.', 'veyqo-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_veyqo_return', array( $this, 'handle_return' ) );
		add_action( 'woocommerce_api_veyqo_webhook', array( $this, 'handle_webhook' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array( 'title' => __( 'Enable', 'veyqo-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Accept payments with Veyqo', 'veyqo-woocommerce' ), 'default' => 'no' ),
			'title'          => array( 'title' => __( 'Title', 'veyqo-woocommerce' ), 'type' => 'text', 'default' => __( 'Pay with card, transfer or USSD', 'veyqo-woocommerce' ) ),
			'description'    => array( 'title' => __( 'Description', 'veyqo-woocommerce' ), 'type' => 'textarea', 'default' => __( 'You will be taken to a secure page to pay.', 'veyqo-woocommerce' ) ),
			'testmode'       => array( 'title' => __( 'Test mode', 'veyqo-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Use the test (sandbox) key', 'veyqo-woocommerce' ), 'default' => 'yes' ),
			'test_secret_key' => array( 'title' => __( 'Test secret key', 'veyqo-woocommerce' ), 'type' => 'password', 'description' => __( 'kc_test_… from Veyqo → API keys (sandbox).', 'veyqo-woocommerce' ) ),
			'live_secret_key' => array( 'title' => __( 'Live secret key', 'veyqo-woocommerce' ), 'type' => 'password', 'description' => __( 'kc_live_… from Veyqo → API keys (live).', 'veyqo-woocommerce' ) ),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'veyqo-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'In Veyqo → Webhooks, add %s subscribed to payment.succeeded and payment.failed, then paste its signing secret here.', 'veyqo-woocommerce' ),
					'<code>' . esc_html( WC()->api_request_url( 'veyqo_webhook' ) ) . '</code>'
				),
			),
			'provider'       => array( 'title' => __( 'Provider (optional)', 'veyqo-woocommerce' ), 'type' => 'text', 'description' => __( 'Leave empty to let your Veyqo routing choose. Or a slug such as paystack.', 'veyqo-woocommerce' ) ),
		);
	}

	private function api() {
		$key = 'yes' === $this->get_option( 'testmode' ) ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'live_secret_key' );
		return new Veyqo_Api( (string) $key );
	}

	public function process_payment( $order_id ) {
		$order    = wc_get_order( $order_id );
		$attempt  = (int) $order->get_meta( '_veyqo_attempts' ) + 1;
		$order->update_meta_data( '_veyqo_attempts', $attempt );
		$order->save();

		$return_url = add_query_arg( array( 'order_id' => $order_id, 'key' => $order->get_order_key() ), WC()->api_request_url( 'veyqo_return' ) );
		$provider   = trim( (string) $this->get_option( 'provider' ) );
		$body       = array(
			'amount'       => Veyqo_Api::minor_units( $order->get_total(), $order->get_currency() ),
			'currency'     => $order->get_currency(),
			// One reference per attempt: a customer retrying checkout gets a fresh payment.
			'reference'    => 'WC-' . $order_id . '-' . $attempt,
			'description'  => sprintf( __( 'Order %s', 'veyqo-woocommerce' ), $order->get_order_number() ),
			'callback_url' => $return_url,
			'customer'     => array(
				'email' => $order->get_billing_email(),
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'phone' => $order->get_billing_phone(),
			),
			'metadata'     => array( 'wc_order_id' => (string) $order_id, 'source' => 'woocommerce' ),
		);
		if ( '' !== $provider ) {
			$body['provider'] = $provider;
		}

		$result = $this->api()->request( 'POST', '/payments', $body, 'wc-' . $order_id . '-' . $attempt );
		if ( ! $result['ok'] || empty( $result['data']['checkout_url'] ) ) {
			wc_add_notice( __( 'We could not start the payment. Please try again.', 'veyqo-woocommerce' ), 'error' );
			$order->add_order_note( 'Veyqo: payment not started. ' . ( $result['error']['message'] ?? '' ) );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( self::META_PAYMENT, $result['data']['id'] );
		$order->update_status( 'pending', __( 'Awaiting payment on the provider page (Veyqo).', 'veyqo-woocommerce' ) );
		$order->save();

		return array( 'result' => 'success', 'redirect' => $result['data']['checkout_url'] );
	}

	/** The customer is back from the provider: ask Veyqo what happened. */
	public function handle_return() {
		$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) ( $_GET['key'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$this->settle( $order );
		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
		} else {
			wc_add_notice( __( 'Your payment did not go through. You can try again.', 'veyqo-woocommerce' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
		}
		exit;
	}

	/** Signed events from Veyqo: the order is confirmed even if the customer never returns. */
	public function handle_webhook() {
		$raw   = file_get_contents( 'php://input' );
		$event = Veyqo_Api::verify_webhook(
			$raw,
			$_SERVER['HTTP_VEYQO_TIMESTAMP'] ?? '',
			$_SERVER['HTTP_VEYQO_SIGNATURE'] ?? '',
			(string) $this->get_option( 'webhook_secret' )
		);
		if ( null === $event ) {
			status_header( 400 );
			exit;
		}

		$order_id = absint( $event['data']['metadata']['wc_order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order && in_array( $event['type'] ?? '', array( 'payment.succeeded', 'payment.failed' ), true ) ) {
			$this->settle( $order );
		}
		status_header( 200 );
		exit;
	}

	/** One place decides: Veyqo's verify answer, never the redirect or the event body alone. */
	private function settle( $order ) {
		if ( $order->is_paid() ) {
			return;
		}
		$payment_id = (string) $order->get_meta( self::META_PAYMENT );
		if ( '' === $payment_id ) {
			return;
		}
		$result = $this->api()->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/verify' );
		if ( ! $result['ok'] ) {
			return;
		}
		$payment = $result['data'];
		$expected = Veyqo_Api::minor_units( $order->get_total(), $order->get_currency() );

		if ( 'succeeded' === ( $payment['status'] ?? '' ) ) {
			if ( (int) $payment['amount'] !== $expected || strtoupper( $payment['currency'] ) !== strtoupper( $order->get_currency() ) ) {
				$order->update_status( 'on-hold', __( 'Veyqo: paid amount does not match the order. Check before shipping.', 'veyqo-woocommerce' ) );
				return;
			}
			$order->payment_complete( $payment_id );
			$order->add_order_note( sprintf( 'Veyqo: paid through %s (%s).', $payment['provider'] ?? 'provider', $payment_id ) );
		} elseif ( 'failed' === ( $payment['status'] ?? '' ) && $order->has_status( 'pending' ) ) {
			$order->update_status( 'failed', 'Veyqo: ' . ( $payment['failure_message'] ?? 'payment failed' ) );
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order      = wc_get_order( $order_id );
		$payment_id = (string) $order->get_meta( self::META_PAYMENT );
		if ( '' === $payment_id ) {
			return new WP_Error( 'veyqo_no_payment', __( 'This order has no Veyqo payment.', 'veyqo-woocommerce' ) );
		}
		$body = array( 'reason' => $reason ? $reason : null );
		if ( null !== $amount ) {
			$body['amount'] = Veyqo_Api::minor_units( (float) $amount, $order->get_currency() );
		}
		$result = $this->api()->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/refunds', $body );
		if ( ! $result['ok'] ) {
			return new WP_Error( 'veyqo_refund_failed', $result['error']['message'] ?? __( 'Refund failed.', 'veyqo-woocommerce' ) );
		}
		$order->add_order_note( sprintf( 'Veyqo: refund %s requested (%s).', wc_price( $amount, array( 'currency' => $order->get_currency() ) ), $result['data']['id'] ?? '' ) );
		return true;
	}
}
