<?php
/**
 * Four optional fields at the checkout that an e-invoice for a business needs and WooCommerce does not ask for.
 *
 *   public_authority          the buyer is a public authority and receives invoices through a Leitweg-ID
 *   buyer_reference           the Leitweg-ID ( BT-10 ), required and checked when public_authority is set
 *   order_reference           the buyer's own order number ( BT-13 )
 *   buyer_electronic_address  where the buyer receives e-invoices ( BT-49 ), required by Peppol
 *
 * ⚠️ BT-10 IS NOT ALWAYS A LEITWEG-ID. XRechnung requires BT-10 for every buyer ( BR-DE-15 ), and Peppol requires it when
 * seller and buyer are both in Germany ( DE-R-015 ). A company that is not a public authority has no Leitweg-ID; for
 * that buyer the adapter fills BT-10 with the order number ( EIVG_Adapter_Germanized::meta_default_buyer_reference() ).
 * Only a public authority without a Leitweg-ID ends with a held invoice, and that is the case the check exists for.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Checkout
{
	/** The namespace of the fields in the block checkout. */
	const NS = 'einvoice-validator-for-germanized/';


	public static function boot()
	{
		if ( ! EIVG_Settings::get( 'checkout_fields' ) )
			return;

		// The classic checkout: the fields join the billing address, and WooCommerce stores them as `_billing_eivg_…`.
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'classic_fields' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'classic_script' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic_validate' ), 10, 2 );

		// The block checkout: WooCommerce registers them itself and stores them as `_wc_other/<id>`.
		add_action( 'woocommerce_init', array( __CLASS__, 'block_fields' ) );
	}


	/**
	 * ⚠️ THE PEPPOL ID ONLY WHEN THE SHOP CREATES PEPPOL FILES. Nothing in the shop delivers through the Peppol network;
	 * a field the customer fills for nothing would only raise questions ( EIVG_Adapter_Germanized::creates_peppol() ).
	 *
	 * @return array key => { label, description, type }
	 */
	public static function fields()
	{
		$fields = self::all_fields();
		$a      = EIVG_Flow::adapter();

		if ( ! $a || ! method_exists( $a, 'creates_peppol' ) || ! $a->creates_peppol() )
			unset( $fields['buyer_electronic_address'] );

		return( $fields );
	}


	/** @return array key => { label, description, type } */
	private static function all_fields()
	{
		return( array(
			'public_authority'         => array(
				'label'       => __( 'We are a public authority and receive invoices through a Leitweg-ID', 'einvoice-validator-for-germanized' ),
				'description' => '',
				'type'        => 'checkbox',
			),
			'buyer_reference'          => array(
				'label'       => __( 'Leitweg-ID', 'einvoice-validator-for-germanized' ),
				'description' => __( 'For example 04011000-12345-03. You find it in the order of your authority.', 'einvoice-validator-for-germanized' ),
				'type'        => 'text',
			),
			'order_reference'          => array(
				'label'       => __( 'Your order number', 'einvoice-validator-for-germanized' ),
				'description' => __( 'If your accounting needs your own order number on the invoice.', 'einvoice-validator-for-germanized' ),
				'type'        => 'text',
			),
			'buyer_electronic_address' => array(
				'label'       => __( 'Peppol ID for e-invoices', 'einvoice-validator-for-germanized' ),
				'description' => __( 'In the form scheme:identifier, for example 0204:991-12345-67. Leave it empty to use your VAT ID.', 'einvoice-validator-for-germanized' ),
				'type'        => 'text',
			),
		) );
	}


	/**
	 * @param array $fields
	 * @return array
	 */
	public static function classic_fields( $fields )
	{
		$priority = 125;

		foreach ( self::fields() as $key => $f )
		{
			// ⚠️ THE SAME FILTER BUILDS "My account → Addresses". The own order number changes with every order and is no
			// master data, so it stays out of the account ( EIVG_Master_Data::FIELDS ).
			if ( function_exists( 'is_account_page' ) && is_account_page() && ! in_array( $key, EIVG_Master_Data::FIELDS, true ) )
				continue;

			$fields[ 'billing_eivg_' . $key ] = array(
				'type'        => $f['type'],
				'label'       => $f['label'],
				'description' => $f['description'],
				'required'    => false,
				'class'       => array( 'form-row-wide' ),
				'priority'    => $priority++,
			);
		}

		return( $fields );
	}


	/**
	 * The classic checkout shows the fields only while a company name is entered ( concept, section 4.4 ), and the
	 * Leitweg-ID only while the box for public authorities is ticked.
	 *
	 * ⚠️ AN INLINE SCRIPT ON `wc-checkout`, NOT A FILE OF ITS OWN. A few lines do not justify a request, and the handle
	 * exists exactly on the classic checkout page, so the script never loads anywhere else. A shop without jQuery on the
	 * page has no classic checkout either.
	 */
	public static function classic_script()
	{
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! wp_script_is( 'wc-checkout', 'enqueued' ) )
			return;

		wp_add_inline_script( 'wc-checkout', "jQuery(function($){var c=$('#billing_company'),p=$('#billing_eivg_public_authority'),r=$('#billing_eivg_public_authority_field,#billing_eivg_order_reference_field,#billing_eivg_buyer_electronic_address_field'),l=$('#billing_eivg_buyer_reference_field');if(!c.length)return;function t(){var b=$.trim(c.val())!=='';r.toggle(b);l.toggle(b&&p.is(':checked'));}c.on('input change',t);p.on('change',t);t();});" );
	}


	/**
	 * The classic checkout: a ticked box for public authorities needs a valid Leitweg-ID.
	 *
	 * ⚠️ THE COMPANY NAME DECIDES TOO. The script hides the box without a company name, but a box ticked before the name
	 * was deleted is still posted as ticked. Without a company the fields do not count, as in the block checkout.
	 *
	 * @param array $data the posted checkout fields
	 * @param WP_Error $errors
	 */
	public static function classic_validate( $data, $errors )
	{
		if ( trim( (string) ( $data['billing_company'] ?? '' ) ) === '' || empty( $data['billing_eivg_public_authority'] ) )
			return;

		$error = self::leitweg_error( (string) ( $data['billing_eivg_buyer_reference'] ?? '' ) );

		if ( $error !== '' )
			$errors->add( 'eivg_leitweg_id', $error );
	}


	public static function block_fields()
	{
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) )
			return;

		// Hidden while the company field is empty. WooCommerce evaluates these JSON-schema rules on the checkout document in
		// the browser and again on the server ( read in WooCommerce 11.1, CheckoutFields::is_hidden_field() ).
		$no_company = array(
			'customer' => array(
				'properties' => array(
					'billing_address' => array(
						'properties' => array( 'company' => array( 'const' => '' ) ),
					),
				),
			),
		);

		// ⚠️ ORDER FIELDS LIE UNDER `checkout.additional_fields`, NOT UNDER `customer`. The contact fields are under
		// `customer.additional_fields`; read in WooCommerce 11.1, CheckoutTrait::get_document_object_from_rest_request().
		// ⚠️ `type: object` IS NOT DECORATION. Without any order field the list arrives as an empty JSON array, and on an
		// array the keywords `properties` and `required` do not apply, so the rule passed and the Leitweg-ID became
		// required for every company ( measured on 24.10.2026 with CheckoutFields::is_required_field() ).
		$authority = array(
			'checkout' => array(
				'properties' => array(
					'additional_fields' => array(
						'type'       => 'object',
						'properties' => array( self::NS . 'public-authority' => array( 'const' => true ) ),
						'required'   => array( self::NS . 'public-authority' ),
					),
				),
			),
		);

		foreach ( self::fields() as $key => $f )
		{
			$options = array(
				'id'       => self::NS . str_replace( '_', '-', $key ),
				'label'    => $f['label'],
				'location' => 'order',
				'type'     => $f['type'],
				'required' => false,
				'hidden'   => array( 'type' => 'object', 'properties' => $no_company ),
			);

			if ( $key === 'buyer_reference' )
			{
				// Visible and required only with a company and the ticked box; the check digit is tested on the server.
				$options['hidden']            = array( 'anyOf' => array( array( 'type' => 'object', 'properties' => $no_company ), array( 'not' => array( 'type' => 'object', 'properties' => $authority ) ) ) );
				$options['required']          = array( 'type' => 'object', 'properties' => $authority );
				$options['validate_callback'] = array( __CLASS__, 'block_validate_leitweg' );
			}

			try
			{
				// ⚠️ A WooCommerce that does not know rules rejects the options; the catch then leaves the field out.
				woocommerce_register_additional_checkout_field( $options );
			}
			catch ( \Throwable $e )
			{
				// A second registration of the same id, for example from a network-activated copy, is harmless.
			}
		}
	}


	/**
	 * The block checkout: the check digit of a Leitweg-ID that was entered.
	 *
	 * ⚠️ AN EMPTY VALUE PASSES HERE. Whether the field is required at all decides the rule `required` above, which knows
	 * the box; this callback does not see the other fields.
	 *
	 * @param mixed $value
	 * @return true|WP_Error
	 */
	public static function block_validate_leitweg( $value )
	{
		$value = trim( (string) $value );

		if ( $value === '' )
			return( true );

		$error = self::leitweg_error( $value );

		return( $error === '' ? true : new WP_Error( 'eivg_leitweg_id', $error ) );
	}


	/**
	 * Why a Leitweg-ID is not valid, or '' when it is.
	 *
	 * The format of the KoSIT: a coarse address of 2 to 12 digits, an optional fine address of up to 30 letters and digits,
	 * and two check digits, separated by hyphens. The check digits follow ISO/IEC 7064 MOD 97-10 over the whole ID without
	 * hyphens, letters counted as A = 10 to Z = 35; a valid ID leaves the remainder 1.
	 *
	 * Measured on 24.10.2026 against the Leitweg-IDs in the test files of the KoSIT: 04011000-12345-03, 04011000-12346-97
	 * and 991-33333TEST-33 pass, 04011000-12345-04 fails.
	 *
	 * ⚠️ THE REMAINDER IS COMPUTED IN STEPS, NOT WITH bcmod(). The ID is up to 46 characters long and far beyond an integer,
	 * and bcmath is not guaranteed on every host.
	 *
	 * @param string $id
	 * @return string
	 */
	public static function leitweg_error( $id )
	{
		$id = strtoupper( trim( $id ) );

		if ( $id === '' )
			return( __( 'Please enter the Leitweg-ID of your authority.', 'einvoice-validator-for-germanized' ) );

		if ( ! preg_match( '/^\d{2,12}(-[A-Z0-9]{1,30})?-\d{2}$/', $id ) )
			return( __( 'The Leitweg-ID has an invalid format. It looks like 04011000-12345-03.', 'einvoice-validator-for-germanized' ) );

		$rest = 0;

		foreach ( str_split( str_replace( '-', '', $id ) ) as $char )
		{
			$digits = ctype_digit( $char ) ? $char : (string) ( ord( $char ) - 55 );

			foreach ( str_split( $digits ) as $d )
				$rest = ( $rest * 10 + (int) $d ) % 97;
		}

		return( $rest === 1 ? '' : __( 'The check digits of the Leitweg-ID do not match. Please compare it with the order of your authority.', 'einvoice-validator-for-germanized' ) );
	}


	/**
	 * Whether the buyer of the order is a public authority.
	 *
	 * ⚠️ THE BOX AT THE ORDER WINS IN BOTH DIRECTIONS. It stores "yes" or "no", so the shop owner can also take back a box
	 * the customer ticked by mistake; value() alone would let the ticked box from the checkout win over an empty one.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function public_authority( $order )
	{
		$own = (string) $order->get_meta( '_eivg_public_authority' );

		if ( $own === 'yes' || $own === 'no' )
			return( $own === 'yes' );

		// Without a company name the checkout fields do not count, as at the checkout itself.
		if ( trim( (string) $order->get_billing_company() ) === '' )
			return( false );

		return( (bool) wc_string_to_bool( self::value( $order, 'public_authority' ) ) );
	}


	/**
	 * The value of a field for an order.
	 *
	 * ⚠️ THREE PLACES, IN THIS ORDER: the value the shop owner entered in the box "eInvoice check" of the order, then the
	 * classic checkout, then the block checkout. A correction at the order must win over what the customer typed.
	 *
	 * ⚠️ THE MASTER DATA OF THE CUSTOMER COME LAST, AND ONLY FOR AN ORDER THAT NEVER SAW THE FIELD. An order from the admin
	 * or from before this plugin has none of the three keys and takes the stored value. An order from the checkout has the
	 * key, even when empty, and keeps what the buyer answered then; otherwise an unticked box would be overruled by a box
	 * the same customer ticked a year ago.
	 *
	 * @param WC_Order $order
	 * @param string $key
	 * @return string
	 */
	public static function value( $order, $key )
	{
		$seen = false;

		foreach ( array( '_eivg_' . $key, '_billing_eivg_' . $key, '_wc_other/' . self::NS . str_replace( '_', '-', $key ) ) as $meta )
		{
			$value = trim( (string) $order->get_meta( $meta ) );

			if ( $value !== '' )
				return( $value );

			$seen = $seen || $order->meta_exists( $meta );
		}

		return( $seen ? '' : EIVG_Master_Data::value( $order, $key ) );
	}
}
