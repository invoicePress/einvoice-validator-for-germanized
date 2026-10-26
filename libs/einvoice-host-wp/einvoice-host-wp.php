<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Loader for the shared eInvoice WordPress host package ( SpecialPress\EInvoice\Host ).
 *
 * Not a Composer package on purpose: two of the four consuming plugins ( the Explainer and the
 * Validator ) have no `composer.json` at all today, and this package is a handful of small classes,
 * not a dependency tree - the same "required by hand, no Composer round trip" idiom the Viewer's own
 * `includes/core/` already uses for exactly this reason.
 *
 * Usage from a consuming plugin's bootstrap file:
 *
 *   require_once( dirname( __DIR__ ) . '/libs/einvoice-host-wp/einvoice-host-wp.php' );
 *
 * @package SpecialPress\EInvoice\Host
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


require_once( __DIR__ . '/src/RateLimit.php' );
require_once( __DIR__ . '/src/RoleRateLimit.php' );
require_once( __DIR__ . '/src/RoleGate.php' );
require_once( __DIR__ . '/src/Rest.php' );
require_once( __DIR__ . '/src/MasterDataStore.php' );

/**
 * ⚠️ `SupplierStore` MUST BE LOADED HERE AS WELL. The Composer autoloader would find it, but this file
 * exists precisely for the consumers that have no `composer.json` and require the classes by hand. A
 * consumer that calls `SupplierStore::all()` without this line gets "Class not found", a fatal error
 * while the page is built, not an empty list.
 *
 * ⚠️ A test setup that loads the classes through Composer does not reproduce this loading path and
 * therefore cannot catch a missing line here.
 */
require_once( __DIR__ . '/src/SupplierStore.php' );

require_once( __DIR__ . '/src/PdfPostProcessor.php' );
