# Hook naming convention (suite-todo 0.2c)

`suite-todo-claude-code-v1.1.md`, task 0.2(c) names four hooks: `einvoice/model`,
`einvoice/register_template`, `einvoice/validate`, `einvoice/report_html`. This document records what
they mean and, honestly, which ones exist today versus which ones don't yet have anything to attach to
— per the project's own rule ("Nichts erfinden"), no `do_action()`/`apply_filters()` call was added
here for behaviour that doesn't exist yet.

## The convention itself

Going forward, a hook shared across more than one plugin in this family (or one intended to become
shared) is named `einvoice/<verb-or-noun>` — no plugin prefix (`spev_`, `spx_`, …), because the whole
point of a shared name is that it does not belong to one plugin. A hook that is genuinely local to one
plugin's own internals keeps its existing `<prefix>_*` name; it does not need renaming just because
this convention exists.

## Mapping

| Convention name | Status | Current equivalent |
|---|---|---|
| `einvoice/model` | **Exists, not yet renamed** | `spev_invoice_model` filter ( `Context.php` ) — lets an add-on append sections to the finished invoice model. Left as `spev_invoice_model` for now: renaming a hook a real add-on may already use is a breaking change, not something to do as a side effect of writing this document (see CLAUDE.md, "Vorgeschichte nicht umschreiben" — the same caution applies to a hook contract as to a changelog). Rename it explicitly, in its own task, when something depends on the new name. |
| `einvoice/register_template` | **Does not exist yet** | Belongs to suite-todo task 0.6 ("Template-Hook und Visualizer-Plugin"), not yet built. When 0.6 adds `do_action( 'spev_register_templates', … )` ( as literally named in that task's own text ) it should use `einvoice/register_template` directly instead — no reason to introduce the old name first and rename it later. |
| `einvoice/validate` | **Does not exist yet** | There is no hook at the point a document is validated today — `SPKV_Runner::validate()` is a plain method call, not filtered/actioned. Nothing currently needs to observe or alter that step. Add `do_action( 'einvoice/validate', $content, $result )` ( or similar shape ) only when a real consumer needs it — e.g. task 5.x's usage/quota tracking, or an audit log. |
| `einvoice/report_html` | **Does not exist yet** | Closest existing thing is `SPKV_Plugin::explainer_html()`, a private method, not a hook — it decides whether the Explainer's rendering replaces the raw KoSIT HTML. Turning that decision point into a filter would let a third renderer participate, but nothing today needs a third option. |

## Existing per-plugin hooks (unaffected, for reference)

Not renamed, not superseded by this task — listed so a future rename knows what is actually out there:

- Viewer: `spev_code_tooltip`, `spev_invoice_model`, `spev_force_enqueue`, `spev_label_locale`,
  `spev_date_format`, `spev_template`, `spev_client_ip`
- Explainer: `spx_force_enqueue`, `spx_client_ip`, `spx_data_dir`
- Validator: `spkv_force_enqueue`, `spkv_client_ip`
- Exporter: `spvx_client_ip`

The four `*_client_ip` filters stayed per-plugin ( not folded into one `einvoice/client_ip` ) even
though `RateLimit` itself is now shared — see `RateLimit::clientIp()`'s own doc-comment: the filter name
is still built from the plugin's own `$domain` string, so an integration that already hooks
`spev_client_ip` keeps working unchanged after the suite-todo 0.2 refactor.
