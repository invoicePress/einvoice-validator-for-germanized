<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The shared entry-point contract for a PDF post-processor: something that appends to, or otherwise
 * mutates, an mPDF document AFTER a template has been written into it and BEFORE it is output.
 * `gravityforms_gpdf-epcqr` and `-swissqr` both do exactly this ( each hooked on Gravity PDF's own
 * `gfpdf_mpdf_class` filter, priority 99 ). This interface gives their entry-point methods one shared,
 * named shape instead of each plugin inventing its own five-argument signature, so that a second PDF
 * backend can call the SAME registered post-processors without knowing which backend it is.
 *
 * ⚠️ `gravityforms_gpdf-ksef` DOES NOT IMPLEMENT THIS, AND THAT IS CORRECT. Its QR mechanism
 * ( `Ksef_Pdf_Integration` ) hooks `gfpdf_pdf_html_output`, an HTML-STRING filter that runs BEFORE an
 * mPDF object even exists ( template-tag substitution, `<barcode>` markup resolved later by mPDF's own
 * tag parser ), not an $mpdf-OBJECT filter after the fact. That is the right choice for KSeF's
 * single-line KOD-I payload; EPC's multi-line payload genuinely needs a whole appended page instead of
 * an inline barcode tag. Forcing KSeF into this interface's shape would change the behaviour of shipped
 * code, not merely rename an entry point.
 *
 * @package SpecialPress\EInvoice\Host
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! interface_exists( __NAMESPACE__ . '\\PdfPostProcessor' ) )
{

interface PdfPostProcessor
{

	/**
	 * @param	object	$mpdf	The mPDF instance Gravity PDF's `gfpdf_mpdf_class` filter hands in.
	 *							⚠️ NEVER type-hinted as `\Mpdf\Mpdf`: Gravity PDF bundles its own
	 *							copy under the prefixed namespace `GFPDF_Vendor\Mpdf\Mpdf`, a
	 *							DIFFERENT class tree a `\Mpdf\Mpdf` type-hint would reject outright.
	 *							Implementations duck-type instead ( `method_exists( $mpdf, 'AddPage' )`
	 *							etc. ), as this file's header describes.
	 * @param	array	$context	At minimum 'form', 'entry', 'settings' ( Gravity PDF's own
	 *							per-document settings array, carries 'id' and 'name' ) and 'backend' ( a
	 *							string identifying the calling PDF backend, 'gravity-pdf' for every
	 *							implementation this package ships ). 'model' is reserved for a second
	 *							backend that works from a model array; it is absent or null when called
	 *							from the Gravity PDF backend, which has no such model to hand in.
	 * @return	object	$mpdf, always. This is conceptually a WordPress filter's callback contract
	 *					( return the value, mutated or not ), even for a backend that does not use
	 *					WordPress hooks at all.
	 */

	public function apply( $mpdf, array $context );

}

}
