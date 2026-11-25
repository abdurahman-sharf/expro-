-- 03-schema.sql — مخطط PostgreSQL مقترح (15+) للنظام المكافئ وظيفياً
-- المصدر: دراسة النظام المرجعي في وضع القراءة فقط. كل ما يحمل [مُستنتج] لم يُرَ مباشرة.
-- الاصطلاحات: snake_case · bigint identity · numeric(18,4) للمبالغ · numeric(18,6) لأسعار الصرف · numeric(7,4) للنسب
-- company_id في كل جدول (متعدد الشركات) + سياسة RLS مقترحة في الأسفل.
-- توازن القيد: يُفرض بـ CONSTRAINT TRIGGER مؤجّل (DEFERRABLE INITIALLY DEFERRED) + اختبار إلزامي في طبقة الخدمة.
-- منع الترحيل في فترة مقفلة: trigger على journal_entry عند الانتقال إلى posted (+ فحص في PostingService).

BEGIN;

CREATE TABLE company (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                    varchar(200) NOT NULL,
    vat_number              varchar(15) CHECK (vat_number ~ '^3[0-9]{13}3$'),  -- [مُستنتج] المرجع لا يتحقق هنا؛ نطبّق نمط نموذج العميل
    cr_number               varchar(10),                                         -- [مُستنتج] مطلوب للفوترة الإلكترونية، غير موجود في المرجع
    iban                    varchar(34),
    base_currency           char(3) NOT NULL DEFAULT 'SAR',                      -- لا تُغيَّر بعد أول قيد (مرئي)
    fiscal_year_start_month smallint NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month IN (1, 4, 7)),  -- مرئي: يناير/أبريل/يوليو
    prices_include_tax_default boolean NOT NULL DEFAULT false,                   -- مرئي في شاشة الضرائب: "لا"
    building_no             varchar(4), street varchar(200), district varchar(100), city varchar(100),
    postal_code             varchar(5), additional_no varchar(4),                -- [مُستنتج] عنوان البائع الوطني
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app_user (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  bigint NOT NULL REFERENCES company(id),
    name        varchar(150) NOT NULL,
    email       varchar(254) NOT NULL,
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, email)
);

CREATE TABLE role (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  bigint NOT NULL REFERENCES company(id),
    name        varchar(100) NOT NULL,
    UNIQUE (company_id, name)
);

CREATE TABLE role_permission (
    role_id     bigint NOT NULL REFERENCES role(id) ON DELETE CASCADE,
    screen      varchar(60) NOT NULL,          -- مثل sales_invoice، journal، account، settings
    action      varchar(20) NOT NULL CHECK (action IN ('view','create','edit','edit_draft','delete','approve','print','export')),
    scope       varchar(10) NOT NULL DEFAULT 'all' CHECK (scope IN ('all','own')),  -- [مُستنتج] النطاق غير ظاهر في المرجع
    PRIMARY KEY (role_id, screen, action)
);

CREATE TABLE role_special_permission (
    role_id     bigint NOT NULL REFERENCES role(id) ON DELETE CASCADE,
    permission  varchar(40) NOT NULL CHECK (permission IN ('view_cost','edit_sale_price','exceed_credit_limit')),  -- مرئي
    PRIMARY KEY (role_id, permission)
);

CREATE TABLE user_role (
    user_id bigint NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    role_id bigint NOT NULL REFERENCES role(id),
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE app_module (
    company_id  bigint NOT NULL REFERENCES company(id),
    key         varchar(40) NOT NULL,          -- gl, sales, customers, purchases, inventory, reports, tax, payroll, ...
    category    varchar(40) NOT NULL,
    enabled     boolean NOT NULL DEFAULT false,
    PRIMARY KEY (company_id, key)
);

CREATE TABLE fiscal_period (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  bigint NOT NULL REFERENCES company(id),
    year        smallint NOT NULL,
    month       smallint NOT NULL CHECK (month BETWEEN 1 AND 12),
    start_date  date NOT NULL,
    end_date    date NOT NULL,
    status      text NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),   -- مرئي: مفتوحة/مقفلة (إقفال صلب)
    closed_by   bigint REFERENCES app_user(id),
    closed_at   timestamptz,
    UNIQUE (company_id, year, month),
    CHECK (end_date >= start_date)
);

