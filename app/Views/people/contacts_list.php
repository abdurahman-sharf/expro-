<?php
/**
 * @var list<object> $contacts
 */
$contacts ??= [];
?>
<?php if ($contacts !== []): ?>
    <section class="mb-4 party-contacts" aria-labelledby="party_contacts_title">
        <h2 class="h6" id="party_contacts_title"><?= esc(lang('Customers.contacts')) ?></h2>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th scope="col"><?= esc(lang('Customers.contact_first_name')) ?></th>
                        <th scope="col"><?= esc(lang('Customers.contact_last_name')) ?></th>
                        <th scope="col"><?= esc(lang('Customers.contact_position')) ?></th>
                        <th scope="col"><?= esc(lang('Customers.contact_email')) ?></th>
                        <th scope="col"><?= esc(lang('Customers.contact_phone')) ?></th>
                        <th scope="col"><?= esc(lang('Customers.contact_mobile')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $contact): ?>
                        <tr>
                            <td><?= esc($contact->first_name) ?></td>
                            <td><?= esc($contact->last_name) ?></td>
                            <td><?= esc($contact->position) ?></td>
                            <td><bdi dir="ltr"><?= esc($contact->email) ?></bdi></td>
                            <td><bdi dir="ltr"><?= esc($contact->phone) ?></bdi></td>
                            <td><bdi dir="ltr"><?= esc($contact->mobile) ?></bdi></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
