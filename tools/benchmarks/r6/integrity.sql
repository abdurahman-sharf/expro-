-- Dev only: counts of the half-posted shapes a killed save could leave behind. Every number should be 0.
SELECT 'completed sale without its revenue or cost journal' AS check_name, COUNT(*) AS n FROM ospos_sales s
  WHERE s.sale_status = 0 AND s.sale_type IN (0, 1)
    AND NOT EXISTS (SELECT 1 FROM ospos_accounting_journals j WHERE j.source_type = 'sale' AND j.source_id = s.sale_id AND j.entry_type = 'revenue')
UNION ALL
SELECT 'sale journal without a sale', COUNT(*) FROM ospos_accounting_journals j
  WHERE j.source_type = 'sale' AND NOT EXISTS (SELECT 1 FROM ospos_sales s WHERE s.sale_id = j.source_id)
UNION ALL
SELECT 'sale payment without its journal', COUNT(*) FROM ospos_sales_payments p
  WHERE p.payment_status = 0 AND NOT EXISTS (SELECT 1 FROM ospos_accounting_journals j WHERE j.source_type = 'sale_payment' AND j.source_id = p.payment_id)
UNION ALL
SELECT 'payment journal without a payment', COUNT(*) FROM ospos_accounting_journals j
  WHERE j.source_type = 'sale_payment' AND NOT EXISTS (SELECT 1 FROM ospos_sales_payments p WHERE p.payment_id = j.source_id)
UNION ALL
SELECT 'purchase journal without a purchase', COUNT(*) FROM ospos_accounting_journals j
  WHERE j.source_type = 'purchase' AND NOT EXISTS (SELECT 1 FROM ospos_receivings r WHERE r.receiving_id = j.source_id)
UNION ALL
SELECT 'purchase without a journal', COUNT(*) FROM ospos_receivings r
  WHERE NOT EXISTS (SELECT 1 FROM ospos_accounting_journals j WHERE j.source_type = 'purchase' AND j.source_id = r.receiving_id)
UNION ALL
SELECT 'stock entry without its document', COUNT(*) FROM ospos_accounting_stock_entries e
  WHERE (e.source_type = 'sale' AND NOT EXISTS (SELECT 1 FROM ospos_sales s WHERE s.sale_id = e.source_id))
     OR (e.source_type = 'purchase' AND NOT EXISTS (SELECT 1 FROM ospos_receivings r WHERE r.receiving_id = e.source_id))
UNION ALL
SELECT 'stock entry without its journal', COUNT(*) FROM ospos_accounting_stock_entries e WHERE e.journal_id IS NULL
UNION ALL
SELECT 'inventory row of a sale that is not there', COUNT(*) FROM ospos_inventory i
  WHERE i.trans_comment REGEXP '^POS [0-9]+' AND NOT EXISTS (SELECT 1 FROM ospos_sales s WHERE s.sale_id = CAST(SUBSTRING_INDEX(i.trans_comment, ' ', -1) AS UNSIGNED))
UNION ALL
SELECT 'item quantity differing from the sum of its inventory rows', COUNT(*) FROM (
  SELECT q.item_id, q.location_id FROM ospos_item_quantities q
  LEFT JOIN (SELECT trans_items, trans_location, SUM(trans_inventory) AS s FROM ospos_inventory GROUP BY trans_items, trans_location) i ON i.trans_items = q.item_id AND i.trans_location = q.location_id
  WHERE ABS(q.quantity - COALESCE(i.s, 0)) > 0.0005) d
UNION ALL
SELECT 'journal that does not balance', COUNT(*) FROM (
  SELECT journal_id FROM ospos_accounting_journal_lines GROUP BY journal_id HAVING ABS(SUM(debit) - SUM(credit)) > 0.00005) d
UNION ALL
SELECT 'journal without lines', COUNT(*) FROM ospos_accounting_journals j WHERE NOT EXISTS (SELECT 1 FROM ospos_accounting_journal_lines l WHERE l.journal_id = j.journal_id)
UNION ALL
SELECT 'duplicated journal number', COUNT(*) FROM (SELECT 1 FROM ospos_accounting_journals GROUP BY company_id, series, number HAVING COUNT(*) > 1) d
UNION ALL
SELECT 'duplicated invoice number', COUNT(*) FROM (SELECT 1 FROM ospos_sales WHERE invoice_number IS NOT NULL GROUP BY invoice_number HAVING COUNT(*) > 1) d
UNION ALL
SELECT 'gap in the invoice numbers (max number minus count)', COALESCE(MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)), 0) - COUNT(*) FROM ospos_sales WHERE invoice_number LIKE 'INV-%'
UNION ALL
SELECT 'gap in the customer payment numbers (next number minus 1 minus count)', (SELECT next_number FROM ospos_document_sequences WHERE doc_type = 'customer_payment') - 1 - COUNT(*) FROM ospos_document_numbers WHERE doc_type = 'customer_payment';
