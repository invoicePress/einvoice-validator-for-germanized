<?php
/**
 * The e-invoice details of a customer as master data: public authority, Leitweg-ID and Peppol ID.
 *
 * WooCommerce has no field for a Leitweg-ID or a Peppol ID, and Germanized Pro adds only the VAT ID to the customer
 * ( user meta `billing_vat_id`, read in Germanized Pro 4.3.10, class WC_GZDP_VAT_Helper ). This class keeps the three
 * values at the customer, so a returning buyer does not type them again and an order created in the admin finds them.
 *
 * ⚠️ ONE PLACE FOR THE VALUES: THE USER META `billing_eivg_<field>`. The classic checkout already writes there, because
 * WooCommerce stores every posted field that starts with `billing_` at the customer ( WC_Checkout::process_customer() )
 * and reads it back as the default of the field ( WC_Checkout::get_value() ). The same keys appear in "My account →
 * Addresses", because that form is built from the same filter `woocommerce_billing_fields`. The block checkout stores its
 * order fields only at the order; this class copies them to the same user meta and fills them from there.
 *
 * ⚠️ THE OWN ORDER NUMBER IS NOT MASTER DATA. It changes with every order and stays at the order.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Master_Data
{
	/** The fields kept at the customer. */
	const FIELDS = array( 'public_authority', 'buyer_reference', 'buyer_electronic_address' );


	public static function boot()
	{
		if ( ! EIVG_Settings::get( 'checkout_fields' ) )
			return;

		// The user profile in the admin, below the billing address of WooCommerce.
		add_filter( 'woocommerce_customer_meta_fields', array( __CLASS__, 'profile_fields' ) );

		// "My account → Addresses → Billing address" shows the fields through `woocommerce_billing_fields`; only the check is added.
		add_action( 'woocommerce_after_save_address_validation', array( __CLASS__, 'validate_account' ), 10, 4 );

		// The block checkout: fill the fields from the customer, and copy them back once the order is placed.
		foreach ( self::FIELDS as $key )
			add_filter( 'woocommerce_get_default_value_for_' . EIVG_Checkout::NS . str_replace( '_', '-', $key ), array( __CLASS__, 'block_default' ), 10, 3 );

		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'block_save' ) );
	}


	/**
	 * @param array $fields the field sets of the user profile
	 * @return array
	 */
	public static function profile_fields( $fields )
	{
		if ( ! isset( $fields['billing']['fields'] ) )
			return( $fields );

		foreach ( EIVG_Checkout::fields() as $key => $f )
		{
			if ( ! in_array( $key, self::FIELDS, true ) )
				continue;

			$fields['billing']['fields'][ 'billing_eivg_' . $key ] = array(
				'label'       => $f['label'],
				'description' => $f['description'],
				'type'        => $f['type'] === 'checkbox' ? 'checkbox' : '',
			);
		}

		return( $fields );
	}


	/**
	 * "My account": a ticked box for public authorities needs a valid Leitweg-ID, as at the checkout.
	 *
	 * @param int $user_id
	 * @param string $type 'billing' or 'shipping'
	 * @param array $address the fields of the form
	 */
	public static function validate_account( $user_id, $type, $address = array() )
	{
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce has verified the nonce of the form before this action runs.
		if ( $type !== 'billing' || trim( sanitize_text_field( wp_unslash( $_POST['billing_company'] ?? '' ) ) ) === '' || empty( $_POST['billing_eivg_public_authority'] ) )
			return;

		$error = EIVG_Checkout::leitweg_error( sanitize_text_field( wp_unslash( $_POST['billing_eivg_buyer_reference'] ?? '' ) ) );
		// phpcs:enable

		if ( $error !== '' )
			wc_add_notice( $error, 'error' );
	}


	/**
	 * The block checkout asks for the default of an empty field; the answer is the value stored at the customer.
	 *
	 * ⚠️ THE OBJECT IS USUALLY THE DRAFT ORDER, NOT THE CUSTOMER. WooCommerce reads order fields from the order it keeps
	 * while the buyer fills the checkout; the customer is found through it, and through the logged-in user before the
	 * draft knows its customer.
	 *
	 * @param mixed $value always null
	 * @param string $group
	 * @param WC_Data $object
	 * @return mixed
	 */
	public static function block_default( $value, $group = '', $object = null )
	{
		$user = 0;

		if ( $object instanceof WC_Order )
			$user = (int) $object->get_customer_id();
		elseif ( $object instanceof WC_Customer )
			$user = (int) $object->get_id();

		if ( ! $user )
			$user = get_current_user_id();

		if ( ! $user )
			return( $value );

		// The filter name carries the field; it is taken from the hook that is running.
		$key    = str_replace( '-', '_', substr( current_filter(), strlen( 'woocommerce_get_default_value_for_' . EIVG_Checkout::NS ) ) );
		$stored = trim( (string) get_user_meta( $user, 'billing_eivg_' . $key, true ) );

		if ( $stored === '' )
			return( $value );

		// ⚠️ A CHECKBOX IS READ AS "1". WooCommerce compares the stored value with '1' ( CheckoutFields::get_field_from_object() ).
		return( $key === 'public_authority' ? ( wc_string_to_bool( $stored ) ? '1' : $value ) : $stored );
	}


	/**
	 * The block checkout: copy the three values of the placed order to the customer.
	 *
	 * ⚠️ ONLY WITH A COMPANY NAME. Without one the fields were hidden, and WooCommerce saved them empty; copying them would
	 * erase the master data of a customer who once ordered privately.
	 *
	 * @param WC_Order $order
	 */
	public static function block_save( $order )
	{
		if ( ! $order instanceof WC_Order || ! $order->get_customer_id() || trim( (string) $order->get_billing_company() ) === '' )
			return;

		foreach ( self::FIELDS as $key )
		{
			$value = trim( (string) $order->get_meta( '_wc_other/' . EIVG_Checkout::NS . str_replace( '_', '-', $key ) ) );

			update_user_meta( $order->get_customer_id(), 'billing_eivg_' . $key, $value );
		}
	}


	/**
	 * The value stored at the customer of the order, '' without a customer or a value.
	 *
	 * @param WC_Order $order
	 * @param string $key
	 * @return string
	 */
	public static function value( $order, $key )
	{
		if ( ! in_array( $key, self::FIELDS, true ) || ! $order->get_customer_id() )
			return( '' );

		return( trim( (string) get_user_meta( $order->get_customer_id(), 'billing_eivg_' . $key, true ) ) );
	}
}
