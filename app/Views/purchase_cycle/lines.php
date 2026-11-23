<?php
/**
 * The lines of a purchase cycle document: a table the lines are added to and taken from, each with an item found by name,
 * a note, a quantity and the price expected, quoted or ordered for one unit, sent as lines[n][field].
 *
 * @var list<array{item_id: int, label: string, quantity: string, unit_price: string, description: string}> $rows
 * @var string                                                                                              $itemsUrl
 */
?>
<section class="doc-editor-card doc-lines-card" aria-labelledby="document_lines_title">
<h2 class="doc-editor-title" id="document_lines_title"><?= lang('Purchase_cycle.lines') ?> <span class="doc-lines-count" id="document_lines_count" aria-hidden="true"></span></h2>
<div class="table-responsive">
    <table class="table align-middle doc-lines" id="document_lines">
        <thead>
            <tr>
                <th scope="col" class="doc-line-number">#</th>
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
<div class="doc-lines-foot">
    <button type="button" class="btn btn-link doc-add-line" id="add_line"><span class="bi bi-plus-lg" aria-hidden="true"></span> <?= lang('Purchase_cycle.add_line') ?></button>
</div>
</section>

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

        const count = function() {
            $('#document_lines_count').text($('#document_lines tbody tr').length);
        };

        const addLine = function(data) {
            const index = next++;
            const row = $('<tr class="document-line"></tr>');
            const itemName = $('<input type="text" class="form-control form-control-sm line-item" autocomplete="off">').attr('aria-label', labels.item).attr('placeholder', labels.item).val(data.label || '');
            const itemId = $('<input type="hidden">').attr('name', 'lines[' + index + '][item_id]').val(data.item_id || '');
            const field = function(name, value) {
                return $('<input type="text" class="form-control form-control-sm ltr-input text-end" dir="ltr" inputmode="decimal" autocomplete="off">').attr({name: 'lines[' + index + '][' + name + ']', 'aria-label': labels[name], placeholder: labels[name]}).val(value);
            };
            const remove = $('<button type="button" class="btn btn-sm doc-remove-line remove-line"><span class="bi bi-trash3" aria-hidden="true"></span></button>').attr({'aria-label': labels.remove, title: labels.remove});

            row.append($('<td class="doc-line-number"></td>'))
                .append($('<td class="doc-line-wide"></td>').append(itemName, itemId))
                .append($('<td class="doc-line-wide"></td>').append($('<input type="text" class="form-control form-control-sm" maxlength="255">').attr({name: 'lines[' + index + '][description]', 'aria-label': labels.description, placeholder: labels.description}).val(data.description || '')))
                .append($('<td></td>').append(field('quantity', data.quantity || '1')))
                .append($('<td></td>').append(field('unit_price', data.unit_price || '')))
                .append($('<td class="doc-line-remove"></td>').append(remove));

            $('#document_lines tbody').append(row);
            count();

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
            count();
        });
    });
</script>
