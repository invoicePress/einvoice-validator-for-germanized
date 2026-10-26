<?php
/**
 * The stored check reports ( KoSIT XML ) and their download.
 *
 * @package eivg
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class EIVG_Reports
{
	const ACTION = 'eivg_report';


	/**
	 * The folder of the reports.
	 *
	 * ⚠️ OUTSIDE THE REACH OF A PLAIN LINK. A report repeats name, address and amounts of the invoice. The folder in
	 * uploads carries an `.htaccess` that denies every request and an empty `index.php`; the download goes through
	 * admin-post.php with a nonce and the capability to edit the order. On nginx the `.htaccess` has no effect, so the
	 * folder name also carries a random part that no link reveals.
	 *
	 * @return string with trailing slash, '' when uploads is not writable
	 */
	public static function dir()
	{
		$key = (string) get_option( 'eivg_reports_key', '' );

		if ( $key === '' )
		{
			$key = wp_generate_password( 16, false );
			update_option( 'eivg_reports_key', $key, false );
		}

		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'eivg-reports-' . $key . '/';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) )
			return( '' );

		if ( ! is_file( $dir . '.htaccess' ) )
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" );

		if ( ! is_file( $dir . 'index.php' ) )
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );

		return( $dir );
	}


	/**
	 * Store one report.
	 *
	 * @param WC_Order $order
	 * @param string $stage 'draft' or 'final-<number>'
	 * @param string $label the e-invoice, for example "CIUS Peppol"
	 * @param string $xml the report
	 * @return string the file name, '' on failure
	 */
	public static function store( $order, $stage, $label, $xml )
	{
		$dir = self::dir();

		if ( $dir === '' )
			return( '' );

		$name = sanitize_file_name( sprintf( 'order-%d-%s-%s.xml', $order->get_id(), $stage, $label ) );

		return( file_put_contents( $dir . $name, $xml ) !== false ? $name : '' );
	}


	/**
	 * The download link of a report.
	 *
	 * @param WC_Order $order
	 * @param string $name
	 * @return string
	 */
	public static function url( $order, $name )
	{
		return( wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'order' => $order->get_id(), 'file' => $name ), admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $order->get_id() ) );
	}


	/** The reports of the draft check of an order, newest file per e-invoice. */
	public static function draft_reports( $order )
	{
		$dir = self::dir();

		if ( $dir === '' )
			return( array() );

		return( array_map( 'basename', (array) glob( $dir . sanitize_file_name( 'order-' . $order->get_id() . '-draft-' ) . '*.xml' ) ) );
	}


	/** admin-post.php?action=eivg_report */
	public static function download()
	{
		$id   = absint( $_GET['order'] ?? 0 );
		// The name comes from sanitize_file_name() in store() and needs no URL encoding: letters, digits, dot, hyphen, underscore.
		$name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

		check_admin_referer( self::ACTION . '_' . $id );

		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) )
			wp_die( esc_html__( 'You are not allowed to download this report.', 'einvoice-validator-for-germanized' ), '', array( 'response' => 403 ) );

		// ⚠️ The file must belong to the order named in the signed link; the name alone is not trusted.
		$path = self::dir() . $name;

		if ( strpos( $name, 'order-' . $id . '-' ) !== 0 || ! is_file( $path ) )
			wp_die( esc_html__( 'The report does not exist.', 'einvoice-validator-for-germanized' ), '', array( 'response' => 404 ) );

		nocache_headers();
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- a local file of this plugin, streamed as a download.
		exit;
	}
}
