<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;

/**
 * The words for a refusal of the books, in the language in use. Screens that are not part of
 * accounting, such as the sales register, show a refusal with them.
 */
final class AccountingMessage
{
    private const ARGUMENTS = ['line', 'max', 'code', 'name', 'debit', 'credit', 'date', 'until', 'scope_name', 'count', 'difference', 'min', 'from', 'to', 'net', 'limit', 'days', 'balance', 'percent'];

    public static function of(AccountingException|BranchException|PostingException $exception): string
    {
        if ($exception instanceof AccountingException && $exception->error === AccountingError::PermissionDenied) {
            return lang('Accounting.error_permission_denied');
        }

        $key = match (true) {
            $exception instanceof AccountingException => 'Accounting.error_' . $exception->error->value,
            $exception instanceof BranchException     => 'Branches.error_' . $exception->error->value,
            default                                   => 'Accounting.posting_' . $exception->error->value,
        };

        $arguments = [];

        foreach (self::ARGUMENTS as $name) {
            if (array_key_exists($name, $exception->context)) {
                $arguments[] = (string) $exception->context[$name];
            }
        }

        return lang($key, $arguments);
    }
}
