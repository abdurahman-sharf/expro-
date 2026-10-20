(function(pdf_export, $) {

    const MAX_ROWS = 5000;
    const parser = new DOMParser();

    const to_text = function(value) {
        if (value === null || value === undefined) {
            return '';
        }
        const html = String(value).replace(/<br\s*\/?>/gi, '\n');
        return parser.parseFromString('<body>' + html + '</body>', 'text/html').body.textContent
            .replace(/[ \t ]+/g, ' ')
            .replace(/ ?\n ?/g, '\n')
            .trim();
    };

    const exportable = function(column) {
        return !column.checkbox && column.field && column.title && !/print_hide/.test(column.class || '');
    };

    const collect = function(table, all) {
        const columns = table.getVisibleColumns().filter(exportable);
        const rows = table.getData({
            useCurrentPage: !all && table.options.sidePagination !== 'server',
            includeHiddenRows: false,
            formatted: true
        });
        const matrix = rows.map(function(row) {
            return columns.map(function(column) {
                return to_text(row[column.field]);
            });
        });
        const kept = columns.map(function(column, index) {
            return matrix.some(function(cells) {
                return cells[index] !== '';
            });
        });

        return {
            title: to_text($('#page_title').text()) || document.title.split('|')[0].trim(),
            subtitle: to_text($('#page_subtitle').text()),
            columns: columns.filter(function(column, index) {
                return kept[index];
            }).map(function(column) {
                return to_text(column.title);
            }),
            rows: matrix.map(function(cells) {
                return cells.filter(function(cell, index) {
                    return kept[index];
                });
            })
        };
    };

    const submit = function(payload) {
        const form = $('<form method="post" target="_blank" class="d-none"></form>').attr('action', 'exports/pdf');
        $.each(csrf_form_base(), function(name, value) {
            form.append($('<input type="hidden">').attr('name', name).val(typeof value === 'function' ? value() : value));
        });
        form.append($('<input type="hidden" name="payload">').val(JSON.stringify(payload)));
        form.appendTo(document.body);
        form[0].submit();
        setTimeout(function() {
            form.remove();
        }, 1000);
    };

    pdf_export.buttons = function(all) {
        return function() {
            return {
                exportPdf: {
                    text: lang.line('table_export_pdf'),
                    icon: 'bi-file-earmark-pdf',
                    event: function() {
                        const payload = collect(this, all);
                        if (payload.rows.length > MAX_ROWS) {
                            $.notify(lang.line('table_export_too_many_rows'), { type: 'danger' });
                            return;
                        }
                        submit(payload);
                    },
                    attributes: {
                        title: lang.line('table_export_pdf')
                    }
                }
            };
        };
    };

})(window.pdf_export = window.pdf_export || {}, jQuery);
