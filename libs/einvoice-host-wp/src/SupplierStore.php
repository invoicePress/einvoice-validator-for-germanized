<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The supplier list of the inbound role. `MasterDataStore` next to this file is a FLAT store, one
 * field name and one value, which fits the buyer's own company data perfectly, since that is a single
 * record. A supplier list is not: it is N records, each with its OWN small history of bank accounts
 * ( the known IBAN and BIC with the date first seen ), so it needs its own collection API and its own
 * WP option rather than a wedge fit into MasterDataStore's single-record shape.
 *
 * Consumed by the Inbound Check viewer extension: the checks "IBAN/BIC known" and "plausibility against
 * the supplier profile" read this list; the "Adopt" button is this class's add_account_sighting(),
 * never a direct field write. A new IBAN is only ever appended after the caller has an explicit
 * confirmation from the user, which is why that method has no "silent overwrite" path at all.
 *
 * @package SpecialPress\EInvoice\Host
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\SupplierStore' ) )
{

class SupplierStore
{


	/** @var string the single WP option every supplier record lives in */
	const OPTION = 'einvoice_masterdata_suppliers';


	/**
	 * Read every supplier record, decrypted, in a DETERMINISTIC order ( sorted by id ). Callers that
	 * export this list ( export_csv() below, and anything a later UI adds ) get the same bytes back for
	 * the same underlying data, so an import followed by an export is byte-identical.
	 *
	 * @return	array<int,array>	each entry normalized ( see normalize_record() )
	 * @since	1.0.0
	 */

	public static function all()
	{
		$stored = self::raw();

		ksort( $stored, SORT_STRING );

		$out = array();

		foreach ( $stored as $id => $record )
			$out[] = self::decrypt_record( $id, $record );

		return $out;
	}


	/**
	 * @param	string	$id
	 * @return	array|null
	 * @since	1.0.0
	 */

	public static function get( $id )
	{
		$stored = self::raw();
		$id     = (string) $id;

		return isset( $stored[ $id ] ) ? self::decrypt_record( $id, $stored[ $id ] ) : null;
	}


	/**
	 * Create or replace one supplier record. Encrypts every account's IBAN and BIC on the way in.
	 *
	 * ⚠️ THIS REPLACES THE WHOLE RECORD, INCLUDING 'accounts'. It is meant for manual maintenance and
	 * CSV import, where the caller already has the full, intended record in hand. To add ONE new
	 * account sighting to an EXISTING supplier without touching the rest of its history, use
	 * add_account_sighting() instead; that is the method the "adopt from a checked invoice" button
	 * must call, never this one.
	 *
	 * @param	array	$record	see normalize_record() for the accepted shape; 'id' is optional - a
	 *					missing or empty id creates a new record and returns its generated id.
	 * @return	string	the record's id ( generated if none was given )
	 * @since	1.0.0
	 */

	public static function upsert( array $record )
	{
		$stored = self::raw();

		$normalized = self::normalize_record( $record );
		$id         = $normalized[ 'id' ] !== '' ? $normalized[ 'id' ] : self::generate_id( $normalized[ 'name' ], $stored );

		$normalized[ 'id' ]      = $id;
		$normalized[ 'created' ] = isset( $stored[ $id ][ 'created' ] ) ? $stored[ $id ][ 'created' ] : $normalized[ 'created' ];
		$normalized[ 'updated' ] = gmdate( 'Y-m-d' );

		$stored[ $id ] = self::encrypt_record( $normalized );

		update_option( self::OPTION, $stored, false );

		return $id;
	}


	/**
	 * Append exactly one new bank-account sighting to an existing supplier, without disturbing any
	 * other field or any other account already on file. A duplicate IBAN ( already on this supplier's
	 * record ) is a no-op, not a second entry with a later "first seen" date - the date that matters is
	 * genuinely the FIRST one.
	 *
	 * @param	string	$id
	 * @param	string	$iban
	 * @param	string	$bic
	 * @param	string	$seen_on	'Y-m-d'; defaults to today
	 * @return	bool	false if the supplier id does not exist
	 * @since	1.0.0
	 */

	public static function add_account_sighting( $id, $iban, $bic, $seen_on = '' )
	{
		$record = self::get( (string) $id );

		if ( $record === null )
			return false;

		$iban = self::normalize_iban( (string) $iban );

		foreach ( $record[ 'accounts' ] as $existing )
			if ( $existing[ 'iban' ] === $iban )
				return true; // already known - the "never seen before" moment has already happened

		$record[ 'accounts' ][] = array(
			'iban'       => $iban,
			'bic'        => strtoupper( trim( (string) $bic ) ),
			'first_seen' => ( $seen_on !== '' ) ? $seen_on : gmdate( 'Y-m-d' ),
		);

		self::upsert( $record );

		return true;
	}


	/**
	 * @param	string	$id
	 * @return	bool	false if the id did not exist
	 * @since	1.0.0
	 */

	public static function delete( $id )
	{
		$stored = self::raw();
		$id     = (string) $id;

		if ( ! isset( $stored[ $id ] ) )
			return false;

		unset( $stored[ $id ] );

		update_option( self::OPTION, $stored, false );

		return true;
	}


	/**
	 * The check "IBAN known": is this IBAN known, and if so, for which supplier?
	 *
	 * @param	string	$iban
	 * @return	array|null	the supplier record the IBAN belongs to, or null if no supplier carries it
	 * @since	1.0.0
	 */

	public static function find_by_iban( $iban )
	{
		$iban = self::normalize_iban( (string) $iban );

		foreach ( self::all() as $record )
			foreach ( $record[ 'accounts' ] as $account )
				if ( $account[ 'iban' ] === $iban )
					return $record;

		return null;
	}


	/**
	 * Find a supplier by one of its identifiers ( VAT id, GLN, ... - whatever scheme the invoice
	 * carries ). Used by check (a)/(e) to decide "is this seller a known supplier at all", which in
	 * turn decides whether an unknown IBAN is merely "first time seen" or "known supplier, wrong
	 * account" ( the highest-severity finding the roadmap text asks for ).
	 *
	 * @param	string	$scheme	e.g. 'VAT', 'GLN' - matched case-insensitively
	 * @param	string	$value
	 * @return	array|null
	 * @since	1.0.0
	 */

	public static function find_by_identifier( $scheme, $value )
	{
		$scheme = strtoupper( trim( (string) $scheme ) );
		$value  = trim( (string) $value );

		if ( $value === '' )
			return null;

		foreach ( self::all() as $record )
			foreach ( $record[ 'identifiers' ] as $identifier )
				if ( strtoupper( $identifier[ 'scheme' ] ) === $scheme && $identifier[ 'value' ] === $value )
					return $record;

		return null;
	}


	/**
	 * Every supplier record as CSV, decrypted, one line per supplier. Column order and every sub-list
	 * inside a cell are written in a fixed, deterministic order ( see normalize_record() and all() ), so
	 * exporting an unchanged list twice produces byte-identical output.
	 *
	 * @return	string
	 * @since	1.0.0
	 */

	public static function export_csv()
	{
		/**
		 * ⚠️ `php://temp` IS NO FILE SYSTEM. The WordPress rule
		 * `WordPress.WP.AlternativeFunctions.file_system_operations_*` asks for `WP_Filesystem` instead
		 * of `fopen()`: right for real files, pointless for a memory stream. `fputcsv()` needs a stream
		 * handle; `WP_Filesystem` has none and could not step in here.
		 *
		 * ⚠️ THE COMMENT MUST STAND DIRECTLY ABOVE THE LINE. With a blank line or another comment in
		 * between, `phpcs:ignore` applies to the wrong line, silently and without an error message.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is a memory stream, not a file system; fputcsv() needs a stream handle.
		$fh = fopen( 'php://temp', 'r+' );

		fputcsv( $fh, array( 'id', 'name', 'identifiers', 'accounts', 'currency', 'payment_terms_days', 'created', 'updated' ) );

		foreach ( self::all() as $record )
			fputcsv( $fh, array(
				$record[ 'id' ],
				$record[ 'name' ],
				self::identifiers_to_cell( $record[ 'identifiers' ] ),
				self::accounts_to_cell( $record[ 'accounts' ] ),
				$record[ 'currency' ],
				( $record[ 'payment_terms_days' ] !== null ) ? (string) $record[ 'payment_terms_days' ] : '',
				$record[ 'created' ],
				$record[ 'updated' ],
			) );

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Gegenstueck zum fopen( 'php://temp' ) oben.
		fclose( $fh );

		return (string) $csv;
	}


	/**
	 * Import supplier records from CSV in the shape export_csv() produces. Every row is an upsert keyed
	 * by the 'id' column when present, so re-importing an unchanged export is idempotent rather than
	 * creating duplicates.
	 *
	 * @param	string	$csv
	 * @return	array	array{ imported: int, errors: string[] }
	 * @since	1.0.0
	 */

	public static function import_csv( $csv )
	{
		// ⚠️ As in export_csv(): php://temp is a memory stream, not a file system. fgetcsv() needs a
		// stream handle, which WP_Filesystem cannot provide.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- memory stream, not a file system.
		$fh = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- schreibt in den Speicherstrom von oben.
		fwrite( $fh, (string) $csv );
		rewind( $fh );

		$header    = fgetcsv( $fh );
		$imported  = 0;
		$errors    = array();
		$row_index = 1; // header was row 0

		if ( $header === false )
		{
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Gegenstueck zum fopen( 'php://temp' ) oben.
			fclose( $fh );
			return array( 'imported' => 0, 'errors' => array( 'empty file' ) );
		}

		$columns = array_flip( $header );

		while ( ( $row = fgetcsv( $fh ) ) !== false )
		{
			$row_index++;

			$name = isset( $columns[ 'name' ] ) ? trim( (string) ( $row[ $columns[ 'name' ] ] ?? '' ) ) : '';

			if ( $name === '' )
			{
				$errors[] = "row $row_index: missing name, skipped";
				continue;
			}

			$id = isset( $columns[ 'id' ] ) ? (string) ( $row[ $columns[ 'id' ] ] ?? '' ) : '';

			/*
			 * ⚠️ A COLUMN THE FILE DOES NOT HAVE KEEPS THE STORED VALUE; IT DOES NOT EMPTY IT. upsert()
			 * replaces a whole record. Treating a missing column as an empty field would make a file with
			 * only `id`, `name` and `currency` ( the obvious way to fill in currencies ) wipe every bank
			 * account and identifier of each supplier it names. A column that IS present but empty still
			 * empties the field: that is what the operator wrote.
			 */
			// ⚠️ sanitize_key() BEFORE THE LOOKUP: upsert() stores the id sanitised, so " Acme-GmbH" in the file
			// would otherwise find nothing here and then overwrite "acme-gmbh" with empty fields.
			$existing = $id !== '' ? self::get( sanitize_key( $id ) ) : null;
			$keep     = static function ( $field ) use ( $existing ) {
				return $existing !== null ? $existing[ $field ] : null;
			};

			self::upsert( array(
				'id'                 => $id,
				'name'               => $name,
				'identifiers'        => isset( $columns[ 'identifiers' ] )        ? self::identifiers_from_cell( (string) ( $row[ $columns[ 'identifiers' ] ] ?? '' ) ) : ( $keep( 'identifiers' ) ?? array() ),
				'accounts'           => isset( $columns[ 'accounts' ] )           ? self::accounts_from_cell( (string) ( $row[ $columns[ 'accounts' ] ] ?? '' ) )       : ( $keep( 'accounts' ) ?? array() ),
				'currency'           => isset( $columns[ 'currency' ] )           ? (string) ( $row[ $columns[ 'currency' ] ] ?? '' )           : (string) ( $keep( 'currency' ) ?? '' ),
				'payment_terms_days' => isset( $columns[ 'payment_terms_days' ] ) ? (string) ( $row[ $columns[ 'payment_terms_days' ] ] ?? '' ) : (string) ( $keep( 'payment_terms_days' ) ?? '' ),
				'created'            => isset( $columns[ 'created' ] )            ? (string) ( $row[ $columns[ 'created' ] ] ?? '' )            : (string) ( $keep( 'created' ) ?? '' ),
			) );

			$imported++;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Gegenstueck zum fopen( 'php://temp' ) oben.
		fclose( $fh );

		return array( 'imported' => $imported, 'errors' => $errors );
	}


	// # NORMALIZATION AND SERIALIZATION -----------------------------------------------------------

	/**
	 * @param	array	$record
	 * @return	array	the canonical shape every stored/returned record has
	 * @since	1.0.0
	 */

	private static function normalize_record( array $record )
	{
		$identifiers = array();

		foreach ( (array) ( $record[ 'identifiers' ] ?? array() ) as $entry )
			if ( is_array( $entry ) && trim( (string) ( $entry[ 'scheme' ] ?? '' ) ) !== '' && trim( (string) ( $entry[ 'value' ] ?? '' ) ) !== '' )
				$identifiers[] = array(
					'scheme' => strtoupper( trim( (string) $entry[ 'scheme' ] ) ),
					'value'  => trim( (string) $entry[ 'value' ] ),
				);

		usort( $identifiers, static function ( $a, $b ) { return strcmp( $a[ 'scheme' ] . $a[ 'value' ], $b[ 'scheme' ] . $b[ 'value' ] ); } );

		$accounts = array();

		foreach ( (array) ( $record[ 'accounts' ] ?? array() ) as $entry )
		{
			if ( ! is_array( $entry ) )
				continue;

			$iban = self::normalize_iban( (string) ( $entry[ 'iban' ] ?? '' ) );

			if ( $iban === '' )
				continue;

			$accounts[] = array(
				'iban'       => $iban,
				'bic'        => strtoupper( trim( (string) ( $entry[ 'bic' ] ?? '' ) ) ),
				'first_seen' => self::normalize_date( (string) ( $entry[ 'first_seen' ] ?? '' ) ),
			);
		}

		usort( $accounts, static function ( $a, $b ) {
			return ( $a[ 'first_seen' ] === $b[ 'first_seen' ] ) ? strcmp( $a[ 'iban' ], $b[ 'iban' ] ) : strcmp( $a[ 'first_seen' ], $b[ 'first_seen' ] );
		} );

		return array(
			'id'                 => sanitize_key( (string) ( $record[ 'id' ] ?? '' ) ),
			'name'               => trim( (string) ( $record[ 'name' ] ?? '' ) ),
			'identifiers'        => $identifiers,
			'accounts'           => $accounts,
			'currency'           => strtoupper( trim( (string) ( $record[ 'currency' ] ?? '' ) ) ),
			'payment_terms_days' => ( trim( (string) ( $record[ 'payment_terms_days' ] ?? '' ) ) !== '' ) ? (int) $record[ 'payment_terms_days' ] : null,
			'created'            => self::normalize_date( (string) ( $record[ 'created' ] ?? '' ) ) ?: gmdate( 'Y-m-d' ),
			'updated'            => gmdate( 'Y-m-d' ),
		);
	}


	/**
	 * @param	string	$value
	 * @return	string	'' when unparseable, otherwise 'Y-m-d'
	 * @since	1.0.0
	 */

	private static function normalize_date( $value )
	{
		$value = trim( $value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) )
			return '';

		return $value;
	}


	/**
	 * @param	string	$iban
	 * @return	string	uppercase, spaces removed
	 * @since	1.0.0
	 */

	private static function normalize_iban( $iban )
	{
		return strtoupper( str_replace( array( ' ', "\t" ), '', trim( $iban ) ) );
	}


	/**
	 * @param	string	$name
	 * @param	array	$existing	the raw store, to avoid a collision
	 * @return	string
	 * @since	1.0.0
	 */

	private static function generate_id( $name, array $existing )
	{
		$base = sanitize_key( $name );

		if ( $base === '' )
			$base = 'supplier';

		$id  = $base;
		$n   = 1;

		while ( isset( $existing[ $id ] ) )
			$id = $base . '-' . ( ++$n );

		return $id;
	}


	/**
	 * @param	array	$identifiers
	 * @return	string	"SCHEME:value;SCHEME:value" - already sorted by normalize_record()
	 * @since	1.0.0
	 */

	private static function identifiers_to_cell( array $identifiers )
	{
		$parts = array();

		foreach ( $identifiers as $entry )
			$parts[] = $entry[ 'scheme' ] . ':' . $entry[ 'value' ];

		return implode( ';', $parts );
	}


	/**
	 * @param	string	$cell
	 * @return	array
	 * @since	1.0.0
	 */

	private static function identifiers_from_cell( $cell )
	{
		$out = array();

		foreach ( explode( ';', $cell ) as $part )
		{
			$part = trim( $part );

			if ( $part === '' || strpos( $part, ':' ) === false )
				continue;

			list( $scheme, $value ) = explode( ':', $part, 2 );

			$out[] = array( 'scheme' => $scheme, 'value' => $value );
		}

		return $out;
	}


	/**
	 * @param	array	$accounts
	 * @return	string	"IBAN|BIC|Y-m-d;IBAN|BIC|Y-m-d" - already sorted by normalize_record()
	 * @since	1.0.0
	 */

	private static function accounts_to_cell( array $accounts )
	{
		$parts = array();

		foreach ( $accounts as $entry )
			$parts[] = $entry[ 'iban' ] . '|' . $entry[ 'bic' ] . '|' . $entry[ 'first_seen' ];

		return implode( ';', $parts );
	}


	/**
	 * @param	string	$cell
	 * @return	array
	 * @since	1.0.0
	 */

	private static function accounts_from_cell( $cell )
	{
		$out = array();

		foreach ( explode( ';', $cell ) as $part )
		{
			$part = trim( $part );

			if ( $part === '' )
				continue;

			$fields = explode( '|', $part );

			$out[] = array(
				'iban'       => $fields[ 0 ] ?? '',
				'bic'        => $fields[ 1 ] ?? '',
				'first_seen' => $fields[ 2 ] ?? '',
			);
		}

		return $out;
	}


	// # STORAGE AND ENCRYPTION ---------------------------------------------------------------------

	/**
	 * @return	array<string,array>	id => raw ( still-encrypted ) record
	 * @since	1.0.0
	 */

	private static function raw()
	{
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}


	/**
	 * @param	array	$record	a normalize_record()-shaped record with CLEARTEXT accounts
	 * @return	array	the same record with every account's iban/bic encrypted
	 * @since	1.0.0
	 */

	private static function encrypt_record( array $record )
	{
		foreach ( $record[ 'accounts' ] as &$account )
		{
			$account[ 'iban' ] = self::encrypt( $account[ 'iban' ] );
			$account[ 'bic' ]  = self::encrypt( $account[ 'bic' ] );
		}

		return $record;
	}


	/**
	 * @param	string	$id
	 * @param	array	$record	as stored ( accounts encrypted )
	 * @return	array	the same record with every account's iban/bic decrypted, plus 'id' filled in
	 * @since	1.0.0
	 */

	private static function decrypt_record( $id, array $record )
	{
		$record[ 'id' ] = (string) $id;

		foreach ( $record[ 'accounts' ] as &$account )
		{
			$account[ 'iban' ] = self::decrypt( $account[ 'iban' ] );
			$account[ 'bic' ]  = self::decrypt( $account[ 'bic' ] );
		}

		return $record;
	}


	/**
	 * ⚠️ SAME SCHEME AS MasterDataStore::encryption_key() - deliberately duplicated rather than shared,
	 * because the two classes are independent, separately copyable units ( see that class's own header
	 * for why ). Both derive the key from wp_salt( 'auth' ); a value encrypted by one is therefore
	 * readable by the other, but neither actually calls into the other's code.
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
	 * @return	string	"<base64 iv>:<base64 ciphertext>", or the input unchanged if openssl is unavailable or the input is empty
	 * @since	1.0.0
	 */

	private static function encrypt( $plaintext )
	{
		if ( $plaintext === '' || ! function_exists( 'openssl_encrypt' ) )
			return $plaintext;

		$iv         = openssl_random_pseudo_bytes( 16 );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', self::encryption_key(), OPENSSL_RAW_DATA, $iv );

		if ( $ciphertext === false )
			return $plaintext;

		return base64_encode( $iv ) . ':' . base64_encode( $ciphertext );
	}


	/**
	 * @param	string	$stored
	 * @return	string	the original plaintext, or '' if it cannot be decrypted
	 * @since	1.0.0
	 */

	private static function decrypt( $stored )
	{
		if ( ! function_exists( 'openssl_decrypt' ) )
			return $stored;

		if ( $stored === '' || strpos( $stored, ':' ) === false )
			return $stored;

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
