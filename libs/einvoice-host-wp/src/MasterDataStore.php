<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Second host for the shared seller and company master data. `EInvoicePress_Settings`
 * ( class.einvoicepress-settings.php, copied into the five eInvoice field plugins ) already reads
 * and writes this data, but it extends `GFAddOn`, so a plugin without Gravity Forms ( the free
 * Lite viewers of this family ) cannot use it. This class gives those plugins the SAME
 * `get( $name )` / `has( $name )` read contract, backed by its own WP option instead of GFAddOn's
 * plugin-settings machinery, so a consumer written against that contract does not need to know
 * which of the two stored the value.
 *
 * ⚠️ THIS IS A SEPARATE OPTION ROW, NOT A BRIDGE TO EInvoicePress_Settings' OWN ROW. The two are
 * independent stores that share field names and a read API; export_json() and import_json() below
 * are the explicit way to move data from one to the other. An automatic bridge would make this file
 * depend on GFAddOn being loadable, which is exactly the dependency a Lite install does not have.
 *
 * @package SpecialPress\EInvoice\Host
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\MasterDataStore' ) )
{

class MasterDataStore
{


	/** @var string the single WP option this store lives in */
	const OPTION = 'einvoice_masterdata';


	/**
	 * Field names whose value is bank/payment data - encrypted at rest, decrypted only when read
	 * back through get()/all()/export_json(). The list is deliberately the same field names
	 * `masterdata-catalog.json`'s 'bank' section uses, so a caller never has to know which fields are
	 * "the sensitive ones" - every field this store touches decrypts transparently either way.
	 *
	 * @var string[]
	 */

	const ENCRYPTED_FIELDS = array(
		'en16931_seller_iban',
		'en16931_seller_bic',
		'en16931_seller_account_owner',
		'en16931_seller_bank_name',
		'en16931_seller_account_number',
		'en16931_seller_sort_code',
		'en16931_seller_bank_accounts',
		// The SEPA Creditor Identifier is bank and payment data exactly like the IBAN above, so it gets
		// the same at-rest encryption, not a lesser one.
		'en16931_seller_creditor_id',
	);


	/**
	 * Read one shared setting - same contract as `EInvoicePress_Settings::get()`.
	 *
	 * @param	string	$name
	 * @param	mixed	$default	Returned when the value is missing, empty, or fails to decrypt.
	 * @return	mixed
	 * @since	1.0.0
	 */

	public static function get( $name, $default = '' )
	{
		$stored = self::raw();

		if ( ! array_key_exists( (string) $name, $stored ) )
			return $default;

		$value = self::decrypt_if_needed( (string) $name, $stored[ (string) $name ] );

		return ( $value === '' || $value === null ) ? $default : $value;
	}


	/**
	 * Whether a shared setting carries a usable value - same contract as `EInvoicePress_Settings::has()`.
	 *
	 * @param	string	$name
	 * @return	bool
	 * @since	1.0.0
	 */

	public static function has( $name )
	{
		return self::get( $name, '' ) !== '';
	}


	/**
	 * Write one shared setting. Encrypts automatically when the field name is in ENCRYPTED_FIELDS.
	 *
	 * @param	string	$name
	 * @param	mixed	$value
	 * @return	void
	 * @since	1.0.0
	 */

	public static function set( $name, $value )
	{
		$stored = self::raw();

		$stored[ (string) $name ] = in_array( (string) $name, self::ENCRYPTED_FIELDS, true )
			? self::encrypt( (string) $value )
			: $value;

		update_option( self::OPTION, $stored, false );
	}


	/**
	 * Write several shared settings at once - one write op instead of one per field.
	 *
	 * @param	array	$values	name => value
	 * @return	void
	 * @since	1.0.0
	 */

	public static function set_many( array $values )
	{
		$stored = self::raw();

		foreach ( $values as $name => $value )
			$stored[ (string) $name ] = in_array( (string) $name, self::ENCRYPTED_FIELDS, true )
				? self::encrypt( (string) $value )
				: $value;

		update_option( self::OPTION, $stored, false );
	}


	/**
	 * Every stored field, decrypted - the basis for export_json().
	 *
	 * ⚠️ RETURNS CLEARTEXT BANK DATA. Fine for export_json()'s own use ( the admin explicitly asked
	 * for a downloadable file ), but do not log or display this array's contents elsewhere.
	 *
	 * @return	array
	 * @since	1.0.0
	 */

	public static function all()
	{
		$out = array();

		foreach ( self::raw() as $name => $value )
			$out[ $name ] = self::decrypt_if_needed( $name, $value );

		return $out;
	}


	/**
	 * Export every stored field as a JSON string, for moving company master data between a Gravity
	 * Forms installation ( `EInvoicePress_Settings` ) and a Lite one ( this class ), in either
	 * direction, via a file the admin downloads and uploads again. Bank fields are included IN
	 * CLEARTEXT: encryption at rest protects the database, not a file the admin deliberately exported.
	 *
	 * @return	string
	 * @since	1.0.0
	 */

	public static function export_json()
	{
		return (string) wp_json_encode( self::all(), JSON_PRETTY_PRINT );
	}


	/**
	 * Import a JSON string previously produced by export_json() ( or hand-written in the same shape:
	 * a flat `{ "field_name": "value", ... }` object ) - re-encrypts bank fields on the way in.
	 *
	 * @param	string	$json
	 * @return	bool	false if the JSON was not a flat object
	 * @since	1.0.0
	 */

	public static function import_json( $json )
	{
		$decoded = json_decode( (string) $json, true );

		if ( ! is_array( $decoded ) )
			return false;

		foreach ( $decoded as $value )
			if ( is_array( $value ) )
				return false; // a flat object only - nested structures are not this store's shape

		self::set_many( $decoded );

		return true;
	}


	/**
	 * Erase every stored field.
	 *
	 * ⚠️ THIS CLASS OWNS ONLY THE MASTER DATA ITSELF, NOT WHAT A CONSUMER DERIVED FROM IT. Rules or
	 * mapping constants a consumer built from these fields are that consumer's to remove. `delete_all()`
	 * clears the one option row this class owns. There is deliberately no action hook here yet: a hook
	 * without a real subscriber is not added. A consumer that generates derived artefacts adds one
	 * together with its own cleanup.
	 *
	 * @return	void
	 * @since	1.1.0
	 */

	public static function delete_all()
	{
		delete_option( self::OPTION );
	}


	/**
	 * @return	array
	 * @since	1.0.0
	 */

	private static function raw()
	{
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}


	/**
	 * @param	string	$name
	 * @param	mixed	$value
	 * @return	mixed
	 * @since	1.0.0
	 */

	private static function decrypt_if_needed( $name, $value )
	{
		if ( ! in_array( $name, self::ENCRYPTED_FIELDS, true ) )
			return $value;

		if ( ! is_string( $value ) || $value === '' )
			return $value;

		return self::decrypt( $value );
	}


	/**
	 * ⚠️ THE KEY IS DERIVED FROM wp_salt( 'auth' ), NOT A SECRET THIS CLASS INVENTS OR STORES. Every
	 * WordPress install already has this salt ( it signs auth cookies ); reusing it means there is no
	 * new secret to generate, back up, or leak separately. The trade-off, and it is a real one: a
	 * value encrypted here does not survive being moved to a different WordPress install ( different
	 * salts ) or a salt rotation - export_json()/import_json() exist partly BECAUSE of that, as the
	 * supported way to move this data anywhere the salts differ.
	 *
	 * @return	string	32 raw bytes, suitable for aes-256-cbc
	 * @since	1.0.0
	 */

	private static function encryption_key()
	{
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}


	/**
	 * @param	string	$plaintext
	 * @return	string	"<base64 iv>:<base64 ciphertext>", or '' if openssl is unavailable
	 * @since	1.0.0
	 */

	private static function encrypt( $plaintext )
	{
		// ⚠️ NO ':' IN THE FALLBACK OUTPUT. decrypt() uses the presence of ':' to recognise its own
		// format; this branch only fires when openssl itself is missing on this server, a static
		// property of the PHP build that will be just as missing on the next request's decrypt() -
		// so a value written here is read back as-is, never mistaken for ciphertext.
		if ( $plaintext === '' || ! function_exists( 'openssl_encrypt' ) )
			return $plaintext;

		$iv         = openssl_random_pseudo_bytes( 16 );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', self::encryption_key(), OPENSSL_RAW_DATA, $iv );

		if ( $ciphertext === false )
			return $plaintext;

		return base64_encode( $iv ) . ':' . base64_encode( $ciphertext );
	}


	/**
	 * @param	string	$stored	the "<base64 iv>:<base64 ciphertext>" string encrypt() produced
	 * @return	string	the original plaintext, or '' if it cannot be decrypted ( wrong/rotated salt,
	 *					corrupted value, or malformed input - this class never wrote plaintext, so
	 *					there is no legacy format to fall back to )
	 * @since	1.0.0
	 */

	private static function decrypt( $stored )
	{
		// openssl unavailable is the SAME server-wide condition encrypt() checked when this value
		// was written - if it applied then, the value is plaintext, not ciphertext missing its ':'.
		if ( ! function_exists( 'openssl_decrypt' ) )
			return $stored;

		if ( strpos( $stored, ':' ) === false )
			return '';

		list( $iv_b64, $ciphertext_b64 ) = explode( ':', $stored, 2 );

		$iv         = base64_decode( $iv_b64, true );
		$ciphertext = base64_decode( $ciphertext_b64, true );

		if ( $iv === false || $ciphertext === false )
			return '';

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::encryption_key(), OPENSSL_RAW_DATA, $iv );

		return ( $plaintext === false ) ? '' : $plaintext;
	}


}

}
