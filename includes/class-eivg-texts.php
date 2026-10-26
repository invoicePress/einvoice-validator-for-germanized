<?php
/**
 * Plain words for a finding: the field, the rule text in the language of the site, and where to fix it.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Texts
{
	/** The cause groups of the concept, section 5. */
	const SHOP     = 'shop';
	const ORDER    = 'order';
	const CALC     = 'calc';
	const REFUND   = 'refund';
	const FORMAT   = 'format';

	private static $fields = null;
	private static $rules  = null;


	/** @return string 'de' or 'en' */
	public static function lang()
	{
		return( strpos( (string) determine_locale(), 'de' ) === 0 ? 'de' : 'en' );
	}


	/**
	 * The name of a business term, in the language of the site.
	 *
	 * ⚠️ THE GROUPS STAND IN CAPITALS IN THE SOURCE ( "KÄUFER" for BG-7 ), as in the standard's table. A heading in capitals
	 * reads like shouting in a sentence, so it is turned into "Käufer".
	 *
	 * @param string $bt
	 * @return string
	 */
	public static function field( $bt )
	{
		if ( self::$fields === null )
			self::$fields = self::load( 'fields.json' );

		$name = (string) ( self::$fields[ $bt ][ self::lang() ] ?? self::$fields[ $bt ]['en'] ?? '' );

		if ( $name !== '' && mb_strtoupper( $name ) === $name )
			$name = mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_strtolower( mb_substr( $name, 1 ) );

		return( $name );
	}


	/**
	 * The text of a rule in the language of the site, falling back to English and then to the engine's own text.
	 *
	 * @param string $rule
	 * @param string $engine_text
	 * @return string
	 */
	public static function rule( $rule, $engine_text = '' )
	{
		if ( self::$rules === null )
			self::$rules = self::load( 'rules.json' );

		$text = (string) ( self::$rules[ $rule ][ self::lang() ] ?? self::$rules[ $rule ]['en'] ?? '' );

		if ( $text === '' )
			$text = trim( (string) preg_replace( '/^\[[^\]]+\]\s*-?\s*/', '', (string) $engine_text ) );

		return( $text );
	}


	/**
	 * Which of the five cause groups a finding belongs to, from the fields it names and from its rule.
	 *
	 * ⚠️ BY FIELD, NOT BY RULE NUMBER. The same missing buyer reference is DE-R-015 in Peppol, BR-DE-15 in XRechnung and
	 * part of BR-CO-26 elsewhere. The fields stay the same across the rule sets; the numbers do not.
	 *
	 * @param array $m a message from EIVG_Engine::validate()
	 * @return string one of the constants, '' when no group fits
	 */
	public static function group( array $m )
	{
		$rule = (string) ( $m['rule'] ?? '' );
		$bt   = (array) ( $m['bt'] ?? array() );
		$text = (string) ( $m['text'] ?? '' );

		// Every field the rule names, from the engine and from the rule's own text.
		preg_match_all( '/\bB[TG]-\d+\b/', $text . ' ' . self::rule( $rule ), $mm );
		$all = array_unique( array_merge( $bt, $mm[0] ) );

		$has = static function ( array $list ) use ( $all ) { return( (bool) array_intersect( $list, $all ) ); };

		if ( $has( array( 'BT-3', 'BT-25', 'BT-26' ) ) && preg_match( '/381|credit|gutschrift|preceding|vorausgegangen/i', $text . self::rule( $rule ) ) )
			return( self::REFUND );

		if ( preg_match( '/^BR-CO-1[0-7]$|^BR-[A-Z]{1,2}-0?8$|^BR-[A-Z]{1,2}-09$|^BR-DE-18$|^DE-R-018$/', $rule ) || $has( array( 'BT-106', 'BT-109', 'BT-110', 'BT-112', 'BT-115', 'BT-116', 'BT-117' ) ) && ! $has( array( 'BT-120', 'BT-121' ) ) )
			return( self::CALC );

		if ( $has( array( 'BT-10', 'BT-13', 'BT-44', 'BT-46', 'BT-47', 'BT-48', 'BT-49', 'BT-50', 'BT-51', 'BT-52', 'BT-53', 'BT-55', 'BG-7', 'BG-8' ) ) )
			return( self::ORDER );

		if ( $has( array( 'BT-27', 'BT-29', 'BT-30', 'BT-31', 'BT-32', 'BT-34', 'BT-35', 'BT-36', 'BT-37', 'BT-38', 'BT-40', 'BT-41', 'BT-42', 'BT-43', 'BT-84', 'BT-120', 'BT-121', 'BG-4', 'BG-5', 'BG-6', 'BG-17' ) ) )
			return( self::SHOP );

		if ( preg_match( '/^PEPPOL-EN16931-R008$|^BR-CL-|^BR-DEC-/', $rule ) || $has( array( 'BT-130', 'BT-153', 'BT-24' ) ) )
			return( self::FORMAT );

		return( '' );
	}


	/**
	 * What the shop owner can do about a finding, as one sentence.
	 *
	 * @param array $m
	 * @return string
	 */
	public static function hint( array $m )
	{
		$rule = (string) ( $m['rule'] ?? '' );
		$bt   = (array) ( $m['bt'] ?? array() );

		if ( in_array( 'BT-10', $bt, true ) || in_array( $rule, array( 'DE-R-015', 'BR-DE-15' ), true ) )
			return( __( 'The Leitweg-ID of the public authority is missing. The customer enters it at the checkout, or you enter it in the box "eInvoice check" of the order and check again. If the buyer is not a public authority, remove the tick there; the order number then takes its place.', 'einvoice-validator-for-germanized' ) );

		if ( in_array( 'BT-49', $bt, true ) || in_array( $rule, array( 'PEPPOL-EN16931-R010', 'PEPPOL-EN16931-CL008' ), true ) )
			return( __( 'The Peppol ID of the buyer is missing or has an unknown scheme. Germanized Pro uses the VAT ID of the buyer when the order has one; otherwise the customer enters the Peppol ID at the checkout, or you enter it in the box "eInvoice check" of the order and check again.', 'einvoice-validator-for-germanized' ) );

		if ( in_array( 'BT-48', $bt, true ) )
			return( __( 'The VAT ID of the buyer is missing or invalid. It is needed for a reverse-charge invoice; add it to the order and check again.', 'einvoice-validator-for-germanized' ) );

		switch ( self::group( $m ) )
		{
			case self::SHOP:
				return( __( 'This is a setting of the shop and has to be fixed once: under WooCommerce → Settings → Germanized → Invoices → Business Information.', 'einvoice-validator-for-germanized' ) );
			case self::ORDER:
				return( __( 'This comes from the data of the order. Correct the billing address of the order and check again.', 'einvoice-validator-for-germanized' ) );
			case self::CALC:
				return( __( 'This comes from the way the amounts are rounded or distributed when the invoice is created. You usually cannot change that in the shop; if it stays, finalize the invoice without the check and report the case to the maker of the invoice plugin.', 'einvoice-validator-for-germanized' ) );
			case self::REFUND:
				return( __( 'This concerns a cancellation invoice for a refund. You usually cannot change that in the shop; report the case to the maker of the invoice plugin.', 'einvoice-validator-for-germanized' ) );
			case self::FORMAT:
				return( __( 'This comes from the content or the format of the generated file, for example a product name or a unit. If it stays after correcting the product, report the case to the maker of the invoice plugin.', 'einvoice-validator-for-germanized' ) );
		}

		return( '' );
	}


	/**
	 * One finding in plain words, as data for the views and the mail.
	 *
	 * @param array $m
	 * @return array { rule, flag, text, fields: [ "BT-10 Buyer reference", … ], hint, group }
	 */
	public static function explain( array $m )
	{
		$fields = array();

		foreach ( (array) ( $m['bt'] ?? array() ) as $bt )
		{
			$name     = self::field( (string) $bt );
			$fields[] = trim( $bt . ' ' . $name );
		}

		return( array(
			'rule'   => (string) ( $m['rule'] ?? '' ),
			'flag'   => (string) ( $m['flag'] ?? '' ),
			'text'   => self::rule( (string) ( $m['rule'] ?? '' ), (string) ( $m['text'] ?? '' ) ),
			'fields' => $fields,
			'hint'   => self::hint( $m ),
			'group'  => self::group( $m ),
		) );
	}


	/** @return array */
	private static function load( $file )
	{
		$path = EIVG_DIR . 'assets/data/' . $file;
		$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;

		return( is_array( $data ) ? $data : array() );
	}
}
