<?php
/**
 * The adapter for Germanized Pro.
 *
 * Every call below was read in the source of Germanized Pro 4.3.10 ( package StoreaBill ) and is public there.
 * Germanized Pro is never changed and its tables are never queried directly.
 *
 *   Invoices of an order     \Vendidero\StoreaBill\WooCommerce\Helper::get_order( $order ), ->get_invoices()
 *   Automatic finalization   option `storeabill_invoice_woo_order_auto_finalize`, read in WooCommerce\Automation
 *   E-invoice of a draft     Attachment::generate( '', true ) - the preview mode writes nothing to disk
 *   Sending                  filter `storeabill_send_{type}_to_customer` in Emails\Mailer::maybe_send_invoice()
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Adapter_Germanized implements EIVG_Adapter
{
	const OPTION_AUTO_FINALIZE = 'storeabill_invoice_woo_order_auto_finalize';

	/**
	 * The Peppol address scheme of a VAT ID, by the country prefix of the VAT ID.
	 *
	 * Taken from the EAS code list 5 of the KoSIT on 24.10.2026: every entry named "<country> VAT number", plus 9914
	 * ( Austrian VAT ID ), 9920 ( Spanish tax agency ), 0211 ( Italian Partita IVA ) and 0213 ( Finnish VAT identifier ).
	 * ⚠️ GREECE USES THE PREFIX "EL", NOT "GR". Denmark is missing on purpose: its entry names the ministry, not the VAT ID.
	 */
	const VAT_SCHEMES = array(
		'AT' => '9914', 'BE' => '9925', 'BG' => '9926', 'CY' => '9928', 'CZ' => '9929', 'DE' => '9930', 'EE' => '9931',
		'EL' => '9933', 'ES' => '9920', 'FI' => '0213', 'FR' => '9957', 'HR' => '9934', 'HU' => '9910', 'IE' => '9935',
		'IT' => '0211', 'LT' => '9937', 'LU' => '9938', 'LV' => '9939', 'MT' => '9943', 'NL' => '9944', 'PL' => '9945',
		'PT' => '9946', 'RO' => '9947', 'SE' => '9955', 'SI' => '9949', 'SK' => '9950',
	);

	/** An empty one-page PDF, embedded in the preview where the real PDF will be ( see preview() ). */
	const PLACEHOLDER_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";


	public function available()
	{
		return( class_exists( '\Vendidero\StoreaBill\WooCommerce\Helper' ) && function_exists( 'sab_get_document_attachment' ) && class_exists( '\Vendidero\StoreaBill\eInvoice\Automation' ) );
	}


	/**
	 * Whether Germanized Pro creates Peppol files automatically.
	 *
	 * ⚠️ A PEPPOL FILE IS NOT A PEPPOL DELIVERY. Germanized Pro writes the file and sends it by mail like any invoice; it has
	 * no connection to the Peppol network ( searched in Germanized Pro 4.3.10 on 24.10.2026 ). The Peppol ID of the buyer is
	 * a required field of that file, and it serves the shop owner who passes the file to an access point himself.
	 */
	public function creates_peppol()
	{
		return( $this->available() && (string) \Vendidero\StoreaBill\eInvoice\Automation::get_auto_create_profile( 'cius' ) === 'peppol' );
	}


	public function name()
	{
		return( 'Germanized Pro' );
	}


	public function hook()
	{
		if ( ! $this->available() )
			return;

		add_filter( 'pre_option_' . self::OPTION_AUTO_FINALIZE, array( $this, 'hold_auto_finalize' ), 10, 3 );

		// The instant path of the automation: Germanized Pro syncs and would finalize in the same call.
		add_filter( 'storeabill_woo_auto_sync_order_invoices', array( $this, 'after_auto_sync_scheduled' ), 99, 3 );

		// The deferred path: Germanized Pro runs its own sync in the queue; this runs right after it.
		add_action( 'storeabill_order_auto_sync_callback', array( $this, 'after_deferred_sync' ), 20 );

		// The real files, written after finalization, and the sending that follows them.
		add_action( 'storeabill_invoice_rendered', array( $this, 'rendered' ), 5 );
		add_action( 'storeabill_invoice_cancellation_rendered', array( $this, 'rendered' ), 5 );
		add_filter( 'storeabill_send_invoice_to_customer', array( $this, 'may_send' ), 99, 2 );
		add_filter( 'storeabill_send_invoice_cancellation_to_customer', array( $this, 'may_send' ), 99, 2 );

		// The values of the checkout fields reach the e-invoice as the defaults of its meta fields.
		foreach ( array( 'invoice', 'invoice_cancellation' ) as $type )
			foreach ( array( 'buyer_reference', 'order_reference', 'buyer_electronic_address' ) as $field )
				add_filter( "storeabill_{$type}_einvoice_meta_{$field}_default", array( $this, 'meta_default_' . $field ), 10, 3 );
	}


	/**
	 * Take over the automatic finalization.
	 *
	 * ⚠️ THE OPTION IS ANSWERED "no", NOT CHANGED. Germanized Pro asks `finalize_invoices()` right after its own sync; with
	 * "no" it leaves the invoice a draft, and this plugin finalizes it once the check is passed. The stored option stays
	 * as the shop owner set it, so deactivating this plugin restores Germanized Pro's own behaviour at once.
	 *
	 * ⚠️ ONLY WHEN THE OWNER WANTS AUTOMATIC FINALIZATION. With the stored option "no" nothing is taken over; the owner
	 * finalizes by hand, and the check of the real files after rendering still runs.
	 *
	 * @param mixed $pre
	 * @param string $option
	 * @param mixed $default
	 * @return mixed
	 */
	public function hold_auto_finalize( $pre, $option = '', $default = false )
	{
		if ( ! EIVG_Flow::active() || ! $this->auto_finalizes() )
			return( $pre );

		return( 'no' );
	}


	/** @return bool the option as stored, past the filter above */
	public function auto_finalizes()
	{
		remove_filter( 'pre_option_' . self::OPTION_AUTO_FINALIZE, array( $this, 'hold_auto_finalize' ), 10 );
		$value = get_option( self::OPTION_AUTO_FINALIZE );
		add_filter( 'pre_option_' . self::OPTION_AUTO_FINALIZE, array( $this, 'hold_auto_finalize' ), 10, 3 );

		return( 'yes' === $value );
	}


	/**
	 * The instant path: schedule a check shortly after the request that created the invoice.
	 *
	 * ⚠️ NOT IN THE SAME CALL. The filter runs before Germanized Pro has synced the invoice; a check here would find no
	 * draft. The scheduled check also covers the deferred path if its callback is lost.
	 *
	 * @param bool $sync
	 * @param int $order_id
	 * @param mixed $order
	 * @return bool unchanged
	 */
	public function after_auto_sync_scheduled( $sync, $order_id = 0, $order = null )
	{
		if ( $sync && EIVG_Flow::active() && $this->auto_finalizes() )
			EIVG_Flow::schedule( (int) $order_id, 90 );

		return( $sync );
	}


	/** @param int $order_id */
	public function after_deferred_sync( $order_id )
	{
		if ( EIVG_Flow::active() && $this->auto_finalizes() )
			EIVG_Flow::check( (int) $order_id, 'automation' );
	}


	/**
	 * The Germanized order wrapper.
	 *
	 * @param WC_Order|int $order
	 * @return object|false
	 */
	private function sab_order( $order )
	{
		return( \Vendidero\StoreaBill\WooCommerce\Helper::get_order( $order ) );
	}


	/** @return object|null the last invoice of the order that is still a draft */
	private function draft( $order )
	{
		$sab = $this->sab_order( $order );

		if ( ! $sab )
			return( null );

		$draft = null;

		foreach ( $sab->get_invoices() as $invoice )
			if ( ! $invoice->is_finalized() )
				$draft = $invoice;

		return( $draft );
	}


	public function has_draft( $order )
	{
		return( $this->draft( $order ) !== null );
	}


	/**
	 * The e-invoices Germanized Pro will write for the draft, in memory.
	 *
	 * ⚠️ THREE VALUES ARE SET ONLY AT FINALIZATION: the invoice number, the due date and the end of the service period.
	 * Measured on 23.10.2026 with a Peppol file of a draft: the payment reference ( `PaymentID`, built from the number ) was
	 * an empty element, and Peppol rule PEPPOL-EN16931-R008 rejected it, although the finalized file was fine. The preview
	 * therefore works on a COPY of the draft with these values set the way `Invoice::finalize()` sets them, and the copy
	 * is never saved. The number is the next one of the journal, read and not taken.
	 *
	 * ⚠️ THE META FIELDS ARE FILLED THE WAY `Invoice::create_einvoice()` FILLS THEM: the profile's fields with their
	 * defaults and the two filters, so that the buyer reference from the checkout is in the preview as it will be in the
	 * real file.
	 *
	 * @param WC_Order $order
	 * @return array[]|WP_Error
	 */
	public function preview( $order )
	{
		$draft = $this->draft( $order );

		if ( ! $draft )
			return( new WP_Error( 'eivg_no_draft', __( 'The order has no invoice draft.', 'einvoice-validator-for-germanized' ) ) );

		$copy = clone $draft;
		$copy->set_date_created( time() );

		if ( ! $copy->get_date_due( 'edit' ) && function_exists( 'sab_calculate_invoice_date_due' ) )
			$copy->set_date_due( sab_calculate_invoice_date_due( $copy->get_date_created() ) );

		if ( ! $copy->get_date_of_service( 'edit' ) )
			$copy->set_date_of_service( time() );

		if ( ! $copy->get_date_of_service_end( 'edit' ) )
			$copy->set_date_of_service_end( $copy->get_date_of_service() ? clone $copy->get_date_of_service() : null );

		if ( ! $copy->has_number() && $copy->get_journal() )
		{
			$next = (int) $copy->get_journal()->get_last_number() + 1;
			$copy->set_number( $next );
			$copy->set_formatted_number( $copy->format_number( $next ) );
		}

		$out = array();

		foreach ( $this->profiles( $copy ) as $spec => $profile )
		{
			$att = sab_get_document_attachment( 0, 'einvoice' );
			$att->set_document( $copy );
			$att->set_specification( $spec );
			$att->set_profile( $profile );

			foreach ( $this->meta( $copy, $att, $spec, $profile ) as $key => $value )
				$att->update_meta_data( $key, $value );

			// ⚠️ WITH A PDF, AS AT RENDERING. `Invoice::render()` hands the PDF of the invoice to every e-invoice, and the
			// UBL writer of Germanized Pro embeds it; without one the preview would lack the attachment and miss its errors.
			// A minimal PDF stands in for the real one, which exists only after finalization.
			$xml = $att->generate( self::PLACEHOLDER_PDF, true );

			if ( is_wp_error( $xml ) )
				return( $xml );

			$out[] = array( 'label' => $this->label( $spec, $profile ), 'xml' => (string) $xml );
		}

		return( $out );
	}


	/**
	 * The specifications and profiles Germanized Pro creates automatically for this invoice.
	 *
	 * @param object $invoice
	 * @return array spec => profile
	 */
	private function profiles( $invoice )
	{
		$out = array();

		if ( ! \Vendidero\StoreaBill\eInvoice\Automation::auto_create_einvoices() )
			return( $out );

		foreach ( \Vendidero\StoreaBill\eInvoice\Helper::get_available_specifications_by_invoice( $invoice ) as $spec )
		{
			$profile = (string) \Vendidero\StoreaBill\eInvoice\Automation::get_auto_create_profile( $spec );

			if ( $profile !== '' )
				$out[ $spec ] = $profile;
		}

		return( $out );
	}


	/** The meta fields of a profile, filled as Invoice::create_einvoice() does. */
	private function meta( $invoice, $att, $spec, $profile )
	{
		$data   = \Vendidero\StoreaBill\eInvoice\Helper::get_profile( $spec, $profile );
		// ⚠️ get_general_hook_prefix() is protected in StoreaBill; the prefix is rebuilt the way Document builds it.
		$prefix = 'storeabill_' . $invoice->get_type() . '_';
		$meta   = array();

		foreach ( (array) ( $data['fields'] ?? array() ) as $field )
		{
			$id = (string) ( $field['id'] ?? '' );

			if ( $id !== '' )
				$meta[ $id ] = apply_filters( "{$prefix}einvoice_meta_{$id}_default", $field['default'] ?? '', $att, $invoice ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook of Germanized Pro, applied as Invoice::create_einvoice() applies it.
		}

		return( (array) apply_filters( "{$prefix}einvoice_additional_meta", $meta, $att, $invoice ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook of Germanized Pro, applied as Invoice::create_einvoice() applies it.
	}


	/** "ZUGFeRD Comfort", "CIUS Peppol" */
	private function label( $spec, $profile )
	{
		$s = \Vendidero\StoreaBill\eInvoice\Helper::get_specification( $spec );
		$p = \Vendidero\StoreaBill\eInvoice\Helper::get_profile( $spec, $profile );

		return( trim( ( $s['title'] ?? $spec ) . ' ' . ( $p['title'] ?? $profile ) ) );
	}


	/**
	 * Finalize the drafts of the order.
	 *
	 * @param WC_Order $order
	 * @return true|WP_Error
	 */
	public function finalize( $order )
	{
		$sab = $this->sab_order( $order );

		if ( ! $sab )
			return( new WP_Error( 'eivg_no_order', __( 'Germanized Pro does not know this order.', 'einvoice-validator-for-germanized' ) ) );

		$result = $sab->finalize();

		return( is_wp_error( $result ) ? $result : true );
	}


	/**
	 * After rendering, the real files exist: check them and keep the report.
	 *
	 * @param object $invoice
	 */
	public function rendered( $invoice )
	{
		if ( ! EIVG_Flow::active() || ! is_object( $invoice ) || ! method_exists( $invoice, 'get_einvoices' ) )
			return;

		$order = wc_get_order( (int) $invoice->get_order_id() );

		if ( ! $order )
			return;

		$docs = array();

		foreach ( $invoice->get_einvoices() as $e )
		{
			$path = (string) $e->get_path();

			if ( $path !== '' && is_readable( $path ) )
				$docs[] = array( 'label' => $this->label( $e->get_specification(), $e->get_profile() ), 'xml' => (string) file_get_contents( $path ) );
		}

		EIVG_Flow::check_final( $order, (string) $invoice->get_title(), $docs, 'invoice_cancellation' === $invoice->get_type() );
	}


	/**
	 * Germanized Pro sends the invoice right after rendering, unless the final check failed in the mode "hold".
	 *
	 * @param bool $send
	 * @param object $invoice
	 * @return bool
	 */
	public function may_send( $send, $invoice = null )
	{
		if ( ! $send || ! is_object( $invoice ) )
			return( $send );

		$order = wc_get_order( (int) $invoice->get_order_id() );

		return( $order ? EIVG_Flow::may_send( $order, (string) $invoice->get_title() ) : $send );
	}


	/**
	 * The buyer reference ( BT-10 ).
	 *
	 * ⚠️ ONLY A PUBLIC AUTHORITY HAS A LEITWEG-ID. XRechnung requires BT-10 for every buyer ( BR-DE-15 ), and Peppol requires
	 * it when seller and buyer are both in Germany ( DE-R-015, read in the Peppol rules 3.0.21 ). For any other buyer the
	 * order number takes the place; it is the reference the buyer finds on the order confirmation. A public authority
	 * without a Leitweg-ID keeps the empty value on purpose: the check then holds the invoice, because the authority's
	 * receipt platform would reject it.
	 *
	 * ⚠️ NOT FOR ZUGFeRD. There BT-10 is optional, and Germanized Pro writes the value as the routing ID of the profiles
	 * Comfort and Extended; an order number in that place would look like an address it is not.
	 *
	 * @param string $default
	 * @param object|null $att the e-invoice attachment, with its specification and profile
	 * @param object|null $invoice
	 * @return string
	 */
	public function meta_default_buyer_reference( $default, $att = null, $invoice = null )
	{
		$value = $this->checkout_value( $default, $invoice, 'buyer_reference' );

		if ( $value !== '' || ! is_object( $invoice ) || ! method_exists( $invoice, 'get_order_id' ) )
			return( $value );

		$order = wc_get_order( (int) $invoice->get_order_id() );

		if ( ! $order || EIVG_Checkout::public_authority( $order ) || ! $this->requires_buyer_reference( $att, $order ) )
			return( $value );

		return( (string) $order->get_order_number() );
	}

	/**
	 * Whether the format of the e-invoice requires BT-10 for this order.
	 *
	 * @param object|null $att
	 * @param WC_Order $order
	 * @return bool
	 */
	private function requires_buyer_reference( $att, $order )
	{
		if ( ! is_object( $att ) || ! method_exists( $att, 'get_specification' ) )
			return( false );

		$spec    = (string) $att->get_specification();
		$profile = (string) $att->get_profile();

		if ( $spec === 'zugferd' )
			return( strpos( $profile, 'xrechnung' ) === 0 );

		if ( $spec === 'cius' && $profile === 'peppol' )
		{
			$seller = class_exists( '\Vendidero\StoreaBill\BusinessInformation' ) ? \Vendidero\StoreaBill\BusinessInformation::get_base_country() : WC()->countries->get_base_country();

			return( $seller === 'DE' && $order->get_billing_country() === 'DE' );
		}

		return( false );
	}

	public function meta_default_order_reference( $default, $att = null, $invoice = null )
	{
		return( $this->checkout_value( $default, $invoice, 'order_reference' ) );
	}

	/**
	 * The electronic address of the buyer ( BT-49 ).
	 *
	 * ⚠️ GERMANIZED PRO WANTS "scheme:identifier" AND DROPS A BARE VALUE SILENTLY. `Party::get_electronic_address()` returns
	 * an empty string when the scheme is missing, so a plain value never reaches the file; measured on 23.10.2026 as
	 * PEPPOL-EN16931-R010 although the value was set.
	 *
	 * ⚠️ THE E-MAIL OF THE ORDER IS NO SUBSTITUTE. With the scheme "EM" it reached the file, and Peppol rejected it as
	 * PEPPOL-EN16931-CL008, because "EM" is not in the Peppol list of address schemes ( measured on 23.10.2026 ). Without
	 * a value from the customer, the VAT ID of the buyer takes the place, as in Germanized Pro.
	 *
	 * ⚠️ GERMANIZED PRO GIVES EVERY VAT ID THE SCHEME 9914, AND 9914 IS THE AUSTRIAN VAT ID. Read in Germanized Pro 4.3.10,
	 * `Party::get_electronic_address_id_scheme()`; the name of the code is taken from the EAS code list 5 of the KoSIT
	 * ( `libs/einvoice-reference-data/dist/codelists/EAS/EAS_5.EN.json` ). A German buyer thus got the Peppol address
	 * "9914:de…", which passes every rule, because the rules only test that the scheme is in the list, and which no
	 * access point can deliver to. Measured on 24.10.2026: order 39 of the test shop passed with exactly that address.
	 * This filter sets the scheme from the country prefix of the VAT ID instead; a prefix without its own scheme in the
	 * list leaves Germanized Pro's default.
	 */
	public function meta_default_buyer_electronic_address( $default, $att = null, $invoice = null )
	{
		$value = $this->checkout_value( $default, $invoice, 'buyer_electronic_address' );

		if ( $value !== '' || ! is_object( $invoice ) || ! method_exists( $invoice, 'get_vat_id' ) )
			return( $value );

		$vat = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $invoice->get_vat_id() ) );

		if ( preg_match( '/^([A-Z]{2})[A-Z0-9]+$/', $vat, $m ) && isset( self::VAT_SCHEMES[ $m[1] ] ) )
			return( self::VAT_SCHEMES[ $m[1] ] . ':' . $vat );

		return( $value );
	}


	/** The value from the checkout field, if the order carries one; otherwise Germanized Pro's own default. */
	private function checkout_value( $default, $invoice, $field )
	{
		if ( ! is_object( $invoice ) || ! method_exists( $invoice, 'get_order_id' ) )
			return( $default );

		$order = wc_get_order( (int) $invoice->get_order_id() );
		$value = $order ? EIVG_Checkout::value( $order, $field ) : '';

		return( $value !== '' ? $value : $default );
	}


	/**
	 * Known errors of Germanized Pro.
	 *
	 * ⚠️ THE TYPE OF THE EMBEDDED PDF. Germanized Pro 4.3.10 writes the PDF into every UBL file ( Peppol and XRechnung UBL )
	 * with `mimeCode="attachment/pdf"`, fixed in `eInvoice/CIUS/Invoice.php`; the code list allows `application/pdf`.
	 * Measured on 23.10.2026 with order 22 of the test shop: the finished Peppol file failed BR-CL-24 and
	 * PEPPOL-EN16931-CL001, and its only attachment was `invoice-pdf` with that type. The ZUGFeRD file of the same invoice
	 * passed, because there the XML lies inside the PDF and not the other way round.
	 *
	 * ⚠️ RECOGNISED BY RULE AND DOCUMENT, NOT BY RULE ALONE. The same two rules also fire on an attachment the shop owner
	 * added by hand with a wrong type, and that one the owner can fix.
	 */
	public function known_defect( array $finding )
	{
		$rule = (string) ( $finding['rule'] ?? '' );
		$doc  = (string) ( $finding['document'] ?? '' );

		if ( in_array( $rule, array( 'BR-CL-24', 'PEPPOL-EN16931-CL001' ), true ) && stripos( $doc, 'zugferd' ) === false )
			return( __( 'This is an error of Germanized Pro: it marks the PDF embedded in the e-invoice as "attachment/pdf" instead of "application/pdf". You cannot correct it in the shop. A receiver may reject the file; until Germanized Pro fixes it, ZUGFeRD avoids the error, because there the PDF is not embedded in the XML.', 'einvoice-validator-for-germanized' ) );

		return( '' );
	}


	public function self_test()
	{
		// ⚠️ THROUGH THE GETTERS OF BusinessInformation, NOT get_option(). The option names are an internal of StoreaBill;
		// the getters are what the e-invoice itself reads.
		$bi  = '\Vendidero\StoreaBill\BusinessInformation';
		$o   = static function ( $k ) use ( $bi ) { return( is_callable( array( $bi, 'get_' . $k ) ) ? trim( (string) call_user_func( array( $bi, 'get_' . $k ) ) ) : '' ); };
		$out = array();

		$out[] = array(
			'ok'    => $o( 'vat_id' ) !== '' || $o( 'local_tax_number' ) !== '',
			'label' => __( 'VAT ID or tax number of the seller', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'Enter it under WooCommerce → Settings → Germanized → Invoices → Business Information.', 'einvoice-validator-for-germanized' ),
		);
		$out[] = array(
			'ok'    => $o( 'base_company_name' ) !== '' && $o( 'base_address_1' ) !== '' && $o( 'base_city' ) !== '' && $o( 'base_postcode' ) !== '',
			'label' => __( 'Name and address of the seller', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'Company, street, postcode and city under WooCommerce → Settings → Germanized → Invoices → Business Information.', 'einvoice-validator-for-germanized' ),
		);
		$out[] = array(
			'ok'    => $o( 'contact_full_name' ) !== '' && $o( 'contact_phone' ) !== '' && is_email( $o( 'contact_email' ) ),
			'label' => __( 'Contact name, telephone and e-mail of the seller ( required by XRechnung and Peppol in Germany )', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'Enter them in the contact section of the Business Information.', 'einvoice-validator-for-germanized' ),
		);

		$transfer = in_array( 'yes', array( get_option( 'woocommerce_bacs_settings' )['enabled'] ?? 'no', get_option( 'woocommerce_invoice_settings' )['enabled'] ?? 'no' ), true );

		$out[] = array(
			'ok'    => ! $transfer || $o( 'bank_account_iban' ) !== '',
			'label' => __( 'IBAN for payment by bank transfer or invoice', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'Enter the bank account in the Business Information.', 'einvoice-validator-for-germanized' ),
		);

		$profiles = array();

		if ( class_exists( '\Vendidero\StoreaBill\eInvoice\Automation' ) && \Vendidero\StoreaBill\eInvoice\Automation::auto_create_einvoices() )
			foreach ( array( 'zugferd', 'cius' ) as $spec )
				if ( (string) \Vendidero\StoreaBill\eInvoice\Automation::get_auto_create_profile( $spec ) !== '' )
					$profiles[] = $spec;

		$out[] = array(
			'ok'    => (bool) $profiles,
			'label' => __( 'Germanized Pro creates e-invoices automatically', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'Switch it on under WooCommerce → Settings → Germanized → Invoices → eInvoice and choose a profile. Without it there is nothing to check.', 'einvoice-validator-for-germanized' ),
		);
		$out[] = array(
			'ok'    => $this->auto_finalizes(),
			'label' => __( 'Germanized Pro finalizes invoices automatically', 'einvoice-validator-for-germanized' ),
			'hint'  => __( 'Only then can a faulty invoice be held back before it is numbered. Without it the plugin checks the finished files and holds back only the sending.', 'einvoice-validator-for-germanized' ),
		);

		return( $out );
	}
}
