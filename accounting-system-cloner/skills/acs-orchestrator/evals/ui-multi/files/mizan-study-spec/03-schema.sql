-- 03-schema.sql — مخطط PostgreSQL 15+ مقترح لنظام مكافئ وظيفياً للنظام المرجعي
-- الاصطلاحات: snake_case، مفاتيح bigint identity، company_id في كل جدول (تعدد الشركات + RLS)،
-- numeric(18,4) للمبالغ والكميات، numeric(18,6) لأسعار الصرف، numeric(7,4) للنسب.
-- كل سطر قيد يحمل المبلغ بعملة المعاملة وبالعملة الأساسية مع سعر الصرف.
-- توازن القيد يُفرض بـ trigger مؤجّل (DEFERRABLE INITIALLY DEFERRED) + اختبار إلزامي في طبقة الخدمة.
-- منع الترحيل في فترة مقفلة: trigger على journal_entry (والخدمة تتحقق أيضاً لكل مستند).

BEGIN;

CREATE TABLE company (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name          text NOT NULL,
  vat_number    varchar(15) CHECK (vat_number ~ '^3[0-9]{13}3$'),
  cr_number     varchar(10),
  iban          varchar(34),
  base_currency char(3) NOT NULL DEFAULT 'SAR', -- لا تتغير بعد أول قيد (مرئي)
  fy_start_month smallint NOT NULL DEFAULT 1 CHECK (fy_start_month BETWEEN 1 AND 12),
  -- العنوان الوطني للبائع (ZATCA) — [مُستنتج] غير موجود في إعدادات المرجع
  building_no   varchar(4), street text, district text, city text, postal_code varchar(5), additional_no varchar(4),
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app_module (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id bigint NOT NULL REFERENCES company(id),
  code       text NOT NULL,
  name       text NOT NULL,
  category   text NOT NULL,
  enabled    boolean NOT NULL DEFAULT false,
  UNIQUE (company_id, code)
);

CREATE TABLE currency (
  code      char(3) PRIMARY KEY,
  name_ar   text NOT NULL,
  name_en   text,
  decimals  smallint NOT NULL DEFAULT 2
);

CREATE TABLE company_currency (
  company_id bigint NOT NULL REFERENCES company(id),
  currency_code char(3) NOT NULL REFERENCES currency(code),
  is_base    boolean NOT NULL DEFAULT false,
  PRIMARY KEY (company_id, currency_code)
);

CREATE TABLE exchange_rate (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id    bigint NOT NULL REFERENCES company(id),
  currency_code char(3) NOT NULL REFERENCES currency(code),
  rate_date     date NOT NULL,
  rate          numeric(18,6) NOT NULL CHECK (rate > 0), -- وحدات العملة الأساسية لكل وحدة أجنبية
  source        text NOT NULL DEFAULT 'manual' CHECK (source IN ('manual','auto')),
  UNIQUE (company_id, currency_code, rate_date)
);

CREATE TABLE account (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  parent_id   bigint REFERENCES account(id),
  code        varchar(12) NOT NULL,           -- المرجع: maxlength=8 أرقام؛ الحسابات الفرعية التلقائية "1201-001" (12 خانة)
  name_ar     varchar(100) NOT NULL,
  name_en     varchar(100),
  type        text NOT NULL CHECK (type IN ('asset','liability','equity','income','expense')),
  subtype     text NOT NULL DEFAULT 'general' CHECK (subtype IN ('general','cash','bank','receivable','payable','inventory')),
  nature      text NOT NULL CHECK (nature IN ('debit','credit')),
  is_group    boolean NOT NULL DEFAULT false, -- تجميعي: لا يقبل قيوداً
  requires_cost_center boolean NOT NULL DEFAULT false,
  frozen      boolean NOT NULL DEFAULT false,
  role        text,                           -- دور نظامي: clients, suppliers, sales, sales_cost, purchases, stores, treasury, bank, vat_output, vat_input, opening_balance, retained_earnings, other_payables, fx_realized, fx_unrealized, inventory_variance, branch_current
  currency_code char(3) REFERENCES currency(code), -- [مُستنتج] حساب بعملة محددة (غير ظاهر في المرجع)
  party_type  text CHECK (party_type IN ('customer','supplier')), -- للحسابات الفرعية التلقائية
  created_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, code)
);
CREATE INDEX account_parent_idx ON account(parent_id);