CREATE TABLE document_sequence (
    company_id      bigint NOT NULL REFERENCES company(id),
    entity          varchar(40) NOT NULL,      -- sales_invoice, receipt, journal_entry, customer, supplier, purchase_invoice
    prefix_pattern  varchar(40) NOT NULL,      -- مرئي: 'INV-{YYYY}-'، 'RCV-{YYYY}-'، 'JE-{YYYY}-'
    digits          smallint NOT NULL DEFAULT 5,
    reset_policy    text NOT NULL DEFAULT 'yearly' CHECK (reset_policy IN ('never','yearly')),
    assign_on       text NOT NULL CHECK (assign_on IN ('save','approve')),  -- مرئي: الفاتورة عند الاعتماد، السند والقيد عند الحفظ
    period_year     smallint NOT NULL DEFAULT 0,  -- السنة الحالية للتسلسل (0 = لا إعادة ضبط)
    next_value      bigint NOT NULL DEFAULT 1,
    PRIMARY KEY (company_id, entity, period_year)
);

CREATE TABLE account (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           bigint NOT NULL REFERENCES company(id),
    parent_id            bigint REFERENCES account(id),
    code                 varchar(12) NOT NULL,                 -- مرئي: أرقام فقط maxlength=8؛ الفرعي التلقائي "1201-001" (12 لاستيعاب اللاحقة)
    name_ar              varchar(100) NOT NULL,
    name_en              varchar(100),
    type                 text NOT NULL CHECK (type IN ('asset','liability','equity','income','expense')),
    subtype              text NOT NULL DEFAULT 'general' CHECK (subtype IN ('general','cash','bank','receivable','payable','inventory')),
    nature               text NOT NULL CHECK (nature IN ('debit','credit')),
    is_group             boolean NOT NULL DEFAULT false,       -- تجميعي: لا يقبل قيوداً (مرئي)
    requires_cost_center boolean NOT NULL DEFAULT false,
    frozen               boolean NOT NULL DEFAULT false,
    role                 varchar(30) CHECK (role IN ('treasury','bank','clients','stores','suppliers','vat_output','vat_input',
                             'other_payables','retained_earnings','opening_balance','sales','sales_cost','purchases','fx_gain_loss',
                             'sales_discount')),               -- مرئي (المرجع يسمّي vat_output "income_tax" و fx_gain_loss "fx")
    party_type           text CHECK (party_type IN ('customer','supplier')),  -- للحساب الفرعي التلقائي
    level                smallint NOT NULL,                    -- مرئي: 1، 2، 4 (+ فرعي تلقائي)
    created_at           timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, code)
);
CREATE UNIQUE INDEX account_role_unique ON account (company_id, role) WHERE role IS NOT NULL;  -- [مُستنتج] دور واحد = حساب واحد
CREATE INDEX account_parent_idx ON account (parent_id);

CREATE TABLE account_routing (
    company_id  bigint NOT NULL REFERENCES company(id),
    operation   varchar(60) NOT NULL,   -- sales_revenue, sales_tax, receivables, purchase_free_text, purchase_tax, payables,
                                        -- inventory, cogs, item_opening_balance, stock_count_variance
    mode        text NOT NULL CHECK (mode IN ('auto','auto_subaccount_per_party','auto_create_on_first_use','none')),  -- مرئي
    account_id  bigint REFERENCES account(id),
    PRIMARY KEY (company_id, operation),
    CHECK (mode IN ('none','auto_create_on_first_use') OR account_id IS NOT NULL)
);

CREATE TABLE cost_center (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  bigint NOT NULL REFERENCES company(id),
    parent_id   bigint REFERENCES cost_center(id),     -- [مُستنتج] المرجع مستوى واحد
    name        varchar(100) NOT NULL,
    UNIQUE (company_id, name)
);

CREATE TABLE tax (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         bigint NOT NULL REFERENCES company(id),
    name               varchar(100) NOT NULL,
    rate               numeric(7,4) NOT NULL CHECK (rate >= 0),
    category           char(1) NOT NULL CHECK (category IN ('S','Z','E','O')),        -- مرئي: S/Z/E (O غير موجود في المرجع)
    exemption_code     varchar(20),                                                    -- مرئي: VATEX-SA-...
    exemption_text     varchar(200),
    output_account_id  bigint REFERENCES account(id),
    input_account_id   bigint REFERENCES account(id),
    CHECK (category = 'S' OR exemption_code IS NOT NULL),                              -- [مُستنتج] من متطلبات الفوترة الإلكترونية
    CHECK (category <> 'S' OR rate > 0)
);

