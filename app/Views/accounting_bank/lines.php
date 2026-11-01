<?php
/**
 * @var object       $treasury
 * @var string       $state
 * @var string       $from
 * @var string       $to
 * @var int          $page
 * @var int          $pages
 * @var int          $total
 * @var list<object> $rows
 * @var Closure      $amount
 * @var Closure      $date
 */
$id    = (int) $treasury->treasury_id;
$base  = base_url('accounting_treasuries/bank/' . $id);
$query = static fn (int $target): string => '?' . http_build_query(['state' => $state, 'from' => $from, 'to' => $to, 'page' => $target]);
$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('.bank-unmatch').on('click', function() {
            if (!confirm(<?= json_encode(lang('Accounting_bank.confirm_unmatch'), $flags) ?>)) {
                return;
            }

            $.post(<?= json_encode(base_url('accounting_treasuries/bank/' . $id . '/unmatch/'), $flags) ?> + $(this).data('match'), {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');

                if (response.success) {
                    setTimeout(function() {
                        window.location.reload();
                    }, 700);
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc($base, 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.back_account') ?></a>
</div>

<div class="container-fluid py-3" id="bank_lines">
    <h1 class="h4"><?= lang('Accounting_bank.lines_title', [esc($treasury->name)]) ?></h1>

    <form method="get" class="row g-2 align-items-end mb-3" id="bank_lines_filter">
        <div class="col-auto">
            <label class="form-label" for="state"><?= lang('Accounting_bank.state') ?></label>
            <select class="form-select form-select-sm" name="state" id="state">
                <?php foreach (['all', 'unmatched', 'matched'] as $option): ?>
                    <option value="<?= $option ?>"<?= $state === $option ? ' selected' : '' ?>><?= lang('Accounting_bank.state_' . $option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label" for="from"><?= lang('Accounting_bank.from') ?></label>
            <input type="date" class="form-control form-control-sm" name="from" id="from" value="<?= esc($from, 'attr') ?>">
        </div>
        <div class="col-auto">
            <label class="form-label" for="to"><?= lang('Accounting_bank.to') ?></label>
            <input type="date" class="form-control form-control-sm" name="to" id="to" value="<?= esc($to, 'attr') ?>">
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-outline-primary btn-sm"><?= lang('Accounting_bank.filter') ?></button></div>
    </form>

    <?php if ($rows === []): ?>
        <p class="text-body-secondary" id="bank_lines_empty"><?= lang('Accounting_bank.lines_none') ?></p>
    <?php else: ?>
        <p class="small text-body-secondary"><?= lang('Accounting_bank.lines_count', [$total]) ?></p>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="bank_lines_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_bank.date') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.description') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.reference') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.deposit') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.withdrawal') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.status') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Accounting_bank.actions') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $deposit = bccomp((string) $row->amount, '0', 4) > 0; ?>
                        <tr data-line-id="<?= (int) $row->line_id ?>">
                            <td><?= esc($date((string) $row->line_date)) ?></td>
                            <td><?= esc($row->description) ?></td>
                            <td><bdi dir="ltr"><?= esc($row->reference) ?></bdi></td>
                            <td><?= $deposit ? $amount((string) $row->amount) : '' ?></td>
                            <td><?= $deposit ? '' : $amount(ltrim((string) $row->amount, '-')) ?></td>
                            <td>
                                <?php if ($row->match_id === null): ?>
                                    <span class="badge text-bg-secondary"><?= lang('Accounting_bank.state_unmatched') ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-success"><?= lang('Accounting_bank.state_matched') ?></span>
                                    <?php if ($row->reconciliation_id !== null): ?>
                                        <span class="bi bi-lock-fill" role="img" title="<?= esc(lang('Accounting_bank.locked_hint'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_bank.locked'), 'attr') ?>"></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($row->match_id === null): ?>
                                    <a class="btn btn-outline-primary btn-sm" href="<?= esc($base . '/match/' . (int) $row->line_id, 'attr') ?>"><?= lang('Accounting_bank.match') ?></a>
                                <?php elseif ($row->reconciliation_id === null): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm bank-unmatch" data-match="<?= (int) $row->match_id ?>"><?= lang('Accounting_bank.unmatch') ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav aria-label="<?= esc(lang('Accounting_bank.pages'), 'attr') ?>">
                <ul class="pagination pagination-sm">
                    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= esc($query(max(1, $page - 1)), 'attr') ?>"><?= lang('Accounting_bank.previous') ?></a></li>
                    <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
                    <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= esc($query(min($pages, $page + 1)), 'attr') ?>"><?= lang('Accounting_bank.next') ?></a></li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
