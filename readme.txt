=== eInvoice Validator for Germanized ===
Contributors: naranili
Tags: e-invoice, xrechnung, zugferd, peppol, germanized
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks every e-invoice that Germanized Pro creates against the official rules, in your shop, and holds back a faulty invoice before it goes out.

== Description ==

eInvoice Validator for Germanized is an independent plugin. It is not made by vendidero, the maker of Germanized. It checks the e-invoices that Germanized Pro writes for your WooCommerce orders ( ZUGFeRD, XRechnung, Peppol ) against EN 16931 and its national rules, with the validation engine VAMPIRE PHP on your own server.

Germanized Pro creates e-invoices but does not check them. A faulty e-invoice is usually noticed only when the customer rejects it, and by then it has a number and needs a cancellation. This plugin checks the invoice while it is still a draft.

= How it works =

1. Germanized Pro creates the invoice of an order as a draft, as always.
2. The plugin lets Germanized Pro write the e-invoices of the draft in memory and checks them.
3. If they are valid, the plugin finalizes the invoice, and Germanized Pro numbers and sends it as usual.
4. If they have errors, the invoice stays a draft. You get a note at the order and a mail that names every error in plain words, with the field and what to do.
5. After you correct the order, you click "Check again". You can also finalize the invoice without the check.

After finalization the plugin checks the finished files once more. An error that appears only there stops the sending of the invoice to the customer.

The order itself is never touched. Checkout, payment, order status and downloads run as always; only the invoice waits.

= Businesses and private customers =

You choose per customer class what happens with a faulty invoice: hold it back, finalize it anyway and only report the errors, or do not check. By default, invoices to businesses are held back and invoices to private customers are only reported. An order counts as an order of a business when it carries a company name, a VAT ID or a buyer reference.

= Fields at the checkout =

Optionally the checkout asks for what an e-invoice to a business needs: whether the buyer is a public authority, the buyer's own order number, and the buyer's Peppol ID. The fields appear only when a company name is entered, so the company field of the checkout must be switched on. They work in the block checkout and in the classic checkout. You can also enter or correct them at the order.

A public authority ticks a box and then has to enter its Leitweg-ID. The checkout tests the check digits of the Leitweg-ID and refuses one that does not match. For every other buyer the plugin writes the order number into the buyer reference where the format requires one: always in XRechnung, and in Peppol when seller and buyer are both in Germany.

The box, the Leitweg-ID and the Peppol ID are also kept at the customer. A returning customer finds them filled in, and they can be changed under My account -> Addresses and in the user profile in the admin.

The field for the Peppol ID appears only when Germanized Pro creates Peppol files. Neither Germanized Pro nor this plugin sends invoices through the Peppol network; the Peppol ID is needed by a shop that passes its Peppol files to an access point itself. Without a Peppol ID from the customer, the plugin builds the Peppol address from the buyer's VAT ID, with the address scheme of the country of the VAT ID.

= Reports =

Every check leaves a report in the report format of the KoSIT validator at the order. You can download it there; it never goes to the customer. The folder of the reports is closed to direct access.

= Self-test =

The page WooCommerce -> eInvoice check -> Self-test looks for settings that would make every e-invoice fail: VAT ID and address of the seller, contact data, bank account, the automatic creation of e-invoices in Germanized Pro, the company field of the checkout, and memory.

= Known error of Germanized Pro =

Germanized Pro 4.3.10 marks the PDF it embeds in Peppol and XRechnung UBL files with a wrong type. The plugin shows this error at every invoice and mails it once, but it does not hold an invoice back for it, because you cannot correct it in the shop. ZUGFeRD files are not affected.

== External services ==

**This plugin contacts no external service.** The check runs on your server. The plugin sends nothing to any server, also not to its maker.

== Installation ==

1. Install and activate WooCommerce and Germanized Pro.
2. In Germanized Pro, switch on the automatic creation of e-invoices and the automatic finalization of invoices.
3. Install and activate this plugin.
4. Open WooCommerce -> eInvoice check -> Self-test and fix what it reports.
5. Choose the modes for businesses and private customers under WooCommerce -> eInvoice check -> Settings.

PHP 8.2 is required. A check adds about 25 MB to the memory your shop already uses; 256 MB are recommended.

== Frequently Asked Questions ==

= Does the plugin change Germanized Pro? =

No. It answers one question of Germanized Pro differently while it is active: whether an invoice is finalized right away. The stored setting stays as you set it. Deactivate the plugin and Germanized Pro works as before.

= What happens if the check itself fails? =

Then the invoice is finalized as Germanized Pro would have done, and the note at the order says that it was not checked. A technical problem of the check never holds back an invoice.

= Does it work without automatic finalization? =

Yes, with less effect. Then the plugin checks the finished files after you finalize an invoice by hand, and in the mode "hold back" it stops only the sending.

= Which formats are checked? =

Every file Germanized Pro creates automatically: ZUGFeRD in its profiles, XRechnung and Peppol BIS Billing 3. The engine chooses the rules from the file itself.

== Changelog ==

= 1.0.1 =
* Maintenance release without visible changes.

= 1.0.0 =
* A rule that cannot be evaluated for an invoice no longer stops the check; the report lists it as not evaluable.
* First version: check of the e-invoices of Germanized Pro before finalization, hold back or report per customer class, optional checkout fields for public authorities with Leitweg-ID, order number and Peppol ID, kept at the customer, check reports at the order, overview of held invoices and self-test.
