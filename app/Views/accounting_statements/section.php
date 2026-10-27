<?php
/**
 * @var StatementSection                                       $section
 * @var string                                                 $heading
 * @var string                                                 $total_label
 * @var string                                                 $total_id
 * @var string                                                 $total
 * @var list<array{id: string, label: string, amount: string}> $extra
 * @var Closure                                                $amount
 * @var Closure                                                $ledger_url
 */

use App\Libraries\Accounting\StatementLine;
use App\Libraries\Accounting\StatementSection;

$extra ??= [];
?>
<tbody id="section_<?= esc($total_id, 'attr') ?>">
    <tr class="table-light">
        <th scope="colgroup" colspan="3"><?= esc($heading) ?></th>
    </tr>
    <?php if ($section->lines === [] && $extra === []): ?>
        <tr><td colspan="3" class="text-body-secondary" id="<?= esc($total_id, 'attr') ?>_empty"><?= lang('Accounting_statements.empty') ?></td></tr>
    <?php endif; ?>
    <?php foreach ($section->lines as $line): ?>
        <?php /** @var StatementLine $line */ ?>
        <tr<?= $line->isGroup ? ' class="fw-bold"' : '' ?> data-account="<?= esc($line->code, 'attr') ?>">
            <td><bdi dir="ltr"><?= esc($line->code) ?></bdi></td>
            <td><span class="acc-depth acc-depth-<?= min(6, max(0, $line->level - 1)) ?>"><a href="<?= esc($ledger_url($line->accountId), 'attr') ?>"><?= esc($line->name) ?></a></span></td>
            <td class="text-end"><?= $amount($line->amount) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php foreach ($extra as $row): ?>
        <tr id="<?= esc($row['id'], 'attr') ?>">
            <td></td>
            <td><?= esc($row['label']) ?></td>
            <td class="text-end"><?= $amount($row['amount']) ?></td>
        </tr>
    <?php endforeach; ?>
    <tr class="fw-bold border-top" id="<?= esc($total_id, 'attr') ?>">
        <th scope="row" colspan="2"><?= esc($total_label) ?></th>
        <td class="text-end"><?= $amount($total) ?></td>
    </tr>
</tbody>
