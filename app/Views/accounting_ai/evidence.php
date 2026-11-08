<?php
/**
 * @var list<array<string, scalar|null>> $rows
 * @var bool                             $canViewJournals
 */
?>
<?php if ($rows === []): ?>
    <p class="text-body-secondary evidence-empty"><?= lang('Accounting_ai.no_evidence') ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle evidence-table">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_ai.evidence_row') ?></th>
                    <th scope="col"><?= lang('Accounting_ai.evidence_details') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $type    = (string) ($row['type'] ?? '');
                    $typeKey = 'Accounting_ai.evidence_type_' . $type;
                    $typeTxt = lang($typeKey);
                    ?>
                    <tr class="evidence-row">
                        <td><span class="badge text-bg-secondary"><?= esc($typeTxt === $typeKey ? $type : $typeTxt) ?></span></td>
                        <td>
                            <?php foreach ($row as $key => $value): ?>
                                <?php
                                if ($key === 'type' || $value === null || $value === '') {
                                    continue;
                                }

                                $labelKey = 'Accounting_ai.evidence_' . $key;
                                $label    = lang($labelKey);
                                ?>
                                <span class="me-3 d-inline-block">
                                    <span class="text-body-secondary"><?= esc($label === $labelKey ? (string) $key : $label) ?></span>
                                    <?php if ($key === 'journal_id' && $canViewJournals): ?>
                                        <a href="<?= esc(base_url('accounting_journals/view/' . (int) $value), 'attr') ?>"><bdi dir="ltr">#<?= (int) $value ?></bdi></a>
                                    <?php else: ?>
                                        <bdi dir="ltr"><?= esc((string) $value) ?></bdi>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