CREATE TABLE customer (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      bigint NOT NULL REFERENCES company(id),
    code            varchar(20) NOT NULL,                     -- مرئي: C-0001 تلقائي
    name            varchar(150) NOT NULL,
    kind            text NOT NULL DEFAULT 'registered' CHECK (kind IN ('registered','individual')),
    vat_number      varchar(15) CHECK (vat_number ~ '^3[0-9]{13}3$'),
    cr_number       varchar(10),
    id_type         varchar(10),                              -- [مُستنتج] CRN/700/... غير موجود في المرجع
    is_government   boolean NOT NULL DEFAULT false,           -- [مُستنتج]
    building_no     varchar(4) CHECK (building_no ~ '^[0-9]{4}$'),
    street          varchar(200),
    district        varchar(100),
    city            varchar(100),
    postal_code     varchar(5) CHECK (postal_code ~ '^[0-9]{5}$'),
    additional_no   varchar(4),
    phone           varchar(20),
    email           varchar(254),
    credit_limit    numeric(18,4) CHECK (credit_limit >= 0),
    payment_terms   integer NOT NULL DEFAULT 30 CHECK (payment_terms >= 0),
    status          text NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),  -- مرئي من فلتر القائمة
    account_id      bigint NOT NULL REFERENCES account(id),   -- الحساب الفرعي التلقائي تحت دور clients
    created_by      bigint REFERENCES app_user(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, code),
    CHECK (kind = 'individual' OR vat_number IS NOT NULL)     -- [مُستنتج] المنشأة المسجّلة تحتاج رقماً ضريبياً
);

CREATE TABLE supplier (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      bigint NOT NULL REFERENCES company(id),
    code            varchar(20) NOT NULL,                     -- مرئي: S-0001
    name            varchar(150) NOT NULL,
    vat_number      varchar(15) CHECK (vat_number ~ '^3[0-9]{13}3$'),
    iban            varchar(34),                              -- معروض في القائمة، غير موجود في النموذج
    currency        char(3) NOT NULL DEFAULT 'SAR',           -- [مُستنتج] رصيد بعملة أجنبية في القائمة
    payment_terms   integer NOT NULL DEFAULT 30 CHECK (payment_terms >= 0),
    account_id      bigint NOT NULL REFERENCES account(id),
    created_by      bigint REFERENCES app_user(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, code)
);

CREATE TABLE salesman (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  bigint NOT NULL REFERENCES company(id),
    user_id     bigint REFERENCES app_user(id),
    name        varchar(150) NOT NULL
);

CREATE TABLE unit_of_measure (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  bigint NOT NULL REFERENCES company(id),
    name        varchar(30) NOT NULL,                         -- مرئي: حبة، كرتون، ساعة
    UNIQUE (company_id, name)
);

CREATE TABLE item (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          bigint NOT NULL REFERENCES company(id),
    code                varchar(20) NOT NULL,
    name                varchar(200) NOT NULL,
    type                text NOT NULL DEFAULT 'stock' CHECK (type IN ('stock','service')),
    uom_id              bigint NOT NULL REFERENCES unit_of_measure(id),
    barcode             varchar(50),
    sale_price          numeric(18,4) CHECK (sale_price >= 0),
    purchase_price      numeric(18,4) CHECK (purchase_price >= 0),
    tax_id              bigint REFERENCES tax(id),
    revenue_account_id  bigint REFERENCES account(id),
    cogs_account_id     bigint REFERENCES account(id),
    reorder_level       integer CHECK (reorder_level >= 0),
    avg_cost            numeric(18,4) NOT NULL DEFAULT 0,     -- [مُستنتج] متوسط مرجّح متحرك (Q-30)
    qty_on_hand         numeric(18,3) NOT NULL DEFAULT 0,     -- لقطة أداء؛ المصدر الحقيقي stock_movement
    created_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, code),
    UNIQUE (company_id, barcode)
);

