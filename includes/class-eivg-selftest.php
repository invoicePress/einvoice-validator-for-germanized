<?php
/**
 * The self-test: settings that would make every invoice fail, found before the first order.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Selftest
{

	/** @return array[] list of { ok: bool, label: string, hint: string } */
	public static function run()
	{
		$out = array();

		$out[] = array(
			'ok'    => version_compare( PHP_VERSION, '8.2', '>=' ),
			/* translators: %s: PHP version */
			'label' => sprintf( __( 'PHP 8.2 or newer ( this server: %s )', 'einvoice-validator-for-germanized' ), PHP_VERSION ),
			'hint'  => __( 'Ask your host for a newer PHP version.', 'einvoice-validator-for-germanized' ),
		);
		$out[] = array(
			'ok'    => EIVG_Engine::available(),
			'label' => __( 'The checking engine VAMPIRE and its rules are installed', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'Install the plugin again from the original ZIP file.', 'einvoice-validator-for-germanized' ),
		);

		// ⚠️ MEASURED, NOT ASSUMED: wp_raise_memory_limit( 'admin' ) asks for 256 MB, and a host may refuse it.
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$max   = wp_convert_hr_to_bytes( (string) apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the core filter that wp_raise_memory_limit( 'admin' ) reads.

		$out[] = array(
			'ok'    => $limit < 0 || max( $limit, $max ) >= 256 * MB_IN_BYTES,
			'label' => __( 'At least 256 MB memory for a check', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'Raise WP_MAX_MEMORY_LIMIT in wp-config.php or ask your host.', 'einvoice-validator-for-germanized' ),
		);

		// ⚠️ THE FIELDS AT THE CHECKOUT HANG ON THE COMPANY NAME. With the company field switched off they never appear;
		// measured on 24.10.2026 in the test shop, whose block checkout had it switched off. The option serves both checkouts.
		if ( EIVG_Settings::get( 'checkout_fields' ) )
			$out[] = array(
				'ok'    => get_option( 'woocommerce_checkout_company_field', 'optional' ) !== 'hidden',
				'label' => __( 'The checkout shows the company field', 'einvoice-validator-for-germanized' ),
				'hint'  => __( 'The fields for e-invoices appear only after a company name is entered. Switch the company field on: in the block checkout in the editor of the checkout page, in the classic checkout under Appearance → Customize → WooCommerce → Checkout.', 'einvoice-validator-for-germanized' ),
			);

		$adapter = EIVG_Flow::adapter();

		$out[] = array(
			'ok'    => $adapter && $adapter->available(),
			'label' => __( 'Germanized Pro is active', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'This plugin checks the invoices of Germanized Pro; without it there is nothing to check.', 'einvoice-validator-for-germanized' ),
		);

		if ( $adapter && $adapter->available() )
			$out = array_merge( $out, $adapter->self_test() );

		return( $out );
	}
}
