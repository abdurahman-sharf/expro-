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

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('table.acc-lines').forEach(labelCells);
    });

})();
