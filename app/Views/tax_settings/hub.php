<?php
/**
 * @var list<array{id: string, module: string, path: string}> $cards
 * @var bool                                                  $saudi
 */
?>

<?= view('partial/header') ?>

<div class="container-fluid">
    <h1 class="h4 mb-2"><?= lang('Tax_settings.title') ?></h1>
    <p class="text-body-secondary"><?= lang($saudi ? 'Tax_settings.mode_on' : 'Tax_settings.mode_off') ?></p>

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