CREATE TABLE branch (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  code        varchar(3) NOT NULL,             -- يدخل في الترقيم {BRANCH}
  name        text NOT NULL,
  is_head_office boolean NOT NULL DEFAULT false,
  separate_ledger boolean NOT NULL DEFAULT false, -- المرجع: "لا (بُعد تحليلي)"
  current_account_id bigint REFERENCES account(id), -- حساب جاري الفروع
  UNIQUE (company_id, code)
);

CREATE TABLE warehouse (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  branch_id   bigint NOT NULL REFERENCES branch(id),
  name        text NOT NULL,
  allow_negative boolean NOT NULL DEFAULT false,
  is_transit  boolean NOT NULL DEFAULT false   -- مستودع "بضاعة في الطريق" [مُستنتج]
);
ALTER TABLE branch ADD COLUMN default_warehouse_id bigint REFERENCES warehouse(id);

CREATE TABLE cost_center (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id bigint NOT NULL REFERENCES company(id),
  parent_id  bigint REFERENCES cost_center(id),
  name       text NOT NULL
);

CREATE TABLE fiscal_year (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id bigint NOT NULL REFERENCES company(id),
  start_date date NOT NULL,
  end_date   date NOT NULL,
  status     text NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
  CHECK (end_date > start_date)
);

CREATE TABLE fiscal_period (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id bigint NOT NULL REFERENCES company(id),
  fiscal_year_id bigint NOT NULL REFERENCES fiscal_year(id),
  start_date date NOT NULL,
  end_date   date NOT NULL,
  status     text NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')), -- إقفال صلب على كل المستندات (مرئي)
  closed_at  timestamptz, closed_by bigint,
  UNIQUE (company_id, start_date)
);

CREATE TABLE account_routing (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  tab         text NOT NULL CHECK (tab IN ('sales','purchases','inventory','currency','cash','branches')),
  operation   text NOT NULL,   -- sales_revenue, sales_tax, ar, purchase_free_text, purchase_tax, ap, inventory, cogs, item_opening_balance, inventory_variance, fx_realized, fx_unrealized ...
  mode        text NOT NULL CHECK (mode IN ('auto','auto_subaccount_per_party','auto_create_on_first_use','none','disabled')),
  account_id  bigint REFERENCES account(id),
  UNIQUE (company_id, operation)
);

CREATE TABLE tax (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  name        text NOT NULL,
  rate        numeric(7,4) NOT NULL CHECK (rate >= 0 AND rate <= 100),
  category    char(1) NOT NULL CHECK (category IN ('S','Z','E','O')),
  output_account_id bigint REFERENCES account(id),
  input_account_id  bigint REFERENCES account(id),
  exemption_code text,   -- VATEX-SA-...
  exemption_text text,
  CHECK (category = 'S' OR exemption_code IS NOT NULL) -- [مُستنتج] السبب إلزامي مع Z/E/O
);

CREATE TABLE document_sequence (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  doc_type    text NOT NULL,          -- sales_invoice, receipt, journal_entry, purchase_invoice, stock_transfer, credit_note
  prefix_template text NOT NULL,      -- مثل {BRANCH}-INV-{YYYY}-
  digits      smallint NOT NULL DEFAULT 5,
  reset_policy text NOT NULL CHECK (reset_policy IN ('never','yearly','yearly_per_branch','per_branch')),
  assign_on   text NOT NULL CHECK (assign_on IN ('save','approve')),
  UNIQUE (company_id, doc_type)
);

