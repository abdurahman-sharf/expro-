<?php
/**
 * @var object $journal
 * @var string $number
 * @var string $source
 * @var string $status
 * @var bool   $show_branch
 * @var string $currency
 * @var string $context
 */
$config     = documentConfig();
$rtl        = isRtl();
$alignEnd   = $rtl ? 'al-l' : 'al-r';
$alignStart = $rtl ? 'al-r' : 'al-l';
$logo       = documentLogoSource($config, $context);
$amount     = static fn (?string $value): string => bccomp((string) $value, '0', 4) === 0 ? '' : documentIsolate(to_currency_no_money((string) $value));
$date       = static fn (?string $value): string => $value === null || strtotime($value) === false ? '' : to_date(strtotime($value));
?>
<?php if ($context === 'screen'): ?>
    <link rel="stylesheet" href="<?= assetUrl('css/document.css') ?>">
<?php endif; ?>
<div class="doc doc-journal" id="document" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
    <table class="doc-head">
        <tr>
            <td class="doc-company <?= $alignStart ?>">
                <?php if ($logo !== ''): ?>
                    <img class="doc-logo" src="<?= esc($logo) ?>" alt="">
                <?php endif; ?>
                <?php if (! empty($config['receipt_show_company_name'])): ?>
                    <div class="doc-company-name"><?= esc((string) ($config['company'] ?? '')) ?></div>
                <?php endif; ?>
                <div class="doc-company-info"><?= nl2br(esc(documentCompanyInfo($config))) ?></div>
            </td>
            <td class="doc-titlebox <?= $alignEnd ?>">
                <div class="doc-title"><?= esc(lang('Documents.journal')) ?> <?= $number === '' ? '' : documentIsolate('#' . $number) ?></div>
                <table class="doc-meta">
                    <tr>
                        <td class="doc-meta-label <?= $alignStart ?>"><?= lang('Common.date') ?></td>
                        <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($date($journal->journal_date)) ?></td>
                    </tr>
                    <tr>
                        <td class="doc-meta-label <?= $alignStart ?>"><?= lang('Accounting.status') ?></td>
                        <td class="doc-meta-value <?= $alignEnd ?>"><?= esc($status) ?></td>
                    </tr>
                    <tr>
                        <td class="doc-meta-label <?= $alignStart ?>"><?= lang('Accounting_journals.source') ?></td>
                        <td class="doc-meta-value <?= $alignEnd ?>"><?= esc($source) ?></td>
                    </tr>
                    <?php if ($show_branch): ?>
                        <tr>
                            <td class="doc-meta-label <?= $alignStart ?>"><?= lang('Accounting_journals.branch') ?></td>
                            <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate((string) $journal->branch_code) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($currency !== ''): ?>
                        <tr>
                            <td class="doc-meta-label <?= $alignStart ?>"><?= lang('Accounting_journals.currency') ?></td>
                            <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($currency) ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <?php if ((string) $journal->description !== ''): ?>
        <p class="doc-journal-description"><strong><?= lang('Accounting_journals.description') ?>:</strong> <?= nl2br(esc((string) $journal->description)) ?></p>
    <?php endif; ?>

    <table class="doc-items doc-grid">
        <thead>
            <tr>
                <th class="<?= $alignStart ?>"><?= lang('Accounting_journals.account') ?></th>
                <th class="<?= $alignStart ?>"><?= lang('Accounting_journals.line_description') ?></th>
                <th class="doc-col-amount <?= $alignEnd ?>"><?= lang('Accounting_journals.debit') ?></th>
                <th class="doc-col-amount <?= $alignEnd ?>"><?= lang('Accounting_journals.credit') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($journal->lines as $index => $line): ?>
                <tr class="<?= $index % 2 === 1 ? 'alt' : '' ?>">
                    <td class="<?= $alignStart ?>"><?= documentIsolate((string) $line->account_code) ?> <?= esc((string) $line->account_name) ?></td>
                    <td class="<?= $alignStart ?>"><?= esc((string) $line->description) ?></td>
                    <td class="doc-col-amount <?= $alignEnd ?>"><?= $amount($line->debit) ?></td>
                    <td class="doc-col-amount <?= $alignEnd ?>"><?= $amount($line->credit) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="doc-journal-totals">
                <td class="doc-strong <?= $alignStart ?>" colspan="2"><?= lang('Accounting_journals.totals') ?></td>
                <td class="doc-col-amount doc-strong <?= $alignEnd ?>"><?= documentIsolate(to_currency_no_money((string) $journal->total_debit)) ?></td>
                <td class="doc-col-amount doc-strong <?= $alignEnd ?>"><?= documentIsolate(to_currency_no_money((string) $journal->total_credit)) ?></td>
            </tr>
        </tfoot>
    </table>

    <p class="doc-journal-by">
        <?= lang('Accounting_journals.created_by') ?>: <?= esc((string) $journal->created_by_name) ?>
        <?php if ($journal->posted_at !== null): ?>
            &nbsp;|&nbsp; <?= lang('Accounting_journals.posted_by') ?>: <?= esc((string) $journal->posted_by_name) ?> <?= documentIsolate($date($journal->posted_at)) ?>
        <?php endif; ?>
    </p>
</div>
