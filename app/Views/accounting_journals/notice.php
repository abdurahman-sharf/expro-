<?php
/**
 * @var string|null $notice
 */
?>
<?php if (! empty($notice)): ?>
    <script type="text/javascript">
        $(document).ready(function() {
            $.notify(<?= json_encode($notice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {type: 'success'});
        });
    </script>
<?php endif; ?>
