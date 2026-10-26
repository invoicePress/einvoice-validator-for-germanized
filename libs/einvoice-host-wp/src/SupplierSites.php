<?php
/**
 * The site of a supplier: country, postcode and city ( BT-40, BT-38, BT-37 ), kept beside the supplier list.
 *
 * ⚠️ **WHY A SITE AT ALL** ( Ralf, 19.10.2026 ). A large company often invoices from many sites under one VAT ID, and
 * the sites can be legally separate with accounts of their own; a company can also hold several VAT IDs. Recognised
 * by the VAT ID alone, the second site's account would be reported as the classic fraud pattern "known supplier, new
 * account". With the site the Inbound Check tells "first invoice from this site" from "known site, unknown account".
 *
 * ⚠️ **A CLASS AND AN OPTION OF THEIR OWN, NOT NEW FIELDS IN `SupplierStore`.** Every plugin of the family vendors its
 * own copy of this library, and the copy loaded first wins. An older `SupplierStore` normalises a record to the fields
 * it knows and would drop a site on every save, without a message. A new class name cannot collide that way: an older
 * copy simply has no file for it, and the autoloader of a newer one answers.
 *
 * ⚠️ **ONE SITE PER SUPPLIER RECORD.** A company with three sites is three records with the same VAT ID, each with its
 * own accounts. A record without a site is an entry from before 1.5.0 of this library and matches any site.
 *
 * ⚠️ **NO AUTOLOAD.** The list is needed only by the add-ons that check invoices.
 *
 * @package SpecialPress\EInvoice\Host
 * @since	1.5.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;


class SupplierSites
{

	const OPTION = 'einvoice_masterdata_supplier_sites';


	/**
	 * @return	array<string,array{country:string,postcode:string,city:string}>	supplier id => site
	 */

	public static function all()
	{
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}


	/**
	 * @param	string	$id	supplier id
	 * @return	array{country:string,postcode:string,city:string}|null
	 */

	public static function get( $id )
	{
		$all = self::all();

		return isset( $all[ (string) $id ] ) ? $all[ (string) $id ] : null;
	}


	/**
	 * Stores the site of one supplier; an empty site removes it.
	 *
	 * @param	string	$id
	 * @param	array	$site	country, postcode, city
	 * @return	void
	 */

	public static function set( $id, array $site )
	{
		$all  = self::all();
		$norm = self::normalize( $site );

		if ( $norm[ 'country' ] === '' && $norm[ 'postcode' ] === '' && $norm[ 'city' ] === '' )
			unset( $all[ (string) $id ] );
		else
			$all[ (string) $id ] = $norm;

		update_option( self::OPTION, $all, false );
	}


	/**
	 * Whether a stored site is the site of an invoice.
	 *
	 * ⚠️ **ONLY WHAT BOTH SIDES NAME IS COMPARED.** An invoice without a postcode does not contradict a stored one. Case
	 * and spacing do not count, and a postcode loses its spaces ( "SW1A 1AA" = "SW1A1AA" ).
	 *
	 * @param	array	$stored
	 * @param	array	$invoice
	 * @return	bool
	 */

	public static function same( array $stored, array $invoice )
	{
		$a = self::normalize( $stored );
		$b = self::normalize( $invoice );

		foreach ( array( 'country', 'postcode', 'city' ) as $key )
			if ( $a[ $key ] !== '' && $b[ $key ] !== '' && $a[ $key ] !== $b[ $key ] )
				return false;

		return true;
	}


	/**
	 * @param	array	$site
	 * @return	array{country:string,postcode:string,city:string}
	 */

	public static function normalize( array $site )
	{
		$text = static function ( $v ) {
			return trim( (string) preg_replace( '/\s+/', ' ', mb_strtolower( (string) $v ) ) );
		};

		$country = strtoupper( trim( (string) ( $site[ 'country' ] ?? '' ) ) );

		// "DE - Germany" and "Deutschland (DE)" are display texts of the viewer's model; only the code is kept.
		if ( preg_match( '/^([A-Z]{2})\b/', $country, $m ) || preg_match( '/\(([A-Z]{2})\)/', $country, $m ) )
			$country = $m[ 1 ];

		return array(
			'country'  => $country,
			'postcode' => strtoupper( (string) preg_replace( '/\s+/', '', (string) ( $site[ 'postcode' ] ?? '' ) ) ),
			'city'     => $text( $site[ 'city' ] ?? '' ),
		);
	}


}
