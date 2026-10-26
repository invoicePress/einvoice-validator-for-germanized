<?php
/**
 * The box "eInvoice check" on the order page: the result, the three e-invoice fields, and the two actions.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Order_Box
{

	public static function boot()
	{
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 40 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_fields' ), 60 );
		add_action( 'admin_post_eivg_recheck', array( __CLASS__, 'recheck' ) );
		add_action( 'admin_post_eivg_override', array( __CLASS__, 'override' ) );
	}


	public static function add()
	{
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box( 'eivg-check', __( 'eInvoice check', 'einvoice-validator-for-germanized' ), array( __CLASS__, 'render' ), $screen, 'normal', 'default' );
	}


	/**
	 * @param WP_Post|WC_Order $object the post with classic order storage, the order with HPOS
	 */
	public static function render( $object )
	{
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );

		if ( ! $order )
			return;

		$state   = (string) $order->get_meta( '_eivg_status' );
		$adapter = EIVG_Flow::adapter();
		$draft   = $adapter && $adapter->available() && $adapter->has_draft( $order );

		$labels = array(
			EIVG_Flow::PASSED     => __( 'The e-invoice is valid and the invoice was finalized.', 'einvoice-validator-for-germanized' ),
			EIVG_Flow::HELD       => __( 'The e-invoice has errors. The invoice is held back as a draft.', 'einvoice-validator-for-germanized' ),
			EIVG_Flow::REPORTED   => __( 'The e-invoice has errors. The invoice was finalized anyway, as set for this customer class.', 'einvoice-validator-for-germanized' ),
			EIVG_Flow::OVERRIDDEN => __( 'The invoice was finalized without a passed check.', 'einvoice-validator-for-germanized' ),
			EIVG_Flow::UNCHECKED  => __( 'The e-invoice could not be checked. The invoice was finalized as usual.', 'einvoice-validator-for-germanized' ),
			EIVG_Flow::SKIPPED    => __( 'No check for this customer class.', 'einvoice-validator-for-germanized' ),
		);

		// A passed check can still carry a known error of the invoice plugin; "valid" would then contradict the list below.
		if ( $state === EIVG_Flow::PASSED && array_filter( EIVG_Flow::meta_list( $order, '_eivg_findings' ), static function ( $f ) { return( ! empty( $f['known'] ) ); } ) )
			$labels[ $state ] = __( 'The e-invoice has no error the shop can correct, only the known error of the invoice plugin listed below. The invoice was finalized.', 'einvoice-validator-for-germanized' );

		echo '<p><strong>' . esc_html( $labels[ $state ] ?? __( 'Not checked yet.', 'einvoice-validator-for-germanized' ) ) . '</strong>';

		if ( $state !== '' && (int) $order->get_meta( '_eivg_checked' ) > 0 )
			/* translators: %s: date and time */
			echo ' <span class="description">' . esc_html( sprintf( __( 'Checked on %s.', 'einvoice-validator-for-germanized' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $order->get_meta( '_eivg_checked' ) ) ) ) . '</span>';

		echo '</p>';

		self::findings( EIVG_Flow::meta_list( $order, '_eivg_findings' ) );

		foreach ( EIVG_Flow::meta_list( $order, '_eivg_final' ) as $number => $final )
		{
			if ( ! empty( $final['blocking'] ) )
			{
				/* translators: %s: invoice number */
				echo '<p><strong>' . esc_html( sprintf( __( 'The finished e-invoice of %s has errors:', 'einvoice-validator-for-germanized' ), $number ) ) . '</strong></p>';
				self::findings( (array) ( $final['findings'] ?? array() ) );
			}
		}

		$reports = EIVG_Reports::draft_reports( $order );

		foreach ( EIVG_Flow::meta_list( $order, '_eivg_final' ) as $final )
			$reports = array_merge( $reports, (array) ( $final['reports'] ?? array() ) );

		if ( $reports )
		{
			echo '<p>' . esc_html__( 'Check reports:', 'einvoice-validator-for-germanized' ) . ' ';

			foreach ( array_unique( $reports ) as $i => $name )
				echo ( $i ? ' · ' : '' ) . '<a href="' . esc_url( EIVG_Reports::url( $order, $name ) ) . '">' . esc_html( $name ) . '</a>';

			echo '</p>';

			if ( EIVG_Settings::get( 'explorer_hint' ) )
				echo '<p class="description">' . esc_html__( 'A check report opens with every finding explained in the eInvoice Validation Explorer.', 'einvoice-validator-for-germanized' ) . '</p>';
		}

		echo '<h4>' . esc_html__( 'E-invoice details of this order', 'einvoice-validator-for-germanized' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'A value entered here wins over the one from the checkout. Save the order, then check again.', 'einvoice-validator-for-germanized' ) . '</p>';
		wp_nonce_field( 'eivg_fields', 'eivg_fields_nonce' );

		foreach ( EIVG_Checkout::fields() as $key => $f )
		{
			if ( $f['type'] === 'checkbox' )
			{
				// ⚠️ SHOWN WITH THE CURRENT ANSWER, NOT WITH THE OWN VALUE ONLY. Saving writes "yes" or "no", so the box must
				// start from what the checkout said; an empty box would turn a public authority into a company on save.
				echo '<p><label><input type="checkbox" name="eivg_fields[' . esc_attr( $key ) . ']" value="yes"' . checked( EIVG_Checkout::public_authority( $order ), true, false ) . '> ' . esc_html( $f['label'] ) . '</label></p>';
				continue;
			}

			$own = (string) $order->get_meta( '_eivg_' . $key );

			// The rules of the e-invoice do not test the check digits of a Leitweg-ID, so the box says it itself.
			if ( $key === 'buyer_reference' && EIVG_Checkout::public_authority( $order ) && EIVG_Checkout::value( $order, $key ) !== '' )
			{
				$error = EIVG_Checkout::leitweg_error( EIVG_Checkout::value( $order, $key ) );

				if ( $error !== '' )
					echo '<p class="description" style="color:#b32d2e">' . esc_html( $error ) . '</p>';
			}

			echo '<p><label for="eivg-' . esc_attr( $key ) . '">' . esc_html( $f['label'] ) . '</label><br>';
			echo '<input type="text" class="widefat" name="eivg_fields[' . esc_attr( $key ) . ']" id="eivg-' . esc_attr( $key ) . '" value="' . esc_attr( $own ) . '" placeholder="' . esc_attr( EIVG_Checkout::value( $order, $key ) ) . '"></p>';
		}

		if ( $draft && EIVG_Flow::active() )
		{
			$args = array( 'order' => $order->get_id() );

			echo '<p>';
			echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( $args, array( 'action' => 'eivg_recheck' ) ), admin_url( 'admin-post.php' ) ), 'eivg_recheck_' . $order->get_id() ) ) . '">' . esc_html__( 'Check again', 'einvoice-validator-for-germanized' ) . '</a> ';

			if ( $state === EIVG_Flow::HELD )
				echo '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( $args, array( 'action' => 'eivg_override' ) ), admin_url( 'admin-post.php' ) ), 'eivg_override_' . $order->get_id() ) ) . '">' . esc_html__( 'Finalize without check', 'einvoice-validator-for-germanized' ) . '</a>';

			echo '</p>';
		}
	}


	/** @param array[] $findings */
	private static function findings( array $findings )
	{
		$rows = array_filter( $findings, static function ( $f ) { return( ! empty( $f['blocking'] ) ); } );

		if ( ! $rows )
			return;

		echo '<ul style="list-style:disc;margin-left:1.5em">';

		foreach ( $rows as $f )
		{
			echo '<li><strong>' . esc_html( $f['text'] ) . '</strong>';

			if ( ! empty( $f['fields'] ) )
				echo ' <span class="description">( ' . esc_html( implode( ', ', $f['fields'] ) ) . ' )</span>';

			echo ' <code>' . esc_html( $f['rule'] ) . '</code> <span class="description">' . esc_html( $f['document'] ?? '' ) . '</span>';

			if ( ( $f['hint'] ?? '' ) !== '' )
				echo '<br>' . esc_html( $f['hint'] );

			echo '</li>';
		}

		echo '</ul>';
	}


	/** @param int $order_id */
	public static function save_fields( $order_id )
	{
		if ( ! isset( $_POST['eivg_fields_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eivg_fields_nonce'] ) ), 'eivg_fields' ) )
			return;

		$order = wc_get_order( $order_id );

		if ( ! $order )
			return;

		$input = (array) wp_unslash( $_POST['eivg_fields'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.

		foreach ( EIVG_Checkout::fields() as $key => $f )
		{
			// A checkbox is stored as "yes" or "no", so the answer at the order wins in both directions.
			if ( $f['type'] === 'checkbox' )
			{
				$order->update_meta_data( '_eivg_' . $key, empty( $input[ $key ] ) ? 'no' : 'yes' );
				continue;
			}

			$value = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );

			if ( $value === '' )
				$order->delete_meta_data( '_eivg_' . $key );
			else
				$order->update_meta_data( '_eivg_' . $key, $value );
		}

		$order->save();
	}


	public static function recheck()
	{
		$order = self::order_from_request( 'eivg_recheck' );

		EIVG_Flow::check( $order->get_id(), 'manual' );

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}


	public static function override()
	{
		$order  = self::order_from_request( 'eivg_override' );
		$result = EIVG_Flow::override( $order );

		if ( is_wp_error( $result ) )
			wp_die( esc_html( $result->get_error_message() ) );

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}


	/** @return WC_Order */
	private static function order_from_request( $action )
	{
		$id = absint( $_GET['order'] ?? 0 );

		check_admin_referer( $action . '_' . $id );

		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) )
			wp_die( esc_html__( 'You are not allowed to change this order.', 'einvoice-validator-for-germanized' ), '', array( 'response' => 403 ) );

		$order = wc_get_order( $id );

		if ( ! $order )
			wp_die( esc_html__( 'The order does not exist.', 'einvoice-validator-for-germanized' ), '', array( 'response' => 404 ) );

		return( $order );
	}
}
