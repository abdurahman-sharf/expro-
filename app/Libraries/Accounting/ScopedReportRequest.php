<?php

namespace App\Libraries\Accounting;

/**
 * What a screen of a report that adds up branches reads from the request: the branches asked for,
 * cut down to the ones the employee works in, and the words that say whose figures the report
 * holds. It is for a controller that has the request and the branches of the employee.
 */
trait ScopedReportRequest
{
    /**
     * @return list<int>|null Null means every branch.
     */
    abstract protected function branchIds(): ?array;

    /**
     * What the request names, and nothing when it names it more than once or not as text, which
     * is how a hand-made address gives an array.
     */
    protected function query(string $name): string
    {
        $value = $this->request->getGet($name);

        return is_string($value) ? trim($value) : '';
    }

    protected function flag(string $name): bool
    {
        return $this->query($name) === '1';
    }

    protected function requestedScope(): ReportScope
    {
        $requested = $this->request->getGet('branches');

        return ReportScope::resolve(is_array($requested) ? array_map(static fn (mixed $id): int => is_string($id) ? (int) $id : 0, $requested) : null, $this->branchIds());
    }

    /**
     * The branches the employee may choose from, none for someone who reads one only.
     *
     * @return list<object>
     */
    protected function branchChoices(ReportScope $scope): array
    {
        return $scope->choosable() ? $scope->choices : [];
    }

    /**
     * Whose figures the report holds, said only to someone who reads more than one branch.
     */
    protected function scopeLabel(ReportScope $scope): ?string
    {
        if (! $scope->choosable() || $scope->isEmpty()) {
            return null;
        }

        $codes = implode(lang('Accounting_reports.branch_separator'), $scope->codes);

        return match ($scope->kind) {
            ReportScopeKind::Company  => lang('Accounting_reports.scope_company'),
            ReportScopeKind::Partial  => lang('Accounting_reports.scope_partial', [$codes]),
            ReportScopeKind::Selected => lang('Accounting_reports.scope_selected', [$codes]),
        };
    }

    /**
     * The branches to carry into a link, when the request named any.
     *
     * @return list<int>|null
     */
    protected function branchesToCarry(ReportScope $scope): ?array
    {
        return $this->request->getGet('branches') === null ? null : ($scope->isEmpty() ? [0] : $scope->ids);
    }
}
