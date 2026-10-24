-- Stock the books cannot take in by themselves: one item of the first location whose shelves
-- are two units below what its movements say, and a location of the second branch whose only
-- stock is negative.
UPDATE ospos_item_quantities q
SET q.quantity = COALESCE((
    SELECT SUM(CASE WHEN e.kind = 'posting' THEN e.quantity ELSE -e.quantity END)
    FROM ospos_accounting_stock_entries e
    WHERE e.item_id = q.item_id AND e.location_id = q.location_id
), 0) - 2
WHERE q.item_id = 11 AND q.location_id = 1;

INSERT INTO ospos_stock_locations (location_name, deleted, branch_id) VALUES ('Branch store', 0, 2);
INSERT INTO ospos_item_quantities (item_id, location_id, quantity) VALUES (11, LAST_INSERT_ID(), -2);
