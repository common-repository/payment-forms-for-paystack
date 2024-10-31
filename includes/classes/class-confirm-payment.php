<?php
/**
 * The functions to handle the confirm payment action
 *
 * @package paystack\payment_forms
 */

namespace paystack\payment_forms;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form Submit Class
 */
class Confirm_Payment {

	/**
	 * The helpers class.
	 *
	 * @var \paystack\payment_forms\Helpers
	 */
	public $helpers;

	/**
	 * Holds the current form meta
	 *
	 * @var array
	 */
	protected $meta = array();

	/**
	 * Hold the current transaction response
	 *
	 * @var boolean|object
	 */
	protected $transaction = false;

	/**
	 * Holds the current payment meta retrieved from the DB.
	 *
	 * @var object
	 */
	protected $payment_meta;

	/**
	 * The current form ID we are processing the payment for.
	 *
	 * @var integer
	 */
	protected $form_id = 0;

	/**
	 * The amount paid at checkout.
	 *
	 * @var integer
	 */
	protected $amount = 0;

	/**
	 * The amount save in the form meta
	 *
	 * @var integer
	 */
	protected $oamount = 0;

	/**
	 * The transaction column to update.
	 * Defaults to 'txn_code' and 'txn_code_2' when a payment retry is triggered.
	 *
	 * @var integer
	 */
	protected $txn_column = 'txn_code';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_ajax_pff_paystack_confirm_payment', [ $this, 'confirm_payment' ] );
		add_action( 'wp_ajax_nopriv_pff_paystack_confirm_payment', [ $this, 'confirm_payment' ] );
	}

	/**
	 * Sets up our data for processing.
	 *
	 * @return void
	 */
	protected function setup_data( $payment ) {
		$this->payment_meta = $payment;
		$this->meta         = $this->helpers->parse_meta_values( get_post( $this->payment_meta->post_id ) );
		$this->amount       = $this->payment_meta->amount;
		$this->oamount      = $this->meta['amount'];
		$this->form_id      = $this->payment_meta->post_id;

		if ( 'customer' === $this->meta['txncharge'] ) {
			$this->oamount = $this->helpers->process_transaction_fees( $this->oamount );
		}
	}
	
	/**
	 * Confirm Payment Functionality.
	 */
	public function confirm_payment() {

		if ( ! isset( $_POST['nonce'] ) || false === wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'pff-paystack-confirm' ) ) {
			$response = array(
				'error' => true,
				'error_message' => __( 'Nonce verification is required.', 'pff-paystack' ),
			);
	
			exit( wp_json_encode( $response ) );	
		}

		// This is a false positive, we are using isset as WPCS suggest in the PCP plugin.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! isset( $_POST['code'] ) || '' === trim( wp_unslash( $_POST['code'] ) ) ) {
			$response = array(
				'error' => true,
				'error_message' => __( 'Did you make a payment?', 'pff-paystack' ),
			);
	
			exit( wp_json_encode( $response ) );
		}

		// If this is a retry payment then set the colum accordingly.
		if ( isset( $_POST['retry'] ) ) {
			$this->txn_column = 'txn_code_2';
		}
	
		$this->helpers = new Helpers();
		$code          = sanitize_text_field( wp_unslash( $_POST['code'] ) );
		$record        = $this->helpers->get_db_record( $code, $this->txn_column );

		if ( false !== $record ) {

			$this->setup_data( $record );

			// Verify our transaction with the Paystack API.
			$transaction = pff_paystack()->classes['transaction-verify']->verify_transaction( $code );

			if ( ! empty( $transaction ) && isset( $transaction['data'] )  ) {
				$transaction['data'] = json_decode( $transaction['data'] );
				if ( 'success' === $transaction['data']->status ) {
					$this->update_sold_inventory();
					$response = $this->update_payment_dates( $transaction['data'] );
				}
			} else {
				$response = [
					'message' => __( 'Failed to connect to Paystack.', 'pff-paystack' ),
					'result'  => 'failed',
				];	
			}
	
		} else {
			$response = [
				'message' => __( 'Payment Verification Failed', 'pff-paystack' ),
				'result'  => 'failed',
			];
		}
	

		// Create plan and send reciept.
		if ( 'success' === $response['result'] ) {
			
			// Create a plan that the user will be subscribed to.
			
			/*$pstk_logger = new kkd_pff_paystack_plugin_tracker( 'pff-paystack', Kkd_Pff_Paystack_Public::fetchPublicKey() );
			$pstk_logger->log_transaction_success( $code );*/

			$this->maybe_create_subscription();
	
			
			$sendreceipt = $this->meta['sendreceipt'];
			if ( 'yes' === $sendreceipt ) {
				$decoded = json_decode( $this->payment_meta->metadata );
				$fullname = $decoded[1]->value;

				/**
				 * Allow 3rd Party Plugins to hook into the email sending.
				 * 
				 * 10: Email_Receipt::send_receipt();
				 * 11: Email_Receipt_Owner::send_receipt_owner();
				 */
				do_action( 'pff_paystack_send_receipt',
					$this->payment_meta->post_id,
					$this->payment_meta->currency,
					$this->payment_meta->amount_paid,
					$fullname,
					$this->payment_meta->email,
					$this->payment_meta->reference,
					$this->payment_meta->metadata
				);
			}
		}
	
		if ( 'success' === $response['result'] && '' !== $this->meta['redirect'] ) {
			$response['result'] = 'success2';
			$response['link']   = $this->meta['redirect'];
		}
	
		echo wp_json_encode( $response );
		die();
	}

	/**
	 * Update the sold invetory with the amount of payments made.
	 *
	 * @return void
	 */
	protected function update_sold_inventory() {
		$usequantity = $this->meta['usequantity'];
		$sold        = (int) $this->meta['sold'];

		if ( 'yes' === $usequantity ) {
			$quantity = 1;
			// Nonce is checked above in the parent function confirm_payment().
			// phpcs:ignore WordPress.Security.NonceVerification
			if ( isset( $_POST['quantity'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification
				$quantity = (int) sanitize_text_field( wp_unslash( $_POST['quantity'] ) );
			}
			$sold     = $this->meta['sold'];

			if ( '' === $sold ) {
				$sold = '0';
			}
			$sold += $quantity;
		} else {
			$sold++;
		}

		if ( $this->meta['sold'] ) {
			update_post_meta( $this->form_id, '_sold', $sold );
		} else {
			add_post_meta( $this->form_id, '_sold', $sold, true );
		}
	}

	/**
	 * Updates the paid Date for the current record.
	 *
	 * @param object $data
	 * @return array
	 */
	protected function update_payment_dates( $data ) {
		global $wpdb;
		$table  = $wpdb->prefix . PFF_PAYSTACK_TABLE;
		$return = [
			'message' => __( 'DB not updated.', 'pff-paystack' ),
			'result' => 'failed',
		];

		$amount_paid    = $data->amount / 100;
		$paystack_ref   = $data->reference;
		$paid_at        = $data->transaction_date;
		if ( 'optional' === $this->meta['recur'] || 'plan' === $this->meta['recur'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'paid'    => 1,
					'amount'  => $amount_paid,
					'paid_at' => $paid_at,
				),
				array( $this->txn_column => $paystack_ref )
			);
			$return = [
				'message' => $this->meta['successmsg'],
				'result' => 'success',
			];
		} else {
			// If this the price paid was free, or if it was a variable amount.
			if ( 0 === (int) $this->oamount || 1 === $this->meta['usevariableamount'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$table,
					array(
						'paid'    => 1,
						'amount'  => $amount_paid,
						'paid_at' => $paid_at,
					),
					array( $this->txn_column => $paystack_ref )
				);
				$return = [
					'message' => $this->meta['successmsg'],
					'result' => 'success',
				];
			} else {
				if ( $this->oamount !== $amount_paid ) {
					$return = [
						// translators: %1$s: currency, %2$s: formatted amount required
						'message' => sprintf( __( 'Invalid amount Paid. Amount required is %1$s<b>%2$s</b>', 'pff-paystack' ), $this->meta['currency'], number_format( $this->oamount ) ),
						'result' => 'failed',
					];
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update(
						$table,
						array(
							'paid'    => 1,
							'paid_at' => $paid_at,
						),
						array( $this->txn_column => $paystack_ref )
					);
					$return = [
						'message' => $this->meta['successmsg'],
						'result' => 'success',
					];
				}
			}
		}
		return $return;
	}

	protected function maybe_create_subscription() {
		// Create a "subscription" and attach it to the current plan code.
		if ( 1 == $this->meta['startdate_enabled'] && ! empty( $this->meta['startdate_days'] ) && ! empty( $this->meta['startdate_plan_code'] ) ) {
			$start_date = gmdate( 'c', strtotime( '+' . $this->meta['startdate_days'] . ' days' ) );
			$body       = array(
				'start_date' => $start_date,
				'plan'       => $this->meta['startdate_plan_code'],
				'customer'   => $this->payment_meta->email,
			);

			$created_sub = pff_paystack()->classes['request-subscription']->create_subscription( $body );
			if ( false !== $created_sub ) {
				// Nothing defined for this.
			}
		}
	}
}