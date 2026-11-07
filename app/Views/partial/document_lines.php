<?php
/**
 * The lines of a document written before it is an invoice: a table the lines are added to and taken from,
 * each with an item found by name, a quantity, a price and a discount, sent as lines[n][field].
 *
 * @var list<array{item_id: int, label: string, quantity: string, unit_price: string, discount: string, discount_type: int, description: string}> $rows
 * @var string                                                                                                                                    $items_url
 */
?>
<h2 class="h6 mt-3"><?= lang('Sales_orders.lines') ?></h2>
<div class="table-responsive">
    <table class="table table-sm align-middle" id="document_lines">
        <thead>
            <tr>
                <th scope="col"><?= lang('Sales_orders.item') ?></th>
                <th scope="col"><?= lang('Sales_orders.description') ?></th>
                <th scope="col" class="text-end"><?= lang('Sales_orders.quantity') ?></th>
                <th scope="col" class="text-end"><?= lang('Sales_orders.unit_price') ?></th>
                <th scope="col" class="text-end"><?= lang('Sales_orders.discount') ?></th>
                <th scope="col"><?= lang('Sales_orders.discount_type') ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
<button type="button" class="btn btn-outline-secondary btn-sm" id="add_line"><?= lang('Sales_orders.add_line') ?></button>

<script type="text/javascript">
    $(document).ready(function() {
        const initial = <?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        const labels = {percent: "<?= esc(lang('Sales_orders.discount_percent'), 'js') ?>", fixed: "<?= esc(lang('Sales_orders.discount_fixed'), 'js') ?>", remove: "<?= esc(lang('Sales_orders.remove_line'), 'js') ?>"};
        let next = 0;

        const addLine = function(data) {
            const index = next++;
            const row = $('<tr class="document-line"></tr>');
            const itemName = $('<input type="text" class="form-control form-control-sm line-item" autocomplete="off">').val(data.label || '');
            const itemId = $('<input type="hidden">').attr('name', 'lines[' + index + '][item_id]').val(data.item_id || '');
            const field = function(name, value) {
                return $('<input type="text" class="form-control form-control-sm ltr-input text-end" dir="ltr" inputmode="decimal" autocomplete="off">').attr('name', 'lines[' + index + '][' + name + ']').val(value);
            };
            const type = $('<select class="form-select form-select-sm"></select>').attr('name', 'lines[' + index + '][discount_type]')
                .append($('<option value="0"></option>').text(labels.percent))
                .append($('<option value="1"></option>').text(labels.fixed))
                .val(String(data.discount_type || 0));
            const remove = $('<button type="button" class="btn btn-outline-danger btn-sm remove-line"></button>').text(labels.remove);

            row.append($('<td></td>').append(itemName, itemId))
                .append($('<td></td>').append($('<input type="text" class="form-control form-control-sm" maxlength="255">').attr('name', 'lines[' + index + '][description]').val(data.description || '')))
                .append($('<td></td>').append(field('quantity', data.quantity || '1')))
                .append($('<td></td>').append(field('unit_price', data.unit_price || '')))
                .append($('<td></td>').append(field('discount', data.discount || '0')))
                .append($('<td></td>').append(type))
                .append($('<td></td>').append(remove));

            $('#document_lines tbody').append(row);

            itemName.autocomplete({
                source: "<?= esc($items_url, 'js') ?>",
                minChars: 0,
                delay: 100,
                select: function(event, ui) {
                    itemId.val(ui.item.value);
                    itemName.val(ui.item.label);
                    if (row.find('[name$="[unit_price]"]').val() === '') {
                        row.find('[name$="[unit_price]"]').val(ui.item.price);
                    }

                    return false;
                },
                focus: function(event, ui) {
                    itemName.val(ui.item.label);

                    return false;
                }
            }).on('input', function() {
                itemId.val('');
            });
        };

        initial.length ? initial.forEach(addLine) : addLine({});

        $('#add_line').on('click', function() {
            addLine({});
        });

        $(document).on('click', '.remove-line', function() {
            $(this).closest('tr').remove();
        });
    });
</script>
