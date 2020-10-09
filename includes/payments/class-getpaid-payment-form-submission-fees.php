<?php
/**
 * Processes fees for a payment form submission.
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment form submission fees class
 *
 */
class GetPaid_Payment_Form_Submission_Fees {

	/**
	 * The fee validation error.
	 * @var string
	 */
	public $fee_error;

	/**
	 * Submission fees.
	 * @var array
	 */
	public $fees = array();

    /**
	 * Class constructor
	 *
	 * @param GetPaid_Payment_Form_Submission $submission
	 */
	public function __construct( $submission ) {

		// Process any existing invoice fees.
		if ( $submission->has_invoice() ) {
			$this->fees = $submission->get_invoice()->get_fees();
		}

		// Process price fields.
		$data         = $submission->get_data();
		$payment_form = $submission->get_payment_form();

		foreach ( $payment_form->get_elements() as $element ) {

			if ( 'price_input' == $element['type'] ) {
				$this->process_price_input( $element, $data );
			}

			if ( 'price_select' == $element['type'] ) {
				$this->process_price_select( $element, $data );
			}

		}

	}

	/**
	 * Process a price input field.
	 *
	 * @param array $element
	 * @param array $data
	 */
	public function process_price_input( $element, $data ) {

		// Abort if not passed.
		if ( empty( $data[ $element['id'] ] ) ) {
			return;
		}

		$amount  = (float) wpinv_sanitize_amount( $data[ $element['id'] ] );
		$minimum = (float) wpinv_sanitize_amount( $element['minimum'] );

		if ( $amount < $minimum ) {
			return $this->set_error( sprintf( __( 'The minimum allowed amount is %s', 'invoicing' ), $minimum ) );
		}

		$this['fees'][ $element['label'] ] = array(
			'name'          => $element['label'],
			'initial_fee'   => $amount,
			'recurring_fee' => 0,
		);

	}

	/**
	 * Process a price select field.
	 *
	 * @param array $element
	 * @param array $data
	 */
	public function process_price_select( $element, $data ) {

		// Abort if not passed.
		if ( empty( $data[ $element['id'] ] ) ) {
			return;
		}

		$options  = getpaid_convert_price_string_to_options( $element['options'] );
		$selected = wpinv_parse_list( $data[ $element['id'] ] );
		$total    = 0;

		foreach ( $selected as $price ) {

			if ( ! isset( $options[ $price ] ) ) {
				return $this->set_error( __( 'You have selected an invalid amount', 'invoicing' ) );
			}

			$total += (float) wpinv_sanitize_amount( $price );
		}

		$this['fees'][ $element['label'] ] = array(
			'name'          => $element['label'],
			'initial_fee'   => $total,
			'recurring_fee' => 0,
		);

	}

	/**
	 * Sets an error without overwriting the previous error.
	 *
	 * @param string $error
	 */
	public function set_error( $error ) {
		if ( empty( $this->fee_error ) ) {
			$this->fee_error    = $error;
		}
	}

}