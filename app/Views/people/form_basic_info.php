<?php
/**
 * @var object $person_info
 * @var array  $config
 */
?>

<div class="form-grid">
    <div class="field">
        <?= form_label(lang('Common.first_name'), 'first_name', ['class' => 'form-label text-danger required']) ?>
        <div class="field-control">
            <?= form_input([
                'name'  => 'first_name',
                'id'    => 'first_name',
                'class' => 'form-control form-control-sm',
                'value' => $person_info->first_name,
            ]) ?>
        </div>
    </div>

    <div class="field">
        <?= form_label(lang('Common.last_name'), 'last_name', ['class' => 'form-label text-danger required']) ?>
        <div class="field-control">
            <?= form_input([
                'name'  => 'last_name',
                'id'    => 'last_name',
                'class' => 'form-control form-control-sm',
                'value' => $person_info->last_name,
            ]) ?>
        </div>
    </div>

    <div class="field span-all">
        <span id="gender_label" class="form-label<?= ! empty($basic_version) ? ' text-danger required' : '' ?>"><?= lang('Common.gender') ?></span>
        <div class="field-control" role="radiogroup" aria-labelledby="gender_label">
            <label class="choice">
                <?= form_radio([
                    'name'    => 'gender',
                    'type'    => 'radio',
                    'id'      => 'gender_male',
                    'value'   => 1,
                    'checked' => $person_info->gender === '1',
                ]) ?> <?= lang('Common.gender_male') ?>
            </label>
            <label class="choice">
                <?= form_radio([
                    'name'    => 'gender',
                    'type'    => 'radio',
                    'id'      => 'gender_female',
                    'value'   => 0,
                    'checked' => $person_info->gender === '0',
                ]) ?> <?= lang('Common.gender_female') ?>
            </label>
        </div>
    </div>
</div>

<div class="form-section">
    <h3 class="form-section-title"><?= lang('Common.section_contact') ?></h3>
    <div class="form-grid">
        <div class="field">
            <?= form_label(lang('Common.email'), 'email', ['class' => 'form-label']) ?>
            <div class="input-group">
                <span class="input-group-text"><span class="bi bi-envelope" aria-hidden="true"></span></span>
                <?= form_input([
                    'name'  => 'email',
                    'id'    => 'email',
                    'class' => 'form-control form-control-sm',
                    'value' => $person_info->email,
                ]) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Common.phone_number'), 'phone_number', ['class' => 'form-label']) ?>
            <div class="input-group">
                <span class="input-group-text"><span class="bi bi-telephone" aria-hidden="true"></span></span>
                <?= form_input([
                    'name'  => 'phone_number',
                    'id'    => 'phone_number',
                    'class' => 'form-control form-control-sm',
                    'value' => $person_info->phone_number,
                ]) ?>
            </div>
        </div>
    </div>
</div>

<div class="form-section">
    <h3 class="form-section-title"><?= lang('Common.section_address') ?></h3>
    <div class="form-grid">
        <div class="field">
            <?= form_label(lang('Common.address_1'), 'address_1', ['class' => 'form-label']) ?>
            <?= form_input([
                'name'  => 'address_1',
                'id'    => 'address_1',
                'class' => 'form-control form-control-sm',
                'value' => $person_info->address_1,
            ]) ?>
        </div>

        <div class="field">
            <?= form_label(lang('Common.address_2'), 'address_2', ['class' => 'form-label']) ?>
            <?= form_input([
                'name'  => 'address_2',
                'id'    => 'address_2',
                'class' => 'form-control form-control-sm',
                'value' => $person_info->address_2,
            ]) ?>
        </div>

        <div class="field">
            <?= form_label(lang('Common.zip'), 'postcode', ['class' => 'form-label']) ?>
            <?= form_input([
                'name'  => 'zip',
                'id'    => 'postcode',
                'class' => 'form-control form-control-sm',
                'value' => $person_info->zip,
            ]) ?>
        </div>

        <div class="field">
            <?= form_label(lang('Common.city'), 'city', ['class' => 'form-label']) ?>
            <?= form_input([
                'name'  => 'city',
                'id'    => 'city',
                'class' => 'form-control form-control-sm',
                'value' => $person_info->city,
            ]) ?>
        </div>

        <div class="field">
            <?= form_label(lang('Common.state'), 'state', ['class' => 'form-label']) ?>
            <?= form_input([
                'name'  => 'state',
                'id'    => 'state',
                'class' => 'form-control form-control-sm',
                'value' => $person_info->state,
            ]) ?>
        </div>

        <div class="field">
            <?= form_label(lang('Common.country'), 'country', ['class' => 'form-label']) ?>
            <?= form_input([
                'name'  => 'country',
                'id'    => 'country',
                'class' => 'form-control form-control-sm',
                'value' => $person_info->country,
            ]) ?>
        </div>
    </div>
</div>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        nominatim.init({
            fields: {
                postcode: {
                    dependencies: ["postcode", "city", "state", "country"],
                    response: {
                        field: 'postalcode',
                        format: ["postcode", "village|town|hamlet|city_district|city", "state", "country"]
                    }
                },

                city: {
                    dependencies: ["postcode", "city", "state", "country"],
                    response: {
                        format: ["postcode", "village|town|hamlet|city_district|city", "state", "country"]
                    }
                },

                state: {
                    dependencies: ["state", "country"]
                },

                country: {
                    dependencies: ["state", "country"]
                }
            },
            language: '<?= current_language_code() ?>',
            country_codes: '<?= esc($config['country_codes'], 'js') ?>'
        });
    });
</script>
