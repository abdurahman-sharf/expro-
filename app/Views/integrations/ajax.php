<script type="text/javascript">
    $(document).ready(function() {
        $('.integration-ajax').on('submit', function(event) {
            event.preventDefault();
            $.ajax({
                type: 'POST',
                url: $(this).attr('action'),
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.reload();
                    }
                }
            });
        });

        $('.integration-post').on('click', function() {
            $.post($(this).data('url'), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });
    });
</script>
