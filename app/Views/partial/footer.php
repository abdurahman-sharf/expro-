
            </main>

            <footer id="footer" class="app-footer">
                <span><?= lang('Common.copyrights', [date('Y')]) ?> <?= BRAND_NAME ?>. <?= lang('Common.all_rights_reserved') ?></span>
                <span class="app-footer-sep" aria-hidden="true">|</span>
                <span><?= lang('Common.version') ?> <bdi dir="ltr"><?= esc(config('App')->application_version) ?></bdi></span>
            </footer>
        </div>
    </div>

    <div class="modal fade" id="global_search" data-index-url="<?= site_url('home/searchIndex') ?>" tabindex="-1" aria-labelledby="global_search_title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down search-dialog">
            <div class="modal-content">
                <div class="search-head">
                    <h2 class="search-title" id="global_search_title"><?= lang('Common.global_search') ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= lang('Common.close') ?>"></button>
                </div>
                <div class="search-box">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="global_search_input" class="search-input" role="combobox" aria-expanded="true" aria-controls="global_search_list" aria-autocomplete="list" autocomplete="off" placeholder="<?= lang('Common.global_search_placeholder') ?>" aria-label="<?= lang('Common.global_search_placeholder') ?>">
                    <kbd class="app-search-kbd" dir="ltr">Esc</kbd>
                </div>
                <p class="search-intro" id="global_search_intro"><?= lang('Common.global_search_intro') ?></p>
                <ul id="global_search_list" class="search-results" role="listbox" aria-label="<?= lang('Common.global_search_results') ?>"
                    data-group-module="<?= esc(lang('Common.global_search_group_module'), 'attr') ?>"
                    data-group-report="<?= esc(lang('Common.global_search_group_report'), 'attr') ?>"
                    data-group-setting="<?= esc(lang('Common.global_search_group_setting'), 'attr') ?>"></ul>
                <div id="global_search_status" class="search-status" role="status" aria-live="polite" hidden
                    data-empty="<?= esc(lang('Common.global_search_no_results'), 'attr') ?>">
                    <span class="search-status-icon" aria-hidden="true"><i class="bi bi-search"></i></span>
                    <p class="search-status-title"></p>
                    <p class="search-status-hint"><?= lang('Common.global_search_no_results_hint') ?></p>
                </div>
                <p class="search-hints" aria-hidden="true">
                    <span><kbd class="app-search-kbd">↑</kbd> <kbd class="app-search-kbd">↓</kbd> <?= lang('Common.global_search_hint_navigate') ?></span>
                    <span><kbd class="app-search-kbd" dir="ltr">Enter</kbd> <?= lang('Common.global_search_hint_open') ?></span>
                    <span><kbd class="app-search-kbd" dir="ltr">Esc</kbd> <?= lang('Common.global_search_hint_close') ?></span>
                </p>
            </div>
        </div>
    </div>
</body>

</html>
