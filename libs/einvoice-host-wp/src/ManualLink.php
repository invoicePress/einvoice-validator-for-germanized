<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The link to the manual that ships with a plugin: in the plugin list and on the settings page.
 *
 * Ralf's order F3.10 of 18.10.2026 ( ENTSCHEIDUNGEN.md ): every plugin of the suite shows "Manual" under its name in the
 * plugin list ( `plugin_action_links_<file>` ) and "Manual · Support" in its description line ( `plugin_row_meta` ), and
 * "Open manual" at the top right of each settings page. The target is `manual/manual.<language>.html` in the package,
 * chosen by `get_user_locale()`, falling back to English. Two lines per plugin:
 *
 *     require_once __DIR__ . '/libs/einvoice-host-wp/src/ManualLink.php';
 *     \SpecialPress\EInvoice\Host\ManualLink::register( __FILE__, static function () {
 *         return array( 'manual' => __( 'Manual', 'my-domain' ), 'support' => __( 'Support', 'my-domain' ) );
 *     }, 'https://wordpress.org/support/plugin/my-plugin/' );
 *
 * ⚠️ **NOT ONE PLUGIN HAD IT ON 19.10.2026**, the published viewer 2.36.0 included ( finding B194 ). The release build
 * therefore stops a package that ships `manual/` without calling register() ( `_claude-tools/check-kit-usage.mjs` ).
 *
 * ⚠️ **THE TEXTS ARRIVE AS A CALLBACK**, for the reason given at EInvoicePress_Design_Kit::offer_on_general_page(): a
 * plugin calls register() while its file is included, and a `__()` there loads the text domain before `init`. The
 * callback runs on the admin hooks, when translating is allowed. This library has no text domain of its own.
 *
 * ⚠️ **NO LINK WITHOUT THE FILE.** The manual lies in `manual/` only in the release ZIP; in a development folder it is
 * under `docs/manual/`. A link to a file that is not there would be a 404 in the plugin list, so url() asks the disk.
 *
 * ⚠️ **A NEW CLASS, NOT A NEW METHOD ON AN OLD ONE.** Every plugin carries its own copy of this library, and the copy
 * that loads first serves all of them. A method added to an existing class would be missing whenever an older copy won.
 *
 * @package SpecialPress\EInvoice\Host
 * @since 1.6.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;

if ( ! class_exists( __NAMESPACE__ . '\\ManualLink' ) )
{

class ManualLink
{

	/**
	 * Hooks the two links of one plugin into the plugin list.
	 *
	 * @param	string		$plugin_file	the main file of the plugin, `__FILE__` there
	 * @param	callable	$texts			returns array( 'manual' => …, 'support' => … ), both translated plain text
	 * @param	string		$support_url	where "Support" leads; empty leaves "Support" out
	 * @return	void
	 */

	public static function register( $plugin_file, $texts, $support_url = '' )
	{
		$plugin_file = (string) $plugin_file;

		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'plugin_basename' ) )
			return;

		$base = plugin_basename( $plugin_file );

		// Under the name, next to "Deactivate" and "Settings".
		add_filter( 'plugin_action_links_' . $base, static function ( $links ) use ( $plugin_file, $texts ) {

			$url = self::url( $plugin_file );

			if ( $url === '' )
				return( $links );

			$t = self::texts( $texts );
			$links = is_array( $links ) ? $links : array();
			$links[ 'manual' ] = self::anchor( $url, $t[ 'manual' ] );

			return( $links );

		} );

		// In the description line, after "Version" and "By".
		add_filter( 'plugin_row_meta', static function ( $meta, $file ) use ( $base, $plugin_file, $texts, $support_url ) {

			if ( $file !== $base )
				return( $meta );

			$meta = is_array( $meta ) ? $meta : array();
			$t    = self::texts( $texts );
			$url  = self::url( $plugin_file );

			if ( $url !== '' )
				$meta[] = self::anchor( $url, $t[ 'manual' ] );

			if ( (string) $support_url !== '' && $t[ 'support' ] !== '' )
				$meta[] = self::anchor( (string) $support_url, $t[ 'support' ] );

			return( $meta );

		}, 10, 2 );
	}


	/**
	 * The address of the manual in the language of the user, or '' when the package carries none.
	 *
	 * ⚠️ The user's language, not the site's: an English-speaking admin on a German site reads the English manual.
	 *
	 * @param	string	$plugin_file
	 * @return	string
	 */

	public static function url( $plugin_file )
	{
		$dir    = dirname( (string) $plugin_file ) . '/manual/';
		$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : 'en_US';
		$lang   = strtolower( substr( $locale, 0, 2 ) );

		foreach ( array_unique( array( $lang, 'en', 'de' ) ) as $l )
		{
			if ( preg_match( '/^[a-z]{2}$/', $l ) && is_readable( $dir . 'manual.' . $l . '.html' ) )
				return( plugins_url( 'manual/manual.' . $l . '.html', (string) $plugin_file ) );
		}

		return( '' );
	}


	/**
	 * The button "Open manual" for the top right of a settings page, or '' when the package carries no manual.
	 *
	 * @param	string	$plugin_file
	 * @param	string	$label			translated plain text
	 * @return	string	HTML
	 */

	public static function button( $plugin_file, $label )
	{
		$url = self::url( $plugin_file );

		if ( $url === '' )
			return( '' );

		return( '<a class="page-title-action" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( (string) $label ) . '</a>' );
	}


	/** @return array{manual:string,support:string} */
	private static function texts( $texts )
	{
		$t = is_callable( $texts ) ? (array) call_user_func( $texts ) : array();

		return( array(
			'manual'  => isset( $t[ 'manual' ] ) ? (string) $t[ 'manual' ] : 'Manual',
			'support' => isset( $t[ 'support' ] ) ? (string) $t[ 'support' ] : '',
		) );
	}


	/** A link that opens in a new tab, so that the plugin list stays where it was. */
	private static function anchor( $url, $text )
	{
		return( '<a href="' . esc_url( (string) $url ) . '" target="_blank" rel="noopener">' . esc_html( (string) $text ) . '</a>' );
	}

}

}