CREATE TABLE journal_entry (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      bigint NOT NULL REFERENCES company(id),
    number          varchar(30),                              -- JE-YYYY-NNNNN عند الحفظ
    entry_date      date NOT NULL,
    reference       varchar(100),
    memo            varchar(500) NOT NULL,
    source_type     text NOT NULL DEFAULT 'manual' CHECK (source_type IN ('manual','sales_invoice','sales_invoice_cost',
                        'receipt','purchase_invoice','item_opening','credit_note','fx_revaluation','year_close')),
    source_id       bigint,
    status          text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted')),
    is_recurring    boolean NOT NULL DEFAULT false,           -- مرئي: "قيد متكرر شهرياً"
    recurring_parent_id bigint REFERENCES journal_entry(id),
    reversal_of_id  bigint REFERENCES journal_entry(id),      -- [مُستنتج] لا عكس في المرجع؛ نضيفه
    posted_at       timestamptz,
    posted_by       bigint REFERENCES app_user(id),
    created_by      bigint REFERENCES app_user(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, number)
);
CREATE INDEX journal_entry_source_idx ON journal_entry (source_type, source_id);
CREATE INDEX journal_entry_date_idx ON journal_entry (company_id, entry_date);

CREATE TABLE journal_line (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    entry_id        bigint NOT NULL REFERENCES journal_entry(id) ON DELETE CASCADE,
    line_no         smallint NOT NULL,
    account_id      bigint NOT NULL REFERENCES account(id),
    debit           numeric(18,4) NOT NULL DEFAULT 0 CHECK (debit >= 0),
    credit          numeric(18,4) NOT NULL DEFAULT 0 CHECK (credit >= 0),
    currency_code   char(3) NOT NULL DEFAULT 'SAR',
    exchange_rate   numeric(18,6) NOT NULL DEFAULT 1 CHECK (exchange_rate > 0),
    debit_base      numeric(18,4) NOT NULL DEFAULT 0,
    credit_base     numeric(18,4) NOT NULL DEFAULT 0,
    cost_center_id  bigint REFERENCES cost_center(id),
    memo            varchar(500),
    CHECK ((debit = 0) <> (credit = 0)),                      -- مدين أو دائن في السطر، لا كلاهما ولا صفر
    UNIQUE (entry_id, line_no)
);
CREATE INDEX journal_line_account_idx ON journal_line (account_id);

CREATE TABLE sales_invoice (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          bigint NOT NULL REFERENCES company(id),
    number              varchar(30),                          -- NULL في المسودة؛ INV-YYYY-NNNNN عند الإصدار (مرئي)
    customer_id         bigint NOT NULL REFERENCES customer(id),
    invoice_date        date NOT NULL,
    reference           varchar(200),
    prices_include_tax  boolean NOT NULL DEFAULT false,
    doc_discount        numeric(18,4) NOT NULL DEFAULT 0 CHECK (doc_discount >= 0),
    salesman_id         bigint REFERENCES salesman(id),
    subtotal            numeric(18,4) NOT NULL DEFAULT 0,     -- net بعد خصم المستند
    tax_total           numeric(18,4) NOT NULL DEFAULT 0,
    total               numeric(18,4) NOT NULL DEFAULT 0,
    paid_amount         numeric(18,4) NOT NULL DEFAULT 0,
    status              text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','issued','partially_paid','paid','cancelled')),
    delivery_status     text NOT NULL DEFAULT 'undelivered' CHECK (delivery_status IN ('undelivered','delivered')),
    -- حقول الفوترة الإلكترونية [مُستنتج — غير مطبّقة في المرجع، مطلوبة في السعودية]
    invoice_type_code   char(3) NOT NULL DEFAULT '388',
    invoice_subtype     varchar(7),                           -- 01xxxxx قياسية / 02xxxxx مبسطة
    uuid                uuid,
    icv                 bigint,
    pih                 varchar(64),
    invoice_hash        varchar(64),
    qr_payload          text,
    zatca_status        text CHECK (zatca_status IN ('pending','cleared','reported','rejected','warning')),
    issued_at           timestamptz,
    created_by          bigint REFERENCES app_user(id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, number),
    CHECK (status = 'draft' OR number IS NOT NULL),
    CHECK (paid_amount >= 0 AND paid_amount <= total),
    CHECK (status <> 'partially_paid' OR (paid_amount > 0 AND paid_amount < total)),   -- يمنع AN-02
    CHECK (status <> 'paid' OR paid_amount = total),
    CHECK (status <> 'draft' OR paid_amount = 0)
);

