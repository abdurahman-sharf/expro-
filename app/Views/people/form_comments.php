<?php
/**
 * @var object $person_info
 */
?>

<div class="field">
    <?= form_label(lang('Common.comments'), 'comments', ['class' => 'form-label']) ?>
    <?= form_textarea([
        'name'  => 'comments',
        'id'    => 'comments',
        'class' => 'form-control form-control-sm',
        'value' => $person_info->comments
    ]) ?>
</div>
