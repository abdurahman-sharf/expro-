INSERT INTO ospos_people (person_id, first_name, last_name, gender, phone_number, email, address_1, address_2, city, state, zip, country, comments) VALUES
(2, 'أحمد', 'العلي', 1, '0501234567', 'ahmed@example.com', 'شارع الملك فهد', '', 'الرياض', '', '11564', 'السعودية', ''),
(3, 'سارة', 'الحربي', 0, '0507654321', 'sara@example.com', 'حي النخيل', '', 'جدة', '', '21442', 'السعودية', ''),
(4, 'Michael', 'Brown', 1, '555-0101', 'mike@example.com', '12 Main St', '', 'Austin', 'TX', '73301', 'US', ''),
(5, 'خالد', 'المنصور', 1, '0551112233', 'khaled@example.com', 'طريق الأمير سلطان', '', 'الدمام', '', '31411', 'السعودية', ''),
(6, 'مؤسسة', 'النور', 1, '0114445566', 'alnoor@example.com', 'المنطقة الصناعية', '', 'الرياض', '', '11421', 'السعودية', ''),
(7, 'شركة', 'الأفق', 1, '0126667788', 'horizon@example.com', 'شارع التحلية', '', 'جدة', '', '21589', 'السعودية', ''),
(8, 'مصنع', 'الخليج', 1, '0138889900', 'gulf@example.com', 'المدينة الصناعية', '', 'الدمام', '', '31952', 'السعودية', '');

INSERT INTO ospos_customers (person_id, company_name, account_number, taxable, points, deleted, discount, discount_type, date, employee_id, consent) VALUES
(2, '', NULL, 1, 0, 0, 0, 0, NOW(), 1, 1),
(3, '', NULL, 1, 0, 0, 0, 0, NOW(), 1, 1),
(4, '', NULL, 1, 0, 0, 0, 0, NOW(), 1, 1),
(5, '', NULL, 1, 0, 0, 5, 0, NOW(), 1, 1);

INSERT INTO ospos_suppliers (person_id, company_name, agency_name, account_number, tax_id, deleted, category) VALUES
(6, 'مؤسسة النور للتجارة', '', 'S-100', '300123', 0, 0),
(7, 'شركة الأفق للتوريد', '', 'S-101', '300124', 0, 0),
(8, 'مصنع الخليج للأغذية', '', 'S-102', '300125', 0, 0);

INSERT INTO ospos_items (item_id, name, category, supplier_id, item_number, description, cost_price, unit_price, reorder_level, receiving_quantity, allow_alt_description, is_serialized, deleted, stock_type, item_type) VALUES
(1, 'أرز بسمتي 5 كجم', 'مواد غذائية', 8, '6281001', '', 30.00, 42.50, 10, 1, 0, 0, 0, 0, 0),
(2, 'زيت زيتون 1 لتر', 'مواد غذائية', 8, '6281002', '', 28.00, 39.00, 10, 1, 0, 0, 0, 0, 0),
(3, 'سكر ناعم 2 كجم', 'مواد غذائية', 8, '6281003', '', 7.50, 10.00, 20, 1, 0, 0, 0, 0, 0),
(4, 'شاي أخضر علبة', 'مشروبات', 6, '6281004', '', 9.00, 14.50, 15, 1, 0, 0, 0, 0, 0),
(5, 'قهوة عربية 250 جم', 'مشروبات', 6, '6281005', '', 22.00, 33.00, 5, 1, 0, 0, 0, 0, 0),
(6, 'ماء معدني 12 حبة', 'مشروبات', 7, '6281006', '', 6.00, 9.00, 30, 1, 0, 0, 0, 0, 0),
(7, 'منظف أرضيات 3 لتر', 'منزلية', 7, '6281007', '', 14.00, 21.00, 8, 1, 0, 0, 0, 0, 0),
(8, 'مناديل ورقية 10 علب', 'منزلية', 7, '6281008', '', 11.00, 17.50, 12, 1, 0, 0, 0, 0, 0),
(9, 'USB Flash 64GB', 'Electronics', 6, '6281009', '', 18.00, 29.00, 4, 1, 0, 0, 0, 0, 0),
(10, 'سماعات لاسلكية', 'Electronics', 6, '6281010', '', 55.00, 89.00, 3, 1, 0, 0, 0, 0, 0),
(11, 'دفتر ملاحظات A5', 'قرطاسية', 7, '6281011', '', 3.00, 6.00, 25, 1, 0, 0, 0, 0, 0),
(12, 'قلم حبر جاف 50 قطعة', 'قرطاسية', 7, '6281012', '', 16.00, 25.00, 6, 1, 0, 0, 0, 0, 0);