CREATE TABLE document_sequence_counter (
  sequence_id bigint NOT NULL REFERENCES document_sequence(id),
  branch_id   bigint REFERENCES branch(id),
  year        smallint,
  next_value  bigint NOT NULL DEFAULT 1,
  UNIQUE (sequence_id, branch_id, year)
);

CREATE TABLE app_user (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id bigint NOT NULL REFERENCES company(id),
  name       text NOT NULL,
  email      text NOT NULL,
  is_active  boolean NOT NULL DEFAULT true,
  UNIQUE (company_id, email)
);

CREATE TABLE role (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id bigint NOT NULL REFERENCES company(id),
  name       text NOT NULL,
  UNIQUE (company_id, name)
);

CREATE TABLE permission (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  module_code text NOT NULL,          -- ربط مجموعة الصلاحيات بالوحدة
  resource   text NOT NULL,           -- sales_invoice, journal_entry, account, settings ...
  action     text NOT NULL CHECK (action IN ('view','create','edit','edit_draft','delete','approve','post','print','export','view_cost','edit_price','override_credit_limit','post_in_closed_period')),
  UNIQUE (resource, action)
);

CREATE TABLE role_permission (
  role_id       bigint NOT NULL REFERENCES role(id),
  permission_id bigint NOT NULL REFERENCES permission(id),
  scope         text NOT NULL DEFAULT 'all' CHECK (scope IN ('all','own','branch')), -- [مُستنتج]
  PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_role (
  user_id bigint NOT NULL REFERENCES app_user(id),
  role_id bigint NOT NULL REFERENCES role(id),
  PRIMARY KEY (user_id, role_id)
);

CREATE TABLE user_branch (   -- "المستخدم مقيّد بفروعه المسموحة" (مرئي)
  user_id   bigint NOT NULL REFERENCES app_user(id),
  branch_id bigint NOT NULL REFERENCES branch(id),
  PRIMARY KEY (user_id, branch_id)
);

CREATE TABLE approval_rule (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  doc_type    text NOT NULL,
  min_amount  numeric(18,4) NOT NULL DEFAULT 0,
  max_amount  numeric(18,4),               -- NULL = بلا حد أعلى
  step_no     smallint NOT NULL DEFAULT 1, -- اعتماد على مراحل
  approver_role_id bigint NOT NULL REFERENCES role(id)
);

CREATE TABLE customer (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  code         varchar(20) NOT NULL,       -- تلقائي C-NNNN
  name         varchar(150) NOT NULL,
  kind         text NOT NULL DEFAULT 'registered' CHECK (kind IN ('registered','individual')),
  vat_number   varchar(15) CHECK (vat_number ~ '^3[0-9]{13}3$'),
  cr_number    varchar(10),
  id_type      text,                        -- [مُستنتج] CRN/700/... لـ ZATCA
  is_government boolean NOT NULL DEFAULT false, -- [مُستنتج]
  building_no  varchar(4) CHECK (building_no ~ '^[0-9]{4}$'),
  street text, district text, city text,
  postal_code  varchar(5) CHECK (postal_code ~ '^[0-9]{5}$'),
  additional_no varchar(4),
  phone text, email text,
  currency_code char(3) NOT NULL DEFAULT 'SAR' REFERENCES currency(code),
  credit_limit numeric(18,4) CHECK (credit_limit >= 0),
  payment_terms_days integer NOT NULL DEFAULT 30 CHECK (payment_terms_days >= 0),
  account_id   bigint REFERENCES account(id),  -- الحساب الفرعي التلقائي تحت clients
  status       text NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),
  created_by   bigint REFERENCES app_user(id),
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, code),
  CHECK (kind = 'individual' OR vat_number IS NOT NULL OR true) -- الرقم الضريبي يظهر للمنشأة فقط؛ الإلزامية غير متحققة (Q)
);

