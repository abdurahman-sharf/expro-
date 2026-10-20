<?php

use Config\OSPOS;

?>

            </main>

            <footer id="footer" class="app-footer">
                <?= lang('Common.copyrights', [date('Y')]) ?> ·
                <a href="https://opensourcepos.org" target="_blank" rel="noopener"><?= lang('Common.website') ?></a> ·
                <?= esc(config('App')->application_version) ?> -
                <a target="_blank" rel="noopener" href="https://github.com/opensourcepos/opensourcepos/commit/<?= esc(config(OSPOS::class)->commit_sha1) ?>">
                    <?= esc(substr(config(OSPOS::class)->commit_sha1, 0, 6)); ?>
                </a>
            </footer>
        </div>
    </div>

    <div class="modal fade" id="global_search" data-index-url="<?= site_url('home/searchIndex') ?>" tabindex="-1" aria-label="<?= lang('Common.global_search') ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable search-dialog">
            <div class="modal-content">
                <div class="search-box">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="global_search_input" class="search-input" role="combobox" aria-expanded="true" aria-controls="global_search_list" aria-autocomplete="list" autocomplete="off" placeholder="<?= lang('Common.global_search_placeholder') ?>" aria-label="<?= lang('Common.global_search_placeholder') ?>">
                    <kbd class="app-search-kbd" dir="ltr">Esc</kbd>
                </div>
                <ul id="global_search_list" class="search-results" role="listbox" aria-label="<?= lang('Common.global_search_results') ?>"></ul>
                <p id="global_search_status" class="search-status" role="status" aria-live="polite" hidden></p>
            </div>
        </div>
    </div>
</body>

</html>
