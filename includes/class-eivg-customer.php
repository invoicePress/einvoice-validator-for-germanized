<?php
/**
 * Business or private customer, and the mode that follows from it.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Customer
{

	/**
	 * Whether an order counts as an order of a business.
	 *
	 * ⚠️ ONE OF THREE IS ENOUGH: a company name, a VAT ID ( Germanized Pro keeps it in `_billing_vat_id` ) or a buyer
	 * reference. A business that leaves the company field empty but gives a Leitweg-ID is a public buyer and must not be
	 * treated as a private person.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function is_business( $order )
	{
		if ( trim( (string) $order->get_billing_company() ) !== '' )
			return( true );

		if ( trim( (string) $order->get_meta( '_billing_vat_id' ) ) !== '' )
			return( true );

		return( EIVG_Checkout::value( $order, 'buyer_reference' ) !== '' );
	}


	/**
	 * The mode for an order: hold, report or off.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function mode( $order )
	{
		$mode = (string) EIVG_Settings::get( self::is_business( $order ) ? 'mode_business' : 'mode_private' );

		return( (string) apply_filters( 'eivg_mode', $mode, $order ) );
	}
}
