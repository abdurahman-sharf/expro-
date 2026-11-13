<?php

use App\Libraries\MasterData\PartyContacts;

/**
 * @var list<object> $contacts
 */
$contacts ??= [];

$cells = [
    'first_name' => ['label' => 'Customers.contact_first_name', 'type' => 'text', 'max' => PartyContacts::LIMITS['first_name']],
    'last_name'  => ['label' => 'Customers.contact_last_name', 'type' => 'text', 'max' => PartyContacts::LIMITS['last_name']],
    'email'      => ['label' => 'Customers.contact_email', 'type' => 'email', 'max' => PartyContacts::LIMITS['email']],
    'phone'      => ['label' => 'Customers.contact_phone', 'type' => 'tel', 'max' => PartyContacts::LIMITS['phone']],
    'mobile'     => ['label' => 'Customers.contact_mobile', 'type' => 'tel', 'max' => PartyContacts::LIMITS['mobile']],
    'position'   => ['label' => 'Customers.contact_position', 'type' => 'text', 'max' => PartyContacts::LIMITS['position']],
    'note'       => ['label' => 'Customers.contact_note', 'type' => 'text', 'max' => PartyContacts::LIMITS['note']],
];

$renderRow = static function (string $index, ?object $contact) use ($cells): void {
    ?>
    <tr class="contact-row">
        <?php foreach ($cells as $field => $cell): ?>
            <td>
                <?php if ($field === 'first_name'): ?>
                    <input type="hidden" name="<?= esc(PartyContacts::FIELD . "[{$index}][contact_id]", 'attr') ?>" value="<?= esc((string) ($contact->contact_id ?? ''), 'attr') ?>">
                <?php endif; ?>
                <input type="<?= esc($cell['type'], 'attr') ?>" name="<?= esc(PartyContacts::FIELD . "[{$index}][{$field}]", 'attr') ?>" class="form-control form-control-sm" maxlength="<?= (int) $cell['max'] ?>" value="<?= esc((string) ($contact->{$field} ?? ''), 'attr') ?>" aria-label="<?= esc(lang($cell['label']), 'attr') ?>"<?= $field === 'email' || $field === 'phone' || $field === 'mobile' ? ' dir="ltr"' : '' ?>>
            </td>
        <?php endforeach; ?>
        <td class="text-end">
            <button type="button" class="btn btn-outline-danger btn-sm contact-remove" title="<?= esc(lang('Customers.contacts_remove'), 'attr') ?>" aria-label="<?= esc(lang('Customers.contacts_remove'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button>
        </td>
    </tr>
    <?php
};
?>
<div class="contacts-panel" id="contacts_panel" data-next="<?= count($contacts) ?>">
    <input type="hidden" name="<?= esc(PartyContacts::MARKER, 'attr') ?>" value="1">
    <div class="table-responsive">
        <table class="table table-sm align-middle" id="contacts_table">
            <thead>
                <tr>
                    <?php foreach ($cells as $cell): ?>
                        <th scope="col"><?= esc(lang($cell['label'])) ?></th>
                    <?php endforeach; ?>
                    <th scope="col"><span class="visually-hidden"><?= esc(lang('Customers.contacts_remove')) ?></span></th>
                </tr>
            </thead>
            <tbody id="contacts_rows">
                <?php foreach ($contacts as $position => $contact) {
                    $renderRow((string) $position, $contact);
                } ?>
            </tbody>
        </table>
    </div>
    <p class="text-secondary" id="contacts_empty"<?= $contacts === [] ? '' : ' hidden' ?>><?= esc(lang('Customers.contacts_empty')) ?></p>
    <button type="button" class="btn btn-outline-primary btn-sm" id="contact_add"><span class="bi bi-plus-lg" aria-hidden="true"></span> <?= esc(lang('Customers.contacts_add')) ?></button>

    <template id="contact_template">
        <?php $renderRow('__INDEX__', null); ?>
    </template>
</div>
<script type="text/javascript">
    $(document).ready(function() {
        const panel = document.getElementById('contacts_panel');
        const rows = document.getElementById('contacts_rows');
        const empty = document.getElementById('contacts_empty');
        const refresh = function() {
            empty.hidden = rows.querySelector('.contact-row') !== null;
        };

        document.getElementById('contact_add').addEventListener('click', function() {
            const index = parseInt(panel.dataset.next, 10);
            panel.dataset.next = String(index + 1);
            rows.insertAdjacentHTML('beforeend', document.getElementById('contact_template').innerHTML.replaceAll('__INDEX__', String(index)));
            refresh();
        });

        rows.addEventListener('click', function(event) {
            const button = event.target.closest('.contact-remove');

            if (button !== null) {
                button.closest('.contact-row').remove();
                refresh();
            }
        });
    });
</script>
