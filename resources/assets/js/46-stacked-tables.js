(function() {

    const cleanText = function(node) {
        return node.textContent.replace(/\s+/g, ' ').trim();
    };

    const spanOf = function(cell) {
        return Math.max(1, parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
    };

    const labelCells = function(table) {
        const heading = table.querySelector('thead tr:last-child');

        if (!heading) {
            return;
        }

        const labels = [];

        heading.querySelectorAll('th').forEach(function(cell) {
            for (let index = 0; index < spanOf(cell); index++) {
                labels.push(cleanText(cell));
            }
        });

        table.querySelectorAll('tbody tr, tfoot tr').forEach(function(row) {
            let position = 0;

            row.querySelectorAll('td, th').forEach(function(cell) {
                if (cell.tagName === 'TD' && spanOf(cell) === 1 && !cell.hasAttribute('data-label') && labels[position]) {
                    cell.setAttribute('data-label', labels[position]);
                }

                position += spanOf(cell);
            });
        });
    };

    const SCROLLERS = '.table-responsive, .fixed-table-body';

    const focusScrollers = function() {
        document.querySelectorAll(SCROLLERS).forEach(function(region) {
            const scrolls = region.scrollWidth > region.clientWidth || region.scrollHeight > region.clientHeight;

            if (scrolls && !region.hasAttribute('tabindex')) {
                region.setAttribute('tabindex', '0');
                region.setAttribute('data-scroll-focus', '1');
            } else if (!scrolls && region.hasAttribute('data-scroll-focus')) {
                region.removeAttribute('tabindex');
                region.removeAttribute('data-scroll-focus');
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('table.acc-lines').forEach(labelCells);
        focusScrollers();

        let pending = null;
        const later = function() {
            clearTimeout(pending);
            pending = setTimeout(focusScrollers, 150);
        };

        window.addEventListener('resize', later);
        new MutationObserver(later).observe(document.body, {childList: true, subtree: true});
    });

})();
