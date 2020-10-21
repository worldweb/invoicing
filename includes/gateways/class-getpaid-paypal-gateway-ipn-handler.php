<?php
/**
 * Paypal payment gateway IPN handler
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paypal Payment Gateway IPN handler class.
 *
 */
class GetPaid_Paypal_Gateway_IPN_Handler {

	/**
	 * Payment method id.
	 *
	 * @var string
	 */
	protected $id = 'paypal';

	/**
	 * Payment method object.
	 *
	 * @var GetPaid_Paypal_Gateway
	 */
	protected $gateway;

	/**
	 * Class constructor.
	 *
	 * @param GetPaid_Paypal_Gateway $gateway
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
		$this->verify_ipn();
	}

	/**
	 * Processes ipns and marks payments as complete.
	 *
	 * @return void
	 */
	public function verify_ipn() {

		wpinv_error_log( 'GetPaid PayPal IPN Handler' );

		// Validate the IPN.
		if ( empty( $_POST ) || ! $this->validate_ipn() ) {
			wp_die( 'PayPal IPN Request Failure', 500 );
		}

		// Process the IPN.
		$posted  = wp_unslash( $_POST );
		$invoice = $this->get_ipn_invoice( $posted );

		// Abort if it was not paid by our gateway.
		if ( $this->id != $invoice->get_gateway() ) {
			wpinv_error_log( 'Aborting, Invoice was not paid via PayPal' );
			wp_die( 'Invoice not paid via PayPal', 500 );
		}

		$posted['payment_status'] = isset( $posted['payment_status'] ) ? sanitize_key( strtolower( $posted['payment_status'] ) ) : '';
		$posted['txn_type']       = sanitize_key( strtolower( $posted['txn_type'] ) );

		wpinv_error_log( 'Payment status:' . $posted['payment_status'] );
		wpinv_error_log( 'IPN Type:' . $posted['txn_type'] );

		if ( method_exists( $this, 'ipn_txn_' . $posted['txn_type'] ) ) {
			call_user_func( array( $this, 'ipn_txn_' . $posted['txn_type'] ), $invoice, $posted );
			wpinv_error_log( 'Done processing IPN' );
			wp_die( 'Processed', 200 );
		}

		wpinv_error_log( 'Aborting, Unsupported IPN type:' . $posted['txn_type'] );
		wp_die( 'Unsupported IPN type', 200 );

	}

	/**
	 * Retrieves IPN Invoice.
	 *
	 * @param array $posted
	 * @return WPInv_Invoice
	 */
	protected function get_ipn_invoice( $posted ) {

		wpinv_error_log( 'Retrieving PayPal IPN Response Invoice' );

		if ( ! empty( $posted['custom'] ) ) {
			$invoice = new WPInv_Invoice( $posted['custom'] );

			if ( $invoice->exists() ) {
				wpinv_error_log( 'Found invoice #' . $invoice->get_number() );
				return $invoice;
			}

		}

		wpinv_error_log( 'Could not retrieve the associated invoice.' );
		wp_die( 'Could not retrieve the associated invoice.', 500 );
	}