CREATE TABLE sales_invoice_line (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id      bigint NOT NULL REFERENCES sales_invoice(id) ON DELETE CASCADE,
    line_no         smallint NOT NULL,
    item_id         bigint REFERENCES item(id),               -- NULL = بند نصي حر
    description     varchar(500),
    qty             numeric(18,3) NOT NULL CHECK (qty > 0),
    unit_price      numeric(18,4) NOT NULL CHECK (unit_price >= 0),
    tax_id          bigint NOT NULL REFERENCES tax(id),
    tax_rate        numeric(7,4) NOT NULL,                    -- لقطة النسبة وقت الإصدار
    discount_alloc  numeric(18,4) NOT NULL DEFAULT 0,         -- نصيب السطر من خصم المستند [مُستنتج] (Q-40)
    net_amount      numeric(18,4) NOT NULL,
    tax_amount      numeric(18,4) NOT NULL,
    line_total      numeric(18,4) NOT NULL,
    unit_cost       numeric(18,4),                            -- لقطة التكلفة للبند المخزني
    UNIQUE (invoice_id, line_no),
    CHECK (item_id IS NOT NULL OR (description IS NOT NULL AND length(description) > 0))  -- الوصف إلزامي للبند الحر (مرئي كتلميح)
);

CREATE TABLE receipt (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      bigint NOT NULL REFERENCES company(id),
    number          varchar(30) NOT NULL,                     -- RCV-YYYY-NNNNN عند الحفظ
    invoice_id      bigint NOT NULL REFERENCES sales_invoice(id),
    receipt_date    date NOT NULL,
    amount          numeric(18,4) NOT NULL CHECK (amount >= 0.01),
    account_id      bigint NOT NULL REFERENCES account(id),   -- treasury / bank
    journal_entry_id bigint REFERENCES journal_entry(id),
    created_by      bigint REFERENCES app_user(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, number)
);

CREATE TABLE purchase_invoice (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          bigint NOT NULL REFERENCES company(id),
    number              varchar(30),
    supplier_id         bigint NOT NULL REFERENCES supplier(id),
    supplier_invoice_no varchar(50) NOT NULL,
    invoice_date        date NOT NULL DEFAULT CURRENT_DATE,
    currency_code       char(3) NOT NULL DEFAULT 'SAR',       -- [مُستنتج] غير موجود في نموذج المرجع
    exchange_rate       numeric(18,6) NOT NULL DEFAULT 1 CHECK (exchange_rate > 0),
    subtotal            numeric(18,4) NOT NULL DEFAULT 0,
    tax_total           numeric(18,4) NOT NULL DEFAULT 0,
    total               numeric(18,4) NOT NULL DEFAULT 0,
    paid_amount         numeric(18,4) NOT NULL DEFAULT 0,
    status              text NOT NULL DEFAULT 'issued' CHECK (status IN ('issued','partially_paid','paid','cancelled')),
    created_by          bigint REFERENCES app_user(id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, supplier_id, supplier_invoice_no)     -- [مُستنتج] (Q-36)
);

CREATE TABLE purchase_invoice_line (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id      bigint NOT NULL REFERENCES purchase_invoice(id) ON DELETE CASCADE,
    line_no         smallint NOT NULL,
    item_id         bigint REFERENCES item(id),
    description     varchar(500),
    qty             numeric(18,3) NOT NULL CHECK (qty > 0),
    unit_cost       numeric(18,4) NOT NULL CHECK (unit_cost >= 0),
    tax_id          bigint NOT NULL REFERENCES tax(id),
    tax_rate        numeric(7,4) NOT NULL,
    net_amount      numeric(18,4) NOT NULL,
    tax_amount      numeric(18,4) NOT NULL,
    line_total      numeric(18,4) NOT NULL,
    UNIQUE (invoice_id, line_no),
    CHECK (item_id IS NOT NULL OR (description IS NOT NULL AND length(description) > 0))
);

CREATE TABLE stock_movement (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      bigint NOT NULL REFERENCES company(id),
    item_id         bigint NOT NULL REFERENCES item(id),
    movement_date   date NOT NULL,
    qty             numeric(18,3) NOT NULL,                   -- موجب إدخال، سالب إخراج
    unit_cost       numeric(18,4) NOT NULL,
    source_type     text NOT NULL CHECK (source_type IN ('opening','purchase_invoice','sales_invoice','credit_note','adjustment')),
    source_id       bigint,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX stock_movement_item_idx ON stock_movement (item_id, movement_date);

-- غير موجود في المرجع؛ مطلوب لتصحيح الفواتير الصادرة (الفوترة الإلكترونية) [مُستنتج]
CREATE TABLE credit_note (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          bigint NOT NULL REFERENCES company(id),
    number              varchar(30) NOT NULL,
    original_invoice_id bigint NOT NULL REFERENCES sales_invoice(id),
    issue_reason        varchar(300) NOT NULL,
    note_date           date NOT NULL,
    subtotal            numeric(18,4) NOT NULL,
    tax_total           numeric(18,4) NOT NULL,
    total               numeric(18,4) NOT NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, number)
);

