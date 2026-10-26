<?php
/**
 * The check of one e-invoice with VAMPIRE PHP.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Engine
{

	/** @return string the folder of the bundled VAMPIRE PHP */
	public static function dir()
	{
		return( EIVG_DIR . 'vendor/invoicepress/vampire-php' );
	}


	/** @return bool whether the engine and its rule packages are there */
	public static function available()
	{
		return( class_exists( '\EinvoiceValidator\Validator' ) && is_file( self::dir() . '/rulesets/scenarios.json' ) );
	}


	/**
	 * Check one e-invoice.
	 *
	 * ⚠️ THE ENGINE CHOOSES THE RULES ITSELF, from the specification identifier in the invoice ( BT-24 ): ZUGFeRD Comfort is
	 * checked as EN 16931 CII, a Peppol file as Peppol BIS Billing 3, an XRechnung as XRechnung. The plugin never names a
	 * scenario, so it cannot check a file against the wrong rules.
	 *
	 * ⚠️ AN ENGINE FAILURE IS NO VERDICT. It comes back as `error`, and the caller decides; the flow treats it as "not
	 * checked", never as "invalid", so that a missing memory reserve cannot hold back every invoice of a shop.
	 *
	 * @param string $xml
	 * @return array { accepted: bool, scenario: string, messages: array[], report: string, error: string }
	 */
	public static function validate( $xml )
	{
		$out = array( 'accepted' => false, 'scenario' => '', 'messages' => array(), 'report' => '', 'error' => '' );

		if ( ! self::available() )
		{
			$out['error'] = __( 'The checking engine VAMPIRE is missing from this installation.', 'einvoice-validator-for-germanized' );

			return( $out );
		}

		// ⚠️ Measured on 23.10.2026 in the test shop: WordPress with WooCommerce and Germanized Pro peaked at 112 MB, two
		// checks ( Peppol and ZUGFeRD ) raised the peak to 136 MB. The limit for admin work ( 256 MB by default ) leaves room.
		wp_raise_memory_limit( 'admin' );

		try
		{
			$validator = new \EinvoiceValidator\Validator(
				scenarioResolver: \EinvoiceValidator\Pipeline\ScenarioResolver::fromJsonFile( self::dir() . '/rulesets/scenarios.json' ),
				baseDir: self::dir()
			);

			$result = $validator->validateString( (string) $xml );
		}
		catch ( \Throwable $e )
		{
			$out['error'] = $e->getMessage();

			return( $out );
		}

		$out['accepted'] = (bool) $result->isAccepted();
		$out['scenario'] = (string) $result->scenarioName();

		foreach ( $result->messages() as $m )
		{
			$out['messages'][] = array(
				'flag' => strtolower( (string) $m->flag->name ),
				'rule' => (string) $m->ruleId,
				'bt'   => array_values( array_map( 'strval', (array) $m->bt ) ),
				'text' => (string) $m->text,
			);
		}

		try
		{
			$out['report'] = ( new \EinvoiceValidator\Report\KositXmlReport( 'invoicepress/vampire-php', EIVG_VERSION ) )->render( $result, (string) $xml );
		}
		catch ( \Throwable $e )
		{
			// The report is a by-product; a failure here does not change the verdict.
			$out['report'] = '';
		}

		return( $out );
	}


	/**
	 * Whether a message counts against the invoice. Fatal findings and errors do; warnings and information do not.
	 *
	 * @param array $message
	 * @return bool
	 */
	public static function is_blocking( array $message )
	{
		return( in_array( $message['flag'] ?? '', array( 'fatal', 'error' ), true ) );
	}
}
