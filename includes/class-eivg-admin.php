<?php
/**
 * The page WooCommerce → eInvoice check: held invoices, settings and self-test.
 *
 * ⚠️ NO DESIGN KIT. The page uses the markup of WordPress itself ( wrap, nav-tab, form-table, widefat ), as every
 * WooCommerce and Gravity Forms plugin of the suite does.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Admin
{
	const PAGE = 'eivg';


	public static function boot()
	{
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_eivg_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_' . EIVG_Reports::ACTION, array( 'EIVG_Reports', 'download' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EIVG_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}


	public static function menu()
	{
		add_submenu_page( 'woocommerce', __( 'eInvoice check', 'einvoice-validator-for-germanized' ), __( 'eInvoice check', 'einvoice-validator-for-germanized' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}


	/** @return string */
	public static function url( $tab = '' )
	{
		return( add_query_arg( array_filter( array( 'page' => self::PAGE, 'tab' => $tab ) ), admin_url( 'admin.php' ) ) );
	}


	public static function action_links( $links )
	{
		array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'einvoice-validator-for-germanized' ) . '</a>' );

		return( $links );
	}


	/**
	 * A notice on the plugin list and the WooCommerce pages when the check cannot run.
	 */
	public static function notice()
	{
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'woocommerce_page_' . self::PAGE ), true ) || EIVG_Flow::active() )
			return;

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'eInvoice Validator for Germanized is not checking any invoice at the moment. The self-test names the cause.', 'einvoice-validator-for-germanized' ) . ' <a href="' . esc_url( self::url( 'selftest' ) ) . '">' . esc_html__( 'Open the self-test', 'einvoice-validator-for-germanized' ) . '</a></p></div>';
	}


	public static function render()
	{
		if ( ! current_user_can( 'manage_woocommerce' ) )
			return;

		$tabs = array(
			'held'     => __( 'Held invoices', 'einvoice-validator-for-germanized' ),
			'settings' => __( 'Settings', 'einvoice-validator-for-germanized' ),
			'selftest' => __( 'Self-test', 'einvoice-validator-for-germanized' ),
		);
		$tab  = sanitize_key( (string) ( $_GET['tab'] ?? 'held' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a tab changes nothing.
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'held';

		echo '<div class="wrap"><h1>' . esc_html__( 'eInvoice check', 'einvoice-validator-for-germanized' ) . '</h1>';

		if ( ! empty( $_GET['saved'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'einvoice-validator-for-germanized' ) . '</p></div>';

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $key => $label )
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>';

		echo '</nav>';

		call_user_func( array( __CLASS__, 'tab_' . $tab ) );

		echo '</div>';
	}


	/**
	 * The orders whose invoice waits as a draft.
	 *
	 * ⚠️ THROUGH wc_get_orders(), NOT THROUGH A QUERY OF ITS OWN, so that the list works with the classic order storage and
	 * with the order tables ( HPOS ) alike.
	 */
	private static function tab_held()
	{
		$orders = wc_get_orders( array(
			'limit'      => 100,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => array( array( 'key' => '_eivg_status', 'value' => EIVG_Flow::HELD ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a short list of held orders.
		) );

		echo '<p>' . esc_html__( 'These invoices were held back as drafts because their e-invoice has errors. Correct the order and check again, or finalize the invoice without the check.', 'einvoice-validator-for-germanized' ) . '</p>';

		if ( ! $orders )
		{
			echo '<p><strong>' . esc_html__( 'No invoice is held back.', 'einvoice-validator-for-germanized' ) . '</strong></p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'einvoice-validator-for-germanized' ) . '</th><th>' . esc_html__( 'Customer', 'einvoice-validator-for-germanized' ) . '</th><th>' . esc_html__( 'Checked', 'einvoice-validator-for-germanized' ) . '</th><th>' . esc_html__( 'First error', 'einvoice-validator-for-germanized' ) . '</th></tr></thead><tbody>';

		foreach ( $orders as $order )
		{
			$first = null;

			foreach ( EIVG_Flow::meta_list( $order, '_eivg_findings' ) as $f )
				if ( ! empty( $f['blocking'] ) ) { $first = $f; break; }

			$name = trim( $order->get_billing_company() ) !== '' ? $order->get_billing_company() : $order->get_formatted_billing_full_name();

			echo '<tr><td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $order->get_meta( '_eivg_checked' ) ) ) . '</td>';
			echo '<td>' . ( $first ? esc_html( $first['text'] ) . '<br><span class="description">' . esc_html( $first['hint'] ) . '</span>' : '' ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}


	private static function tab_settings()
	{
		$s     = EIVG_Settings::all();
		$modes = array(
			EIVG_Settings::HOLD   => __( 'Hold back a faulty invoice as a draft', 'einvoice-validator-for-germanized' ),
			EIVG_Settings::REPORT => __( 'Finalize it anyway and report the errors', 'einvoice-validator-for-germanized' ),
			EIVG_Settings::OFF    => __( 'Do not check', 'einvoice-validator-for-germanized' ),
		);

		$select = static function ( $name, $value ) use ( $modes )
		{
			echo '<select name="eivg[' . esc_attr( $name ) . ']" id="eivg-' . esc_attr( $name ) . '">';

			foreach ( $modes as $key => $label )
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';

			echo '</select>';
		};

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="eivg_save">';
		wp_nonce_field( 'eivg_save' );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="eivg-mode_business">' . esc_html__( 'Orders of businesses', 'einvoice-validator-for-germanized' ) . '</label></th><td>';
		$select( 'mode_business', $s['mode_business'] );
		echo '<p class="description">' . esc_html__( 'An order counts as an order of a business when it carries a company name, a VAT ID or a buyer reference.', 'einvoice-validator-for-germanized' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="eivg-mode_private">' . esc_html__( 'Orders of private customers', 'einvoice-validator-for-germanized' ) . '</label></th><td>';
		$select( 'mode_private', $s['mode_private'] );
		echo '<p class="description">' . esc_html__( 'A private customer does not need an e-invoice. Peppol and XRechnung files for private customers almost always lack the buyer reference, so holding them back is rarely useful.', 'einvoice-validator-for-germanized' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Checkout', 'einvoice-validator-for-germanized' ) . '</th><td><label><input type="checkbox" name="eivg[checkout_fields]" value="1"' . checked( $s['checkout_fields'], true, false ) . '> ' . esc_html__( 'Ask at the checkout whether the buyer is a public authority, and for Leitweg-ID, order number and e-invoice address', 'einvoice-validator-for-germanized' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The three fields are optional for the customer. Without them, an e-invoice for a public authority fails the check.', 'einvoice-validator-for-germanized' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="eivg-notify_email">' . esc_html__( 'Mail about errors to', 'einvoice-validator-for-germanized' ) . '</label></th><td><input type="email" class="regular-text" name="eivg[notify_email]" id="eivg-notify_email" value="' . esc_attr( $s['notify_email'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Leave it empty to use the address of the site administrator.', 'einvoice-validator-for-germanized' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Detailed analysis', 'einvoice-validator-for-germanized' ) . '</th><td><label><input type="checkbox" name="eivg[explorer_hint]" value="1"' . checked( $s['explorer_hint'], true, false ) . '> ' . esc_html__( 'Show a hint how to open the check report in the eInvoice Validation Explorer', 'einvoice-validator-for-germanized' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}


	private static function tab_selftest()
	{
		echo '<p>' . esc_html__( 'The self-test looks for settings that would make every e-invoice fail. Fix these before the first order.', 'einvoice-validator-for-germanized' ) . '</p>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( EIVG_Selftest::run() as $row )
		{
			echo '<tr><td style="width:2em">' . ( $row['ok'] ? '<span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>' : '<span class="dashicons dashicons-warning" style="color:#d63638"></span>' ) . '</td>';
			echo '<td><strong>' . esc_html( $row['label'] ) . '</strong>' . ( $row['ok'] ? '' : '<br>' . esc_html( $row['hint'] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}


	public static function save()
	{
		check_admin_referer( 'eivg_save' );

		if ( ! current_user_can( 'manage_woocommerce' ) )
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'einvoice-validator-for-germanized' ), '', array( 'response' => 403 ) );

		EIVG_Settings::save( (array) wp_unslash( $_POST['eivg'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is checked in EIVG_Settings::save().

		wp_safe_redirect( add_query_arg( 'saved', 1, self::url( 'settings' ) ) );
		exit;
	}
}