CREATE TABLE supplier (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  code         varchar(20) NOT NULL,       -- S-NNNN
  name         varchar(150) NOT NULL,
  vat_number   varchar(15) CHECK (vat_number ~ '^3[0-9]{13}3$'),
  iban         varchar(34),
  currency_code char(3) NOT NULL DEFAULT 'SAR' REFERENCES currency(code),
  payment_terms_days integer NOT NULL DEFAULT 30,
  account_id   bigint REFERENCES account(id),  -- حساب فرعي تلقائي تحت suppliers
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, code)
);

CREATE TABLE salesman (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id bigint NOT NULL REFERENCES company(id),
  name       text NOT NULL,
  user_id    bigint REFERENCES app_user(id)
);

CREATE TABLE unit_of_measure (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id bigint NOT NULL REFERENCES company(id),
  name       text NOT NULL
);

CREATE TABLE item (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id    bigint NOT NULL REFERENCES company(id),
  code          varchar(20) NOT NULL,
  name          text NOT NULL,
  type          text NOT NULL DEFAULT 'stock' CHECK (type IN ('stock','service')),
  uom_id        bigint REFERENCES unit_of_measure(id),
  barcode       text,
  sale_price    numeric(18,4) CHECK (sale_price >= 0),
  purchase_price numeric(18,4) CHECK (purchase_price >= 0),
  tax_id        bigint REFERENCES tax(id),
  revenue_account_id bigint REFERENCES account(id),
  cogs_account_id    bigint REFERENCES account(id),
  reorder_level numeric(18,4),
  UNIQUE (company_id, code)
);

CREATE TABLE inventory_settings (
  company_id  bigint PRIMARY KEY REFERENCES company(id),
  valuation   text NOT NULL DEFAULT 'fifo' CHECK (valuation IN ('fifo','moving_average')), -- لا يتغير بعد أول حركة
  system      text NOT NULL DEFAULT 'perpetual' CHECK (system IN ('perpetual','periodic')),
  allow_negative boolean NOT NULL DEFAULT false,
  variance_account_id bigint REFERENCES account(id)
);

CREATE TABLE journal_entry (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  branch_id    bigint REFERENCES branch(id),
  number       varchar(40),                -- {BRANCH}-JE-{YYYY}-NNNNN عند الحفظ
  entry_date   date NOT NULL,
  reference    text,
  memo         text NOT NULL,
  source_type  text NOT NULL DEFAULT 'manual', -- manual, sales_invoice, sales_invoice_cogs, receipt, purchase_invoice, stock_transfer, item_opening, fx_revaluation, fx_revaluation_reversal, recurring
  source_id    bigint,
  status       text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted','reversed')),
  reversal_of_id bigint REFERENCES journal_entry(id),
  is_recurring boolean NOT NULL DEFAULT false,
  created_by   bigint REFERENCES app_user(id),
  created_at   timestamptz NOT NULL DEFAULT now(),
  posted_at    timestamptz,
  UNIQUE (company_id, number)
);
CREATE INDEX journal_entry_source_idx ON journal_entry(source_type, source_id);
CREATE INDEX journal_entry_date_idx ON journal_entry(company_id, entry_date);

CREATE TABLE journal_line (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  entry_id      bigint NOT NULL REFERENCES journal_entry(id) ON DELETE CASCADE,
  line_no       smallint NOT NULL,
  account_id    bigint NOT NULL REFERENCES account(id),
  branch_id     bigint REFERENCES branch(id),        -- بُعد تحليلي
  cost_center_id bigint REFERENCES cost_center(id),
  currency_code char(3) NOT NULL REFERENCES currency(code),
  exchange_rate numeric(18,6) NOT NULL DEFAULT 1 CHECK (exchange_rate > 0),
  debit         numeric(18,4) NOT NULL DEFAULT 0 CHECK (debit >= 0),
  credit        numeric(18,4) NOT NULL DEFAULT 0 CHECK (credit >= 0),
  debit_base    numeric(18,4) NOT NULL DEFAULT 0 CHECK (debit_base >= 0),
  credit_base   numeric(18,4) NOT NULL DEFAULT 0 CHECK (credit_base >= 0),
  memo          text,
  CHECK (NOT (debit > 0 AND credit > 0)),
  CHECK (debit_base = round(debit * exchange_rate, 2) OR debit = 0),
  CHECK (credit_base = round(credit * exchange_rate, 2) OR credit = 0)
);
CREATE INDEX journal_line_account_idx ON journal_line(account_id);

