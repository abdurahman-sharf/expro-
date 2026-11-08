<?php
/**
 * The lines of a purchase cycle document: a table the lines are added to and taken from, each with an item found by name,
 * a note, a quantity and the price expected, quoted or ordered for one unit, sent as lines[n][field].
 *
 * @var list<array{item_id: int, label: string, quantity: string, unit_price: string, description: string}> $rows
 * @var string                                                                                              $itemsUrl
 */
?>
<h2 class="h6 mt-3"><?= lang('Purchase_cycle.lines') ?></h2>
<div class="table-responsive">
    <table class="table table-sm align-middle" id="document_lines">
        <thead>
            <tr>
                <th scope="col"><?= lang('Purchase_cycle.item') ?></th>
                <th scope="col"><?= lang('Purchase_cycle.line_note') ?></th>
                <th scope="col" class="text-end"><?= lang('Purchase_cycle.quantity') ?></th>
                <th scope="col" class="text-end"><?= lang('Purchase_cycle.unit_price') ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
<button type="button" class="btn btn-outline-secondary btn-sm" id="add_line"><?= lang('Purchase_cycle.add_line') ?></button>

<script type="text/javascript">
    $(document).ready(function() {
        const initial = <?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        const labels = {
            remove: "<?= esc(lang('Purchase_cycle.remove_line'), 'js') ?>",
            item: "<?= esc(lang('Purchase_cycle.item'), 'js') ?>",
            description: "<?= esc(lang('Purchase_cycle.line_note'), 'js') ?>",
            quantity: "<?= esc(lang('Purchase_cycle.quantity'), 'js') ?>",
            unit_price: "<?= esc(lang('Purchase_cycle.unit_price'), 'js') ?>"
        };
        let next = 0;

        const addLine = function(data) {
            const index = next++;
            const row = $('<tr class="document-line"></tr>');
            const itemName = $('<input type="text" class="form-control form-control-sm line-item" autocomplete="off">').attr('aria-label', labels.item).val(data.label || '');
            const itemId = $('<input type="hidden">').attr('name', 'lines[' + index + '][item_id]').val(data.item_id || '');
            const field = function(name, value) {
                return $('<input type="text" class="form-control form-control-sm ltr-input text-end" dir="ltr" inputmode="decimal" autocomplete="off">').attr({name: 'lines[' + index + '][' + name + ']', 'aria-label': labels[name]}).val(value);
            };
            const remove = $('<button type="button" class="btn btn-outline-danger btn-sm remove-line"></button>').text(labels.remove);

            row.append($('<td></td>').append(itemName, itemId))
                .append($('<td></td>').append($('<input type="text" class="form-control form-control-sm" maxlength="255">').attr({name: 'lines[' + index + '][description]', 'aria-label': labels.description}).val(data.description || '')))
                .append($('<td></td>').append(field('quantity', data.quantity || '1')))
                .append($('<td></td>').append(field('unit_price', data.unit_price || '')))
                .append($('<td></td>').append(remove));

            $('#document_lines tbody').append(row);

            itemName.autocomplete({
                source: "<?= esc($itemsUrl, 'js') ?>",
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