CREATE TABLE audit_log (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  bigint NOT NULL REFERENCES company(id),
    user_id     bigint REFERENCES app_user(id),
    action      varchar(30) NOT NULL,          -- create, update, delete, approve, post, ... (المرئي: اعتماد)
    entity_type varchar(40) NOT NULL,
    entity_id   bigint NOT NULL,
    entity_ref  varchar(50),
    before_data jsonb,                         -- [مُستنتج] غير معروض في المرجع؛ نوصي به
    after_data  jsonb,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_log_entity_idx ON audit_log (entity_type, entity_id);

CREATE TABLE saved_report (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  bigint NOT NULL REFERENCES company(id),
    owner_id    bigint REFERENCES app_user(id),
    name        varchar(150) NOT NULL,
    source      text NOT NULL CHECK (source IN ('invoices','journal')),   -- مرئي
    definition  jsonb NOT NULL                                            -- الأعمدة والتجميع والفلاتر [مُستنتج]
);

-- توازن القيد عند الترحيل (مؤجّل حتى نهاية المعاملة)
CREATE FUNCTION check_entry_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_entry bigint := COALESCE(NEW.entry_id, OLD.entry_id);
    v_status text;
    v_diff numeric;
    v_count int;
BEGIN
    SELECT status INTO v_status FROM journal_entry WHERE id = v_entry;
    IF v_status = 'posted' THEN
        SELECT COALESCE(SUM(debit_base - credit_base), 0), COUNT(*) INTO v_diff, v_count
          FROM journal_line WHERE entry_id = v_entry;
        IF v_diff <> 0 OR v_count < 2 THEN
            RAISE EXCEPTION 'القيد % غير متوازن (الفرق %)', v_entry, v_diff;
        END IF;
    END IF;
    RETURN NULL;
END $$;

CREATE CONSTRAINT TRIGGER journal_line_balanced
    AFTER INSERT OR UPDATE OR DELETE ON journal_line
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_entry_balanced();

-- منع الترحيل في فترة مقفلة، ومنع القيد على حساب تجميعي أو مجمّد
CREATE FUNCTION check_entry_postable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status = 'posted' THEN
        IF EXISTS (SELECT 1 FROM fiscal_period p WHERE p.company_id = NEW.company_id AND p.status = 'closed'
                     AND NEW.entry_date BETWEEN p.start_date AND p.end_date) THEN
            RAISE EXCEPTION 'لا يمكن الترحيل في فترة مقفلة (%)', NEW.entry_date;
        END IF;
        IF EXISTS (SELECT 1 FROM journal_line l JOIN account a ON a.id = l.account_id
                    WHERE l.entry_id = NEW.id AND (a.is_group OR a.frozen)) THEN
            RAISE EXCEPTION 'لا يمكن الترحيل على حساب تجميعي أو مجمّد';
        END IF;
        PERFORM 1 FROM journal_line WHERE entry_id = NEW.id;  -- إعادة فحص التوازن عند تغيير الحالة
        IF (SELECT COALESCE(SUM(debit_base - credit_base), 0) FROM journal_line WHERE entry_id = NEW.id) <> 0 THEN
            RAISE EXCEPTION 'القيد غير متوازن';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entry_postable
    BEFORE INSERT OR UPDATE OF status, entry_date ON journal_entry
    FOR EACH ROW EXECUTE FUNCTION check_entry_postable();

-- المستندات الصادرة غير قابلة للحذف (تُصحَّح بإشعار دائن) — يمنع AN-04
CREATE FUNCTION prevent_issued_invoice_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'لا يمكن حذف فاتورة صادرة؛ استخدم إشعاراً دائناً';
    END IF;
    RETURN OLD;
END $$;
CREATE TRIGGER sales_invoice_no_delete BEFORE DELETE ON sales_invoice
    FOR EACH ROW EXECUTE FUNCTION prevent_issued_invoice_delete();

-- عزل الشركات (يُفعَّل بعد ضبط app.company_id لكل اتصال)
ALTER TABLE customer ENABLE ROW LEVEL SECURITY;
CREATE POLICY customer_company_isolation ON customer
    USING (company_id = current_setting('app.company_id', true)::bigint);
-- كرّر السياسة لكل جدول يحوي company_id.

COMMIT;