-- توازن القيد بالعملة الأساسية: trigger مؤجّل يفحص كل قيد مرحّل عند نهاية المعاملة
CREATE FUNCTION check_entry_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d numeric; c numeric; st text;
BEGIN
  SELECT status INTO st FROM journal_entry WHERE id = COALESCE(NEW.entry_id, OLD.entry_id);
  IF st IS DISTINCT FROM 'posted' THEN RETURN NULL; END IF;
  SELECT COALESCE(sum(debit_base),0), COALESCE(sum(credit_base),0) INTO d, c
    FROM journal_line WHERE entry_id = COALESCE(NEW.entry_id, OLD.entry_id);
  IF d <> c THEN RAISE EXCEPTION 'unbalanced journal entry % (debit %, credit %)', COALESCE(NEW.entry_id, OLD.entry_id), d, c; END IF;
  RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER journal_line_balanced AFTER INSERT OR UPDATE OR DELETE ON journal_line
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_entry_balanced();

-- منع الترحيل في فترة مقفلة
CREATE FUNCTION check_period_open() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.status = 'posted' AND EXISTS (
       SELECT 1 FROM fiscal_period p WHERE p.company_id = NEW.company_id AND p.status = 'closed'
         AND NEW.entry_date BETWEEN p.start_date AND p.end_date) THEN
    RAISE EXCEPTION 'period closed for date %', NEW.entry_date;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER journal_entry_period_open BEFORE INSERT OR UPDATE ON journal_entry
  FOR EACH ROW EXECUTE FUNCTION check_period_open();

CREATE TABLE sales_invoice (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id    bigint NOT NULL REFERENCES company(id),
  branch_id     bigint NOT NULL REFERENCES branch(id),       -- [مُستنتج] من فرع المستخدم في الترويسة
  warehouse_id  bigint REFERENCES warehouse(id),             -- [مُستنتج] المستودع الافتراضي للفرع
  number        varchar(40),                                 -- يُولَّد عند الاعتماد: {BRANCH}-INV-{YYYY}-NNNNN
  customer_id   bigint NOT NULL REFERENCES customer(id),
  invoice_date  date NOT NULL,
  due_date      date,                                        -- [مُستنتج] التاريخ + مدة السداد
  reference     text,
  currency_code char(3) NOT NULL REFERENCES currency(code),  -- [مُستنتج] = عملة العميل
  exchange_rate numeric(18,6) NOT NULL DEFAULT 1 CHECK (exchange_rate > 0),
  prices_include_tax boolean NOT NULL DEFAULT false,
  doc_discount  numeric(18,4) NOT NULL DEFAULT 0 CHECK (doc_discount >= 0),
  salesman_id   bigint REFERENCES salesman(id),
  subtotal      numeric(18,4) NOT NULL DEFAULT 0,
  tax_total     numeric(18,4) NOT NULL DEFAULT 0,
  total         numeric(18,4) NOT NULL DEFAULT 0,
  total_base    numeric(18,4) NOT NULL DEFAULT 0,
  tax_total_base numeric(18,4) NOT NULL DEFAULT 0,            -- ZATCA: الضريبة بالريال
  paid_amount   numeric(18,4) NOT NULL DEFAULT 0,
  status        text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','pending_approval','issued','cancelled')),
  payment_status text NOT NULL DEFAULT 'unpaid' CHECK (payment_status IN ('unpaid','partial','paid')),
  delivery_status text NOT NULL DEFAULT 'not_delivered' CHECK (delivery_status IN ('not_delivered','delivered')),
  -- ZATCA
  invoice_type_code char(3) NOT NULL DEFAULT '388',
  invoice_subtype varchar(7),        -- 01xxxxx قياسية / 02xxxxx مبسطة
  uuid          uuid,
  icv           bigint,
  pih           text,
  invoice_hash  text,
  qr_payload    text,
  zatca_status  text CHECK (zatca_status IN ('pending','cleared','reported','rejected','warnings')),
  zatca_messages jsonb,
  cleared_at    timestamptz, reported_at timestamptz,
  egs_unit_id   bigint,
  created_by    bigint REFERENCES app_user(id),
  approved_by   bigint REFERENCES app_user(id),
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, number),
  CHECK (status = 'draft' OR number IS NOT NULL),
  CHECK (payment_status <> 'partial' OR (paid_amount > 0 AND paid_amount < total)),   -- يمنع AN-02
  CHECK (status <> 'draft' OR (payment_status = 'unpaid' AND delivery_status = 'not_delivered')) -- يمنع AN-02/AN-06
);

