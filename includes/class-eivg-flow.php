<?php
/**
 * The flow of the check: draft → check → finalize or hold, then the check of the real files.
 *
 * The flow never names an invoice plugin; everything specific goes through the adapter ( EIVG_Adapter ).
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Flow
{
	/** The states of an order, kept in the order meta `_eivg_status`. */
	const PASSED     = 'passed';      // checked, no error, finalized by this plugin
	const HELD       = 'held';        // checked, errors, kept as a draft ( mode "hold" )
	const REPORTED   = 'reported';    // checked, errors, finalized anyway ( mode "report" )
	const OVERRIDDEN = 'overridden';  // held, then finalized by the shop owner without a passed check
	const UNCHECKED  = 'unchecked';   // the engine could not check; finalized, never held
	const SKIPPED    = 'skipped';     // mode "off" for this customer class

	const HOOK = 'eivg_check_order';

	/** @var EIVG_Adapter|null */
	private static $adapter = null;


	/** @param EIVG_Adapter $adapter */
	public static function boot( EIVG_Adapter $adapter )
	{
		self::$adapter = $adapter;

		if ( ! $adapter->available() )
			return;

		$adapter->hook();

		add_action( self::HOOK, array( __CLASS__, 'scheduled' ), 10, 1 );
	}


	/** @return EIVG_Adapter|null */
	public static function adapter()
	{
		return( self::$adapter );
	}


	/**
	 * A list kept in the order meta.
	 *
	 * ⚠️ NOT `(array) $order->get_meta()`. A missing key comes back as an empty string, and `(array) ''` is a list with
	 * one empty entry, not an empty list. A loop over it reads an offset of a string and stops with a fatal error; a test
	 * on 23.10.2026 stopped exactly so on an order without a final check.
	 *
	 * @param WC_Order $order
	 * @param string $key
	 * @return array
	 */
	public static function meta_list( $order, $key )
	{
		$value = $order->get_meta( $key );

		return( is_array( $value ) ? $value : array() );
	}


	/**
	 * Whether the check runs at all.
	 *
	 * ⚠️ WITHOUT THE ENGINE NOTHING IS TAKEN OVER. A missing VAMPIRE would otherwise keep every invoice as a draft that
	 * nobody finalizes; Germanized Pro then works exactly as without this plugin, and the self-test names the cause.
	 *
	 * @return bool
	 */
	public static function active()
	{
		return( self::$adapter !== null && self::$adapter->available() && EIVG_Engine::available() );
	}


	/**
	 * Schedule a check in the queue of WooCommerce ( Action Scheduler ).
	 *
	 * @param int $order_id
	 * @param int $delay seconds
	 */
	public static function schedule( $order_id, $delay = 0 )
	{
		if ( $order_id <= 0 || ! function_exists( 'as_schedule_single_action' ) )
			return;

		$args = array( 'order_id' => (int) $order_id );

		if ( function_exists( 'as_unschedule_all_actions' ) )
			as_unschedule_all_actions( self::HOOK, $args, 'eivg' );

		as_schedule_single_action( time() + max( 0, (int) $delay ), self::HOOK, $args, 'eivg' );
	}


	/** @param int $order_id */
	public static function scheduled( $order_id )
	{
		self::check( (int) $order_id, 'automation' );
	}


	/**
	 * Check the draft of an order and decide.
	 *
	 * ⚠️ AN ENGINE FAILURE NEVER HOLDS AN INVOICE. The state is "unchecked", the invoice is finalized as Germanized Pro
	 * would have done, and the note at the order says so. Holding on a failure of this plugin would turn a technical
	 * problem of the shop into missing invoices for the customers.
	 *
	 * @param int $order_id
	 * @param string $source 'automation' or 'manual'
	 * @return string|null the new state, null when there was nothing to check
	 */
	public static function check( $order_id, $source = 'automation' )
	{
		if ( ! self::active() )
			return( null );

		$order = wc_get_order( (int) $order_id );

		if ( ! $order || ! self::$adapter->has_draft( $order ) )
			return( null );

		$mode = EIVG_Customer::mode( $order );

		if ( $mode === EIVG_Settings::OFF )
		{
			self::set_state( $order, self::SKIPPED, array() );
			self::finalize( $order );

			return( self::SKIPPED );
		}

		$preview = self::$adapter->preview( $order );

		if ( is_wp_error( $preview ) || ! $preview )
		{
			$why = is_wp_error( $preview ) ? $preview->get_error_message() : __( 'Germanized Pro created no e-invoice for the draft.', 'einvoice-validator-for-germanized' );

			self::set_state( $order, self::UNCHECKED, array() );
			/* translators: %s: the reason */
			$order->add_order_note( sprintf( __( 'eInvoice check: the e-invoice could not be checked ( %s ). The invoice was finalized as usual.', 'einvoice-validator-for-germanized' ), $why ) );
			self::finalize( $order );

			return( self::UNCHECKED );
		}

		$result = self::validate_all( $order, $preview, 'draft' );

		if ( $result['error'] !== '' )
		{
			self::set_state( $order, self::UNCHECKED, array() );
			/* translators: %s: the error of the checking engine */
			$order->add_order_note( sprintf( __( 'eInvoice check: the checking engine failed ( %s ). The invoice was finalized as usual.', 'einvoice-validator-for-germanized' ), $result['error'] ) );
			self::finalize( $order );

			return( self::UNCHECKED );
		}

		if ( ! $result['blocking'] )
		{
			self::set_state( $order, self::PASSED, $result['findings'] );

			if ( ! $result['known'] )
				$order->add_order_note( __( 'eInvoice check: the e-invoice is valid. The invoice was finalized.', 'einvoice-validator-for-germanized' ) );
			else
			{
				/* translators: %s: name of the invoice plugin */
				$order->add_order_note( sprintf( __( 'eInvoice check: the e-invoice has no error the shop can correct, only a known error of %s. The invoice was finalized.', 'einvoice-validator-for-germanized' ), self::$adapter->name() ) );
				self::mail_known_once( $order, $result['known'] );
			}

			self::finalize( $order );

			return( self::PASSED );
		}

		$state   = $mode === EIVG_Settings::HOLD ? self::HELD : self::REPORTED;
		$changed = self::set_state( $order, $state, $result['findings'] );
		$count   = count( $result['blocking'] );

		if ( $state === self::HELD )
			/* translators: %d: number of errors */
			$order->add_order_note( sprintf( _n( 'eInvoice check: the e-invoice has %d error. The invoice was held back as a draft; see the box "eInvoice check" of this order.', 'eInvoice check: the e-invoice has %d errors. The invoice was held back as a draft; see the box "eInvoice check" of this order.', $count, 'einvoice-validator-for-germanized' ), $count ) );
		else
			/* translators: %d: number of errors */
			$order->add_order_note( sprintf( _n( 'eInvoice check: the e-invoice has %d error. The invoice was finalized anyway, as set for this customer class.', 'eInvoice check: the e-invoice has %d errors. The invoice was finalized anyway, as set for this customer class.', $count, 'einvoice-validator-for-germanized' ), $count ) );

		// ⚠️ A MAIL ONLY FOR A HELD INVOICE, and once per new result; a repeated check with the same findings stays quiet.
		// "Report only" means a note at the order and nothing more ( concept, section 4.3 ): with private customers in that
		// mode, a mail per order would bury the one that matters.
		if ( $state === self::HELD && ( $changed || $source === 'manual' ) )
			self::mail( $order, $state, $result['blocking'] );

		if ( $state === self::REPORTED )
			self::finalize( $order );

		return( $state );
	}


	/**
	 * Finalize the draft past the check, at the shop owner's request.
	 *
	 * @param WC_Order $order
	 * @return true|WP_Error
	 */
	public static function override( $order )
	{
		if ( ! self::$adapter || ! self::$adapter->has_draft( $order ) )
			return( new WP_Error( 'eivg_no_draft', __( 'The order has no invoice draft.', 'einvoice-validator-for-germanized' ) ) );

		$order->update_meta_data( '_eivg_status', self::OVERRIDDEN );
		$order->save();

		$user = trim( (string) wp_get_current_user()->display_name );

		if ( $user !== '' )
			/* translators: %s: name of the user */
			$order->add_order_note( sprintf( __( 'eInvoice check: %s finalized the invoice without a passed check.', 'einvoice-validator-for-germanized' ), $user ) );
		else
			$order->add_order_note( __( 'eInvoice check: the invoice was finalized without a passed check.', 'einvoice-validator-for-germanized' ) );

		return( self::finalize( $order ) );
	}


	/**
	 * Check the real files after rendering, and decide whether the invoice may be sent.
	 *
	 * ⚠️ THIS CHECK RUNS EVEN WHEN THE DRAFT PASSED. A setting changed in between, an e-invoice created by hand, or a
	 * difference between draft and final file would otherwise go out unseen. Its verdict is kept per invoice number,
	 * because an order can carry several invoices and cancellations.
	 *
	 * @param WC_Order $order
	 * @param string $number the formatted number of the invoice
	 * @param array[] $docs list of { label, xml }
	 * @param bool $cancellation
	 */
	public static function check_final( $order, $number, array $docs, $cancellation = false )
	{
		if ( ! $docs || EIVG_Customer::mode( $order ) === EIVG_Settings::OFF )
			return;

		$result = self::validate_all( $order, $docs, 'final-' . sanitize_file_name( $number ) );
		$final  = EIVG_Flow::meta_list( $order, '_eivg_final' );

		$final[ $number ] = array(
			'time'     => time(),
			'error'    => $result['error'],
			'blocking' => count( $result['blocking'] ),
			'known'    => count( $result['known'] ),
			'findings' => $result['findings'],
			'reports'  => $result['reports'],
		);

		$order->update_meta_data( '_eivg_final', $final );
		$order->save();

		if ( $result['error'] !== '' || ! $result['blocking'] )
			return;

		// ⚠️ ONLY WHAT THE DRAFT CHECK DID NOT ALREADY SAY. An invoice finalized in the mode "report" or past the check
		// carries the errors of its draft into the final file; repeating them as a second note and mail is noise. New
		// errors in the final file ( a setting changed in between, an e-invoice added by hand ) are what this check is for.
		$key   = static function ( $f ) { return( $f['rule'] . '|' . $f['document'] ); };
		$known = array_map( $key, array_filter( self::meta_list( $order, '_eivg_findings' ), static function ( $f ) { return( ! empty( $f['blocking'] ) && empty( $f['known'] ) ); } ) );
		$new   = array_values( array_filter( $result['blocking'], static function ( $f ) use ( $key, $known ) { return( ! in_array( $key( $f ), $known, true ) ); } ) );

		if ( ! $new )
			return;

		$count = count( $new );

		/* translators: 1: invoice number, 2: number of errors */
		$order->add_order_note( sprintf( _n( 'eInvoice check: the finished e-invoice of %1$s has %2$d error that the check of the draft did not show.', 'eInvoice check: the finished e-invoice of %1$s has %2$d errors that the check of the draft did not show.', $count, 'einvoice-validator-for-germanized' ), $number, $count ) );

		if ( EIVG_Customer::mode( $order ) === EIVG_Settings::HOLD && (string) $order->get_meta( '_eivg_status' ) !== self::OVERRIDDEN )
			self::mail( $order, 'final', $new, $number );
	}


	/**
	 * Whether Germanized Pro may send an invoice to the customer.
	 *
	 * ⚠️ ONLY THE MODE "hold" STOPS THE SENDING, AND ONLY FOR A FAILED FINAL CHECK. An invoice the shop owner finalized
	 * past the check is sent: the owner has decided.
	 *
	 * @param WC_Order $order
	 * @param string $number
	 * @return bool
	 */
	public static function may_send( $order, $number )
	{
		if ( EIVG_Customer::mode( $order ) !== EIVG_Settings::HOLD )
			return( true );

		if ( (string) $order->get_meta( '_eivg_status' ) === self::OVERRIDDEN )
			return( true );

		$final = EIVG_Flow::meta_list( $order, '_eivg_final' );

		if ( empty( $final[ $number ]['blocking'] ) )
			return( true );

		/* translators: %s: invoice number */
		$order->add_order_note( sprintf( __( 'eInvoice check: %s was not sent to the customer because the finished e-invoice has errors. Send it by hand once it is corrected or accepted.', 'einvoice-validator-for-germanized' ), $number ) );

		return( false );
	}


	/**
	 * Check every e-invoice of a list, keep the reports, and collect the findings in plain words.
	 *
	 * ⚠️ A KNOWN ERROR OF THE INVOICE PLUGIN COUNTS AS AN ERROR IN THE LIST, BUT NOT IN THE DECISION. It stays visible with
	 * `blocking` set, lands in `known` instead of `blocking`, and its hint is the adapter's explanation
	 * ( EIVG_Adapter::known_defect() ).
	 *
	 * @return array { error: string, blocking: array[], known: array[], findings: array[], reports: string[] }
	 */
	private static function validate_all( $order, array $docs, $stage )
	{
		$out = array( 'error' => '', 'blocking' => array(), 'known' => array(), 'findings' => array(), 'reports' => array() );

		foreach ( $docs as $doc )
		{
			$r = EIVG_Engine::validate( (string) $doc['xml'] );

			if ( $r['error'] !== '' )
			{
				$out['error'] = $r['error'];
				continue;
			}

			if ( $r['report'] !== '' )
			{
				$file = EIVG_Reports::store( $order, $stage, (string) $doc['label'], $r['report'] );

				if ( $file !== '' )
					$out['reports'][] = $file;
			}

			foreach ( $r['messages'] as $m )
			{
				$e             = EIVG_Texts::explain( $m );
				$e['document'] = (string) $doc['label'];
				$e['blocking'] = EIVG_Engine::is_blocking( $m );
				$e['known']    = false;

				if ( $e['blocking'] )
				{
					$defect = self::$adapter->known_defect( $e );

					if ( $defect !== '' )
					{
						$e['hint']  = $defect;
						$e['known'] = true;
					}
				}

				$out['findings'][] = $e;

				if ( $e['blocking'] && $e['known'] )
					$out['known'][] = $e;
				elseif ( $e['blocking'] )
					$out['blocking'][] = $e;
			}
		}

		return( $out );
	}


	/**
	 * Store the state and the findings of the draft check.
	 *
	 * @return bool whether the result differs from the last one
	 */
	private static function set_state( $order, $state, array $findings )
	{
		$key = md5( $state . wp_json_encode( array_map( static function ( $f ) { return( $f['rule'] . '|' . $f['document'] ); }, $findings ) ) );
		$old = (string) $order->get_meta( '_eivg_hash' );

		$order->update_meta_data( '_eivg_status', $state );
		$order->update_meta_data( '_eivg_findings', $findings );
		$order->update_meta_data( '_eivg_checked', time() );
		$order->update_meta_data( '_eivg_hash', $key );
		$order->save();

		return( $old !== $key );
	}


	/** @return true|WP_Error */
	private static function finalize( $order )
	{
		$result = self::$adapter->finalize( $order );

		if ( is_wp_error( $result ) )
			/* translators: %s: the error message of the invoice plugin */
			$order->add_order_note( sprintf( __( 'eInvoice check: the invoice could not be finalized ( %s ).', 'einvoice-validator-for-germanized' ), $result->get_error_message() ) );

		return( $result );
	}


	/**
	 * The mail about a known error of the invoice plugin, once per site and error.
	 *
	 * ⚠️ ONCE, NOT PER ORDER. The known error of Germanized Pro sits in every UBL file it writes; a mail per order would
	 * teach the shop owner to ignore the mails of this plugin. The note at each order stays.
	 *
	 * @param WC_Order $order
	 * @param array[] $known
	 */
	private static function mail_known_once( $order, array $known )
	{
		$sent = get_option( 'eivg_known_mailed', array() );
		$sent = is_array( $sent ) ? $sent : array();
		$keys = array_unique( array_map( static function ( $f ) { return( $f['rule'] ); }, $known ) );

		sort( $keys );
		$key = implode( '|', $keys );

		if ( in_array( $key, $sent, true ) )
			return;

		$sent[] = $key;
		update_option( 'eivg_known_mailed', $sent, false );

		self::mail( $order, 'known', $known );
	}


	/**
	 * The mail to the shop owner, with each error in plain words.
	 *
	 * @param WC_Order $order
	 * @param string $state held, reported or final
	 * @param array[] $blocking
	 * @param string $number
	 */
	private static function mail( $order, $state, array $blocking, $number = '' )
	{
		$to = EIVG_Settings::notify_email();

		if ( ! is_email( $to ) )
			return;

		/* translators: 1: site name, 2: order number */
		$subject = sprintf( __( '[%1$s] E-invoice of order %2$s has errors', 'einvoice-validator-for-germanized' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );

		if ( $state === self::HELD )
			$lead = __( 'The invoice of this order was held back as a draft. It is finalized and sent as soon as the e-invoice is valid.', 'einvoice-validator-for-germanized' );
		elseif ( $state === self::REPORTED )
			$lead = __( 'The invoice of this order was finalized anyway, as set for this customer class. The customer may reject it.', 'einvoice-validator-for-germanized' );
		elseif ( $state === 'known' )
			/* translators: %s: name of the invoice plugin */
			$lead = sprintf( __( 'The invoice of this order was finalized. Its e-invoice has no error the shop can correct, but a known error of %s that a receiver may reject.', 'einvoice-validator-for-germanized' ), self::$adapter->name() );
		else
			/* translators: %s: invoice number */
			$lead = sprintf( __( 'The finished e-invoice of %s has errors.', 'einvoice-validator-for-germanized' ), $number );

		$lines = array( $lead, '' );

		foreach ( $blocking as $f )
		{
			$lines[] = '• ' . $f['text'] . ( $f['fields'] ? ' ( ' . implode( ', ', $f['fields'] ) . ' )' : '' ) . ' [' . $f['rule'] . ', ' . $f['document'] . ']';

			if ( $f['hint'] !== '' )
				$lines[] = '  ' . $f['hint'];
		}

		$lines[] = '';
		/* translators: %s: link to the order */
		$lines[] = sprintf( __( 'Open the order: %s', 'einvoice-validator-for-germanized' ), $order->get_edit_order_url() );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
