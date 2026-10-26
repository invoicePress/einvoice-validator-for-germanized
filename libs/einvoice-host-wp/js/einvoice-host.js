// SPDX-License-Identifier: GPL-2.0-or-later
/* Shared bridge registry for the eInvoice plugin family.
 *
 * Replaces the growing set of one-off globals ( window.SPKV_renderReport, window.SPX_wireReport, … )
 * with a single namespace. Each plugin that exposes a function for a sibling to call registers it here
 * ( `EInvoice.register( 'validator', { renderReport: fn } )` ) instead of inventing a new bare global
 * every time a new bridge is needed.
 *
 * ⚠️ THE OLD GLOBALS STAY, AS LITERAL ALIASES. A consumer that reads window.SPKV_renderReport directly
 * ( the viewer's own spev-uploader.js ) must keep working unchanged. This file does not remove or wrap
 * them; the plugins that define them still set them, in addition to registering here.
 *
 * Loaded by every participating plugin as a script dependency ( `wp_register_script` with this as a
 * dep of the plugin's own uploader script ), so it is guaranteed to exist before any `register()` call
 * runs, regardless of which plugin's assets happen to enqueue first.
 */

( function () {
	'use strict';

	if ( typeof window.EInvoice !== 'undefined' )
		return; // already defined - this file can be enqueued by more than one active plugin on the same page

	var registry = {};

	window.EInvoice = {

		/**
		 * @param {string} name  a short, stable key for the registering plugin, e.g. 'validator'
		 * @param {object} api   whatever functions/data that plugin wants siblings to call
		 */
		register: function ( name, api ) {
			registry[ name ] = api;
		},

		/**
		 * @param {string} name
		 * @return {object|undefined}
		 */
		get: function ( name ) {
			return registry[ name ];
		}

	};

}() );