CREATE TABLE sales_invoice_line (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  invoice_id  bigint NOT NULL REFERENCES sales_invoice(id) ON DELETE CASCADE,
  line_no     smallint NOT NULL,
  item_id     bigint REFERENCES item(id),   -- NULL = بند نصي حر
  description text,
  qty         numeric(18,4) NOT NULL CHECK (qty > 0),
  unit_price  numeric(18,4) NOT NULL CHECK (unit_price >= 0),
  tax_id      bigint REFERENCES tax(id),
  tax_rate    numeric(7,4) NOT NULL DEFAULT 15,
  discount_alloc numeric(18,4) NOT NULL DEFAULT 0, -- حصة خصم الإجمالي [مُستنتج]
  net_amount  numeric(18,4) NOT NULL,
  tax_amount  numeric(18,4) NOT NULL,
  unit_cost   numeric(18,4),                -- تكلفة FIFO عند الإصدار
  CHECK (item_id IS NOT NULL OR description IS NOT NULL)
);

CREATE TABLE receipt (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  branch_id    bigint NOT NULL REFERENCES branch(id),
  number       varchar(40) NOT NULL,        -- {BRANCH}-RCV-{YYYY}-NNNNN عند الحفظ
  receipt_date date NOT NULL,
  customer_id  bigint NOT NULL REFERENCES customer(id),
  account_id   bigint NOT NULL REFERENCES account(id),  -- treasury/bank
  currency_code char(3) NOT NULL REFERENCES currency(code),
  exchange_rate numeric(18,6) NOT NULL DEFAULT 1,        -- [مُستنتج] غير ظاهر في المرجع (Q-03)
  amount       numeric(18,4) NOT NULL CHECK (amount > 0),
  status       text NOT NULL DEFAULT 'posted' CHECK (status IN ('draft','posted','cancelled')),
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, number)
);

CREATE TABLE receipt_allocation (
  receipt_id  bigint NOT NULL REFERENCES receipt(id),
  invoice_id  bigint NOT NULL REFERENCES sales_invoice(id),
  amount      numeric(18,4) NOT NULL CHECK (amount > 0),
  fx_diff_base numeric(18,4) NOT NULL DEFAULT 0,  -- فرق العملة المحقق
  PRIMARY KEY (receipt_id, invoice_id)
);

CREATE TABLE sales_credit_note (  -- [مُستنتج] لم يُعثر على شاشته في المرجع؛ مطلوب لـ ZATCA (381)
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  branch_id    bigint NOT NULL REFERENCES branch(id),
  number       varchar(40),
  original_invoice_id bigint NOT NULL REFERENCES sales_invoice(id),
  issue_reason text NOT NULL,
  note_date    date NOT NULL,
  total        numeric(18,4) NOT NULL,
  status       text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','issued'))
);

