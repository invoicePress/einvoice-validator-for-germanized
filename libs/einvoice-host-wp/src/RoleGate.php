<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * "Available from role": who may use a feature at all - every visitor, or logged-in users from a role upwards.
 *
 * Used where a daily number makes no sense but access does: the add-ons of the viewer and the studios. The stored
 * value is a capability, not a role name:
 *
 *   ''                    every visitor, also without login
 *   'read'                subscribers and above
 *   'edit_posts'          contributors and above
 *   'publish_posts'       authors and above
 *   'edit_others_posts'   editors and above
 *   'manage_options'      administrators only
 *
 * ⚠️ A CAPABILITY, BECAUSE ROLES HAVE NO ORDER IN WORDPRESS. "From author upwards" is not a property of the role
 * list; it is "can publish posts". Asked as a capability, a custom role that was given `publish_posts` counts as an
 * author as well, and a site that renamed or removed "Author" still works.
 *
 * ⚠️ THE TEXTS COME FROM THE PLUGIN, for the reason given at RateLimit::renderRoleLimitsField().
 *
 * @package SpecialPress\EInvoice\Host
 * @since 1.4.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;

if ( ! class_exists( __NAMESPACE__ . '\\RoleGate' ) )
{

class RoleGate
{

	/** The levels in their order, lowest first. */
	const LEVELS = array( '', 'read', 'edit_posts', 'publish_posts', 'edit_others_posts', 'manage_options' );


	/**
	 * May the current visitor use a feature that is open from this level?
	 *
	 * @param	string	$level	one of LEVELS
	 * @return	bool
	 */

	public static function allowed( $level )
	{
		$level = self::sanitize( $level );

		if( $level === '' )
			return( true );

		return( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( $level ) );
	}


	/**
	 * @param	mixed	$value
	 * @return	string	a known level, '' for anything else
	 */

	public static function sanitize( $value )
	{
		$value = is_string( $value ) ? $value : '';

		return( in_array( $value, self::LEVELS, true ) ? $value : '' );
	}


	/**
	 * A select with the six levels.
	 *
	 * @param	string	$name	the field name
	 * @param	string	$value	the stored level
	 * @param	array	$labels	level => translated label, supplied by the plugin
	 * @return	void
	 */

	public static function renderSelect( $name, $value, array $labels )
	{
		$value = self::sanitize( $value );

		echo '<select name="' . esc_attr( (string) $name ) . '">';

		foreach( self::LEVELS as $level )
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $level ), selected( $value, $level, false ), esc_html( (string) ( $labels[ $level ] ?? $level ) ) );

		echo '</select>';
	}

}

}