INSERT INTO ospos_item_quantities (item_id, location_id, quantity) VALUES
(1, 1, 45), (2, 1, 30), (3, 1, 8), (4, 1, 60), (5, 1, 22), (6, 1, 120),
(7, 1, 16), (8, 1, 4), (9, 1, 9), (10, 1, 2), (11, 1, 80), (12, 1, 14);

-- Items listed in the manage view need an inventory row, as the UI would create.
INSERT INTO ospos_inventory (trans_items, trans_user, trans_date, trans_comment, trans_location, trans_inventory)
SELECT item_id, 1, DATE_SUB(NOW(), INTERVAL 30 DAY), 'Manual Edit of Quantity', 1, quantity FROM ospos_item_quantities;

INSERT INTO ospos_expense_categories (category_name, category_description, deleted) VALUES
('إيجار', 'إيجار المحل', 0),
('كهرباء وماء', 'الخدمات', 0),
('رواتب', 'رواتب الموظفين', 0);

INSERT INTO ospos_expenses (date, amount, payment_type, expense_category_id, description, employee_id, deleted) VALUES
(NOW(), 1500.00, 'Cash', 1, 'إيجار شهر أكتوبر', 1, 0),
(NOW(), 320.50, 'Due', 2, 'فاتورة الكهرباء', 1, 0),
(NOW(), 4200.00, 'Check', 3, 'رواتب الموظفين', 1, 0);

INSERT INTO ospos_giftcards (giftcard_number, value, deleted, person_id) VALUES
(1001, 150.00, 0, 2),
(1002, 500.00, 0, 3);

INSERT IGNORE INTO ospos_grants (permission_id, person_id, menu_group) VALUES ('taxes', 1, 'office');

INSERT IGNORE INTO ospos_grants (permission_id, person_id, menu_group) VALUES
('accounting_chart', 1, 'office'),
('accounting_journals', 1, 'office'),
('accounting_cost_centers', 1, 'office'),
('acc_chart_manage', 1, '--'),
('acc_journals_all', 1, '--'),
('acc_journals_draft', 1, '--'),
('acc_journals_post', 1, '--'),
('acc_journals_reverse', 1, '--'),
('acc_journals_audit', 1, '--'),
('acc_cost_centers_manage', 1, '--');

REPLACE INTO ospos_app_config (`key`, `value`) VALUES
('accounting_base_currency', 'SAR'),
('accounting_start_date', '2026-01-01'),
('accounting_enabled', '1');

INSERT INTO ospos_accounting_fiscal_years (name, start_date, end_date) VALUES ('2026', '2026-01-01', '2026-12-31');

INSERT INTO ospos_accounting_cost_centers (parent_id, code, name, active) VALUES
(NULL, '1', 'الإدارة', 1),
(NULL, '2', 'المبيعات', 1),
(NULL, '3', 'المستودع', 1);

INSERT INTO ospos_accounting_cost_centers (parent_id, code, name, active)
SELECT cost_center_id, '21', 'معرض الرياض', 1 FROM ospos_accounting_cost_centers WHERE code = '2';
