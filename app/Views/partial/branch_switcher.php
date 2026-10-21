<?php
/**
 * @var object $user_info
 */

use App\Libraries\Organization\BranchContext;
use App\Libraries\Organization\BranchGuard;

$personId = (int) ($user_info->person_id ?? 0);
$guard    = new BranchGuard();
$branches = $personId > 0 ? $guard->selectableBranches($personId) : [];

if (count($branches) < 2) {
    return;
}

$current = (new BranchContext($guard))->current($personId);
?>

<div class="app-branch">
    <label class="visually-hidden" for="branch_switcher"><?= lang('Branches.switcher_label') ?></label>
    <i class="bi bi-building" aria-hidden="true"></i>
    <select id="branch_switcher" class="form-select form-select-sm" data-url="<?= esc(site_url('home/switchBranch'), 'attr') ?>">
        <option value=""><?= lang('Branches.all_my_branches') ?></option>
        <?php foreach ($branches as $branch): ?>
            <option value="<?= (int) $branch->branch_id ?>"<?= $current === (int) $branch->branch_id ? ' selected' : '' ?>><?= esc($branch->code . ' - ' . $branch->name) ?></option>
        <?php endforeach; ?>
    </select>
</div>
