<?php
/**
 * @var object|null                                                        $treasury
 * @var list<object>                                                       $branches
 * @var array<string, string>                                              $kinds
 * @var array<string, array{mode: string, members: list<int>}>             $scopes
 * @var list<object>                                                       $staff
 * @var list<object>                                                       $scope_branches
 * @var list<array{code: string, country: string, en: string, ar: string}> $directory
 * @var string                                                             $selected_bank
 */

use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;

$isNew  = $treasury === null;
$isBank = ! $isNew && $treasury->kind === TreasuryService::BANK;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_treasuries/save/' . ($isNew ? NEW_ENTRY : (int) $treasury->treasury_id), ['id' => 'treasury_form', 'class' => 'app-form']) ?>
    <fieldset id="treasury_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_treasuries.name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="231" value="<?= esc($isNew ? '' : $treasury->name, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.kind'), 'kind', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="kind" id="kind" class="form-select form-select-sm"<?= ! $isNew && (int) $treasury->is_main === 1 ? ' disabled' : '' ?>>
                        <?php foreach ($kinds as $value => $text): ?>
                            <option value="<?= esc($value, 'attr') ?>"<?= ! $isNew && $treasury->kind === $value ? ' selected' : '' ?>><?= esc($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (! $isNew && (int) $treasury->is_main === 1): ?>
                        <input type="hidden" name="kind" value="<?= esc($treasury->kind, 'attr') ?>">
                    <?php endif; ?>
                </div>
            </div>

            <div class="field bank-field"<?= $isBank ? '' : ' hidden' ?>>
                <?= form_label(lang('Accounting_treasuries.bank_directory'), 'bank_code', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="search" id="bank_search" class="form-control form-control-sm mb-1" autocomplete="off" placeholder="<?= esc(lang('Accounting_treasuries.bank_search'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_treasuries.bank_search'), 'attr') ?>">
                    <select name="bank_code" id="bank_code" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_treasuries.bank_other') ?></option>
                        <?php foreach ($directory as $bank): ?>
                            <option value="<?= esc($bank['code'], 'attr') ?>" data-country="<?= esc($bank['country'], 'attr') ?>"<?= $selected_bank === $bank['code'] ? ' selected' : '' ?>><?= esc($bank['en'] . ' - ' . $bank['ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field bank-field bank-name-field"<?= $isBank && $selected_bank === '' || ! $isBank ? '' : ' hidden' ?>>
                <?= form_label(lang('Accounting_treasuries.bank_name'), 'bank_name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="bank_name" id="bank_name" class="form-control form-control-sm" maxlength="120" value="<?= esc($isNew ? '' : (string) ($treasury->bank_name ?? ''), 'attr') ?>">
                </div>
            </div>

            <div class="field bank-field"<?= $isBank ? '' : ' hidden' ?>>
                <?= form_label(lang('Accounting_treasuries.account_number'), 'account_number', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="account_number" id="account_number" class="form-control form-control-sm" dir="ltr" maxlength="40" autocomplete="off" value="<?= esc($isNew ? '' : (string) ($treasury->account_number ?? ''), 'attr') ?>">
                </div>
            </div>

            <?php if ($isBank && TreasuryService::lacksBankDetails($treasury)): ?>
                <p class="alert alert-warning small mb-0" id="bank_details_missing"><?= lang('Accounting_treasuries.bank_details_missing_hint') ?></p>
            <?php endif; ?>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.branch'), 'branch_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?php if ($isNew && count($branches) > 1): ?>
                        <select name="branch_id" id="branch_id" class="form-select form-select-sm">
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch->branch_id ?>"><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="form-control-plaintext"><?= esc($isNew ? ($branches[0]->code ?? '') : $treasury->branch_code) ?></span>
                        <?php if ($isNew && $branches !== []): ?>
                            <input type="hidden" name="branch_id" value="<?= (int) $branches[0]->branch_id ?>">
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.description'), 'description', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="description" id="description" class="form-control form-control-sm" rows="2" maxlength="500"><?= esc($isNew ? '' : $treasury->description) ?></textarea>
                </div>
            </div>
        </div>
    </fieldset>

    <fieldset id="treasury_scopes" class="mt-3">
        <legend class="fs-6"><?= lang('Accounting_treasuries.scope_title') ?></legend>
        <p class="small text-body-secondary"><?= lang('Accounting_treasuries.scope_note') ?></p>
        <div class="form-grid">
            <?php foreach ([TreasuryScopes::DEPOSIT => 'scope_deposit', TreasuryScopes::WITHDRAW => 'scope_withdraw'] as $direction => $label): ?>
                <?php
                $scope   = $scopes[$direction] ?? ['mode' => TreasuryScopes::ALL, 'members' => []];
                $members = $scope['members'];
                ?>
                <div class="field scope-block" data-direction="<?= $direction ?>">
                    <?= form_label(lang('Accounting_treasuries.' . $label), $direction . '_scope', ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <select name="<?= $direction ?>_scope" id="<?= $direction ?>_scope" class="form-select form-select-sm scope-mode">
                            <?php foreach (TreasuryScopes::MODES as $mode): ?>
                                <option value="<?= $mode ?>"<?= $scope['mode'] === $mode ? ' selected' : '' ?>><?= lang('Accounting_treasuries.scope_' . $mode) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div class="scope-members mt-1" data-mode="<?= TreasuryScopes::STAFF ?>"<?= $scope['mode'] === TreasuryScopes::STAFF ? '' : ' hidden' ?>>
                            <?php foreach ($staff as $employee): ?>
                                <div class="form-check small">
                                    <input type="checkbox" class="form-check-input" id="<?= $direction ?>_staff_<?= (int) $employee->person_id ?>" name="<?= $direction ?>_members[]" value="<?= (int) $employee->person_id ?>"<?= $scope['mode'] === TreasuryScopes::STAFF && in_array((int) $employee->person_id, $members, true) ? ' checked' : '' ?><?= $scope['mode'] === TreasuryScopes::STAFF ? '' : ' disabled' ?>>
                                    <label class="form-check-label" for="<?= $direction ?>_staff_<?= (int) $employee->person_id ?>"><?= esc($employee->employee_name) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="scope-members mt-1" data-mode="<?= TreasuryScopes::BRANCHES ?>"<?= $scope['mode'] === TreasuryScopes::BRANCHES ? '' : ' hidden' ?>>
                            <?php foreach ($scope_branches as $scopeBranch): ?>
                                <div class="form-check small">
                                    <input type="checkbox" class="form-check-input" id="<?= $direction ?>_branch_<?= (int) $scopeBranch->branch_id ?>" name="<?= $direction ?>_members[]" value="<?= (int) $scopeBranch->branch_id ?>"<?= $scope['mode'] === TreasuryScopes::BRANCHES && in_array((int) $scopeBranch->branch_id, $members, true) ? ' checked' : '' ?><?= $scope['mode'] === TreasuryScopes::BRANCHES ? '' : ' disabled' ?>>
                                    <label class="form-check-label" for="<?= $direction ?>_branch_<?= (int) $scopeBranch->branch_id ?>"><?= esc($scopeBranch->code . ' - ' . $scopeBranch->name) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        const isBank = function() {
            return $('#kind').val() === 'bank';
        };

        const syncBankName = function() {
            $('.bank-name-field').prop('hidden', !isBank() || $('#bank_code').val() !== '');
        };

        $('#kind').on('change', function() {
            $('.bank-field').prop('hidden', !isBank());
            syncBankName();
        });

        $('#bank_code').on('change', syncBankName);

        let searching = null;

        $('#bank_search').on('input', function() {
            const term = $(this).val();

            clearTimeout(searching);

            searching = setTimeout(function() {
                $.getJSON(<?= json_encode(base_url('accounting_treasuries/banks'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {term: term}, function(response) {
                    const select = $('#bank_code');
                    const chosen = select.find('option:selected');
                    const current = chosen.val();
                    const kept = current === '' ? null : chosen.clone();

                    select.find('option[value!=""]').remove();

                    response.rows.forEach(function(bank) {
                        select.append($('<option>').val(bank.code).text(bank.en + ' - ' + bank.ar).prop('selected', bank.code === current));
                    });

                    if (kept !== null && select.find('option').filter(function() { return this.value === current; }).length === 0) {
                        select.append(kept.prop('selected', true));
                    }
                });
            }, 150);
        });

        $('.scope-mode').on('change', function() {
            const mode = $(this).val();

            $(this).closest('.scope-block').find('.scope-members').each(function() {
                const shown = $(this).data('mode') === mode;

                $(this).prop('hidden', !shown).find('input').prop('disabled', !shown);
            });
        });

        $.validator.addMethod('scopeMembers', function(value, element) {
            const block = $(element).closest('.scope-block');

            return $(element).val() === 'all' || block.find('.scope-members:not([hidden]) input:checked').length > 0;
        });

        $('#treasury_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_treasuries', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: 'required',
                bank_name: {required: function() { return isBank() && $('#bank_code').val() === ''; }},
                account_number: {required: isBank},
                deposit_scope: {scopeMembers: true},
                withdraw_scope: {scopeMembers: true}
            },

            messages: {
                name: "<?= esc(lang('Accounting_treasuries.name_required'), 'js') ?>",
                bank_name: "<?= esc(lang('Accounting_treasuries.bank_name_required'), 'js') ?>",
                account_number: "<?= esc(lang('Accounting_treasuries.account_number_required'), 'js') ?>",
                deposit_scope: "<?= esc(lang('Accounting_treasuries.scope_members_required'), 'js') ?>",
                withdraw_scope: "<?= esc(lang('Accounting_treasuries.scope_members_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
