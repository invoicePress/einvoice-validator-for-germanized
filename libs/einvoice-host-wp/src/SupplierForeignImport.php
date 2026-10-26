<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Suppliers from the export of a bookkeeping program - DATEV, and lists with the usual column names such as those of
 * Lexware and sevdesk - turned into the CSV `SupplierStore::import_csv()` reads.
 *
 *   SupplierForeignImport::to_store_csv( $bytes )   null when the file is none of these exports, else
 *                                                   array( 'csv' => …, 'suppliers' => n, 'skipped_debtors' => n, 'skipped_no_key' => n )
 *
 * ⚠️ **THE TWIN IS `libs/einvoice-core-js/src/inbound/supplierImport.mjs`.** Recognition, the columns read, the rule
 * "creditors only" and the joined VAT ID are the same; the reasons stand there and are not repeated here. Only what is
 * PHP's own stands here.
 *
 * ⚠️ **A NEW CLASS, NOT A NEW METHOD OF `SupplierStore`.** Every plugin carries its own copy of this package, and of
 * several copies the first one loaded serves the whole site ( `class_exists` guard ). An older copy of `SupplierStore`
 * without the new method would win and end the import in a fatal error. The callers ask `class_exists()` for this class
 * and fall back to the own format when it is missing.
 *
 * ⚠️ **AN IMPORT AGAIN CREATES NO SECOND SUPPLIER AND DROPS NO ACCOUNT.** A supplier already on the list is found by its
 * VAT ID, else its tax number; its id goes into the CSV, so `import_csv()` updates instead of adding. Its known
 * accounts and identifiers stay, and the export only adds what is new: an account the bookkeeping program does not
 * name must not vanish from the list that guards against forged bank details.
 *
 * @package SpecialPress\EInvoice\Host
 * @since   1.7.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\SupplierForeignImport' ) )
{

class SupplierForeignImport
{

	/** The column names of a contact list, compared without case, spaces, dots, hyphens, slashes and brackets. */
	const ALIASES = array(
		'company' => array( 'firma', 'firmenname', 'organisation', 'unternehmen', 'unternehmensname', 'lieferant', 'lieferantenname', 'kreditor', 'kreditorname', 'company', 'companyname', 'supplier', 'suppliername' ),
		'name'    => array( 'name', 'name1', 'bezeichnung', 'kontobezeichnung' ),
		'vat'     => array( 'ustidnr', 'ustid', 'ustidentnr', 'ustidentifikationsnummer', 'umsatzsteueridnr', 'umsatzsteueridentifikationsnummer', 'euustidnr', 'vatid', 'vat', 'vatnumber' ),
		'tax_no'  => array( 'steuernummer', 'steuernr', 'taxnumber', 'taxno' ),
		'iban'    => array( 'iban', 'iban1', 'ibannr', 'ibannr1', 'bankverbindungiban', 'bankiban' ),
		'bic'     => array( 'bic', 'bic1', 'swift', 'swiftcode', 'swiftbic', 'bankbic' ),
	);


	/**
	 * @param	string	$bytes	the uploaded file as read
	 * @return	array|null
	 */

	public static function to_store_csv( $bytes )
	{
		$found = self::read( self::decode( (string) $bytes ) );

		if ( $found === null )
			return null;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is a memory stream, not a file system; fputcsv() needs a stream handle.
		$fh = fopen( 'php://temp', 'r+' );

		fputcsv( $fh, array( 'id', 'name', 'identifiers', 'accounts' ) );

		$today = gmdate( 'Y-m-d' );

		foreach ( $found[ 'suppliers' ] as $s )
		{
			$existing = null;

			if ( $s[ 'vat_id' ] !== '' )
				$existing = SupplierStore::find_by_identifier( 'VAT', $s[ 'vat_id' ] );

			// ⚠️ The tax number under both names the store knows: `TAX` from the form of the supplier list, `FC` from the
			// adoption button of the Inbound Check. It is written as `TAX`, the name of the page where suppliers are kept.
			foreach ( array( 'TAX', 'FC' ) as $tax_scheme )
				if ( $existing === null && $s[ 'tax_no' ] !== '' )
					$existing = SupplierStore::find_by_identifier( $tax_scheme, $s[ 'tax_no' ] );

			$identifiers = $existing ? $existing[ 'identifiers' ] : array();
			$accounts    = $existing ? $existing[ 'accounts' ] : array();

			foreach ( array( 'VAT' => $s[ 'vat_id' ], 'TAX' => $s[ 'tax_no' ] ) as $scheme => $value )
				if ( $value !== '' && ! self::has( $identifiers, 'scheme', $scheme, 'value', $value ) && ! ( $scheme === 'TAX' && self::has( $identifiers, 'scheme', 'FC', 'value', $value ) ) )
					$identifiers[] = array( 'scheme' => $scheme, 'value' => $value );

			foreach ( $s[ 'accounts' ] as $a )
				if ( ! self::has( $accounts, 'iban', $a[ 'iban' ] ) )
					$accounts[] = array( 'iban' => $a[ 'iban' ], 'bic' => $a[ 'bic' ], 'first_seen' => $today );

			fputcsv( $fh, array(
				$existing ? (string) $existing[ 'id' ] : '',
				$s[ 'name' ] !== '' ? $s[ 'name' ] : ( $existing ? (string) $existing[ 'name' ] : '' ),
				implode( ';', array_map( static function ( $i ) { return $i[ 'scheme' ] . ':' . $i[ 'value' ]; }, $identifiers ) ),
				implode( ';', array_map( static function ( $a ) { return $a[ 'iban' ] . '|' . $a[ 'bic' ] . '|' . $a[ 'first_seen' ]; }, $accounts ) ),
			) );
		}

		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- counterpart of the fopen( 'php://temp' ) above.
		fclose( $fh );

		return array(
			'csv'             => $csv,
			'suppliers'       => count( $found[ 'suppliers' ] ),
			'skipped_debtors' => $found[ 'skipped_debtors' ],
			'skipped_no_key'  => $found[ 'skipped_no_key' ],
		);
	}


	/**
	 * The text of the file: UTF-8 when it is valid UTF-8, else Windows-1252, as DATEV writes it.
	 *
	 * @param	string	$bytes
	 * @return	string
	 */

	public static function decode( $bytes )
	{
		$bytes = (string) $bytes;

		if ( strncmp( $bytes, "\xEF\xBB\xBF", 3 ) === 0 )
			$bytes = substr( $bytes, 3 );

		if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $bytes, 'UTF-8' ) )
			return $bytes;

		return function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' ) : $bytes;
	}


	/**
	 * @param	string	$text
	 * @return	array|null	array( 'format', 'suppliers' => list of array( name, vat_id, tax_no, accounts, source ), 'skipped_debtors', 'skipped_no_key' )
	 */

	public static function read( $text )
	{
		$lines = preg_split( '/\r\n|\n|\r/', (string) $text );

		if ( ! is_array( $lines ) )
			return null;

		if ( end( $lines ) === '' )
			array_pop( $lines );

		if ( ! $lines )
			return null;

		if ( preg_match( '/^"?EXTF"?;/', $lines[0] ) )
			return preg_match( '#Debitoren/Kreditoren#i', $lines[0] ) ? self::read_datev( $lines ) : null;

		return self::read_columns( $lines );
	}


	private static function read_datev( array $lines )
	{
		$header = array_map( 'trim', str_getcsv( $lines[1] ?? '', ';', '"', '' ) );
		$col    = static function ( $re ) use ( $header ) {
			foreach ( $header as $i => $h )
				if ( preg_match( $re, $h ) )
					return $i;
			return -1;
		};

		$konto   = $col( '/^Konto$/' );
		$company = $col( '/^Name \(Adressat(en)?typ Unternehmen\)$/u' );
		$person  = $col( '/^Name \(Adressat(en)?typ natürl\. Person\)$/u' );
		$first   = $col( '/^Vorname \(Adressat(en)?typ natürl\. Person\)$/u' );
		$none    = $col( '/^Name \(Adressat(en)?typ keine Angabe\)$/u' );
		$short   = $col( '/^Kurzbezeichnung$/' );
		$eu_land = $col( '/^EU-(Land|Mitgliedsstaat|Mitgliedstaat)$/' );
		$eu_id   = $col( '/^EU-(USt-IdNr\.|UStID)$/' );
		$tax_no  = $col( '/^Steuernummer$/' );

		$banks = array();

		for ( $k = 1; $k <= 10; $k++ )
		{
			$iban = $col( '/^IBAN(-Nr\.)? ' . $k . '$/' );

			if ( $iban !== -1 )
				$banks[] = array( 'iban' => $iban, 'bic' => $col( '/^SWIFT-Code ' . $k . '$/' ) );
		}

		$out = array( 'format' => 'datev', 'suppliers' => array(), 'skipped_debtors' => 0, 'skipped_no_key' => 0 );

		foreach ( array_slice( $lines, 2 ) as $line )
		{
			if ( trim( $line ) === '' )
				continue;

			$c       = str_getcsv( $line, ';', '"', '' );
			$account = self::at( $c, $konto );

			if ( preg_match( '/^[1-6]/', $account ) )
			{
				$out[ 'skipped_debtors' ]++;
				continue;
			}

			$name = self::at( $c, $company );

			if ( $name === '' )
				$name = self::at( $c, $none );

			if ( $name === '' )
				$name = trim( self::at( $c, $first ) . ' ' . self::at( $c, $person ) );

			if ( $name === '' )
				$name = self::at( $c, $short );

			$country = strtoupper( self::at( $c, $eu_land ) );
			$rest    = strtoupper( (string) preg_replace( '/\s+/', '', self::at( $c, $eu_id ) ) );
			$vat_id  = $rest === '' ? '' : ( preg_match( '/^[A-Z]{2}/', $rest ) ? $rest : $country . $rest );

			$accounts = array();

			foreach ( $banks as $b )
			{
				$iban = self::iban( self::at( $c, $b[ 'iban' ] ) );

				if ( $iban !== '' )
					$accounts[] = array( 'iban' => $iban, 'bic' => strtoupper( self::at( $c, $b[ 'bic' ] ) ) );
			}

			self::push( $out, array( 'name' => $name, 'vat_id' => $vat_id, 'tax_no' => self::at( $c, $tax_no ), 'accounts' => $accounts, 'source' => 'DATEV ' . $account ) );
		}

		return $out;
	}


	private static function read_columns( array $lines )
	{
		$sep    = self::separator( $lines[0] );
		$header = array_map( array( __CLASS__, 'squash' ), str_getcsv( $lines[0], $sep, '"', '' ) );
		$find   = static function ( array $list ) use ( $header ) {
			foreach ( $header as $i => $h )
				if ( in_array( $h, $list, true ) )
					return $i;
			return -1;
		};

		$idx = array();

		foreach ( self::ALIASES as $field => $list )
			$idx[ $field ] = $find( $list );

		if ( $idx[ 'company' ] === -1 && $idx[ 'name' ] === -1 )
			return null;

		if ( $idx[ 'vat' ] === -1 && $idx[ 'tax_no' ] === -1 )
			return null;

		$out = array( 'format' => 'columns', 'suppliers' => array(), 'skipped_debtors' => 0, 'skipped_no_key' => 0 );

		foreach ( array_slice( $lines, 1 ) as $line )
		{
			if ( trim( $line ) === '' )
				continue;

			$c    = str_getcsv( $line, $sep, '"', '' );
			$iban = self::iban( self::at( $c, $idx[ 'iban' ] ) );
			$name = self::at( $c, $idx[ 'company' ] );

			self::push( $out, array(
				'name'     => $name !== '' ? $name : self::at( $c, $idx[ 'name' ] ),
				'vat_id'   => strtoupper( (string) preg_replace( '/\s+/', '', self::at( $c, $idx[ 'vat' ] ) ) ),
				'tax_no'   => self::at( $c, $idx[ 'tax_no' ] ),
				'accounts' => $iban !== '' ? array( array( 'iban' => $iban, 'bic' => strtoupper( self::at( $c, $idx[ 'bic' ] ) ) ) ) : array(),
				'source'   => '',
			) );
		}

		return $out;
	}


	private static function push( array &$out, array $s )
	{
		if ( $s[ 'vat_id' ] === '' && $s[ 'tax_no' ] === '' )
		{
			$out[ 'skipped_no_key' ]++;
			return;
		}

		$out[ 'suppliers' ][] = $s;
	}


	private static function has( array $list, $k1, $v1, $k2 = null, $v2 = null )
	{
		foreach ( $list as $e )
			if ( strtoupper( (string) ( $e[ $k1 ] ?? '' ) ) === strtoupper( (string) $v1 ) && ( $k2 === null || (string) ( $e[ $k2 ] ?? '' ) === (string) $v2 ) )
				return true;

		return false;
	}


	private static function at( array $cells, $i )
	{
		return ( $i >= 0 && isset( $cells[ $i ] ) ) ? trim( (string) $cells[ $i ] ) : '';
	}


	private static function iban( $value )
	{
		return strtoupper( (string) preg_replace( '/\s+/', '', (string) $value ) );
	}


	private static function separator( $line )
	{
		$semi  = substr_count( $line, ';' );
		$comma = substr_count( $line, ',' );
		$tab   = substr_count( $line, "\t" );

		return ( $tab > $semi && $tab > $comma ) ? "\t" : ( $comma > $semi ? ',' : ';' );
	}


	private static function squash( $s )
	{
		$s = mb_strtolower( (string) $s, 'UTF-8' );
		$s = (string) preg_replace( '#[\s.\-_/()]+#u', '', $s );

		return strtr( $s, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u' ) );
	}

}

}
