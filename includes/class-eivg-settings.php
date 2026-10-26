<?php
/**
 * The settings of the plugin.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Settings
{
	const OPTION = 'eivg_settings';

	/** The three modes of a customer class ( concept, section 4.3 ). */
	const HOLD   = 'hold';
	const REPORT = 'report';
	const OFF    = 'off';


	/**
	 * Defaults.
	 *
	 * ⚠️ BUSINESS INVOICES ARE HELD, PRIVATE ONES ONLY REPORTED. The obligation applies between businesses. An invoice
	 * to a private customer fails XRechnung and Peppol almost always ( no electronic address, no buyer reference ), and
	 * holding it back would stop every private order's invoice for nothing.
	 *
	 * @return array
	 */
	public static function defaults()
	{
		return( array(
			'mode_business'   => self::HOLD,
			'mode_private'    => self::REPORT,
			'checkout_fields' => true,
			'explorer_hint'   => true,
			'notify_email'    => '',
		) );
	}


	/** @return array */
	public static function all()
	{
		$saved = get_option( self::OPTION, array() );

		return( array_merge( self::defaults(), is_array( $saved ) ? $saved : array() ) );
	}


	/**
	 * @param string $key
	 * @return mixed
	 */
	public static function get( $key )
	{
		$all = self::all();

		return( $all[ $key ] ?? null );
	}


	/**
	 * Store the settings from the admin form, each value checked against its range.
	 *
	 * @param array $input
	 */
	public static function save( array $input )
	{
		$modes = array( self::HOLD, self::REPORT, self::OFF );
		$b     = (string) ( $input['mode_business'] ?? self::HOLD );
		$p     = (string) ( $input['mode_private'] ?? self::REPORT );
		$mail  = sanitize_email( (string) ( $input['notify_email'] ?? '' ) );

		update_option( self::OPTION, array(
			'mode_business'   => in_array( $b, $modes, true ) ? $b : self::HOLD,
			'mode_private'    => in_array( $p, $modes, true ) ? $p : self::REPORT,
			'checkout_fields' => ! empty( $input['checkout_fields'] ),
			'explorer_hint'   => ! empty( $input['explorer_hint'] ),
			'notify_email'    => is_email( $mail ) ? $mail : '',
		), false );
	}


	/**
	 * Where the mails about held invoices go: the address in the settings, otherwise the site's admin address.
	 *
	 * @return string
	 */
	public static function notify_email()
	{
		$mail = (string) self::get( 'notify_email' );

		return( $mail !== '' ? $mail : (string) get_option( 'admin_email' ) );
	}
}
