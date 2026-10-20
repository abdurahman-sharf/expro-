<?php
/**
 * @var array $allowed_modules
 */
?>

<?= view('partial/header') ?>

<h2 class="h5 mb-4"><?= lang('Common.welcome_message') ?></h2>

<?php foreach (navigation_groups($allowed_modules, 'home') as $groupIndex => $group): ?>
    <?php $items = array_filter($group['items'], static fn(array $item): bool => $item['id'] !== 'home'); ?>
    <?php if ($items !== []): ?>
        <section class="mb-4" aria-labelledby="home-group-<?= $groupIndex ?>">
            <h3 class="h6 text-secondary mb-3" id="home-group-<?= $groupIndex ?>"><?= esc($group['label']) ?></h3>
            <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-3 row-cols-xxl-4">
                <?php foreach ($items as $item): ?>
                    <div class="col">
                        <a class="home-tile" href="<?= $item['url'] ?>">
                            <span class="home-tile-icon"><i class="bi <?= $item['icon'] ?>" aria-hidden="true"></i></span>
                            <span class="home-tile-text">
                                <span class="home-tile-title"><?= esc($item['label']) ?></span>
                                <span class="home-tile-desc"><?= esc(lang('Module.' . $item['id'] . '_desc')) ?></span>
                            </span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endforeach; ?>

<?= view('partial/footer') ?>