CREATE TABLE purchase_invoice (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  branch_id    bigint NOT NULL REFERENCES branch(id),
  warehouse_id bigint REFERENCES warehouse(id),
  number       varchar(40),
  supplier_id  bigint NOT NULL REFERENCES supplier(id),
  supplier_invoice_no text NOT NULL,
  invoice_date date NOT NULL,
  currency_code char(3) NOT NULL REFERENCES currency(code),
  exchange_rate numeric(18,6) NOT NULL DEFAULT 1,
  subtotal     numeric(18,4) NOT NULL DEFAULT 0,
  tax_total    numeric(18,4) NOT NULL DEFAULT 0,
  total        numeric(18,4) NOT NULL DEFAULT 0,
  status       text NOT NULL DEFAULT 'issued' CHECK (status IN ('draft','issued','cancelled')),
  UNIQUE (company_id, supplier_id, supplier_invoice_no)
);

CREATE TABLE purchase_invoice_line (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  invoice_id  bigint NOT NULL REFERENCES purchase_invoice(id) ON DELETE CASCADE,
  line_no     smallint NOT NULL,
  item_id     bigint REFERENCES item(id),
  description text,
  qty         numeric(18,4) NOT NULL CHECK (qty > 0),
  unit_cost   numeric(18,4) NOT NULL CHECK (unit_cost >= 0),
  tax_id      bigint REFERENCES tax(id),
  net_amount  numeric(18,4) NOT NULL,
  tax_amount  numeric(18,4) NOT NULL,
  CHECK (item_id IS NOT NULL OR description IS NOT NULL)
);

CREATE TABLE stock_transfer (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id    bigint NOT NULL REFERENCES company(id),
  number        varchar(40),
  transfer_date date,
  from_warehouse_id bigint NOT NULL REFERENCES warehouse(id),
  to_warehouse_id   bigint NOT NULL REFERENCES warehouse(id),
  transit_warehouse_id bigint REFERENCES warehouse(id),
  status        text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','sent','received','cancelled')),
  sent_at timestamptz, received_at timestamptz,
  CHECK (from_warehouse_id <> to_warehouse_id)
);

CREATE TABLE stock_transfer_line (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  transfer_id bigint NOT NULL REFERENCES stock_transfer(id) ON DELETE CASCADE,
  item_id     bigint NOT NULL REFERENCES item(id),
  qty         numeric(18,4) NOT NULL CHECK (qty >= 0.001),
  total_cost  numeric(18,4)   -- محسوبة من طبقات FIFO للمستودع المصدر
);