	/**
	 * Check PayPal IPN validity.
	 */
	protected function validate_ipn() {

		wpinv_error_log( 'Validating PayPal IPN response' );

		// Retrieve the associated invoice.
		$posted  = wp_unslash( $_POST );
		$invoice = $this->get_ipn_invoice( $posted );

		if ( $this->gateway->is_sandbox( $invoice ) ) {
			wpinv_error_log( $posted, 'Invoice was processed in sandbox hence logging the posted data' );
		}

		// Validate the IPN.
		$posted['cmd'] = '_notify-validate';

		// Send back post vars to paypal.
		$params = array(
			'body'        => $posted,
			'timeout'     => 60,
			'httpversion' => '1.1',
			'compress'    => false,
			'decompress'  => false,
			'user-agent'  => 'GetPaid/' . WPINV_VERSION,
		);

		// Post back to get a response.
		$response = wp_safe_remote_post( $this->gateway->is_sandbox( $invoice ) ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr', $params );

		// Check to see if the request was valid.
		if ( ! is_wp_error( $response ) && $response['response']['code'] < 300 && strstr( $response['body'], 'VERIFIED' ) ) {
			wpinv_error_log( $response['body'], 'Received valid response from PayPal IPN' );
			return true;
		}

		if ( is_wp_error( $response ) ) {
			wpinv_error_log( $response->get_error_message(), 'Received invalid response from PayPal IPN' );
			return false;
		}

		wpinv_error_log( $response['body'], 'Received invalid response from PayPal IPN' );
		return false;

	}

	/**
	 * Check currency from IPN matches the invoice.
	 *
	 * @param WPInv_Invoice $invoice          Invoice object.
	 * @param string   $currency currency to validate.
	 */
	protected function validate_ipn_currency( $invoice, $currency ) {

		if ( strtolower( $invoice->get_currency() ) !== strtolower( $currency ) ) {

			/* translators: %s: currency code. */
			$invoice->update_status( 'wpi-processing', sprintf( __( 'Validation error: PayPal currencies do not match (code %s).', 'invoicing' ), $currency ) );

			wpinv_error_log( "Currencies do not match: {$currency} instead of {$invoice->get_currency()}", 'IPN Error', __FILE__, __LINE__, true );
		}

		wpinv_error_log( $currency, 'Validated IPN Currency' );
	}

	/**
	 * Check payment amount from IPN matches the invoice.
	 *
	 * @param WPInv_Invoice $invoice          Invoice object.
	 * @param float   $amount amount to validate.
	 */
	protected function validate_ipn_amount( $invoice, $amount ) {
		if ( number_format( $invoice->get_total(), 2, '.', '' ) !== number_format( $amount, 2, '.', '' ) ) {

			/* translators: %s: Amount. */
			$invoice->update_status( 'wpi-processing', sprintf( __( 'Validation error: PayPal amounts do not match (gross %s).', 'invoicing' ), $amount ) );

			wpinv_error_log( "Amounts do not match: {$amount} instead of {$invoice->get_total()}", 'IPN Error', __FILE__, __LINE__, true );
		}

		wpinv_error_log( $amount, 'Validated IPN Amount' );
	}

	/**
	 * Verify receiver email from PayPal.
	 *
	 * @param WPInv_Invoice $invoice          Invoice object.
	 * @param string   $receiver_email Email to validate.
	 */
	protected function validate_ipn_receiver_email( $invoice, $receiver_email ) {
		$paypal_email = wpinv_get_option( 'paypal_email' );

		if ( strcasecmp( trim( $receiver_email ), trim( $paypal_email ) ) !== 0 ) {
			wpinv_record_gateway_error( 'IPN Error', "IPN Response is for another account: {$receiver_email}. Your email is {$paypal_email}" );

			/* translators: %s: email address . */
			$invoice->update_status( 'wpi-processing', sprintf( __( 'Validation error: PayPal IPN response from a different email address (%s).', 'invoicing' ), $receiver_email ) );

			return wpinv_error_log( "IPN Response is for another account: {$receiver_email}. Your email is {$paypal_email}", 'IPN Error', __FILE__, __LINE__, true );
		}

		wpinv_error_log( 'Validated PayPal Email' );
	}

	/**
	 * Handles one time payments.
	 *
	 * @param WPInv_Invoice $invoice  Invoice object.
	 * @param array    $posted Posted data.
	 */
	protected function ipn_txn_web_accept( $invoice, $posted ) {

		// Collect payment details
		$payment_status = strtolower( $posted['payment_status'] );
		$business_email = isset( $posted['business'] ) && is_email( $posted['business'] ) ? trim( $posted['business'] ) : trim( $posted['receiver_email'] );

		$this->validate_ipn_receiver_email( $invoice, $business_email );
		$this->validate_ipn_currency( $invoice, $posted['mc_currency'] );

		// Update the transaction id.
		if ( ! empty( $posted['txn_id'] ) ) {
			$invoice->set_transaction_id( wpinv_clean( $posted['txn_id'] ) );
			$invoice->save();
		}

		// Process a refund.
		if ( $payment_status == 'refunded' || $payment_status == 'reversed' ) {

			update_post_meta( $invoice->get_id(), 'refunded_remotely', 1 );

			if ( ! $invoice->is_refunded() ) {
				$invoice->update_status( 'wpi-refunded', $posted['reason_code'] );
			}

			return wpinv_error_log( $posted['reason_code'] );
		}

		// Process payments.
		if ( $payment_status == 'completed' ) {

			if ( $invoice->is_paid() && 'wpi_processing' != $invoice->get_status() ) {
				return wpinv_error_log( 'Aborting, Invoice #' . $invoice->get_number() . ' is already paid.' );
			}

			$this->validate_ipn_amount( $invoice, $posted['mc_gross'] );

			$note = '';

			if ( ! empty( $posted['mc_fee'] ) ) {
				$note = sprintf( __( 'PayPal Transaction Fee %.', 'invoicing' ), sanitize_text_field( $posted['mc_fee'] ) );
			}

			if ( ! empty( $posted['payer_status'] ) ) {
				$note = ' ' . sprintf( __( 'Buyer status %.', 'invoicing' ), sanitize_text_field( $posted['payer_status'] ) );
			}

			$invoice->mark_paid( ( ! empty( $posted['txn_id'] ) ? sanitize_text_field( $posted['txn_id'] ) : '' ), trim( $note ) );
			return wpinv_error_log( 'Invoice marked as paid.' );

		}

		// Pending payments.
		if ( $payment_status == 'pending' ) {

			/* translators: %s: pending reason. */
			$invoice->update_status( 'wpi-onhold', sprintf( __( 'Payment pending (%s).', 'invoicing' ), $posted['pending_reason'] ) );

			return wpinv_error_log( 'Invoice marked as "payment held".' );
		}

		/* translators: %s: payment status. */
		$invoice->update_status( 'wpi-failed', sprintf( __( 'Payment %s via IPN.', 'invoicing' ), sanitize_text_field( $posted['payment_status'] ) ) );

	}

	/**
	 * Handles one time payments.
	 *
	 * @param WPInv_Invoice $invoice  Invoice object.
	 * @param array    $posted Posted data.
	 */
	protected function ipn_txn_cart( $invoice, $posted ) {
		$this->ipn_txn_web_accept( $invoice, $posted );
	}

	/**
	 * Handles subscription sign ups.
	 *
	 * @param WPInv_Invoice $invoice  Invoice object.
	 * @param array    $posted Posted data.
	 */
	protected function ipn_txn_subscr_signup( $invoice, $posted ) {

		wpinv_error_log( 'Processing subscription signup' );

		// Make sure the invoice has a subscription.
		$subscription = getpaid_get_invoice_subscription( $invoice );

		if ( empty( $subscription ) ) {
			return wpinv_error_log( 'Aborting, Subscription for the invoice ' . $invoice->get_id() . ' not found' );
		}

		// Validate the IPN.
		$business_email = isset( $posted['business'] ) && is_email( $posted['business'] ) ? trim( $posted['business'] ) : trim( $posted['receiver_email'] );
		$this->validate_ipn_receiver_email( $invoice, $business_email );
		$this->validate_ipn_currency( $invoice, $posted['mc_currency'] );

		// Activate the subscription.
		$duration = strtotime( $subscription->get_expiration() ) - strtotime( $subscription->get_date_created() );
		$subscription->set_date_created( current_time( 'mysql' ) );
		$subscription->set_expiration( date( 'Y-m-d H:i:s', ( current_time( 'timestamp' ) + $duration ) ) );
		$subscription->set_profile_id( sanitize_text_field( $posted['subscr_id'] ) );
		$subscription->activate();

		// Set the transaction id.
		if ( ! empty( $posted['txn_id'] ) ) {
			$invoice->set_transaction_id( $posted['txn_id'] );
		}

		// Update the payment status.
		$invoice->mark_paid();

		$invoice->add_note( sprintf( __( 'PayPal Subscription ID: %s', 'invoicing' ) , $posted['subscr_id'] ), false, false, true );

		wpinv_error_log( 'Subscription started.' );
	}

	/**
	 * Handles subscription renewals.
	 *
	 * @param WPInv_Invoice $invoice  Invoice object.
	 * @param array    $posted Posted data.
	 */
	protected function ipn_txn_subscr_payment( $invoice, $posted ) {

		// Make sure the invoice has a subscription.
		$subscription = wpinv_get_subscription( $invoice );

		if ( empty( $subscription ) ) {
			return wpinv_error_log( 'Aborting, Subscription for the invoice ' . $invoice->get_id() . ' not found' );
		}

		// Abort if this is the first payment.
		if ( date( 'Ynd', $subscription->get_time_created() ) == date( 'Ynd', strtotime( $posted['payment_date'] ) ) ) {
			$invoice->set_transaction_id( sanitize_text_field( $posted['txn_id'] ) );
			$invoice->save();
			return;
		}

		wpinv_error_log( 'Processing subscription renewal payment for the invoice ' . $invoice->get_id() );

		// Abort if the payment is already recorded.
		if ( wpinv_get_id_by_transaction_id( $posted['txn_id'] ) ) {
			return wpinv_error_log( 'Aborting, Transaction ' . $posted['txn_id'] .' has already been processed' );
		}

		$args = array(
			'transaction_id' => $posted['txn_id'],
			'gateway'        => $this->id,
		);

		$invoice = wpinv_get_invoice( $subscription->add_payment( $args ) );

		if ( empty( $invoice ) ) {
			return;
		}

		$invoice->add_note( wp_sprintf( __( 'PayPal Transaction ID: %s', 'invoicing' ) , $posted['txn_id'] ), false, false, true );
		$invoice->add_note( wp_sprintf( __( 'PayPal Subscription ID: %s', 'invoicing' ) , $posted['subscr_id'] ), false, false, true );

		$subscription->renew();
		wpinv_error_log( 'Subscription renewed.' );

	}

	/**
	 * Handles subscription cancelations.
	 *
	 * @param WPInv_Invoice $invoice  Invoice object.
	 */
	protected function ipn_txn_subscr_cancel( $invoice ) {

		// Make sure the invoice has a subscription.
		$subscription = wpinv_get_subscription( $invoice );

		if ( empty( $subscription ) ) {
			return wpinv_error_log( 'Aborting, Subscription for the invoice ' . $invoice->get_id() . ' not found' );
		}

		wpinv_error_log( 'Processing subscription cancellation for the invoice ' . $invoice->get_id() );
		$subscription->cancel();
		wpinv_error_log( 'Subscription cancelled.' );

	}

	/**
	 * Handles subscription completions.
	 *
	 * @param WPInv_Invoice $invoice  Invoice object.
	 * @param array    $posted Posted data.
	 */
	protected function ipn_txn_subscr_eot( $invoice ) {

		// Make sure the invoice has a subscription.
		$subscription = wpinv_get_subscription( $invoice );

		if ( empty( $subscription ) ) {
			return wpinv_error_log( 'Aborting, Subscription for the invoice ' . $invoice->get_id() . ' not found' );
		}

		wpinv_error_log( 'Processing subscription end of life for the invoice ' . $invoice->get_id() );
		$subscription->complete();
		wpinv_error_log( 'Subscription completed.' );

	}

	/**
	 * Handles subscription fails.
	 *
	 * @param WPInv_Invoice $invoice  Invoice object.
	 * @param array    $posted Posted data.
	 */
	protected function ipn_txn_subscr_failed( $invoice ) {

		// Make sure the invoice has a subscription.
		$subscription = wpinv_get_subscription( $invoice );

		if ( empty( $subscription ) ) {
			return wpinv_error_log( 'Aborting, Subscription for the invoice ' . $invoice->get_id() . ' not found' );
		}

		wpinv_error_log( 'Processing subscription payment failure for the invoice ' . $invoice->get_id() );
		$subscription->failing();
		wpinv_error_log( 'Subscription marked as failing.' );

	}

}
