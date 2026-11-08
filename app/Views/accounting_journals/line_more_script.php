<?php
/**
 * Turns the digits and the decimal separator an Arabic keyboard types in the rate of a tax note into the
 * ones the books read, as the amounts of a journal are turned.
 */
?>
<script type="text/javascript">
    $(document).on('input', '.acc-tax-percent', function() {
        const digits = {'٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9'};
        const normalized = String($(this).val()).replace(/[٠-٩۰-۹]/g, function(character) {
            return digits[character];
        }).replace(/[٫,،]/g, '.').trim();

        if (normalized !== $(this).val()) {
            $(this).val(normalized);
        }
    });
</script>
