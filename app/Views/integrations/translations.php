<?php

use App\Libraries\Integrations\EntityTranslation;
use App\Libraries\Integrations\TranslationKind;

/**
 * @var list<EntityTranslation> $rows
 * @var int                     $total
 * @var int                     $page
 * @var int                     $pages
 * @var TranslationKind|null    $kind
 * @var string                  $search
 * @var string                  $language
 * @var list<string>            $locales
 * @var bool                    $enabled
 * @var bool                    $printing
 * @var EntityTranslation|null  $editing
 */
$kindChoices = ['' => lang('Integrations.all_kinds')];

foreach (TranslationKind::cases() as $case) {
    $kindChoices[$case->value] = lang($case->languageKey());
}
?>

<?= view('partial/header') ?>
<?= view('integrations/ajax') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Integrations.second_language') ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('integrations'), 'attr') ?>"><?= lang('Integrations.back') ?></a>
</div>

<p class="text-body-secondary"><?= lang('Integrations.translations_help') ?></p>

<?php if (! $enabled): ?>
    <div class="alert alert-warning" id="translations_off" role="status"><?= lang('Integrations.second_language_off') ?></div>
<?php elseif (! $printing): ?>
    <div class="alert alert-info" id="translations_not_printed" role="status"><?= lang('Integrations.second_language_not_printed') ?></div>
<?php endif; ?>

<?= form_open('integrations/translations/save', ['id' => 'translation_form', 'class' => 'app-form card integration-ajax mb-3']) ?>
    <div class="card-body">
        <h2 class="h6"><?= lang($editing === null ? 'Integrations.translation_new' : 'Integrations.translation_edit') ?></h2>
        <div class="form-grid">
            <div class="field">
                <label class="form-label" for="translation_kind"><?= lang('Integrations.kind') ?></label>
                <div class="field-control">
                    <?php if ($editing === null): ?>
                        <?= form_dropdown('kind', array_slice($kindChoices, 1, null, true), '', ['class' => 'form-select form-select-sm', 'id' => 'translation_kind']) ?>
                    <?php else: ?>
                        <input type="hidden" name="kind" value="<?= esc($editing->kind->value, 'attr') ?>">
                        <span class="form-control-plaintext"><?= esc(lang($editing->kind->languageKey())) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="translation_key"><?= lang('Integrations.entity_key') ?></label>
                <div class="field-control">
                    <?php if ($editing === null): ?>
                        <input type="text" class="form-control form-control-sm" id="translation_key" name="key" maxlength="190">
                        <p class="form-text mb-0"><?= lang('Integrations.entity_key_help') ?></p>
                    <?php else: ?>
                        <input type="hidden" name="key" value="<?= esc($editing->key, 'attr') ?>">
                        <span class="form-control-plaintext"><bdi><?= esc($editing->entityName !== '' ? $editing->entityName : $editing->key) ?></bdi></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="translation_language"><?= lang('Integrations.language') ?></label>
                <div class="field-control">
                    <?php if ($editing === null && $language === ''): ?>
                        <?= form_dropdown('language', ['' => '-'] + array_combine($locales, $locales), '', ['class' => 'form-select form-select-sm', 'id' => 'translation_language', 'required' => 'required']) ?>
                    <?php else: ?>
                        <input type="text" class="form-control form-control-sm" id="translation_language" name="language" value="<?= esc($editing?->language ?? $language, 'attr') ?>" readonly>
                    <?php endif; ?>
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="translation_name"><?= lang('Integrations.second_name') ?></label>
                <div class="field-control">
                    <input type="text" class="form-control form-control-sm" id="translation_name" name="name" maxlength="255" dir="auto" value="<?= esc($editing?->name ?? '', 'attr') ?>">
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="translation_note"><?= lang('Integrations.address_note') ?></label>
                <div class="field-control">
                    <input type="text" class="form-control form-control-sm" id="translation_note" name="address_note" maxlength="255" dir="auto" value="<?= esc($editing?->addressNote ?? '', 'attr') ?>">
                    <p class="form-text mb-0"><?= lang('Integrations.address_note_help') ?></p>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" id="translation_submit"><?= lang('Common.submit') ?></button>
        <?php if ($editing !== null): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('integrations/translations'), 'attr') ?>"><?= lang('Common.cancel') ?></a>
        <?php endif; ?>
    </div>
<?= form_close() ?>

<form method="get" action="<?= esc(base_url('integrations/translations'), 'attr') ?>" class="row g-2 mb-2" id="translation_filter">
    <div class="col-auto"><?= form_dropdown('kind', $kindChoices, $kind?->value ?? '', ['class' => 'form-select form-select-sm', 'aria-label' => lang('Integrations.kind')]) ?></div>
    <div class="col-auto"><input type="search" class="form-control form-control-sm" name="search" value="<?= esc($search, 'attr') ?>" placeholder="<?= esc(lang('Common.search'), 'attr') ?>"></div>
    <div class="col-auto"><button type="submit" class="btn btn-outline-primary btn-sm"><?= lang('Common.search') ?></button></div>
</form>

<?php if ($rows === []): ?>
    <p class="text-body-secondary" id="translations_empty"><?= lang('Integrations.no_translations') ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm" id="translations_table">
            <thead>
                <tr>
                    <th><?= lang('Integrations.kind') ?></th>
                    <th><?= lang('Integrations.entity_name') ?></th>
                    <th><?= lang('Integrations.language') ?></th>
                    <th><?= lang('Integrations.second_name') ?></th>
                    <th><?= lang('Integrations.address_note') ?></th>
                    <th><span class="visually-hidden"><?= lang('Integrations.actions') ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc(lang($row->kind->languageKey())) ?></td>
                        <td><bdi><?= esc($row->entityName !== '' ? $row->entityName : $row->key) ?></bdi></td>
                        <td><?= esc($row->language) ?></td>
                        <td dir="auto"><?= esc($row->name) ?></td>
                        <td dir="auto"><?= esc($row->addressNote) ?></td>
                        <td class="text-nowrap">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('integrations/translations') . '?edit=' . $row->id, 'attr') ?>" title="<?= esc(lang('Common.edit'), 'attr') ?>"><span class="bi bi-pencil" aria-hidden="true"></span></a>
                            <button type="button" class="btn btn-outline-danger btn-sm integration-post" data-url="<?= esc(base_url('integrations/translations/delete/' . $row->id), 'attr') ?>" title="<?= esc(lang('Common.delete'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <nav aria-label="<?= esc(lang('Integrations.pages'), 'attr') ?>">
            <ul class="pagination pagination-sm">
                <?php for ($number = 1; $number <= $pages; $number++): ?>
                    <li class="page-item<?= $number === $page ? ' active' : '' ?>"><a class="page-link" href="<?= esc(base_url('integrations/translations') . '?' . http_build_query(['kind' => $kind?->value ?? '', 'search' => $search, 'page' => $number]), 'attr') ?>"><?= $number ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?= view('partial/footer') ?>
