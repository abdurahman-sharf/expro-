<?php

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;

/**
 * @var PurchaseDocumentKind $kind
 * @var object|null          $document
 * @var list<object>         $lines
 * @var array<int, string>   $locations
 * @var string|null          $number
 * @var string               $today
 */
$isNew      = $document === null;
$module     = $kind->module();
$rows       = $isNew ? [] : array_map(static fn (object $line): array => ['item_id' => (int) $line->item_id, 'label' => (string) $line->item_name, 'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'), 'unit_price' => (string) $line->unit_price, 'description' => (string) $line->description], $lines);
$locationId = $isNew ? (int) array_key_first($locations) : (int) $document->location_id;
$action     = $isNew ? $module . '/save' : $module . '/save/' . (int) $document->document_id;
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang($isNew ? 'Purchase_cycle.new_' . $kind->value : 'Purchase_cycle.edit_' . $kind->value) ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($module), 'attr') ?>"><?= lang('Purchase_cycle.back') ?></a>
</div>

<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open($action, ['id' => 'document_form', 'class' => 'app-form']) ?>
    <p class="small text-body-secondary"><?= lang('Purchase_cycle.form_note') ?></p>

    <div class="form-grid">
        <div class="field">
            <label class="form-label" for="document_number"><?= lang('Purchase_cycle.number') ?></label>
            <div class="field-control"><span class="form-control-plaintext" id="document_number"><bdi dir="ltr"><?= esc($number ?? lang('Purchase_cycle.number_next')) ?></bdi></span></div>
        </div>

        <?php if ($kind->hasSupplier()): ?>
            <div class="field">
                <label class="form-label<?= $kind->needsSupplier() ? ' text-danger required' : '' ?>" for="supplier_name"><?= lang('Purchase_cycle.supplier') ?></label>
                <div class="field-control">
                    <input type="text" id="supplier_name" class="form-control form-control-sm" autocomplete="off" value="<?= esc($isNew ? '' : (string) ($document->supplier_name ?? ''), 'attr') ?>">
                    <input type="hidden" name="supplier_id" id="supplier_id" value="<?= $isNew ? '' : (int) ($document->supplier_id ?? 0) ?>">
                </div>
            </div>
        <?php endif; ?>

        <div class="field">
            <label class="form-label text-danger required" for="document_date"><?= lang('Purchase_cycle.date') ?></label>
            <div class="field-control">
                <input type="date" name="document_date" id="document_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($isNew ? $today : (string) $document->document_date, 'attr') ?>">
            </div>
        </div>

        <div class="field">
            <label class="form-label" for="due_date"><?= lang($kind->dueLabelKey()) ?></label>
            <div class="field-control">
                <input type="date" name="due_date" id="due_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($isNew ? '' : (string) ($document->due_date ?? ''), 'attr') ?>">
            </div>
        </div>

        <div class="field">
            <label class="form-label text-danger required" for="location_id"><?= lang('Purchase_cycle.location') ?></label>
            <div class="field-control">
                <select name="location_id" id="location_id" class="form-select form-select-sm">
                    <?php foreach ($locations as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= (int) $id === $locationId ? ' selected' : '' ?>><?= esc($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field span-all">
            <label class="form-label" for="note"><?= lang('Purchase_cycle.note') ?></label>
            <div class="field-control">
                <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"><?= esc($isNew ? '' : (string) $document->note) ?></textarea>
            </div>
        </div>
    </div>

    <?= view('purchase_cycle/lines', ['rows' => $rows, 'itemsUrl' => $module . '/items']) ?>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-sm" id="submit_document"><?= lang('Common.submit') ?></button>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?php if ($kind->hasSupplier()): ?>
        $('#supplier_name').autocomplete({
            source: '<?= esc($module, 'js') ?>/suppliers',
            minChars: 0,
            delay: 100,
            select: function(event, ui) {
                $('#supplier_id').val(ui.item.value);
                $('#supplier_name').val(ui.item.label);

                return false;
            },
            focus: function(event, ui) {
                $('#supplier_name').val(ui.item.label);

                return false;
            }
        }).on('input', function() {
            $('#supplier_id').val('');
        });
        <?php endif; ?>

        $('#document_form').on('submit', function(event) {
            event.preventDefault();
            $('#submit_document').prop('disabled', true);
            $(this).ajaxSubmit({
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.href = response.redirect;
                    } else {
                        $('#submit_document').prop('disabled', false);
                    }
                },
                error: function() {
                    toast_support.show("<?= esc(lang('Common.request_failed'), 'js') ?>", 'danger');
                    $('#submit_document').prop('disabled', false);
                }
            });
        });
    });
</script>

<?= view('partial/footer') ?>
