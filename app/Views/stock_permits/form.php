<?php
/**
 * @var object|null        $permit    The permit being corrected, or null for a new one
 * @var list<object>       $lines
 * @var StockPermitType    $type
 * @var array<int, string> $locations
 * @var list<object>       $accounts
 * @var string|null        $number
 * @var string             $today
 */
use App\Libraries\Stock\StockPermitType;

$editing  = $permit !== null;
$withCost = $type === StockPermitType::Add;
$action   = $editing ? 'stock_documents/permits/update/' . (int) $permit->permit_id : 'stock_documents/permits/save';
$party    = $type === StockPermitType::Add ? 'supplier' : ($type === StockPermitType::Issue ? 'customer' : null);
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open($action, ['id' => 'permit_form', 'class' => 'app-form']) ?>
    <input type="hidden" name="type" value="<?= esc($type->value, 'attr') ?>">
    <p class="fw-bold" id="permit_form_title"><?= lang($type->labelKey()) ?></p>
    <p class="small text-body-secondary"><?= lang('Stock_documents.form_note_' . $type->value) ?></p>

    <fieldset id="permit_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Stock_documents.number'), 'permit_number', ['class' => 'form-label']) ?>
                <div class="field-control"><span class="form-control-plaintext" id="permit_number"><bdi dir="ltr"><?= esc($number ?? lang('Stock_documents.number_next')) ?></bdi></span></div>
            </div>

            <div class="field">
                <?= form_label(lang('Stock_documents.date'), 'permit_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="permit_date" id="permit_date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($today, 'attr') ?>" value="<?= esc($editing ? (string) $permit->permit_date : $today, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang($type === StockPermitType::Transfer ? 'Stock_documents.from_location' : 'Stock_documents.location'), 'location_id', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <?php if ($editing): ?>
                        <span class="form-control-plaintext" id="location_name"><?= esc((string) $permit->location_name) ?></span>
                    <?php else: ?>
                        <select name="location_id" id="location_id" class="form-select form-select-sm">
                            <?php foreach ($locations as $locationId => $name): ?>
                                <option value="<?= (int) $locationId ?>"><?= esc($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($type === StockPermitType::Transfer): ?>
                <div class="field">
                    <?= form_label(lang('Stock_documents.to_location'), 'to_location_id', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <?php if ($editing): ?>
                            <span class="form-control-plaintext" id="to_location_name"><?= esc((string) $permit->to_location_name) ?></span>
                        <?php else: ?>
                            <select name="to_location_id" id="to_location_id" class="form-select form-select-sm">
                                <?php foreach ($locations as $locationId => $name): ?>
                                    <option value="<?= (int) $locationId ?>"><?= esc($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($party !== null): ?>
                <?php $partyName = $editing ? (string) ($party === 'supplier' ? $permit->supplier_name : $permit->customer_name) : ''; ?>
                <?php $partyId   = $editing ? (int) ($party === 'supplier' ? $permit->supplier_id : $permit->customer_id) : 0; ?>
                <div class="field">
                    <?= form_label(lang('Stock_documents.' . $party), 'party_name', ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <input type="text" id="party_name" class="form-control form-control-sm" autocomplete="off" value="<?= esc($partyName, 'attr') ?>">
                        <input type="hidden" name="<?= $party ?>_id" id="party_id" value="<?= $partyId > 0 ? $partyId : '' ?>">
                    </div>
                </div>

                <div class="field">
                    <?= form_label(lang('Stock_documents.account'), 'account_id', ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <select name="account_id" id="account_id" class="form-select form-select-sm">
                            <option value=""><?= lang($type === StockPermitType::Add ? 'Stock_documents.account_automatic_add' : 'Stock_documents.account_automatic_issue') ?></option>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?= (int) $account->account_id ?>"<?= $editing && (int) $permit->account_id === (int) $account->account_id ? ' selected' : '' ?>><?= esc($account->code . ' ' . $account->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field span-all">
                <?= form_label(lang('Stock_documents.note'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"><?= esc($editing ? (string) $permit->note : '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle" id="permit_lines">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Stock_documents.item') ?></th>
                        <th scope="col" class="text-end"><?= lang('Stock_documents.quantity') ?></th>
                        <?php if ($withCost): ?>
                            <th scope="col" class="text-end"><?= lang('Stock_documents.unit_cost') ?></th>
                        <?php endif; ?>
                        <th scope="col"><span class="visually-hidden"><?= lang('Stock_documents.remove_line') ?></span></th>
                    </tr>
                </thead>
                <tbody id="permit_lines_body"></tbody>
            </table>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="permit_add_line"><span class="bi bi-plus" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.add_line') ?></button>
            <?php if (! $withCost): ?>
                <p class="small text-body-secondary mt-2 mb-0"><?= lang('Stock_documents.cost_is_average') ?></p>
            <?php endif; ?>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        const withCost = <?= $withCost ? 'true' : 'false' ?>;
        const existing = <?= json_encode(array_map(static fn (object $line): array => ['id' => (int) $line->item_id, 'label' => $line->item_name, 'quantity' => rtrim(rtrim(bcadd((string) $line->quantity, '0', 3), '0'), '.'), 'cost' => rtrim(rtrim(bcadd((string) $line->unit_cost, '0', 6), '0'), '.')], $lines), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let next = 0;

        const addLine = function(line) {
            const index = next++;
            const row = $('<tr></tr>');
            const name = $('<input type="text" class="form-control form-control-sm" autocomplete="off">').attr('aria-label', "<?= esc(lang('Stock_documents.item'), 'js') ?>").val(line.label || '');
            const id = $('<input type="hidden">').attr('name', 'lines[' + index + '][item_id]').val(line.id || '');
            const quantity = $('<input type="text" class="form-control form-control-sm ltr-input text-end" dir="ltr" inputmode="decimal" autocomplete="off">').attr({name: 'lines[' + index + '][quantity]', 'aria-label': "<?= esc(lang('Stock_documents.quantity'), 'js') ?>"}).val(line.quantity || '');
            const remove = $('<button type="button" class="btn btn-outline-danger btn-sm"></button>').attr('aria-label', "<?= esc(lang('Stock_documents.remove_line'), 'js') ?>").append('<span class="bi bi-x" aria-hidden="true"></span>');

            row.append($('<td></td>').append(name, id), $('<td></td>').append(quantity));

            if (withCost) {
                row.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ltr-input text-end" dir="ltr" inputmode="decimal" autocomplete="off">').attr({name: 'lines[' + index + '][unit_cost]', 'aria-label': "<?= esc(lang('Stock_documents.unit_cost'), 'js') ?>"}).val(line.cost || '')));
            }

            row.append($('<td></td>').append(remove));
            $('#permit_lines_body').append(row);

            name.autocomplete({
                source: 'stock_documents/items',
                minChars: 0,
                delay: 100,
                appendTo: '.modal-content',
                select: function(event, ui) {
                    id.val(ui.item.value);
                    name.val(ui.item.label);

                    return false;
                }
            }).on('input', function() {
                id.val('');
            });

            remove.on('click', function() {
                row.remove();
            });
        };

        $('#permit_add_line').on('click', function() {
            addLine({});
        });

        if (existing.length > 0) {
            existing.forEach(addLine);
        } else {
            addLine({});
        }

        <?php if ($party !== null): ?>
        $('#party_name').autocomplete({
            source: 'stock_documents/parties?kind=<?= $party ?>',
            minChars: 0,
            delay: 100,
            appendTo: '.modal-content',
            select: function(event, ui) {
                $('#party_id').val(ui.item.value);
                $('#party_name').val(ui.item.label);

                return false;
            }
        }).on('input', function() {
            $('#party_id').val('');
        });
        <?php endif; ?>

        $('#permit_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        toast_support.show(response.message, response.success ? 'success' : 'danger');
                        if (response.success) {
                            window.location.reload();
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                permit_date: 'required'
            },

            messages: {
                permit_date: "<?= esc(lang('Stock_documents.date_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
