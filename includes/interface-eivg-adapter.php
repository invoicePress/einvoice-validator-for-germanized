<?php
/**
 * What the core needs from an invoice plugin.
 *
 * ⚠️ ONE ADAPTER PER INVOICE PLUGIN, AND THE CORE NEVER NAMES ONE. A later "eInvoice Validator for German Market" takes
 * every other file of this plugin unchanged and brings its own class that implements this interface.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


interface EIVG_Adapter
{
	/** @return bool whether the invoice plugin is active and can be checked */
	public function available();

	/** @return string the name of the invoice plugin, for the views */
	public function name();

	/** Register the hooks of the invoice plugin that call back into EIVG_Flow. */
	public function hook();

	/**
	 * Whether the order has an invoice that is still a draft and waits for finalization.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public function has_draft( $order );

	/**
	 * The e-invoices of the draft, written in memory and not stored.
	 *
	 * @param WC_Order $order
	 * @return array[]|WP_Error list of { label: string, xml: string }
	 */
	public function preview( $order );

	/**
	 * Finalize the drafts of the order; the invoice plugin then numbers and sends them as usual.
	 *
	 * @param WC_Order $order
	 * @return true|WP_Error
	 */
	public function finalize( $order );

	/**
	 * Whether the invoice plugin would finalize automatically, which is the step the core takes over.
	 *
	 * @return bool
	 */
	public function auto_finalizes();

	/**
	 * Whether a finding is a known error of the invoice plugin itself, which the shop owner cannot correct.
	 *
	 * Such a finding is shown and mailed, but it does not hold an invoice back: holding would stop every invoice of the
	 * shop for something only the maker of the invoice plugin can fix.
	 *
	 * @param array $finding a finding from EIVG_Texts::explain()
	 * @return string what the shop owner should know and can do, '' when the finding is not a known error
	 */
	public function known_defect( array $finding );

	/**
	 * Settings of the invoice plugin that would make every invoice fail, for the self-test.
	 *
	 * @return array[] list of { ok: bool, label: string, hint: string }
	 */
	public function self_test();
}
