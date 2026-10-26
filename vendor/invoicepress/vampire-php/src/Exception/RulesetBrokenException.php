<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Exception;

/**
 * The rule package itself cannot serve a rule, independent of the document: a side document ( `fn:document()` ) is
 * missing or not allowed.
 *
 * ⚠️ THIS ONE STILL ENDS THE VALIDATION. A rule that throws on one document is skipped and reported as not evaluable
 * ( Engine::notEvaluable() ), but a broken package would then quietly skip the same rules on every invoice and could
 * accept what the official validator refuses - "never a silently empty code list". The Engine rethrows this class.
 * VAMPIRE JS has the same split ( `RulesetBrokenError` in `runtime/SideDocuments.mjs` ).
 */
final class RulesetBrokenException extends \RuntimeException
{
}
