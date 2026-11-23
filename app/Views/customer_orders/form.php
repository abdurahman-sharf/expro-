<?php
/**
 * @var object|null        $order
 * @var list<object>       $lines
 * @var array<int, string> $locations
 * @var string|null        $number
 * @var string             $today
 */
$isNew      = $order === null;
$rows       = $isNew ? [] : array_map(static fn (object $line): array => ['item_id' => (int) $line->item_id, 'label' => (string) $line->item_name, 'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'), 'unit_price' => (string) $line->unit_price, 'discount' => (string) $line->discount, 'discount_type' => (int) $line->discount_type, 'description' => (string) $line->description], $lines);
$locationId = $isNew ? (int) array_key_first($locations) : (int) ($lines[0]->item_location ?? array_key_first($locations));
$action     = $isNew ? 'customer_orders/save' : 'customer_orders/save/' . (int) $order->order_id;
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang($isNew ? 'Sales_orders.new' : 'Sales_orders.edit') ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('customer_orders'), 'attr') ?>"><?= lang('Sales_orders.back') ?></a>
</div>

<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open($action, ['id' => 'sales_order_form', 'class' => 'app-form']) ?>
    <p class="doc-editor-note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= lang('Sales_orders.form_note') ?></p>

    <section class="doc-editor-card">
    <div class="form-grid">
        <div class="field">
            <label class="form-label" for="order_number"><?= lang('Sales_orders.number') ?></label>
            <div class="field-control"><span class="form-control-plaintext" id="order_number"><bdi dir="ltr"><?= esc($number ?? lang('Sales_orders.number_next')) ?></bdi></span></div>
        </div>

        <div class="field">
            <label class="form-label text-danger required" for="customer_name"><?= lang('Sales_orders.customer') ?></label>
            <div class="field-control">
                <input type="text" id="customer_name" class="form-control form-control-sm" autocomplete="off" value="<?= esc($isNew ? '' : (string) $order->customer_name, 'attr') ?>">
                <input type="hidden" name="customer_id" id="customer_id" value="<?= $isNew ? '' : (int) $order->customer_id ?>">
            </div>
        </div>

        <div class="field">
            <label class="form-label text-danger required" for="order_date"><?= lang('Sales_orders.date') ?></label>
            <div class="field-control">
                <input type="date" name="order_date" id="order_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($isNew ? $today : (string) $order->order_date, 'attr') ?>">
            </div>
        </div>

        <div class="field">
            <label class="form-label text-danger required" for="location_id"><?= lang('Sales_orders.location') ?></label>
            <div class="field-control">
                <select name="location_id" id="location_id" class="form-select form-select-sm">
                    <?php foreach ($locations as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= (int) $id === $locationId ? ' selected' : '' ?>><?= esc($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field span-all">
            <label class="form-label" for="note"><?= lang('Sales_orders.note') ?></label>
            <div class="field-control">
                <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"><?= esc($isNew ? '' : (string) $order->note) ?></textarea>
            </div>
        </div>
    </div>
    </section>

    <?= view('partial/document_lines', ['rows' => $rows, 'itemsUrl' => 'customer_orders/items']) ?>

    <div class="doc-editor-actions">
        <a class="btn btn-outline-secondary" href="<?= esc(base_url('customer_orders'), 'attr') ?>"><?= lang('Sales_orders.back') ?></a>
        <button type="submit" class="btn btn-primary" id="submit_order"><span class="bi bi-check2" aria-hidden="true"></span> <?= lang('Common.submit') ?></button>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#customer_name').autocomplete({
            source: 'customer_orders/customers',
            minChars: 0,
            delay: 100,
            select: function(event, ui) {
                $('#customer_id').val(ui.item.value);
                $('#customer_name').val(ui.item.label);

                return false;
            },
            focus: function(event, ui) {
                $('#customer_name').val(ui.item.label);

                return false;
            }
        }).on('input', function() {
            $('#customer_id').val('');
        });

        $('#sales_order_form').on('submit', function(event) {
            event.preventDefault();
            $('#submit_order').prop('disabled', true);
            $(this).ajaxSubmit({
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.href = response.redirect;
                    } else {
                        $('#submit_order').prop('disabled', false);
                    }
                },
                error: function() {
                    toast_support.show("<?= esc(lang('Common.request_failed'), 'js') ?>", 'danger');
                    $('#submit_order').prop('disabled', false);
                }
            });
        });
    });
</script>

<?= view('partial/footer') ?>