CREATE TABLE stock_movement (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  item_id      bigint NOT NULL REFERENCES item(id),
  warehouse_id bigint NOT NULL REFERENCES warehouse(id),
  movement_date date NOT NULL,
  qty          numeric(18,4) NOT NULL,      -- موجب وارد، سالب صادر
  total_cost   numeric(18,4) NOT NULL,
  source_type  text NOT NULL,               -- opening, purchase_invoice, sales_invoice, transfer_out, transfer_in, adjustment
  source_id    bigint,
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX stock_movement_item_wh_idx ON stock_movement(item_id, warehouse_id, movement_date);

CREATE TABLE stock_layer (   -- طبقات FIFO لكل صنف ومستودع [مُستنتج: النطاق لكل مستودع — Q-14]
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  item_id      bigint NOT NULL REFERENCES item(id),
  warehouse_id bigint NOT NULL REFERENCES warehouse(id),
  in_movement_id bigint NOT NULL REFERENCES stock_movement(id),
  layer_date   date NOT NULL,
  qty_in       numeric(18,4) NOT NULL CHECK (qty_in > 0),
  qty_remaining numeric(18,4) NOT NULL CHECK (qty_remaining >= 0),
  unit_cost    numeric(18,4) NOT NULL CHECK (unit_cost >= 0),
  CHECK (qty_remaining <= qty_in)
);
CREATE INDEX stock_layer_fifo_idx ON stock_layer(item_id, warehouse_id, layer_date, id) WHERE qty_remaining > 0;

CREATE TABLE fx_revaluation (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  valuation_date date NOT NULL,
  journal_entry_id bigint REFERENCES journal_entry(id),
  reversal_entry_id bigint REFERENCES journal_entry(id),  -- يُعكس أول يوم في الفترة التالية
  status       text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted','reversed'))
);

CREATE TABLE fx_revaluation_line (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  revaluation_id  bigint NOT NULL REFERENCES fx_revaluation(id) ON DELETE CASCADE,
  account_id      bigint NOT NULL REFERENCES account(id),
  currency_code   char(3) NOT NULL REFERENCES currency(code),
  balance_fc      numeric(18,4) NOT NULL,
  book_rate       numeric(18,6) NOT NULL,
  valuation_rate  numeric(18,6) NOT NULL,
  diff_base       numeric(18,4) NOT NULL   -- balance_fc × (valuation_rate − book_rate)
);

CREATE TABLE egs_unit (      -- وحدات الربط مع منصة الفوترة الإلكترونية (بيانات وصفية فقط)
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id   bigint NOT NULL REFERENCES company(id),
  branch_id    bigint NOT NULL REFERENCES branch(id),
  name         text NOT NULL,
  serial_no    text NOT NULL,
  invoice_types text NOT NULL CHECK (invoice_types IN ('standard','simplified','both')),
  environment  text NOT NULL DEFAULT 'simulation' CHECK (environment IN ('sandbox','simulation','production')),
  csid_status  text NOT NULL DEFAULT 'not_onboarded' CHECK (csid_status IN ('not_onboarded','compliance','production','expired')),
  certificate_expires_at date,
  last_icv     bigint NOT NULL DEFAULT 0,
  last_invoice_hash text
);
ALTER TABLE sales_invoice ADD CONSTRAINT sales_invoice_egs_fk FOREIGN KEY (egs_unit_id) REFERENCES egs_unit(id);

CREATE TABLE zatca_submission (   -- طابور الإرسال وإعادة المحاولة كل 15 دقيقة (مرئي)
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  invoice_id  bigint NOT NULL REFERENCES sales_invoice(id),
  kind        text NOT NULL CHECK (kind IN ('clearance','reporting')),
  attempts    integer NOT NULL DEFAULT 0,
  next_attempt_at timestamptz,
  status      text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','sent','accepted','rejected')),
  response    jsonb
);

CREATE TABLE document_approval (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  doc_type    text NOT NULL,
  doc_id      bigint NOT NULL,
  step_no     smallint NOT NULL,
  approver_id bigint REFERENCES app_user(id),
  decision    text CHECK (decision IN ('approved','rejected')),
  decided_at  timestamptz
);

CREATE TABLE audit_log (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  user_id     bigint REFERENCES app_user(id),
  occurred_at timestamptz NOT NULL DEFAULT now(),
  action      text NOT NULL,
  entity      text NOT NULL,
  entity_id   bigint,
  record_label text,
  before_data jsonb,     -- [مُستنتج] غير ظاهر في المرجع؛ موصى به
  after_data  jsonb
);

CREATE TABLE saved_report (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  name        text NOT NULL,
  source      text NOT NULL CHECK (source IN ('invoices','journal')),
  definition  jsonb NOT NULL,   -- الأعمدة والتجميع والفلاتر
  created_by  bigint REFERENCES app_user(id)
);

CREATE TABLE import_job (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id  bigint NOT NULL REFERENCES company(id),
  entity      text NOT NULL CHECK (entity IN ('customers','items','opening_balances')),
  status      text NOT NULL DEFAULT 'pending',
  created_at  timestamptz NOT NULL DEFAULT now()
);

COMMIT;
