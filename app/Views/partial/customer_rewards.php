<?php
/**
 * @var array $customer_rewards
 */
?>

<?php
$i = 0;

foreach ($customer_rewards as $reward_key => $reward_category) {
    $customer_reward_id = $reward_category['package_id'];
    $customer_reward_name = $reward_category['package_name'];
    $customer_points_percent = $reward_category['points_percent'];
    ++$i;
?>

    <div class="mb-3" style="<?= $reward_category['deleted'] ? 'display: none;' : 'display: block;' ?>">
        <?= form_label(lang('Config.customer_reward') . " $i", "customer_reward_$i", ['class' => 'form-label col-3 col-md-2 text-danger required']) ?>
        <div class="col-4 col-lg-3">
            <?php $form_data = [
                'name'  => 'customer_reward_' . $customer_reward_id,
                'id'    => 'customer_reward_' . $customer_reward_id,
                'class' => 'customer_reward valid_chars form-control form-control-sm required',
                'value' => $customer_reward_name
            ];
            $reward_category['deleted'] && $form_data['disabled'] = 'disabled';
            echo form_input($form_data);
            ?>
        </div>
        <div class="col-3 col-lg-2">
            <?php $form_data = [
                'name'  => 'reward_points_' . $customer_reward_id,
                'id'    => 'reward_points_' . $customer_reward_id,
                'class' => 'customer_reward valid_chars form-control form-control-sm required',
                'value' => $customer_points_percent
            ];
            $reward_category['deleted'] && $form_data['disabled'] = 'disabled';
            echo form_input($form_data);
            ?>
        </div>
        <span class="add_customer_reward bi bi-plus-lg" style="padding-top: 0.5em;"></span>
        <span>&nbsp;&nbsp;</span>
        <span class="remove_customer_reward bi bi-dash-lg" style="padding-top: 0.5em;"></span>
    </div>

<?php } ?>
