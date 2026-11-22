<?php

use App\Libraries\EInvoicing\ReadinessCheck;

/**
 * @var list<array{id: string, module: string, path: string}> $cards
 * @var bool                                                  $saudi
 * @var list<ReadinessCheck>|null                             $checks What is not ready, for whoever holds electronic invoicing.
 */
?>

<?= view('partial/header') ?>

<div class="container-fluid">
    <h1 class="h4 mb-2"><?= lang('Tax_settings.title') ?></h1>
    <p class="text-body-secondary"><?= lang($saudi ? 'Tax_settings.mode_on' : 'Tax_settings.mode_off') ?></p>

    <?php if ($checks !== null): ?>
        <div class="card mb-3" id="tax_missing">
            <div class="card-body">
                <div class="fw-semibold mb-1"><?= lang('Tax_settings.missing_title') ?></div>
                <?php if ($checks === []): ?>
                    <div class="text-success"><?= lang('Tax_settings.missing_none') ?></div>
                <?php else: ?>
                    <ul class="mb-0">
                        <?php foreach ($checks as $check): ?>
                            <li class="<?= $check->status === 'fail' ? 'text-danger' : 'text-warning-emphasis' ?>"><?= lang('Accounting_einvoicing.check_' . $check->id) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <?php foreach ($cards as $card): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <a class="card h-100 text-decoration-none" href="<?= esc(site_url($card['path']), 'attr') ?>" id="tax_card_<?= esc($card['id'], 'attr') ?>">
                    <div class="card-body">
                        <div class="fw-semibold"><?= lang('Tax_settings.card_' . $card['id']) ?></div>
                        <div class="small text-body-secondary"><?= lang('Tax_settings.card_' . $card['id'] . '_help') ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?= view('partial/footer') ?>
