<?php // used in reports?>
// Utility functions for safe localStorage access
function safeSetItem(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (e) {
        console.error(`Failed to set item in localStorage: ${e.message}`);
    }
}

function safeGetItem(key) {
    try {
        return localStorage.getItem(key);
    } catch (e) {
        console.error(`Failed to get item from localStorage: ${e.message}`);
        return null; // Default fallback
    }
}

function safeRemoveItem(key) {
    try {
        localStorage.removeItem(key);
    } catch (e) {
        console.error(`Failed to remove item from localStorage: ${e.message}`);
    }
}

// Load saved column visibility from localStorage
const savedVisibility = JSON.parse(safeGetItem('columnVisibility')) || { cost: false, profit: false };
let visibleColumns = savedVisibility;

// Function to save column visibility to localStorage
function saveColumnVisibility(visibility) {
    safeSetItem('columnVisibility', JSON.stringify(visibility));
}

// Apply column visibility on table initialization
function applyColumnVisibility(columns) {
    return columns.map(function (col) {
        if (visibleColumns[col.field] !== undefined) {
            col.visible = visibleColumns[col.field]; // Apply visibility from localStorage
        }
        return col;
    });
}

// Event listener for column visibility toggle
$('#table').on('column-switch.bs.table', function (e, field, checked) {
    visibleColumns[field] = checked; // Save the visibility of this column
    saveColumnVisibility(visibleColumns); // Store it in localStorage
});

// Ensure that saved column visibility is applied immediately after table load
$('#table').bootstrapTable('refreshOptions', {
    columns: $('#table').bootstrapTable('getOptions').columns // Force refresh to apply column visibility
});

// Initialize dialog (if editable)
const init_dialog = function () {
<?php if (isset($editable)): ?>
        table_support.submit_handler('<?= site_url("reports/get_detailed_{$editable}_row") ?>');
<?php endif; ?>
};
