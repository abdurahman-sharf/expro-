<?php
/**
 * @var list<object> $books
 * @var bool         $canManage
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('.book-action').on('click', function() {
            if (!confirm($(this).data('confirm'))) {
                return;
            }

            $.post($(this).data('url'), {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm me-auto" href="<?= esc(base_url('accounting_cheques?direction=paid'), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cheques.back') ?></a>
    <?php if ($canManage): ?>
        <button class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('accounting_cheques/book_form'), 'attr') ?>" title="<?= esc(lang('Accounting_cheques.new_book'), 'attr') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cheques.new_book') ?>
        </button>
    <?php endif; ?>
</div>

<div class="table-responsive px-2">
    <table class="table table-sm" id="cheque_books">
        <thead><tr>
            <th><?= lang('Accounting_cheques.book_name') ?></th>
            <th><?= lang('Accounting_cheques.bank') ?></th>
            <th><?= lang('Accounting_cheques.first_serial') ?></th>
            <th><?= lang('Accounting_cheques.last_serial') ?></th>
            <th><?= lang('Accounting_cheques.written') ?></th>
            <th><?= lang('Accounting_cheques.status') ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <?php $bookId = (int) $book->book_id; ?>
            <tr>
                <td><?= esc($book->name) ?></td>
                <td><?= esc($book->treasury_name) ?> (<?= esc($book->branch_code) ?>)</td>
                <td><bdi dir="ltr"><?= esc((string) $book->first_serial) ?></bdi></td>
                <td><bdi dir="ltr"><?= esc((string) $book->last_serial) ?></bdi></td>
                <td><?= (int) $book->written ?></td>
                <td><?= lang((int) $book->active === 1 ? 'Accounting_cheques.book_in_use' : 'Accounting_cheques.book_out_of_use') ?></td>
                <td>
                    <?php if ($canManage): ?>
                        <a class="modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" href="<?= esc(base_url("accounting_cheques/book_form/{$bookId}"), 'attr') ?>" title="<?= esc(lang('Accounting_cheques.edit_book'), 'attr') ?>"><span class="bi bi-pencil-square" aria-hidden="true"></span></a>
                        <button type="button" class="btn btn-link p-0 book-action" data-url="<?= esc(base_url('accounting_cheques/book_active/' . $bookId . '/' . ((int) $book->active === 1 ? 0 : 1)), 'attr') ?>" data-confirm="<?= esc(lang((int) $book->active === 1 ? 'Accounting_cheques.confirm_book_off' : 'Accounting_cheques.confirm_book_on'), 'attr') ?>" title="<?= esc(lang((int) $book->active === 1 ? 'Accounting_cheques.confirm_book_off' : 'Accounting_cheques.confirm_book_on'), 'attr') ?>"><span class="bi <?= (int) $book->active === 1 ? 'bi-slash-circle' : 'bi-check-circle' ?>" aria-hidden="true"></span></button>
                        <?php if ((int) $book->written === 0): ?>
                            <button type="button" class="btn btn-link p-0 text-danger book-action" data-url="<?= esc(base_url("accounting_cheques/delete_book/{$bookId}"), 'attr') ?>" data-confirm="<?= esc(lang('Accounting_cheques.confirm_book_delete'), 'attr') ?>" title="<?= esc(lang('Common.delete'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($books === []): ?>
            <tr><td colspan="7" class="text-center text-body-secondary"><?= lang('Accounting_cheques.books_empty') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?= view('partial/footer') ?>
