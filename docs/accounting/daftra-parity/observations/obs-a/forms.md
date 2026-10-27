# R-03 forms: Daftra add/edit forms, field lists, rules and gating (read-only observation)

Account: https://opes.daftra.com (trial, owner role "New Beauty", Arabic UI), observed 2026-10-27. Area folder: `/home/user/daftra-tools/out/forms/`.

## 0. How this was done

- Everything was opened with GET only (Playwright, chromium, saved session; credentials never printed or stored). A network guard (`flib.mjs open()`) aborted every non-GET request to opes.daftra.com and every document navigation whose address matched logout|delete|remove|cancel|change_|renew. No Save/Submit/Delete/Approve/Pay button was pressed; select/checkbox/radio were changed locally only to see which fields appear, and the page was reloaded afterwards. `changedAnything = false`.
- The guard log `_nonget-requests.log` lists the ajax POSTs the pages tried to send while probing (numbering_eval_prefix on the numbering page, cost_centers/get_auto_for_account and add_cost_center on the journal form); they were blocked and nothing was written.
- Per form: (1) `<name>.json` = DOM controls after load (+ tabs, dropdown menus); (2) `p-<name>.probe.json` = controls with required markers, defaults, option lists, section/tab, and an automatic conditional-visibility probe (every select with 2-14 options, every checkbox/radio, every tab and collapse); (3) `src-<name>.html` and `src-<name>.inline.js` = served page source (used to read validation rules and logic); (4) `js/*.js` = the static scripts the pages load (new-invoice-v2, new-purchase_orders-v2, product-add, add_client, assets, assets_next_dep, ...); (5) screenshots `<name>.png`; (6) interactive probes `act-*.json/png` for selectize widgets and tabs.
- Column legend for the field tables: `req` = `*` the label shows the red asterisk; `cls` = the input has class "required" (client rule notEmpty, message «مطلوب»); both empty = optional. `at load` = whether the control was visible right after load (custom-styled checkboxes and selectize inputs often report "hidden" although they are displayed; inactive tab panes and collapsed sections are also "hidden").
- Validation messages: nothing is validated on blur (tested on the client form); all messages in this file are read from the page's embedded dictionary and form scripts, not from a submit.

## 1. Account state that gates the forms (what is on, what is off)

Settings pages captured: `st-*.json/txt/png` (invoices, estimates, sales-order, purchase-invoices, purchase-cycle, inventory, product-settings, clients, finance-general, accounting-general, taxes, numbering, categories, brands, unit templates, custom fields, plugin manager). Key states: sales orders OFF; purchase cycle OFF; sales debit note OFF; purchase credit note OFF; multi-units OFF; bundles OFF; serial/batch/expiry tracking OFF; stock requests and permit-linking to invoices OFF; client opening balance / credit limit / credit period / photo / national id / birth date / gender / barcode / link / multiple addresses / map OFF; branches app OFF; cheques app OFF; estimates ON; advanced pricing ON; journal tax column ON; journal cost-centre column ON.

- **pw-forms-1** (observed) Plugin manager (/v2/owner/plugin-manager/2) shows enabled (تم التفعيل): المبيعات، الفاتورة الإلكترونية السعودية، إدارة المخزون، المالية، الحسابات العامه & القيود اليومية، العملاء، الموظفين; and disabled (تم التعطيل): دورة المشتريات، دورة الشيكات، الفروع، التصنيع، نقاط البيع، ادارة الاقساط، العروض، المبيعات المستهدفة و العمولات، SMS، المتجر الإلكترونى.  
  evidence: `st-plugin-manager.txt`
- **pw-forms-2** (observed) Purchase cycle (purchase request, quotation request, purchase quotation, purchase order) is a disabled app: /owner/settings/purchase_cycle only has four unchecked toggles (data[72][enable_purchase_request_manual_status], enable_quotation_request_manual_status, enable_purchase_quotation_manual_status, enable_purchase_order_manual_status) labelled حالات طلبات الشراء / حالات طلبات عروض الأسعار / حالات عروض أسعار المشتريات / حالات أوامر الشراء (custom statuses per document). The add forms of these four documents could not be opened.  
  evidence: `st-purchase-cycle.json`
- **pw-forms-3** (observed) GET of /v2/owner/entity/purchase_request/list, purchase_quotation/list and quotation_request/list redirected to the dashboard "/" (HTTP 200, title لوحة التحكم) while a control URL with a non-existent entity (/v2/owner/entity/zzz_nonexistent/list) returned HTTP 500 and the legacy guesses /owner/purchase_requests/add etc. returned 404.  
  evidence: `_url-probes.log`
- **pw-forms-4** (observed) In Daftra "فواتير الشراء" is the internal entity purchase_order: list /v2/owner/entity/purchase_order/list, add /owner/purchase_invoices/add (form PurchaseOrderForm, hidden data[PurchaseOrder][type]=0) and a second React create page /v2/owner/entity/purchase_order/create titled "إضافة | فاتورة الشراء". A real purchase order (أمر شراء) of the purchase cycle is therefore not available in this account.  
  evidence: `p-pinv-add.probe.json`
- **pw-forms-5** (observed) Sales orders are disabled: /owner/invoices/sales_order_settings has the unchecked toggle data[Site][enable_sales_order] "نظام أوامر البيع" (dependent hidden controls: next_sales_order_number 00001, exceeding_the_sales_order_qty_in_the_sales_invoices "مسموح", apply_offers_on_sales_orders). The list /v2/owner/entity/sales_order/list shows the empty state "لم يتم إضافة اى سجلات بعد" and its button "أضف أمر البيع" links to /owner/invoices/add_sales_order, which redirected to /owner/invoices/index.  
  evidence: `st-sales-order.json`
- **pw-forms-6** (observed) GET /owner/sales_orders/add renders the ordinary sales invoice form (title "فاتورة جديدة", form InvoiceForm posting to /owner/invoices/add, hidden data[Invoice][type]=0) with exactly the same field names and defaults as /owner/invoices/add (only the CSRF token differs): the sales-order add form is not reachable while the module is off.  
  evidence: `p-salesorder-add.probe.json`
- **pw-forms-8** (observed) Purchase credit note ("إشعار دائن" on the purchase side) is off: data[72][enable_purchase_credit_note] is unchecked in /owner/settings/purchase_invoices (next number field next_cn_number 00001 disabled); GET /owner/purchase_invoices/add_credit_note redirected to /v2/owner/entity/purchase_order/list. Sales debit note ("اشعار مدين") is off too: data[Site][enable_debit_note] unchecked in /owner/invoices/settings (next_debit_note_number 00001).  
  evidence: `st-purchase-invoices.json`
- **pw-forms-9** (observed) Multiple units per item (data[72][enable_multi_units]), bundles (data[72][enable_bundles], page text beside it "مسموح") and serial/batch/expiry tracking (data[72][enable_tracking_numbers]) are all unchecked in /owner/settings/inventory; the product add form therefore renders no unit, bundle or tracking control. The settings page also exposes unchecked data[72][enable_stock_requests], enable_requisitions (sales) and enable_requisitions_po (purchases), and the negative-stock radio negativeInventoryOption is on "السماح فقط للمنتجات القابلة للتتبع بالكميات".  
  evidence: `st-inventory.json`
- **pw-forms-10** (observed) /owner/clients/settings has these financial and identity toggles all unchecked: data[1][starting_balance] أرصدة افتتاحية، data[1][enable_client_credit_limit] الحد الائتماني، data[1][client_credit_period] المدة الائتمانية، data[1][photo]، national_id، birth_date، gender، barcode، link، multiple_addresses، map_location; client type setting is "فردي وتجاري معًا" (value 0); next client number data[1][next_auto_number] 000005 is disabled/readonly. The client add form therefore has no opening balance, credit limit, credit period, photo, national id, birth date, gender, barcode, link, multiple-address or map fields in its DOM.  
  evidence: `st-clients.json`
- **pw-forms-11** (observed) No records exist for: recurring invoices (list text "لم يتم" + "اضغط هنا"), stock permits, stocktakings, receipt vouchers (سندات القبض), fixed assets, sales orders, price lists, unit templates, product categories, brands, client categories, income categories. Edit forms for these could not be opened. Existing records allowed edit forms for: estimate 13, purchase invoice 27, debit note 7, purchase refund 6, expense 1, transfer 1, treasury 1 and 3, journal 134, client 1, supplier 1, product 1.  
  evidence: `list-subscriptions.txt`

Records that exist in the account (so which edit forms could be opened): estimates 1, purchase invoices 22, debit notes 2, purchase refunds 2, expenses 2, treasuries 3, journals 126, suppliers 7, clients 4, products 9. Nothing exists for: recurring invoices, sales orders, stock permits, stocktakings, receipt vouchers, assets, price lists, unit templates, categories, brands.

## 2. Engine-wide behaviour (all forms)

- **pw-forms-12** (observed) Required fields are marked in the label with a red "*" (label text contains " *"); inputs also carry class "required" (client-side rule notEmpty, message «مطلوب» / "Required") or data-form-rules="required" (asset form). Nothing is validated on blur: focusing and tabbing out of the required client fields produced no message; messages appear only on submit (not tested, read-only).  
  evidence: `val-client-add.png`
- **pw-forms-13** (observed) The page-embedded translation dictionary contains the validation messages used by the sales/purchase/journal/asset forms, e.g. «مطلوب»، «هذا الحقل مطلوب»، «هذا الحقل مطلوب ولا يمكن ان يكون فارغ»، «يجب إدخال رقم بشكل صحيح»، «يجب إدخال رقم موجب»، «يجب إدخال تاريخ صحيح»، «مطلوب بريد إلكتروني صالح»، «يمكنك إضافة حتى 500 عنصر فقط»، «يمكنك إضافة حتي 10 ملفات فقط»، «يمكنك إضافة حتي 10 تنبيهات»، «لا يمكنك تحديد نفس الضريبة في مدخلات ضريبة 1 وضريبة 2»، «الايام يجب أن تكون أقل من فترة الاشتراك»، «إجمالي المدين يجب أن يكون مساوياَ لإجمالي الدائن»، «يجب أن تضيف على الأقل حركتان للقيد»، «من فضلك قم بحفظ بيانات العميل بالضغط على حفظ العميل».  
  evidence: `_i18n-validation.txt`
- **pw-forms-14** (observed) Sales document drafts are auto-kept in the browser: when a new (non-edit) sales document has at least 3 item rows and a total, calculateTotals() debounced 1 s adds ?draftId=<timestamp> to the URL, appends hidden data[non_completed_id] and stores the draft in localStorage (JJLC); the journal form also reads ?draftId and posts data[non_completed_id].  
  evidence: `js/new-invoice-v2_v01266.js`
- **pw-forms-15** (observed) Save menus: sales documents (invoice, estimate, credit note) have "حفظ كـ مسودة" + "حفظ دون طباعة" with a dropdown "حفظ دون إرسال | إرسال مع تعديل البريد | حفظ وطباعة | حفظ وإضافة جديد" (invoice/credit note) and a preview dropdown "معاينة على المتصفح | عرض PDF"; the recurring invoice has only "معاينة" and "حفظ"; purchase documents have "معاينة" + "حفظ كمسودة" + "حفظ"; expense/income/journal have "حفظ كمسودة" + "حفظ" + "خيارات اخرى" (expense/income: "حفظ & طباعة | حفظ & إضافة جديدة", journal: "حفظ & إضافة جديدة"); product has "حفظ" with dropdown "حفظ & إضافة جديدة"; stock permit "تأكيد" with dropdown "تأكيد & إضافة جديدة". Edit forms of issued purchase documents show only "معاينة" and "تحديث".  
  evidence: `p-invoice-add.probe.json`
- **pw-forms-16** (observed) Line-grid dropdown "نسخ من جدول البيانات" (paste from spreadsheet) exists on journal lines, purchase documents and stock permits; the per-document rich text editor (html_notes, الملاحظات/الشروط) is Summernote with font-size menu 8,9,10,11,12,14,18,24,36.  
  evidence: `journal-add.json`
- **pw-forms-17** (observed) Document numbering types behind the "إعدادات الترقيم المتسلسل" links (numbering/settings/<id>) read from the inline handlers: 17 = stocktaking (autoNumberSettingsStocktaking), 15 = stock transfer permit (…Requisition), 13 = outbound permit, 12 = inbound permit; the only link on the estimate settings page is 2, on the sales-order page 32, on the clients page 0. The numbering page has next_number, mode (numeric digits), digit_no (6), char_no, require_unique, add_prefix + prefix_format and prefix_serial_option (0 continue after last number, 1 separate sequence per prefix, 2 reset on prefix change).  
  evidence: `st-numbering.json`
- **pw-forms-18** (inference) The invoice settings page links numbering/settings/1 and 35 and the purchase settings page 5 and 36; the nearby fields are the invoice/PO number and the debit-note / credit-note next number (SiteNextDebitNoteNumber, 72NextCnNumber), so 1 and 5 are most likely invoices and purchase invoices and 35 and 36 the sales debit note and purchase credit note (order inferred, not proven).  
  evidence: `src-set-invoices.html`
- **pw-forms-19** (observed) Next numbers in this account: client 000005, supplier 000008, product 000010, sales invoice 000028, estimate 000002, purchase invoice 000025, purchase debit note 00003, purchase refund 000003, manual/auto journal 000135, expense 000003, receipt voucher 000001, asset code 000001, stocktaking 000001, stock permit 000001.  
  evidence: `p-pinv-add.probe.json`

## 3. Sales order (أمر البيع) - add form not reachable; raw schema only

Opened: /owner/invoices/add_sales_order (official add button), /owner/sales_orders/add, /v2/owner/entity/sales_order/list, /v2/owner/entity/sales_order/create, /owner/invoices/sales_order_settings. Evidence: `p-salesorder-add.probe.json`, `v2-sales-order-create.probe.json`, `st-sales-order.json`, `salesorder-list.json`, `_url-probes.log`.

Raw React schema fields (non-hidden controls), as rendered by /v2/owner/entity/sales_order/create:

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| معلومات أمر البيع | Item Columns | item_columns | text |  |  |  | shown |
| معلومات أمر البيع | Shipping Fees | shipping_amount | number |  |  |  | shown |
| معلومات أمر البيع | خصم عام (مبلغ) | discount_amount | number |  |  |  | shown |
| معلومات أمر البيع | تفاصيل اضافيه | extra_details | text |  |  |  | shown |
| معلومات أمر البيع | Show Client Shipping Address | client_active_secondary_address | checkbox |  | unchecked |  | shown |
| معلومات أمر البيع | مصدر الطلب | order_source_id | select-one |  | (empty) | = | hidden/other tab |
| معلومات أمر البيع | قيمة التسوية | adjustment_value | number |  |  |  | shown |
| معلومات أمر البيع | Adjustment Label | adjustment_label | text |  |  |  | shown |
| معلومات أمر البيع | الأذون المخزنية | requisitions[] | select-multiple |  |  |  | hidden/other tab |
| معلومات أمر البيع | كود العملة | currency_code | text |  |  |  | shown |
| معلومات أمر البيع | Client state | client_state | text |  |  |  | shown |
| معلومات أمر البيع | Client city | client_city | text |  |  |  | shown |
| معلومات أمر البيع | Client postal code | client_postal_code | text |  |  |  | shown |
| معلومات أمر البيع | Client address 2 | client_address2 | text |  |  |  | shown |
| معلومات أمر البيع | Client address | client_address1 | text |  |  |  | shown |
| معلومات أمر البيع | Client email | client_email | email |  |  |  | shown |
| معلومات أمر البيع | Client Last Name | client_last_name | text |  |  |  | shown |
| معلومات أمر البيع | Client First Name | client_first_name | text |  |  |  | shown |
| معلومات أمر البيع | Client Business Name | client_business_name | text |  |  |  | shown |
| معلومات أمر البيع | Client country code | client_country_code | text |  |  |  | shown |
| معلومات أمر البيع | Is Offline | is_offline | checkbox |  | unchecked |  | shown |
| معلومات أمر البيع | العميل * | client_id | select-one | * | (empty) | = | hidden/other tab |
| معلومات أمر البيع | P.O. Number | po_number | text |  |  |  | shown |
| معلومات أمر البيع | المستودع | store_id | select-one |  | (empty) | = | hidden/other tab |
| معلومات أمر البيع | رقم الفاتورة * | no[code] | text | * | 000001 |  | shown |
| معلومات أمر البيع | خيار شحن | shipping_option_id | select-one |  | (empty) | = | hidden/other tab |
| معلومات أمر البيع | Invoice Layout | invoice_layout_id | select-one |  | (empty) | = | hidden/other tab |
| معلومات أمر البيع | Require Terms Approval | required_terms_file | checkbox |  | unchecked |  | shown |
| معلومات أمر البيع | ملاحظات | html_notes | textarea |  |  |  | shown |
| معلومات أمر البيع | قيمة القسط | due_after | number |  |  |  | shown |
| معلومات أمر البيع | خصم عام (نسبة) | discount | number |  |  |  | shown |
| معلومات أمر البيع | Is Draft | draft | checkbox |  | unchecked |  | shown |
| معلومات أمر البيع | الوثيقة | (widget, no name) | file |  |  |  | hidden/other tab |


**Facts**

- **pw-forms-5** (observed) Sales orders are disabled: /owner/invoices/sales_order_settings has the unchecked toggle data[Site][enable_sales_order] "نظام أوامر البيع" (dependent hidden controls: next_sales_order_number 00001, exceeding_the_sales_order_qty_in_the_sales_invoices "مسموح", apply_offers_on_sales_orders). The list /v2/owner/entity/sales_order/list shows the empty state "لم يتم إضافة اى سجلات بعد" and its button "أضف أمر البيع" links to /owner/invoices/add_sales_order, which redirected to /owner/invoices/index.  
  evidence: `st-sales-order.json`
- **pw-forms-6** (observed) GET /owner/sales_orders/add renders the ordinary sales invoice form (title "فاتورة جديدة", form InvoiceForm posting to /owner/invoices/add, hidden data[Invoice][type]=0) with exactly the same field names and defaults as /owner/invoices/add (only the CSRF token differs): the sales-order add form is not reachable while the module is off.  
  evidence: `p-salesorder-add.probe.json`
- **pw-forms-7** (observed) The React page /v2/owner/entity/sales_order/create (title "إضافة | أمر البيع", section "معلومات أمر البيع") does render although the module is off; it is an auto-generated entity form exposing the raw schema: client_id (العميل *), no[code] (رقم الفاتورة * default 000001), date (التاريخ *), issue date (تاريخ الاصدار), due_after (labelled قيمة القسط), discount (خصم عام (نسبة)), discount_amount (خصم عام (مبلغ)), adjustment_label/adjustment_value, shipping_amount, shipping_option_id, store_id (المستودع), po_number, currency_code, order_source_id, requisitions[], html_notes, required_terms_file, draft ("Is Draft"), delivery date, client_* and client_secondary_* address fields, repeaters payment[n].*, invoice_item[n].* (item, description, unit_price, quantity, discount, discount_type, unit_factor_id, unit_factor, unit_name, unit_small_name, summary_tax1, summary_tax2, serials, tracking_data, col_3..col_8, display_order, sales_person_id, extra_details), invoice custom field and installment agreement repeaters.  
  evidence: `v2-sales-order-create.probe.json`

## 4. Recurring invoice (فاتورة دورية / اشتراك) - add form

URL /owner/invoices/add_subscription. Tabs: الخصم والتسوية, بيانات الشحن, إرفاق المستندات, التنبيهات الآلية. Buttons: معاينة (dropdown: معاينة على المتصفح / عرض PDF), حفظ.

Hidden fields: data[Invoice][extra_details]= ; data[Invoice][item_columns]= ; data[Invoice][subscription_id]= ; data[Invoice][summary_refund]= ; data[Invoice][sub_type]= ; data[Invoice][type]=0 ; data[Invoice][invoice_layout_id]=0 ; data[Invoice][active]=0 ; data[Invoice][subscription_notify]=0 ; data[Invoice][show_from_to]=0 ; data[Invoice][auto_payment]=0 ; data[Invoice][id]= ; data[Invoice][language_id]=7 ; data[Invoice][currency_code]=SAR ; data[Invoice][manual_currency_rate_hidden]= ; data[Client][id]= ; data[Invoice][client_client_number]= ; data[Invoice][client_type]= ; data[Invoice][client_is_government_entity]=0 ; data[Invoice][client_bn1_label]=الرقم الضريبي ; data[Invoice][client_bn2_label]=سجل تجارى/ رقم موحد ; data[Invoice][client_default_currency_code]= ; data[Client][client_from_data]=0 ; data[Invoice][address_id]= ; data[Invoice][group_price_id]= ; data[InvoiceItem][0][product_id]= ; data[InvoiceItem][0][org_name]= ; data[Invoice][store_id]=1 ; data[invoiceDocuments]= ; data[template-InvoiceDocument][%unid%][id]= ; data[template-InvoiceDocument][%unid%][document_id]=%id%

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | قالب الفاتورة | invoice_layout_id | select-one |  | التصميم الافتراضي للفاتورة | 0=التصميم الافتراضي للفاتورة ; -1=التصميم الافتراضي للوقت | shown |
| خيارات الإصدار الآلي | الاشتراك | data[Invoice][name] | text |  |  | maxlen 255 | shown |
| خيارات الإصدار الآلي | إصدار فاتورة كل | data[Invoice][subscription_period] | number |  | 1 |  | shown |
| خيارات الإصدار الآلي | إصدار فاتورة كل | data[Invoice][subscription_unit] | select-one |  | أشهر | days=أيام ; weeks=أسابيع ; months=أشهر ; years=سنوات | hidden/other tab |
| خيارات الإصدار الآلي | عدد مرات التكرار | data[Invoice][subscription_max_repeat] | number |  |  |  | shown |
| خيارات الإصدار الآلي | تاريخ أول فاتورة | data[Invoice][date] | text |  | 27/10/2026 |  | shown |
| خيارات الإصدار الآلي | أصدر الفاتورة قبل | data[Invoice][subscription_issue_before] | text |  | 0 |  | shown |
| خيارات الإصدار الآلي | نشط | data[Invoice][active] | checkbox |  | checked |  | hidden/other tab |
| خيارات الإصدار الآلي | أرسل لى نسخة من الفاتورة المنشأة | data[Invoice][subscription_notify] | checkbox |  | unchecked |  | hidden/other tab |
| خيارات الإصدار الآلي | عرض تاريخ "منذ" و"حتى" في الفاتورة | data[Invoice][show_from_to] | checkbox |  | unchecked |  | hidden/other tab |
| خيارات الإصدار الآلي | تفعيل الدفع التلقائي لهذه الفاتورة | data[Invoice][auto_payment] | checkbox |  | unchecked |  | hidden/other tab |
|  | الطريقة: | data[Invoice][is_offline] | select-one |  | طباعة | 0=أرسل عبر البريد ; 1=طباعة | hidden/other tab |
|  | العملة * | data[Invoice][currency_code] | select-one | * | SAR ريال سعودي | =العملة الأساسية ; AED=AED درهم إماراتي ; AFN=AFN أفغاني ; ALL=ALL ليك ألباني ; AMD=AMD درام أرميني ; ANG=ANG غويلدر الأنتيل الهولندية ; ... (161 options) | hidden/other tab |
|  | تحديث معلومات العميل | (widget, no name) | checkbox |  | unchecked |  | hidden/other tab |
|  | شروط الدفع | data[Invoice][due_after] | number |  |  |  | shown |
|  |  | data[InvoiceItem][0][item] | textarea | cls |  | ph="البند" | shown |
|  |  | data[InvoiceItem][0][description] | textarea | cls |  | ph="الوصف" | shown |
|  |  | data[InvoiceItem][0][unit_price] | number | cls |  | ph="سعر الوحدة" | shown |
|  |  | data[InvoiceItem][0][quantity] | number | cls |  | ph="الكمية" | shown |
|  |  | data[InvoiceItem][0][discount] | number |  |  | ph="الخصم" | shown |
|  |  | data[InvoiceItem][0][discount_type] | select-one | cls | % | 1=% ; 2=$ | shown |
|  |  | data[InvoiceItem][0][tax1] | select-one |  | بدون ضريبة | =بدون ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب ; ph="الضريبة 1" | shown |
|  |  | data[InvoiceItem][0][tax2] | select-one |  | بدون ضريبة | =بدون ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب ; ph="الضريبة 2" | hidden/other tab |
| tab الخصم والتسوية | الخصم | data[Invoice][discount] | number |  |  | ph="0" | shown |
| tab الخصم والتسوية | الخصم | data[Invoice][discount_amount] | number |  |  | ph="0" | hidden/other tab |
| tab الخصم والتسوية | التسوية | data[Invoice][adjustment_label] | text |  |  |  | shown |
| tab الخصم والتسوية | التسوية | data[Invoice][adjustment_value] | number |  |  | ph="0.00" | shown |
| tab بيانات الشحن | بيانات الشحن | data[Invoice][shipping_options] | select-one |  | آلي | =آلي ; 1=لا تظهر ; 2=أظهر عنوان العميل ; 3=أظهر عنوان العميل الثانوي | hidden/other tab |
| tab بيانات الشحن | مصاريف الشحن | data[Invoice][shipping_amount] | text |  |  |  | hidden/other tab |
| tab الملفات التي تم رفعها مسبقاً | المستند | doc | select-one |  | Select Document | =Select Document | hidden/other tab |
| tab tab-8 | الدفعة القادمه | data[Invoice][deposit] | number |  |  | ph="0" | hidden/other tab |
| tab tab-8 | الدفعة القادمه | data[Invoice][deposit_type] | select-one |  | المبلغ | 1=المبلغ | hidden/other tab |
|  |  | data[Invoice][html_notes] | textarea |  |  |  | hidden/other tab |
| 13 | Select from files | files | file |  |  |  | hidden/other tab |

Conditional visibility found by the probe:
- tab "بيانات الشحن": shows ["data[Invoice][shipping_options](بيانات الشحن)","data[Invoice][shipping_amount](مصاريف الشحن)"]; hides ["data[Invoice][discount](الخصم)","discount_type-selectized(الخصم)","data[Invoice][adjustment_label](التسوية)","data[Invoice][adjustment_value](التسوية)"]
- tab "إرفاق المستندات": shows []; hides ["data[Invoice][shipping_options](بيانات الشحن)","data[Invoice][shipping_amount](مصاريف الشحن)"]


**Facts**

- **pw-forms-20** (observed) Recurring invoice add form (/owner/invoices/add_subscription, title "إضافة اشتراك", InvoiceForm multipart, hidden data[Invoice][type]=0 and page flag IS_SUBSCRIPTION) = the sales invoice form plus a card "خيارات الإصدار الآلي": الاشتراك (data[Invoice][name], text 255, required), إصدار فاتورة كل (subscription_period number default 1 + subscription_unit select days=أيام/weeks=أسابيع/months=أشهر default/years=سنوات), عدد مرات التكرار (subscription_max_repeat), تاريخ أول فاتورة (data[Invoice][date]), أصدر الفاتورة قبل (subscription_issue_before default 0), checkboxes نشط (active, checked), أرسل لى نسخة من الفاتورة المنشأة (subscription_notify), عرض تاريخ "منذ" و"حتى" في الفاتورة (show_from_to), تفعيل الدفع التلقائي لهذه الفاتورة (auto_payment).  
  evidence: `p-subscription-add.probe.json`
- **pw-forms-21** (observed) Recurring invoice differences from the plain invoice form: no invoice number field, no issue-date field, no "التسليم" or "إيداع" tabs and no "مدفوع بالفعل" block; the deposit control is relabelled "الدفعة القادمه" (data[Invoice][deposit], deposit_type); the tab "التنبيهات الآلية" (#tab-2) is visible (hidden on the plain invoice); save buttons are only "معاينة" and "حفظ".  
  evidence: `p-subscription-add.probe.json`
- **pw-forms-22** (observed) Client-side rules of the recurring invoice (new-invoice-v2 JS): name required; subscription period numeric and not negative; max repeat numeric and not negative (may be empty); "أصدر الفاتورة قبل" must be numeric, not negative and less than the period in days where days=1, weeks=7, months=30, years=365 (message «الايام يجب أن تكون أقل من فترة الاشتراك»).  
  evidence: `js/new-invoice-v2_v01266.js`
- **pw-forms-23** (observed) Automatic reminders tab ("التنبيهات الآلية", up to 10 rows): empty state «لم تعد أي تنبيهات آلية حتى الآن / اضغط هنا لإنشاء تنبيه جديد», button "إضافة تذكير"; a row has data[InvoiceReminder][n][send_when] (options: 0=في تاريخ الإصدار, 1=في تاريخ الفاتورة, 2=في تاريخ الاستحقاق, 3=قبل ميعاد الفاتورة (عدد أيام), 4=بعد ميعاد الفاتورة (عدد أيام); placeholder اختر متى), [days] (visible only for 3 and 4, then required numeric not negative) and [email_template_id] (default option "استخدم القالب الافتراضي").  
  evidence: `act-subscription-reminders.png`
- **pw-forms-24** (observed) No recurring invoice exists in the account (list /owner/invoices/subscriptions empty with link "اضغط هنا لإنشاء فواتير دورية"), so the edit form and the statuses/columns of generated invoices could not be observed.  
  evidence: `list-subscriptions.txt`

## 5. Quote / estimate (عرض سعر) - add and edit

URL add /owner/invoices/add_estimate; edit /owner/invoices/edit_estimate/13. Buttons: معاينة (menu), حفظ كـ مسودة, حفظ دون طباعة (menu: حفظ دون إرسال / إرسال مع تعديل البريد / حفظ وطباعة / حفظ وإضافة جديد).

Hidden fields: data[Invoice][extra_details]= ; data[Invoice][item_columns]= ; data[Invoice][subscription_id]= ; data[Invoice][summary_refund]= ; data[Invoice][sub_type]= ; data[Invoice][type]=3 ; data[Invoice][invoice_layout_id]=0 ; data[Invoice][id]= ; data[Invoice][language_id]=7 ; data[Invoice][currency_code]=SAR ; data[Invoice][manual_currency_rate_hidden]= ; data[Client][id]= ; data[Invoice][client_client_number]= ; data[Invoice][client_type]= ; data[Invoice][client_is_government_entity]=0 ; data[Invoice][client_bn1_label]=الرقم الضريبي ; data[Invoice][client_bn2_label]=سجل تجارى/ رقم موحد ; data[Invoice][client_default_currency_code]= ; data[Client][client_from_data]=0 ; data[Invoice][address_id]= ; data[Invoice][group_price_id]= ; data[Invoice][hidden_no]=000002 ; data[InvoiceItem][0][product_id]= ; data[InvoiceItem][0][org_name]= ; data[Invoice][store_id]=1 ; data[invoiceDocuments]= ; data[template-InvoiceDocument][%unid%][id]= ; data[template-InvoiceDocument][%unid%][document_id]=%id%

Differences from the invoice form (diff of controls, `p-invoice-add` vs `p-estimate-add`): estimate lacks is_received/received_date, the Deposit block (deposit, deposit_type, Deposit[is_paid], treasury, method, transaction), the Payment block and the template name; type 3; labels رقم عرض الأسعار / تاريخ عرض الأسعار / صالح لمدة; issue date hidden.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | قالب الفاتورة | invoice_layout_id | select-one |  | التصميم الافتراضي لعرض الأسعار | 0=التصميم الافتراضي لعرض الأسعار ; -1=التصميم الافتراضي للوقت | shown |
|  | الطريقة: | data[Invoice][is_offline] | select-one |  | طباعة | 0=أرسل عبر البريد ; 1=طباعة | hidden/other tab |
|  | العملة * | data[Invoice][currency_code] | select-one | * | SAR ريال سعودي | =العملة الأساسية ; AED=AED درهم إماراتي ; AFN=AFN أفغاني ; ALL=ALL ليك ألباني ; AMD=AMD درام أرميني ; ANG=ANG غويلدر الأنتيل الهولندية ; ... (161 options) | hidden/other tab |
|  | تحديث معلومات العميل | (widget, no name) | checkbox |  | unchecked |  | hidden/other tab |
|  | رقم عرض الأسعار | data[Invoice][no] | text |  | 000002 | maxlen 255 | shown |
|  | تاريخ عرض الأسعار | data[Invoice][date] | text |  | 27/10/2026 |  | shown |
|  | مسئول مبيعات | data[Invoice][sales_person_id] | select-one |  | New Beauty #000001 | 1=New Beauty #000001 | hidden/other tab |
|  | تاريخ الإصدار | data[Invoice][issue_date] | text |  | 27/10/2026 |  | hidden/other tab |
|  | صالح لمدة | data[Invoice][due_after] | number |  |  |  | hidden/other tab |
|  |  | data[InvoiceItem][0][item] | textarea | cls |  | ph="البند" | shown |
|  |  | data[InvoiceItem][0][description] | textarea | cls |  | ph="الوصف" | shown |
|  |  | data[InvoiceItem][0][unit_price] | number | cls |  | ph="سعر الوحدة" | shown |
|  |  | data[InvoiceItem][0][quantity] | number | cls |  | ph="الكمية" | shown |
|  |  | data[InvoiceItem][0][discount] | number |  |  | ph="الخصم" | shown |
|  |  | data[InvoiceItem][0][discount_type] | select-one | cls | % | 1=% ; 2=$ | shown |
|  |  | data[InvoiceItem][0][tax1] | select-one |  | بدون ضريبة | =بدون ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب ; ph="الضريبة 1" | shown |
|  |  | data[InvoiceItem][0][tax2] | select-one |  | بدون ضريبة | =بدون ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب ; ph="الضريبة 2" | hidden/other tab |
| tab الخصم والتسوية | الخصم | data[Invoice][discount] | number |  |  | ph="0" | shown |
| tab الخصم والتسوية | الخصم | data[Invoice][discount_amount] | number |  |  | ph="0" | hidden/other tab |
| tab الخصم والتسوية | التسوية | data[Invoice][adjustment_label] | text |  |  |  | shown |
| tab الخصم والتسوية | التسوية | data[Invoice][adjustment_value] | number |  |  | ph="0.00" | shown |
| tab بيانات الشحن | بيانات الشحن | data[Invoice][shipping_options] | select-one |  | آلي | =آلي ; 1=لا تظهر ; 2=أظهر عنوان العميل ; 3=أظهر عنوان العميل الثانوي | hidden/other tab |
| tab بيانات الشحن | مصاريف الشحن | data[Invoice][shipping_amount] | text |  |  |  | hidden/other tab |
| tab الملفات التي تم رفعها مسبقاً | المستند | doc | select-one |  | Select Document | =Select Document | hidden/other tab |
|  |  | data[Invoice][html_notes] | textarea |  |  |  | hidden/other tab |
| 13 | Select from files | files | file |  |  |  | hidden/other tab |


**Facts**

- **pw-forms-25** (observed) Estimate add form (/owner/invoices/add_estimate, title "عرض سعر جديد") is the invoice form with hidden data[Invoice][type]=3; relabelled رقم عرض الأسعار (default 000002), تاريخ عرض الأسعار, and "صالح لمدة" in place of شروط الدفع (same field data[Invoice][due_after]); issue date hidden; no "التسليم" and "إيداع" tabs; no "مدفوع بالفعل" block; layout options "التصميم الافتراضي لعرض الأسعار | التصميم الافتراضي للوقت". Tabs: الخصم والتسوية، بيانات الشحن، إرفاق المستندات.  
  evidence: `p-estimate-add.probe.json`
- **pw-forms-26** (observed) Estimate statuses (filter payment_status on /owner/invoices/estimates): 0=مفتوح, 1=تم الإرسال, 2=تمت مشاهدته, 3=تم الرد, 4=تمت الموافقة, 5=حولت لفاتورة, 6=تم الطلب, -1=مسودة. The status is not a field of the add/edit form (it is set from list/view actions); settings: SiteDisableEstimateModule "نظام عروض الأسعار" checked, next_estimate_number 000002, apply_offers_on_estimates unchecked.  
  evidence: `list-filters.json`
- **pw-forms-27** (observed) Estimate edit form (/owner/invoices/edit_estimate/13) has the same controls as the add form plus data[InvoiceItem][n][invoice_item_id], extra_details JSON containing client_balance, invoice_version, uuid, client_is_government_entity, client_identifier_type, and group_price_id "NaN"; save buttons: معاينة, حفظ دون طباعة (menu: حفظ دون إرسال | إرسال مع تعديل البريد | حفظ وطباعة) with no "حفظ كـ مسودة".  
  evidence: `estimate-edit-13.json`

## 6. Sales invoice form (baseline shared by invoice, estimate, credit note, recurring invoice)

URL /owner/invoices/add (also /owner/invoices/add_creditnote type 5, /owner/invoices/edit_creditnote/3, edit_refund/26). Evidence `p-invoice-add.probe.json`, `src-invoice-add.*`, `js/new-invoice-v2_v01266.js`.

Tabs: الخصم والتسوية, إيداع, بيانات الشحن, التسليم, إرفاق المستندات, (hidden) التنبيهات الآلية, الشروط والأحكام, مستند جديد, الملفات التي تم رفعها مسبقاً.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | قالب الفاتورة | invoice_layout_id | select-one |  | التصميم الافتراضي للفاتورة | 0=التصميم الافتراضي للفاتورة ; -1=التصميم الافتراضي للوقت | shown |
|  | الطريقة: | data[Invoice][is_offline] | select-one |  | طباعة | 0=أرسل عبر البريد ; 1=طباعة | hidden/other tab |
|  | العملة * | data[Invoice][currency_code] | select-one | * | SAR ريال سعودي | =العملة الأساسية ; AED=AED درهم إماراتي ; AFN=AFN أفغاني ; ALL=ALL ليك ألباني ; AMD=AMD درام أرميني ; ANG=ANG غويلدر الأنتيل الهولندية ; ... (161 options) | hidden/other tab |
|  | تحديث معلومات العميل | (widget, no name) | checkbox |  | unchecked |  | hidden/other tab |
|  | رقم الفاتورة | data[Invoice][no] | text |  | 000028 | maxlen 255 ; RO | shown |
|  | تاريخ الفاتورة | data[Invoice][date] | text |  | 27/10/2026 |  | shown |
|  | مسئول مبيعات | data[Invoice][sales_person_id] | select-one |  | New Beauty #000001 | 1=New Beauty #000001 | hidden/other tab |
|  | تاريخ الإصدار | data[Invoice][issue_date] | text |  | 27/10/2026 |  | shown |
|  | شروط الدفع | data[Invoice][due_after] | number |  |  |  | shown |
|  |  | data[InvoiceItem][0][item] | textarea | cls |  | ph="البند" | shown |
|  |  | data[InvoiceItem][0][description] | textarea | cls |  | ph="الوصف" | shown |
|  |  | data[InvoiceItem][0][unit_price] | number | cls |  | ph="سعر الوحدة" | shown |
|  |  | data[InvoiceItem][0][quantity] | number | cls |  | ph="الكمية" | shown |
|  |  | data[InvoiceItem][0][discount] | number |  |  | ph="الخصم" | shown |
|  |  | data[InvoiceItem][0][discount_type] | select-one | cls | % | 1=% ; 2=$ | shown |
|  |  | data[InvoiceItem][0][tax1] | select-one |  | بدون ضريبة | =بدون ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب ; ph="الضريبة 1" | shown |
|  |  | data[InvoiceItem][0][tax2] | select-one |  | بدون ضريبة | =بدون ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب ; ph="الضريبة 2" | hidden/other tab |
| tab الخصم والتسوية | الخصم | data[Invoice][discount] | number |  |  | ph="0" | shown |
| tab الخصم والتسوية | الخصم | data[Invoice][discount_amount] | number |  |  | ph="0" | hidden/other tab |
| tab الخصم والتسوية | التسوية | data[Invoice][adjustment_label] | text |  |  |  | shown |
| tab الخصم والتسوية | التسوية | data[Invoice][adjustment_value] | number |  |  | ph="0.00" | shown |
| tab بيانات الشحن | بيانات الشحن | data[Invoice][shipping_options] | select-one |  | آلي | =آلي ; 1=لا تظهر ; 2=أظهر عنوان العميل ; 3=أظهر عنوان العميل الثانوي | hidden/other tab |
| tab بيانات الشحن | مصاريف الشحن | data[Invoice][shipping_amount] | text |  |  |  | hidden/other tab |
| tab التسليم | تأكيد التسليم | data[Invoice][is_received] | checkbox |  | checked |  | hidden/other tab |
| tab التسليم | تاريخ التسليم | data[Invoice][received_date] | text |  | 2026-10-27 07:20 |  | hidden/other tab |
| tab الملفات التي تم رفعها مسبقاً | المستند | doc | select-one |  | Select Document | =Select Document | hidden/other tab |
| tab إيداع | (مدفوع بالفعل ) | data[Deposit][is_paid] | checkbox |  | unchecked |  | hidden/other tab |
| tab إيداع | الدفعة المقدمة (مدفوع بالفعل ) | data[Invoice][deposit] | number |  |  | ph="0" | hidden/other tab |
| tab إيداع | الدفعة المقدمة (مدفوع بالفعل ) | data[Invoice][deposit_type] | select-one |  | المبلغ | 1=المبلغ | hidden/other tab |
| tab إيداع | خزينة | data[Deposit][treasury_id] | select-one |  | Main Treasury | 1=Main Treasury | hidden/other tab |
| tab إيداع | وسيلة دفع | data[Deposit][payment_method] | select-one |  | نقدي | cash=نقدي | hidden/other tab |
| tab إيداع | رقم المعرف | data[Deposit][transaction_id] | text |  |  |  | hidden/other tab |
|  |  | data[Invoice][html_notes] | textarea |  |  |  | hidden/other tab |
| 13 | Select from files | files | file |  |  |  | hidden/other tab |
|  | مدفوع بالفعل | data[Payment][is_paid] | checkbox |  | unchecked |  | hidden/other tab |
|  | وسيلة دفع | data[Payment][payment_method] | select-one |  | نقدي | cash=نقدي | hidden/other tab |
|  | رقم المعرف | data[Payment][transaction_id] | text |  |  |  | hidden/other tab |
|  | خزينة | data[Payment][treasury_id] | select-one |  | Main Treasury | 1=Main Treasury ; 3=افتراضي-S35 بنك | hidden/other tab |
|  | القالب | data[Invoice][name] | text |  |  | maxlen 255 | hidden/other tab |

Conditional visibility:
- data[Payment][is_paid] [مدفوع بالفعل] checkbox=toggle: shows ["PaymentPaymentMethod-selectized(وسيلة دفع)","data[Payment][transaction_id](رقم المعرف)","data[Payment][treasury_id](خزينة)"]; hides []
- tab "إيداع": shows ["data[Deposit][is_paid]((مدفوع بالفعل ))","data[Invoice][deposit](الدفعة المقدمة (مدفوع بالفعل ))","InvoiceDepositType-selectized(الدفعة المقدمة (مدفوع بالفعل ))"]; hides ["data[Invoice][discount](الخصم)","discount_type-selectized(الخصم)","data[Invoice][adjustment_label](التسوية)","data[Invoice][adjustment_value](التسوية)"]
- tab "بيانات الشحن": shows ["data[Invoice][shipping_options](بيانات الشحن)","data[Invoice][shipping_amount](مصاريف الشحن)"]; hides ["data[Deposit][is_paid]((مدفوع بالفعل ))","data[Invoice][deposit](الدفعة المقدمة (مدفوع بالفعل ))","InvoiceDepositType-selectized(الدفعة المقدمة (مدفوع بالفعل ))"]
- tab "التسليم": shows ["data[Invoice][is_received](تأكيد التسليم)","data[Invoice][received_date](تاريخ التسليم)"]; hides ["data[Invoice][shipping_options](بيانات الشحن)","data[Invoice][shipping_amount](مصاريف الشحن)"]
- tab "إرفاق المستندات": shows []; hides ["data[Invoice][is_received](تأكيد التسليم)","data[Invoice][received_date](تاريخ التسليم)"]


**Facts**

- **pw-forms-28** (observed) Sales invoice header fields (/owner/invoices/add, hidden type 0): قالب الفاتورة (invoice_layout_id: 0=التصميم الافتراضي للفاتورة, -1=التصميم الافتراضي للوقت), الطريقة (is_offline 0=أرسل عبر البريد, 1=طباعة default), العميل * (client_id, searchable select "enter name, phones, barcode or ID", button جديد opens inline client fields, shortcut ALT+N), العملة * (currency_code default SAR, option "العملة الأساسية" + 160 codes; hidden at load, a link with tooltip «تعديل العملة» sits beside the client field), رقم الفاتورة (data[Invoice][no] default 000028, readonly attribute set at load although the setting edit_invoice_number is checked), تاريخ الفاتورة, مسئول مبيعات, تاريخ الإصدار, شروط الدفع (due_after, days).  
  evidence: `p-invoice-add.probe.json`
- **pw-forms-29** (observed) Sales document line columns (data[InvoiceItem][n]): item (البند), product_id hidden, description (الوصف), unit_price (سعر الوحدة), quantity (الكمية), discount + discount_type (1=% default, 2=$), tax1 and tax2 (options: بدون ضريبة default, القيمة المضافة, إعدادات الضرائب = -1 opens the taxes settings), store_id and sales_person_id (hidden at load), custom columns col_3..col_5 (hidden); the grid allows at most 500 rows.  
  evidence: `p-invoice-add.probe.json`
- **pw-forms-30** (observed) Sales document totals tab "الخصم والتسوية": data[Invoice][discount] (percent) or data[Invoice][discount_amount] (fixed) chosen by a UI select (name discount_type, outside the data[...] namespace) with options نسبة مئوية (%) | المبلغ; التسوية = adjustment_label (text) + adjustment_value (number). Tab "بيانات الشحن": shipping_options (آلي | لا تظهر | أظهر عنوان العميل | أظهر عنوان العميل الثانوي) + shipping_amount. Tab "التسليم": is_received "تأكيد التسليم" (checked by default, consistent with the checked setting deliver_invoices_by_default «اعتبار فواتير المبيعات مُسلَّمة تلقائيًا») + received_date. Tab "إيداع": Deposit[is_paid] "(مدفوع بالفعل )", deposit amount, deposit_type, treasury (خزينة), payment method (وسيلة دفع), transaction id (رقم المعرف).  
  evidence: `p-invoice-add.probe.json`
- **pw-forms-31** (observed) Sales document computation in client JS (calculateTotals): line = qty×unit_price; line discount type 2 = discount×qty/unit_factor, type 1 = % of line; document percent discount applies to each line net of line discount (kept unrounded); a fixed document discount is converted to a percent = discount_amount ÷ (sum of lines after line discount) × 100; included taxes: net = line − (Σincluded% ÷ (Σincluded%+100)) × line; tax1 and tax2 are each computed on the net (parallel, not compound); total = Σ(net + non-included tax) + shipping (+ non-included shipping tax) + adjustment (only if total>0) − retention (only if total>0) + extra account lines.  
  evidence: `js/new-invoice-v2_v01266.js`
- **pw-forms-32** (observed) Sales document client-side validation (validateFields): client required; header tax1/tax2/adjustment/retention numeric; document discount numeric and ≤ 100; due_after and issue_before numeric and not negative; date and issue date required; item name required (unless template); item description max 100000 chars; unit price and quantity numeric; a line cannot use the same tax in tax1 and tax2; deposit: if deposit_type=2 it must be between 0 and 100 else ≤ document total; terms: if TextCheckbox ticked a terms template is required; each reminder needs send_when and days for types 3/4; multiple payment rows need treasury, method and amount; cost-centre rows need percentage and amount; inline new client must be saved first («من فضلك قم بحفظ بيانات العميل بالضغط على حفظ العميل»).  
  evidence: `js/new-invoice-v2_v01266.js`
- **pw-forms-33** (observed) Sales document status texts computed live: paid = 0 → «Unpaid», 0 < paid < total → «Partial Paid», else «Paid»; "الدفعة القادمة" (Next Payment) row shows an unpaid deposit and is not a payment; checking "مدفوع بالفعل" (Payment[is_paid]) sets paid = total and reveals وسيلة دفع / رقم المعرف / خزينة (treasury options Main Treasury, افتراضي-S35 بنك; method options only نقدي).  
  evidence: `p-invoice-add.probe.json`
- **pw-forms-34** (observed) Invoice number rule: the setting "تعديل رقم الفاتورة يدوياً" (edit_invoice_number) is checked, "معاينة الفاتورة قبل الحفظ" checked, "تبويب الربح في الفاتورة" unchecked, "الدفعات المقدمة" (enable_advance_payment) unchecked, "الحد الأقصى لنسبة الخصم" unchecked, "دفع الفاتورة تلقائيا في حالة وجود رصيد للعميل" checked, discount_option = 3 "خصم على إجمالي الفاتورة ولكل بند معًا", default_sales_tax1_mode = product ("مطابق لضريبة المنتج"), require_tax_1 unchecked.  
  evidence: `st-invoices.json`
- **pw-forms-81** (observed) Inline "جديد" client block inside sales documents posts the client with the document: data[Invoice][client_type] radio (2 فردي, 3 تجاري), client_business_name *, client_first_name, client_last_name, client_email * (required for the print/e-mail method), client_building_no, client_address1, client_address2, client_city (الحي), client_state (المدينة), client_postal_code, client_short_address, client_phone1, client_phone2, client_country_code (SA), client_is_government_entity, client_bn1, client_identifier_type (12 options incl. CRN, NAT, IQA, GCC, PAS, OTH), client_bn2, client_active_secondary_address "إضافة عنوان شحن" with client_secondary_* (name, building, street, sub-number, district, city, postal code, short address, country), UpdateClientData "تحديث معلومات العميل"; hidden Client[client_from_data], address_id, group_price_id.  
  evidence: `p-invoice-add.probe.json`
- **pw-forms-85** (observed) Document-level discount control in sales and purchase documents: tab "الخصم والتسوية" has a UI select (name discount_type, outside the data[...] namespace) with options نسبة مئوية (%) | المبلغ; choosing المبلغ swaps the visible input from [discount] (percent) to [discount_amount]; the sales tab adds التسوية (adjustment_label + adjustment_value), the purchase tab has no adjustment (setting enable_purchases_adjustment unchecked) and its shipping tab adds a tax select.  
  evidence: `p-pinv-add.probe.json`

## 7. Treasury transfer (تحويل بين الخزائن) - add and edit

URL /owner/treasuries/transfer?from_treasury_id=1 ; edit /owner/treasuries/edit_transfer/1 ; entry via the "تحويل" row action on /v2/owner/banks/treasury.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| من: | خزينة | data[Treasury][from_treasury_id] | select-one | cls | Main Treasury | =اختر الخزينة ; 1=Main Treasury ; 3=افتراضي-S35 بنك | shown |
| من: | العملة | data[Treasury][from_currency_code] | select-one |  | SAR ريال سعودي | SAR=SAR ريال سعودي | hidden/other tab |
| من: | المبلغ | data[Treasury][balance] | number |  |  | ph="0.00" | shown |
| من: | المتاح قبل | (widget, no name) | email |  | 84624.25 | DIS | shown |
| من: | المتاح بعد | (widget, no name) | email |  | 84624.25 | DIS | shown |
| إلى: | خزينة | data[Treasury][to_treasury_id] | select-one |  | اختر الخزينة | =اختر الخزينة ; 1=Main Treasury ; 3=افتراضي-S35 بنك | shown |
| إلى: | عملة الصرف | data[Treasury][to_currency_code] | select-one |  | SAR ريال سعودي | AED=AED درهم إماراتي ; AFN=AFN أفغاني ; ALL=ALL ليك ألباني ; AMD=AMD درام أرميني ; ANG=ANG غويلدر الأنتيل الهولندية ; AOA=AOA كوانزا أنغولي ; ... (160 options) | hidden/other tab |
| إلى: | سعر الصرف | data[Treasury][currency_rate] | number |  | 1 |  | hidden/other tab |
| إلى: | المتاح قبل | (widget, no name) | email |  | 0.00 | DIS | shown |
| إلى: | المتاح بعد | (widget, no name) | email |  | 0.00 | DIS | shown |
|  | تاريخ التحويل | data[Treasury][transfer_date] | text | cls | 27/10/2026 07:21 |  | shown |
|  | الملاحظات | data[Treasury][notes] | textarea | cls |  |  | shown |
|  | المرفقات | (widget, no name) | file |  |  |  | hidden/other tab |


**Facts**

- **pw-forms-35** (observed) Treasury transfer form (/owner/treasuries/transfer?from_treasury_id=N, form posts to /owner/treasuries/transfer, submit button "التحويل"): panel "من:" خزينة (data[Treasury][from_treasury_id], required class, options اختر الخزينة + Main Treasury + افتراضي-S35 بنك), العملة (from_currency_code, hidden unless multi-currency), المبلغ (data[Treasury][balance], ph 0.00), المتاح قبل / المتاح بعد (disabled calculated); panel "إلى:" خزينة (to_treasury_id), عملة الصرف (to_currency_code, hidden), سعر الصرف (currency_rate default 1, hidden), المتاح قبل/بعد; then تاريخ التحويل (transfer_date default now with time, class required), الملاحظات (notes, class required), المرفقات.  
  evidence: `p-treasury-transfer.probe.json`
- **pw-forms-36** (observed) Transfer form JS keeps currency_balances per treasury and currency embedded in the page ({"1":{"SAR":84624.25},"3":{"SAR":40}}) and computes from_after = from_balance − amount and to_after = to_balance + amount × currency_rate; both selects list both treasuries (Main Treasury, افتراضي-S35 بنك) and the source is preselected from the query string.  
  evidence: `src-treasury-transfer.inline.js`
- **pw-forms-37** (observed) Transfer edit page /owner/treasuries/edit_transfer/1 uses the same controls (form id Treasury/editTransfer/Form, posting /owner/treasuries/edit_transfer/1) with stored amount 10, to-treasury 3, note «افتراضي-S35 تحويل (أ) 10»; its journal (قيد 134) opens as an automatic entry with button "عرض المصدر" linking to /owner/treasuries/edit_transfer/3.  
  evidence: `transfer-edit-1.json`

## 8. Purchase documents: purchase invoice (فاتورة شراء = purchase_order), purchase refund, purchase debit note

URLs /owner/purchase_invoices/add (type 0), /add_refund (type 6), /add_debit_note (type 14); edit /edit/27, /edit_refund/6, /edit_debit_note/7 (all with ?reset=1 in our capture).

### 8.1 Purchase invoice add

Hidden: data[PurchaseOrder][item_columns]= ; data[PurchaseOrder][invoice_layout_id]=0 ; data[PurchaseOrder][type]=0 ; data[PurchaseOrder][is_offline]=1 ; data[PurchaseOrder][id]= ; data[PurchaseOrder][language_id]=7 ; data[PurchaseOrder][currency_code]=SAR ; data[Supplier][id]= ; data[PurchaseOrder][supplier_supplier_number]= ; data[PurchaseOrder][supplier_bn1_label]=الرقم الضريبي ; data[PurchaseOrder][supplier_bn2_label]=سجل تجارى/ رقم موحد ; data[PurchaseOrder][supplier_default_currency_code]= ; data[Supplier][supplier_from_data]=1 ; data[PurchaseOrder][hidden_no]=000025 ; data[PurchaseOrderItem][0][product_id]= ; data[PurchaseOrderItem][0][org_name]= ; data[PurchaseOrderItem][0][id]= ; data[purchaseDocuments_s3]= ; data[template-PurchaseOrderDocument][%unid%][id]= ; data[template-PurchaseOrderDocument][%unid%][document_id]=%id% ; data[PurchaseOrder][store_id]=1 ; data[Deposit][is_paid]=0 ; data[Payment][is_paid]=0 ; data[PurchaseOrder][is_received]=0 ; next_action=go_index

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | قالب فواتير الشراء | invoice_layout_id | select-one |  | قالب فاتورة الإفتراضى | 0=قالب فاتورة الإفتراضى | shown |
|  | المورد* | data[PurchaseOrder][supplier_id] | select-one | * | (اختر مورد) | =(اختر مورد) ; 7=افتراضي-S35 مورد ; 2=شركة الأمل للمستلزمات (مورد افتراضي) ; 1=شركة الخليج للتوريدات (مورد تجريبي) ; 3=مورد افتراضي-F1 ; 4=مورد افتراضي-F2 ; 5=مورد افتراضي-F3 ; 6=مورد افتراضي-F4 | hidden/other tab |
|  | العملة * | data[PurchaseOrder][currency_code] | select-one | * | SAR ريال سعودي | =العملة الأساسية ; AED=AED درهم إماراتي ; AFN=AFN أفغاني ; ALL=ALL ليك ألباني ; AMD=AMD درام أرميني ; ANG=ANG غويلدر الأنتيل الهولندية ; ... (161 options) | hidden/other tab |
| بيانات المورد الجديد | تحديث بيانات المورد | (widget, no name) | checkbox |  | unchecked |  | hidden/other tab |
|  | رقم فاتورة الشراء | data[PurchaseOrder][no] | text | cls | 000025 | maxlen 255 | shown |
|  | التاريخ | data[PurchaseOrder][date] | text |  | 27/10/2026 |  | shown |
|  | شروط الدفع | data[PurchaseOrder][due_after] | number |  |  |  | shown |
|  | رقم فاتورة الشراء | data[PurchaseOrder][po_number] | text | cls |  | maxlen 255 | hidden/other tab |
|  |  | data[PurchaseOrderItem][0][item] | textarea | cls |  | ph="البند" | shown |
|  |  | data[PurchaseOrderItem][0][description] | textarea | cls |  | ph="الوصف" | shown |
|  |  | data[PurchaseOrderItem][0][store_id] | select-one | cls | Primary Warehouse | 1=Primary Warehouse | hidden/other tab |
|  |  | data[PurchaseOrderItem][0][unit_price] | number | cls |  | ph="سعر الوحدة" | shown |
|  |  | data[PurchaseOrderItem][0][quantity] | number | cls |  | ph="الكمية" | shown |
|  |  | data[PurchaseOrderItem][0][discount] | number |  |  | ph="الخصم" | shown |
|  |  | data[PurchaseOrderItem][0][discount_type] | select-one | cls | % | 1=% ; 2=$ | shown |
|  |  | data[PurchaseOrderItem][0][tax1] | select-one |  | بدون ضريبة | =بدون ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب ; ph="الضريبة 1" | shown |
|  |  | data[PurchaseOrderItem][0][tax2] | select-one |  | بدون ضريبة | =بدون ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب ; ph="الضريبة 2" | hidden/other tab |
|  | المرفقات | (widget, no name) | text |  |  | ph="وسوم الصنف" | shown |
| tab الملفات التي تم رفعها مسبقاً | المستند | doc | select-one |  | Select Document | =Select Document | hidden/other tab |
| tab الشحن | الضرائب | data[PurchaseOrder][shipping_tax_id] | select-one |  | اختر ضريبة | =اختر ضريبة ; 1=القيمة المضافة | hidden/other tab |
| tab الشحن | مصاريف الشحن | data[PurchaseOrder][shipping_amount] | number |  |  |  | hidden/other tab |
| tab الخصم والتسوية | الخصم | data[PurchaseOrder][discount] | number |  |  |  | shown |
| tab الخصم والتسوية | الخصم | data[PurchaseOrder][discount_amount] | number |  |  |  | hidden/other tab |
| tab إيداع | الدفعة مقدمة | data[PurchaseOrder][deposit] | number |  |  |  | hidden/other tab |
| tab إيداع | الدفعة مقدمة | data[PurchaseOrder][deposit_type] | select-one |  | المبلغ | 1=المبلغ ; 2=نسبة مئوية (%) | hidden/other tab |
| tab إيداع | مدفوع بالفعل | data[Deposit][is_paid] | checkbox |  | unchecked |  | hidden/other tab |
| tab إيداع | وسيلة دفع | data[Deposit][payment_method] | select-one |  | نقدي | cash=نقدي ; cheque=شيك ; bank=تحويل بنكي | hidden/other tab |
| tab إيداع | رقم المعرف | data[Deposit][transaction_id] | text |  |  |  | hidden/other tab |
| tab إيداع | خزينة | data[Deposit][treasury_id] | select-one |  | Main Treasury | 1=Main Treasury ; 3=افتراضي-S35 بنك | hidden/other tab |
|  |  | data[PurchaseOrder][html_notes] | textarea |  |  |  | hidden/other tab |
| 13 | Select from files | files | file |  |  |  | hidden/other tab |
|  | تم الدفع بالفعل إلى المورد | data[Payment][is_paid] | checkbox |  | checked |  | hidden/other tab |
|  | وسيلة دفع | data[Payment][payment_method] | select-one |  | نقدي | cash=نقدي | hidden/other tab |
|  | رقم المعرف | data[Payment][transaction_id] | text |  |  |  | shown |
|  | خزينة | data[Payment][treasury_id] | select-one |  | Main Treasury | 1=Main Treasury ; 3=افتراضي-S35 بنك | shown |
|  | مستلم | data[PurchaseOrder][is_received] | checkbox |  | checked |  | hidden/other tab |
|  | تاريخ الإستلام | data[PurchaseOrder][received_date] | text |  | 2026-10-27 07:22 |  | shown |

Conditional visibility:
- data[PurchaseOrder][is_received] [مستلم] checkbox=toggle: shows []; hides ["data[PurchaseOrder][received_date](تاريخ الإستلام)"]
- tab "إيداع": shows ["data[PurchaseOrder][deposit](الدفعة مقدمة)","data[PurchaseOrder][deposit_type](الدفعة مقدمة)"]; hides ["data[PurchaseOrder][discount](الخصم)","discount_type-selectized(الخصم)"]
- tab "الشحن": shows ["data[PurchaseOrder][shipping_tax_id](الضرائب)","data[PurchaseOrder][shipping_amount](مصاريف الشحن)"]; hides ["data[PurchaseOrder][deposit](الدفعة مقدمة)","data[PurchaseOrder][deposit_type](الدفعة مقدمة)"]
- tab "إرفاق المستندات": shows []; hides ["data[PurchaseOrder][shipping_tax_id](الضرائب)","data[PurchaseOrder][shipping_amount](مصاريف الشحن)"]

### 8.2 Purchase debit note: probe
- data[PurchaseOrder][is_received] [مستلم] checkbox=toggle: shows ["data[PurchaseOrder][received_date](تاريخ الإستلام)"]; hides []
- tab "الشحن": shows ["data[PurchaseOrder][shipping_tax_id](الضرائب)","data[PurchaseOrder][shipping_amount](مصاريف الشحن)"]; hides ["data[PurchaseOrder][discount](الخصم)","discount_type-selectized(الخصم)"]
- tab "إرفاق المستندات": shows []; hides ["data[PurchaseOrder][shipping_tax_id](الضرائب)","data[PurchaseOrder][shipping_amount](مصاريف الشحن)"]

### 8.3 Purchase refund: probe
- data[Payment][is_paid] [تم رد المبلغ الإجمالي للبنود المرتجعة من المورد] checkbox=toggle: shows ["PaymentPaymentMethod-selectized(وسيلة دفع)","data[Payment][transaction_id](رقم المعرف)","data[Payment][treasury_id](خزينة)"]; hides []
- data[PurchaseOrder][is_received] [مستلم] checkbox=toggle: shows ["data[PurchaseOrder][received_date](تاريخ الإستلام)"]; hides []
- tab "إيداع": shows ["data[PurchaseOrder][deposit](الدفعة مقدمة)","data[PurchaseOrder][deposit_type](الدفعة مقدمة)"]; hides ["data[PurchaseOrder][discount](الخصم)","discount_type-selectized(الخصم)"]
- tab "الشحن": shows ["data[PurchaseOrder][shipping_tax_id](الضرائب)","data[PurchaseOrder][shipping_amount](مصاريف الشحن)"]; hides ["data[PurchaseOrder][deposit](الدفعة مقدمة)","data[PurchaseOrder][deposit_type](الدفعة مقدمة)"]
- tab "إرفاق المستندات": shows []; hides ["data[PurchaseOrder][shipping_tax_id](الضرائب)","data[PurchaseOrder][shipping_amount](مصاريف الشحن)"]


**Facts**

- **pw-forms-4** (observed) In Daftra "فواتير الشراء" is the internal entity purchase_order: list /v2/owner/entity/purchase_order/list, add /owner/purchase_invoices/add (form PurchaseOrderForm, hidden data[PurchaseOrder][type]=0) and a second React create page /v2/owner/entity/purchase_order/create titled "إضافة | فاتورة الشراء". A real purchase order (أمر شراء) of the purchase cycle is therefore not available in this account.  
  evidence: `p-pinv-add.probe.json`
- **pw-forms-38** (observed) Purchase invoice add form (/owner/purchase_invoices/add, PurchaseOrderForm, hidden type 0): قالب فواتير الشراء, المورد* (searchable "أدخل الاسم، الهاتف أو الرقم التعريفي", inline new supplier fields), العملة * (default SAR), رقم فاتورة الشراء (data[PurchaseOrder][no], default 000025, required), التاريخ, شروط الدفع (due_after), hidden po_number "رقم فاتورة الشراء" (supplier reference); items data[PurchaseOrderItem][n]: item, description, store_id, unit_price, quantity, discount + discount_type (1=% default, 2=$), tax1, tax2; tabs الخصم والتسوية (discount percent or amount), إيداع (deposit amount + deposit_type المبلغ/نسبة مئوية (%) + مدفوع بالفعل + وسيلة دفع cash/cheque/bank + رقم المعرف + خزينة), الشحن (shipping_tax_id الضرائب + shipping_amount), إرفاق المستندات.  
  evidence: `p-pinv-add.probe.json`
- **pw-forms-39** (observed) Purchase invoice payment/receipt block: checkbox data[Payment][is_paid] "تم الدفع بالفعل إلى المورد" is CHECKED by default (consistent with the checked setting po_always_paid «إجعل الفواتير مدفوعه بالفعل افتراضياً») and shows وسيلة دفع (only نقدي), رقم المعرف, خزينة; data[PurchaseOrder][is_received] "مستلم" CHECKED by default (consistent with the checked setting po_received «إجعل فواتير المشتريات مستلمة بالفعل افتراضياً») with تاريخ الإستلام (received_date default now, shown only while مستلم is ticked). Other purchase settings: next_po_number 000025, automatic_pay_po checked, update_product_prices unchecked, enable_purchases_adjustment unchecked, enable_purchase_manual_status unchecked, purchase_discount_option 3, default_purchase_tax_1_behavior = product.  
  evidence: `st-purchase-invoices.json`
- **pw-forms-40** (observed) Purchase document client-side validation: number, supplier and hidden currency required (message «هذا الحقل مطلوب ولا يمكن ان يكون فارغ»); date required and valid; discount numeric ≤ 100; due_after/issue_before numeric ≥ 0; item name required; unit price/qty numeric; deposit % between 0 and 100 or amount ≤ total; multiple payment rows need method, treasury, amount; sum over selected gateways must equal the invoice total; inline supplier must be saved first (message «من فضلك قم بحفظ بيانات المورد بالضغط على حفظ المورد»).  
  evidence: `js/new-purchase_orders-v2_v01266.js`
- **pw-forms-41** (observed) Purchase document types share one form class: hidden data[PurchaseOrder][type] = 0 purchase invoice, 6 purchase refund (/owner/purchase_invoices/add_refund, "مرتجع مشتريات جديد"), 14 debit note (/owner/purchase_invoices/add_debit_note, "إشعار مدين جديد"). Labels change: رقم الارتجاع (default 000003) / تاريخ الارتجاع; رقم الاشعار المدين (default 00003) / تاريخ الاشعار المدين; layout label قالب فواتير المرتجع.  
  evidence: `p-prefund-add.probe.json`
- **pw-forms-42** (observed) Purchase debit note add form = purchase invoice form minus: شروط الدفع, po_number, the إيداع tab and the whole payment block (no "تم الدفع بالفعل", no treasury); keeps مستلم (data[PurchaseOrder][is_received], UNCHECKED by default here, ticking it reveals تاريخ الإستلام); tabs الخصم والتسوية، الشحن، إرفاق المستندات.  
  evidence: `p-pdebit-add.probe.json`
- **pw-forms-43** (observed) Purchase refund add form = purchase invoice form minus شروط الدفع and po_number; the payment checkbox is relabelled "تم رد المبلغ الإجمالي للبنود المرتجعة من المورد" and is unchecked by default (ticking it reveals وسيلة دفع, رقم المعرف, خزينة); keeps the إيداع tab and مستلم (unchecked by default, ticking reveals تاريخ الإستلام).  
  evidence: `p-prefund-add.probe.json`
- **pw-forms-44** (observed) Purchase edit forms: /owner/purchase_invoices/edit/27, edit_debit_note/7, edit_refund/6 use the same controls; buttons are only "معاينة" and "تحديث" (no draft save for issued documents).  
  evidence: `pinv-edit-27.json`
- **pw-forms-45** (observed) Purchase invoice list filters (React list): حالة السداد (filter[payment]), المصدر (filter[type]), عملة, تم أضافته من قبل; the list shows "1 - 20 من 22" invoices; React create pages /v2/owner/entity/purchase_order/create, purchase_refund/create and purchase_debit_note/create expose the identical raw schema (118 controls each): purchase_order_items[n] (item, description, product_id, unit_price, quantity, discount, discount_type, tax1, tax2, summary_tax2, subtotal, status, unit_factor_id, unit_factor, unit_name, unit_small_name, col_3..col_5, display_order), purchase_order_taxes[n] (name, value, invoice_value, included), purchase_order_custom_fields[n], supplier_* inline fields, supplier_id *, store_id, work_order_id, branch_id, shipping_amount, shipping_tax_id, discount (percentage), discount_amount, due_after, label_due_date, draft (مسودة *), is_received, tags[], requisitions[], requisition_delivery_status[].  
  evidence: `v2-purchase-order-create.probe.json`
- **pw-forms-82** (observed) Inline new-supplier block inside purchase documents: supplier_business_name *, first/last name, supplier_email, building, street, sub-number, district, city, postal code, short address, phone1, phone2, country (SA), supplier_bn1 (الرقم الضريبي (اختياري)), supplier_bn2 (سجل تجارى/ رقم موحد (اختياري)), UpdateSupplierData "تحديث بيانات المورد"; hidden Supplier[supplier_from_data]=1, supplier_default_currency_code.  
  evidence: `p-pinv-add.probe.json`

## 9. Purchase request / quotation request / purchase quotation / purchase order (purchase cycle) - NOT AVAILABLE

The app "دورة المشتريات" is disabled; neither list nor add pages exist for the four documents (see section 1). Only their manual-status toggles can be read. No field list could be captured; the matrix row PUR-07 stays unobserved.

**Facts**

- **pw-forms-2** (observed) Purchase cycle (purchase request, quotation request, purchase quotation, purchase order) is a disabled app: /owner/settings/purchase_cycle only has four unchecked toggles (data[72][enable_purchase_request_manual_status], enable_quotation_request_manual_status, enable_purchase_quotation_manual_status, enable_purchase_order_manual_status) labelled حالات طلبات الشراء / حالات طلبات عروض الأسعار / حالات عروض أسعار المشتريات / حالات أوامر الشراء (custom statuses per document). The add forms of these four documents could not be opened.  
  evidence: `st-purchase-cycle.json`
- **pw-forms-3** (observed) GET of /v2/owner/entity/purchase_request/list, purchase_quotation/list and quotation_request/list redirected to the dashboard "/" (HTTP 200, title لوحة التحكم) while a control URL with a non-existent entity (/v2/owner/entity/zzz_nonexistent/list) returned HTTP 500 and the legacy guesses /owner/purchase_requests/add etc. returned 404.  
  evidence: `_url-probes.log`

## 10. Stock permit (إذن مخزني) add - types إضافة (1) and صرف (2)

URLs /owner/requisitions/add/1, /add/2, /add (alias). Evidence `p-req-add-1.probe.json`, `p-req-add-2.probe.json`, `req-add-1.png`.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | التاريخ | data[Requisition][date] | text |  | 27/10/2026 07:21 |  | shown |
|  | الحساب الفرعي | data[Requisition][journal_account_id] | select-one |  | من فضلك اختر | =من فضلك اختر | hidden/other tab |
|  | رقم * | data[Requisition][number] | text | * | 000001 |  | shown |
|  | الملاحظات | data[Requisition][notes] | textarea |  |  |  | shown |
|  | المورد | data[Requisition][supplier_id] | select-one |  | اختر مورد | =اختر مورد | hidden/other tab |
|  | المرفقات | (widget, no name) | file |  |  |  | hidden/other tab |
|  |  | data[RequisitionItem][0][item] | textarea | cls |  | ph="البند" ; RO | shown |
|  |  | data[RequisitionItem][0][unit_price] | number | cls | 0 | ph="سعر الوحدة" | shown |
|  |  | data[RequisitionItem][0][quantity] | number | cls |  | ph="الكمية" | shown |

Type 2 differs only by data[Requisition][type]=2, [order_type]=2 and the party select data[Requisition][client_id] (العميل: شركة النور للتجارة (عميل تجريبي) #000001, عميل الإشعارات (افتراضي) #000004, مؤسسة الأفق للمقاولات (عميل تجريبي) #000002, مؤسسة الرياض للتجارة (عميل افتراضي) #000003) instead of supplier_id.


**Facts**

- **pw-forms-46** (observed) Stock permit add (/owner/requisitions/add/1 = إذن إضافة مخزن with a supplier select, /owner/requisitions/add/2 = إذن صرف مخزن with a client select; form id InvoiceForm, hidden data[Requisition][type] and [order_type] = 1 or 2): panel "بيانات الإذن المخزني": نوع الإذن (static text), التاريخ (default now with time); panel "معلومات أخري": الحساب الفرعي (journal_account_id, placeholder من فضلك اختر, ajax search أدخل الاسم أو الكود), رقم * (number default 000001), الملاحظات, المورد or العميل, المرفقات; grid columns البنود, سعر الوحدة (default 0), الكمية, رصيد المخزن قبل, رصيد المخزن بعد, الإجمالي; buttons تأكيد (+ تأكيد & إضافة جديدة); warehouse is a hidden store_id=1 (single warehouse). Quantity and item are required (rule notEmpty/isNumeric for requisitions).  
  evidence: `p-req-add-1.probe.json`
- **pw-forms-88** (observed) Requisition line grid: item (البند, read-only text bound to a product picker), unit_price (default 0), quantity (الكمية); computed columns رصيد المخزن قبل / رصيد المخزن بعد / الإجمالي; page JS shows «Stock Level After» and «Available Level After» via invoic-inventory; tracking messages «Serial number already exists» / «You cannot choose more than» appear only when serial tracking is on.  
  evidence: `req-add-1.json`

## 11. Stocktaking (الجرد) add

URL /owner/stocktakings/add and /v2/owner/entity/stocktaking/create.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | المستودع * | data[Stocktaking][disabled_store_id] | select-one | * | Primary Warehouse | 1=Primary Warehouse ; DIS | shown |
|  | رقم * | data[Stocktaking][number] | text | *+cls | 000001 |  | shown |
|  | التاريخ | data[Stocktaking][date] | text | cls | 27/10/2026 |  | shown |
|  | التاريخ | data[Stocktaking][time] | time |  | 07:22 |  | shown |
|  | الملاحظات | data[Stocktaking][notes] | textarea |  |  |  | shown |


**Facts**

- **pw-forms-47** (observed) Stocktaking add (/owner/stocktakings/add, title "أضف ورقة الجرد"): المستودع * (disabled select data[Stocktaking][disabled_store_id] showing Primary Warehouse + hidden store_id=1), رقم * (default 000001, required), التاريخ (default today) + time (default now), الملاحظات, hidden status; the only button is "قم بالجرد" (starts the count; the count sheet is not reachable without creating it). The React create page /v2/owner/entity/stocktaking/create exposes stocktaking_records[n][note], source_entry and attachments only. Settings: next_stock_taking_number 000001, calculate_stocktaking_to_stocktaking_date unchecked.  
  evidence: `p-stocktaking-add.probe.json`

## 12. Price list (قائمة أسعار)

URL /v2/owner/price_lists/create.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| قائمة أسعار | الاسم * | name | text | * |  |  | shown |
| قائمة أسعار | الحالة * | status | select-one | * | نشط | =اختر الحالة ; 0=غير نشط ; 1=نشط | shown |


**Facts**

- **pw-forms-48** (observed) Price list create (/v2/owner/price_lists/create, React; form posts to /v2/owner/price_lists): only الاسم * (name) and الحالة * (status: 1=نشط default, 0=غير نشط, placeholder اختر الحالة); list page /v2/owner/price_lists is empty ("لا يوجد قوائم الأسعار أضيفت حتي الآن") with buttons "تحديث جماعى" (→ /v2/owner/products/bulk-update), a dropdown and "أضف قائمة أسعار" (→ /v2/owner/price_lists/create); the product form carries a hidden per-list price input data[priceList][group_price_id] (placeholder "use default price", tooltip "Remove This Product From Price List"). Settings: default price list data[72][default_price_list]; invoice setting change_price_list unchecked.  
  evidence: `pricelist-list.json`

## 13. Supplier add/edit

URL /owner/suppliers/add; edit /owner/suppliers/edit/1.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| بيانات المورد | الاسم التجاري * | data[Supplier][business_name] | text | * |  | maxlen 100 | shown |
| بيانات المورد | الاسم الأول | data[Supplier][first_name] | text |  |  | maxlen 255 | shown |
| بيانات المورد | الاسم الأخير | data[Supplier][last_name] | text |  |  | maxlen 255 | shown |
| بيانات المورد | الهاتف | data[Supplier][phone1] | tel |  |  |  | shown |
| بيانات المورد | جوال | data[Supplier][phone2] | tel |  |  |  | shown |
| بيانات المورد | رقم المبنى | data[Supplier][building_no] | text |  |  | ph="رقم المبنى" | shown |
| بيانات المورد | اسم الشارع | data[Supplier][address1] | text |  |  | ph="اسم الشارع" ; maxlen 255 | shown |
| بيانات المورد | الرقم الفرعي | data[Supplier][address2] | text |  |  | ph="الرقم الفرعي" ; maxlen 255 | shown |
| بيانات المورد | الحي | data[Supplier][city] | text |  |  | ph="الحي" ; maxlen 100 | shown |
| بيانات المورد | المدينة | data[Supplier][state] | text |  |  | ph="المدينة" ; maxlen 100 | shown |
| بيانات المورد | الرمز البريدي | data[Supplier][postal_code] | text |  |  | ph="الرمز البريدي" ; maxlen 20 | shown |
| بيانات المورد | العنوان المختصر | data[Supplier][short_address] | text |  |  | ph="العنوان المختصر" | shown |
| بيانات المورد | البلد | data[Supplier][country_code] | select-one |  | المملكة العربية السعودية (SA) | =[اختر البلد] ; IS=آيسلندا (IS) ; ET=أثيوبيا (ET) ; AZ=أذربيجان (AZ) ; TF=أراض فرنسية جنوبية وأنتارتيكية (TF) ; AM=أرمينيا (AM) ; ... (248 options) | shown |
| بيانات المورد | الرقم الضريبي (اختياري) | data[Supplier][bn1] | text |  |  | maxlen 255 | shown |
| بيانات المورد | سجل تجارى/ رقم موحد (اختياري) | data[Supplier][bn2] | text |  |  | maxlen 255 | shown |
| بيانات المورد | إضافة عنوان ثانوي | data[Supplier][active_secondary_address] | checkbox |  | unchecked |  | hidden/other tab |
| بيانات المورد | رقم المبنى | data[Supplier][secondary_building_no] | text |  |  | ph="رقم المبنى" | hidden/other tab |
| بيانات المورد | اسم الشارع | data[Supplier][secondary_address1] | text |  |  | ph="اسم الشارع" ; maxlen 255 | hidden/other tab |
| بيانات المورد | الرقم الفرعي | data[Supplier][secondary_address2] | text |  |  | ph="الرقم الفرعي" ; maxlen 255 | hidden/other tab |
| بيانات المورد | الحي | data[Supplier][secondary_city] | text |  |  | ph="الحي" ; maxlen 100 | hidden/other tab |
| بيانات المورد | المدينة | data[Supplier][secondary_state] | text |  |  | ph="المدينة" ; maxlen 100 | hidden/other tab |
| بيانات المورد | الرمز البريدي | data[Supplier][secondary_postal_code] | text |  |  | ph="الرمز البريدي" ; maxlen 50 | hidden/other tab |
| بيانات المورد | العنوان المختصر | data[Supplier][secondary_short_address] | text |  |  | ph="العنوان المختصر" | hidden/other tab |
| بيانات المورد | البلد | data[Supplier][secondary_country_code] | select-one |  | المملكة العربية السعودية (SA) | =[اختر البلد] ; IS=آيسلندا (IS) ; ET=أثيوبيا (ET) ; AZ=أذربيجان (AZ) ; TF=أراض فرنسية جنوبية وأنتارتيكية (TF) ; AM=أرمينيا (AM) ; ... (248 options) | hidden/other tab |
| بيانات الحساب | رقم المورد * | data[Supplier][supplier_number] | text | * | 000008 |  | shown |
| بيانات الحساب | العملة | data[Supplier][default_currency_code] | select-one |  | SAR ريال سعودي | =[اختر العملة] ; AED=AED درهم إماراتي ; AFN=AFN أفغاني ; ALL=ALL ليك ألباني ; AMD=AMD درام أرميني ; ANG=ANG غويلدر الأنتيل الهولندية ; ... (161 options) | shown |
| بيانات الحساب | أرصدة افتتاحية | data[Supplier][starting_balance] | number |  |  |  | shown |
| بيانات الحساب | تاريخ رصيد الافتتاحي | data[Supplier][starting_balance_date] | text |  | 27/10/2026 |  | shown |
| بيانات الحساب | البريد الإلكتروني | data[Supplier][email] | email | cls |  |  | shown |
| بيانات الحساب | الملاحظات | data[Supplier][notes] | textarea |  |  |  | shown |
| بيانات الحساب | رقم المورد * | (widget, no name) | file | * |  |  | hidden/other tab |

Contact-list row (after "إضافة جهة اتصال"): data[SupplierDetail][Tempid][first_name|last_name|email|home_phone|mobile] labelled الاسم الأول / الاسم الأخير / البريد الإلكتروني / الهاتف / جوال.


**Facts**

- **pw-forms-49** (observed) Supplier add (/owner/suppliers/add, SupplierForm): panel "بيانات المورد": الاسم التجاري * (business_name, 100), الاسم الأول, الاسم الأخير, الهاتف, جوال, رقم المبنى, اسم الشارع, الرقم الفرعي, الحي, المدينة, الرمز البريدي, العنوان المختصر, البلد (default SA), الرقم الضريبي (اختياري) (bn1), سجل تجارى/ رقم موحد (اختياري) (bn2), إضافة عنوان ثانوي (checkbox revealing 8 secondary address fields), contact list button "إضافة جهة اتصال" (rows: الاسم الأول, الاسم الأخير, البريد الإلكتروني, الهاتف, جوال); panel "بيانات الحساب": رقم المورد * (default 000008), العملة (default SAR), أرصدة افتتاحية (starting_balance, number), تاريخ رصيد الافتتاحي (default today), البريد الإلكتروني, الملاحظات, المرفقات. There is no tax-type, category, language or credit-limit field.  
  evidence: `p-supplier-add.probe.json`
- **pw-forms-50** (observed) Supplier opening balance and its date exist only on the add form: the edit form of supplier 1 has neither data[Supplier][starting_balance] nor starting_balance_date; supplier email input has class "required" without a star in its label (supplier JS rule: .required notEmpty, .numeric isNumeric).  
  evidence: `supplier-edit-1.json`
- **pw-forms-82** (observed) Inline new-supplier block inside purchase documents: supplier_business_name *, first/last name, supplier_email, building, street, sub-number, district, city, postal code, short address, phone1, phone2, country (SA), supplier_bn1 (الرقم الضريبي (اختياري)), supplier_bn2 (سجل تجارى/ رقم موحد (اختياري)), UpdateSupplierData "تحديث بيانات المورد"; hidden Supplier[supplier_from_data]=1, supplier_default_currency_code.  
  evidence: `p-pinv-add.probe.json`

## 14. Client add/edit

URL /owner/clients/add; edit /owner/clients/edit/1; raw React schema /v2/owner/entity/client/create.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| بيانات العميل | (radio group) | data[Client][type] | radio | | none preselected unless marked | 2=فردي ; 3=تجاري | |
| بيانات العميل | الاسم الكامل / الاسم التجاري * | data[Client][business_name] | text | *+cls |  | maxlen 100 | shown |
| بيانات العميل | الاسم الأول | data[Client][first_name] | text |  |  | maxlen 255 | shown |
| بيانات العميل | الاسم الأخير | data[Client][last_name] | text |  |  | maxlen 255 | shown |
| بيانات العميل | الهاتف | data[Client][phone1] | tel |  |  |  | shown |
| بيانات العميل | جوال | data[Client][phone2] | tel |  |  |  | shown |
| بيانات العميل | رقم المبنى | data[Client][building_no] | text |  |  | ph="رقم المبنى" | shown |
| بيانات العميل | اسم الشارع | data[Client][address1] | text |  |  | ph="اسم الشارع" ; maxlen 255 | shown |
| بيانات العميل | الرقم الفرعي | data[Client][address2] | text |  |  | ph="الرقم الفرعي" ; maxlen 255 | shown |
| بيانات العميل | الحي | data[Client][city] | text |  |  | ph="الحي" ; maxlen 100 | shown |
| بيانات العميل | المدينة | data[Client][state] | text |  |  | ph="المدينة" ; maxlen 100 | shown |
| بيانات العميل | الرمز البريدي | data[Client][postal_code] | text |  |  | ph="الرمز البريدي" ; maxlen 20 | shown |
| بيانات العميل | العنوان المختصر | data[Client][short_address] | text |  |  | ph="العنوان المختصر" | shown |
| بيانات العميل | البلد | data[Client][country_code] | select-one |  | المملكة العربية السعودية (SA) | =[اختر البلد] ; IS=آيسلندا (IS) ; ET=أثيوبيا (ET) ; AZ=أذربيجان (AZ) ; TF=أراض فرنسية جنوبية وأنتارتيكية (TF) ; AM=أرمينيا (AM) ; ... (248 options) | hidden/other tab |
| بيانات العميل | المنطقة الزمنية | data[Client][timezone] | select-one |  | اختر منطقة زمنية | =اختر منطقة زمنية ; 283=(UTC-01:00) Azores ; 286=(UTC-01:00) Cape Verde Is. ; 151=(UTC-02:00) Mid-Atlantic ; 144=(UTC-02:00) Montevideo ; 174=(UTC-03:00) Brasilia ; ... (140 options) | hidden/other tab |
| بيانات العميل | العميل جهة حكومية | data[Client][is_government_entity] | checkbox |  | unchecked |  | hidden/other tab |
| بيانات العميل | الرقم الضريبي (اختياري) | data[Client][bn1] | text |  |  | maxlen 255 | shown |
| بيانات العميل | نوع المعرف | data[Client][identifier_type] | select-one |  | اختر نوع المعرف | =اختر نوع المعرف ; CRN=السجل التجاري ; MOM=ترخيص وزارة الشؤون البلدية والقروية والإسكان ; MLS=ترخيص وزارة الموارد البشرية والتنمية الاجتماعية ; SAG=ترخيص الهيئة العامة للاستثمار ; 700=رقم 700 ; TIN=الرقم المميز ; NAT=رقم الهوية الوطنية ; IQA=رقم الإقامة ; GCC=هوية خليجية ; PAS=رقم جواز السفر ; OTH=أخرى | shown |
| بيانات العميل | الرقم الضريبى الموحد | data[Client][bn2] | text |  |  | maxlen 255 ; DIS | hidden/other tab |
| بيانات العميل | سجل تجارى/ رقم موحد (اختياري) | data[Client][bn2] | text |  |  | maxlen 255 | shown |
| بيانات الحساب | رقم الكود * | data[Client][client_number] | text | * | 000005 |  | shown |
| بيانات الحساب | طريقة الفوترة | data[Client][is_offline] | select-one |  | طباعة | 0=أرسل عبر البريد ; 1=طباعة | shown |
| بيانات الحساب | العملة | data[Client][default_currency_code] | select-one |  | SAR ريال سعودي | =[اختر العملة] ; AED=AED درهم إماراتي ; AFN=AFN أفغاني ; ALL=ALL ليك ألباني ; AMD=AMD درام أرميني ; ANG=ANG غويلدر الأنتيل الهولندية ; ... (161 options) | shown |
| بيانات الحساب | البريد الإلكتروني | data[Client][email] | email |  |  |  | shown |
| بيانات الحساب | الملاحظات | data[Client][notes] | textarea |  |  |  | shown |
| بيانات الحساب | رقم الكود * | (widget, no name) | file | * |  |  | hidden/other tab |
| بيانات الحساب | لغة العرض | data[Client][language_code] | select-one |  | اختر اللغة | =اختر اللغة ; 41=الانجليزية (EN) ; 7=العربية (AR) | shown |
| بيانات الحساب | أرسل بيانات الدخول للعميل | data[Client][send_notify] | checkbox |  | unchecked |  | hidden/other tab |

Secondary address fields (shown by "إضافة عنوان ثانوي"): data[Client][secondary_building_no|secondary_address1|secondary_address2|secondary_city|secondary_state|secondary_postal_code|secondary_short_address|secondary_country_code].
Contact-list row: data[ClientDetail][Tempid][first_name|last_name|email|home_phone|mobile].

Probe:
- data[Client][type] [فردي] radio=select: shows ["data[Client][bn2](الرقم الضريبى الموحد)"]; hides ["data[Client][first_name](الاسم الأول)","data[Client][last_name](الاسم الأخير)","data[Client][bn1](الرقم الضريبي (اختياري))","data[Client][bn2](سجل تجارى/ رقم موحد (اختياري))"]
- data[Client][active_secondary_address] [إضافة عنوان ثانوي] checkbox=toggle: shows ["data[Client][secondary_building_no](رقم المبنى)","data[Client][secondary_address1](اسم الشارع)","data[Client][secondary_address2](الرقم الفرعي)","data[Client][secondary_city](الحي)","data[Client][secondary_state](المدينة)","data[Client][secondary_postal_code](الرمز البريدي)","data[Client][secondary_short_address](العنوان المختصر)","data[Client][secondary_country_code](البلد)"]; hides []

Raw React schema (credit limit / opening balance are visible there):

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| معلومات العملاء | إيقاف | suspend | checkbox |  | unchecked |  | shown |
| معلومات العملاء | Last Ip | last_ip | text |  |  |  | shown |
| معلومات العملاء | تاريخ الميلاد | follow_up_status | select-one |  | (empty) | = | hidden/other tab |
| معلومات العملاء | تصنيف | category | text |  |  |  | shown |
| معلومات العملاء | الرقم الضريبي | bn1 | text |  |  |  | shown |
| معلومات العملاء | Tax Number Label | bn1_label | text |  |  |  | shown |
| معلومات العملاء | Registration Number Label | bn2_label | text |  |  |  | shown |
| معلومات العملاء | رقم التسجيل | bn2 | text |  |  |  | shown |
| معلومات العملاء | الرصيد الافتتاحي | starting_balance | number |  |  |  | shown |
| معلومات العملاء | الصورة | (widget, no name) | file |  |  |  | hidden/other tab |
| معلومات العملاء | النوع | gender | select-one |  | (empty) | = | hidden/other tab |
| معلومات العملاء | الموقع | map_location | text |  |  |  | shown |
| معلومات العملاء | النوع | type | select-one |  | (empty) | = | hidden/other tab |
| معلومات العملاء | Credit Limit Amount | credit_limit | number |  |  |  | shown |
| معلومات العملاء | رقم الهوية الوطنية | national_id | text |  |  |  | shown |
| معلومات العملاء | Secondary Follow Up Status | secondary_follow_up_status | text |  |  |  | shown |
| معلومات العملاء | الموظفين المرتبطين | assigned_users[] | select-multiple |  |  |  | hidden/other tab |
| معلومات العملاء | كود اللغة | language_code | number |  |  |  | shown |
| معلومات العملاء | طريقة الايجار | is_offline | checkbox |  | unchecked |  | shown |
| معلومات العملاء | اسم الشركة * | business_name | text | * |  |  | shown |
| معلومات العملاء | الاسم الاول | first_name | text |  |  |  | shown |
| معلومات العملاء | الاسم الاخير | last_name | text |  |  |  | shown |
| معلومات العملاء | البريد الإلكتروني | email | email |  |  |  | shown |
| معلومات العملاء | كلمة المرور | password | password |  |  |  | shown |
| معلومات العملاء | Address2 | address2 | text |  |  |  | shown |
| معلومات العملاء | المدينة | city | text |  |  |  | shown |
| معلومات العملاء | الولاية | state | text |  |  |  | shown |
| معلومات العملاء | Secondary Address2 | secondary_address2 | text |  |  |  | shown |
| معلومات العملاء | كود العملة الإفتراضي | default_currency_code | text |  |  |  | shown |
| معلومات العملاء | رمز الدولة الثانوى | secondary_country_code | text |  |  |  | shown |
| معلومات العملاء | الرقم البريدي الثانوى | secondary_postal_code | text |  |  |  | shown |
| معلومات العملاء | الولاية الثانوىة | secondary_state | text |  |  |  | shown |
| معلومات العملاء | المدينة الثانوىة | secondary_city | text |  |  |  | shown |
| معلومات العملاء | Telephone | phone1[code] | select-one |  | (empty) | = | hidden/other tab |
| معلومات العملاء | Telephone | phone1 | text |  |   |  | shown |
| معلومات العملاء | Secondary Address1 | secondary_address1 | text |  |  |  | shown |
| معلومات العملاء | الاسم الثانوى | secondary_name | text |  |  |  | shown |
| معلومات العملاء | Has a Secondary Address | active_secondary_address | checkbox |  | unchecked |  | shown |
| معلومات العملاء | ملاحظات | notes | textarea |  |  |  | shown |
| معلومات العملاء | رمز الدولة | country_code | select-one |  | (empty) | = | hidden/other tab |
| معلومات العملاء | رقم الأمر | client_number[code] | text |  |  |  | shown |
| معلومات العملاء | Address1 | address1 | text |  |  |  | shown |
| معلومات العملاء | الوسوم | tags[] | select-multiple |  |  |  | hidden/other tab |


**Facts**

- **pw-forms-10** (observed) /owner/clients/settings has these financial and identity toggles all unchecked: data[1][starting_balance] أرصدة افتتاحية، data[1][enable_client_credit_limit] الحد الائتماني، data[1][client_credit_period] المدة الائتمانية، data[1][photo]، national_id، birth_date، gender، barcode، link، multiple_addresses، map_location; client type setting is "فردي وتجاري معًا" (value 0); next client number data[1][next_auto_number] 000005 is disabled/readonly. The client add form therefore has no opening balance, credit limit, credit period, photo, national id, birth date, gender, barcode, link, multiple-address or map fields in its DOM.  
  evidence: `st-clients.json`
- **pw-forms-51** (observed) Client add (/owner/clients/add, ClientForm multipart) panels "بيانات العميل", "قائمة الاتصال", "بيانات الحساب"; no tabs. Fields: نوع العميل radio data[Client][type] (2=فردي, 3=تجاري, none preselected), الاسم الكامل / الاسم التجاري * (business_name, 100), الاسم الأول, الاسم الأخير (255), الهاتف, جوال, address block (رقم المبنى, اسم الشارع, الرقم الفرعي, الحي, المدينة, الرمز البريدي, العنوان المختصر), البلد (SA), نوع المعرف (identifier_type: CRN السجل التجاري, MOM, MLS, SAG, 700, TIN الرقم المميز, NAT رقم الهوية الوطنية, IQA رقم الإقامة, GCC هوية خليجية, PAS رقم جواز السفر, OTH أخرى), الرقم الضريبي (bn1), سجل تجارى/ رقم موحد (bn2), العميل جهة حكومية (hidden), المنطقة الزمنية (hidden), إضافة عنوان ثانوي; account panel: رقم الكود * (client_number default 000005, editable text), طريقة الفوترة (0=أرسل عبر البريد, 1=طباعة default), العملة (SAR), البريد الإلكتروني, التصنيف (multi-select widget), الملاحظات, المرفقات, لغة العرض (41=الانجليزية (EN), 7=العربية (AR)), أرسل بيانات الدخول للعميل (hidden).  
  evidence: `p-client-add.probe.json`
- **pw-forms-52** (observed) Client form type logic (inline JS): فردي (2) hides the business-only rows (the first/last name and tax number rows in this layout), shows individual rows (and birth date/gender when enabled), renames the name label to «Full Name»; for country SA + individual it shows "الرقم الضريبى الموحد" (bn2_individual, enabled only then); تجاري (3) shows business rows and label «Business Name». Probe: clicking فردي hid first_name, last_name, bn1 and the bn2 registration field and revealed the unified number field; clicking تجاري changed nothing visible.  
  evidence: `src-client-add.inline.js`
- **pw-forms-53** (observed) Client invoicing method rule (add_client.js): طريقة الفوترة = 0 (أرسل عبر البريد) adds class required to the email, enables password/confirm password/generate-password and shows the email-method rows (error text: You have to enter the "Email Address" of the client, or change his Invoicing Method); = 1 (طباعة) removes the requirement. Submit rules: input.required → «Required», input.email → «Please enter a valid email», input.number → «Please enter a valid number»; duplicate detection offers buttons .Update_Existed_Client (re-posts with data[Client][ref_url]) and .add_as_newclient (adds data[Client][skip_email]=1).  
  evidence: `js/add_client.js`
- **pw-forms-54** (observed) The React client create page /v2/owner/entity/client/create (raw schema, labels partly mismatched) does show fields hidden by settings in the legacy form: starting_balance (الرصيد الافتتاحي), credit_limit ("Credit Limit Amount"), national_id, gender, map_location, suspend (إيقاف), follow_up_status, secondary_follow_up_status, assigned_users[] (الموظفين المرتبطين), tags[], category, bn1_label/bn2_label, last_ip, password, language_code, default_currency_code, phone1[code]; business_name (اسم الشركة *) is the only required field.  
  evidence: `v2-client-create.probe.json`
- **pw-forms-55** (observed) Client edit form (/owner/clients/edit/1) has the same controls as add except data[Client][send_notify] is absent and client_number holds the stored 000001.  
  evidence: `client-edit-1.json`
- **pw-forms-81** (observed) Inline "جديد" client block inside sales documents posts the client with the document: data[Invoice][client_type] radio (2 فردي, 3 تجاري), client_business_name *, client_first_name, client_last_name, client_email * (required for the print/e-mail method), client_building_no, client_address1, client_address2, client_city (الحي), client_state (المدينة), client_postal_code, client_short_address, client_phone1, client_phone2, client_country_code (SA), client_is_government_entity, client_bn1, client_identifier_type (12 options incl. CRN, NAT, IQA, GCC, PAS, OTH), client_bn2, client_active_secondary_address "إضافة عنوان شحن" with client_secondary_* (name, building, street, sub-number, district, city, postal code, short address, country), UpdateClientData "تحديث معلومات العميل"; hidden Client[client_from_data], address_id, group_price_id.  
  evidence: `p-invoice-add.probe.json`

## 15. Product / service add/edit and related master data

URLs /owner/products/add/1, /add/2, /edit/1; /owner/unit_templates/add; /owner/categories/add?type=1|4; /v2/owner/entity/brand/create. Units, bundles and tracking cannot be shown because their settings are off (see section 1); what the page source reveals is in the facts.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| تفاصيل البند | الاسم * | data[Product][name] | text | *+cls |  | maxlen 255 | shown |
| تفاصيل البند | الرقم التسلسلي SKU | data[Product][product_code] | text |  | 000010 |  | shown |
| تفاصيل البند | الوصف | data[Product][description] | textarea |  |  |  | shown |
| تفاصيل البند | الصور | (widget, no name) | file |  |  |  | hidden/other tab |
| تفاصيل البند | التصنيف | (widget, no name) | text |  |  |  | shown |
| تفاصيل البند | الماركة | (widget, no name) | text |  |  |  | shown |
| تفاصيل البند | المورد | data[Product][supplier_id] | select-one |  | اختر مورد | =اختر مورد ; 7=افتراضي-S35 مورد ; 2=شركة الأمل للمستلزمات (مورد افتراضي) ; 1=شركة الخليج للتوريدات (مورد تجريبي) ; 3=مورد افتراضي-F1 ; 4=مورد افتراضي-F2 ; 5=مورد افتراضي-F3 ; 6=مورد افتراضي-F4 | shown |
| تفاصيل البند | كود المورد للمنتج | data[Product][supplier_code] | text |  |  |  | hidden/other tab |
| تفاصيل البند | باركود | data[Product][barcode] | text |  |  | maxlen 255 | shown |
| تفاصيل التسعير | سعر الشراء | data[Product][buy_price] | number |  |  |  | shown |
| تفاصيل التسعير | سعر البيع | data[Product][unit_price] | number |  |  |  | shown |
| تفاصيل التسعير | الضريبة 1 متقدم | data[Product][tax1] | select-one |  | القيمة المضافة | =اختر ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب | shown |
| تفاصيل التسعير | الضريبة 2 | data[Product][tax2] | select-one |  | اختر ضريبة | =اختر ضريبة ; 1=القيمة المضافة ; -1=إعدادات الضرائب | shown |
| تفاصيل التسعير | ضريبة الشراء 1 | data[Product][purchasing_tax1] | select-one |  | مطابق لضريبه المبيعات 1 | =مطابق لضريبه المبيعات 1 ; 1=القيمة المضافة ; -1=بدون ضريبة | hidden/other tab |
| تفاصيل التسعير | ضريبة الشراء 2 | data[Product][purchasing_tax2] | select-one |  | مطابق لضريبه المبيعات 2 | =مطابق لضريبه المبيعات 2 ; 1=القيمة المضافة ; -1=بدون ضريبة | hidden/other tab |
| تفاصيل التسعير | أقل سعر بيع | data[Product][minimum_price] | number |  |  |  | shown |
| تفاصيل التسعير | الخصم | data[Product][discount] | number |  |  |  | shown |
| تفاصيل التسعير | نوع الخصم | data[Product][discout_type] | select-one |  | % | 1=% ; 2=$ | shown |
| تفاصيل التسعير | هامش الربح نسبة مئوية | data[Product][profit_margin] | number |  |  |  | shown |
| إدارة المخزون | تتبع المخزون | data[Product][track_stock] | checkbox |  | checked |  | hidden/other tab |
| إدارة المخزون | الكمية بالمخزون | data[Product][initial_stock_level] | number |  | 0 |  | shown |
| إدارة المخزون | نبهني عند وصول الكمية إلى أقل من | data[Product][low_stock_thershold] | number |  | 0 |  | shown |
| خيارات أكثر: | ملاحظات داخلية | data[Product][notes] | textarea |  |  |  | shown |
| خيارات أكثر: | وسوم | (widget, no name) | text |  |  |  | shown |
| خيارات أكثر: | الحالة | data[Product][status] | select-one |  | نشط | 0=نشط ; 2=موقوف ; 1=غير نشط | shown |
|  | 112 | data[priceList][group_price_id] | number |  |  | ph="use default price" | hidden/other tab |

Probe + interactive checks:
- data[Product][track_stock] [تتبع المخزون] checkbox=toggle: shows []; hides ["data[Product][initial_stock_level](الكمية بالمخزون)","data[Product][low_stock_thershold](نبهني عند وصول الكمية إلى أقل من)"]
- click "متقدم" (act-product.json): shows data[Product][purchasing_tax1], data[Product][purchasing_tax2]
- select supplier 2: shows data[Product][supplier_code]
- save dropdown: "حفظ & إضافة جديدة"

Service form fields: see `p-product-add-2.probe.json`.

Unit template add form:

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | اسم الوحدة الاساسية | data[UnitTemplate][main_unit_name] | text | cls |  | ph="مثال: جرام" ; maxlen 255 | shown |
|  | التمييز | data[UnitTemplate][unit_small_name] | text | cls |  | ph="مثال: جم" ; maxlen 25 | shown |
|  | اسم الوحدة الأكبر | data[UnitFactor][1][factor_name] | text |  |  | ph="I.e: Kilogram" ; maxlen 255 | shown |
|  | معامل التحويل | data[UnitFactor][1][factor] | number |  |  | ph="مثال: 1000" | shown |
|  | التمييز | data[UnitFactor][1][small_name] | text |  |  | ph="مثال: كجم" ; maxlen 25 | shown |
|  | القالب | data[UnitTemplate][template_name] | text | cls |  | ph="مثال: الوزن" ; maxlen 255 | shown |
|  | نشط | data[UnitTemplate][active] | checkbox |  | checked |  | hidden/other tab |

Category add form:

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| معلومات التصنيف | الاسم | data[Category][name] | text |  |  | maxlen 255 | shown |
| معلومات التصنيف | التصنيف الرئيسي | data[Category][parent_id] | select-one |  | اختر تصنيف | =اختر تصنيف | shown |
| معلومات التصنيف | الوصف | data[Category][description] | textarea |  |  |  | shown |
| معلومات التصنيف | المرفقات | (widget, no name) | file |  |  |  | hidden/other tab |

Expense category add form:

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | الاسم | data[ExpenseCategory][name] | text |  |  | maxlen 100 | shown |
|  | الوصف | data[ExpenseCategory][description] | textarea |  |  |  | shown |
|  | تعطيل | data[ExpenseCategory][deactivated] | checkbox |  | unchecked |  | hidden/other tab |


**Facts**

- **pw-forms-9** (observed) Multiple units per item (data[72][enable_multi_units]), bundles (data[72][enable_bundles], page text beside it "مسموح") and serial/batch/expiry tracking (data[72][enable_tracking_numbers]) are all unchecked in /owner/settings/inventory; the product add form therefore renders no unit, bundle or tracking control. The settings page also exposes unchecked data[72][enable_stock_requests], enable_requisitions (sales) and enable_requisitions_po (purchases), and the negative-stock radio negativeInventoryOption is on "السماح فقط للمنتجات القابلة للتتبع بالكميات".  
  evidence: `st-inventory.json`
- **pw-forms-56** (observed) Product add routes: /owner/products/add/1 ("أضف Product", منتج جديد) and /owner/products/add/2 ("أضف Service", خدمة جديدة) with hidden data[Product][type] 1 or 2; /owner/products/add/1/<id> is the copy form. Product form sections: تفاصيل البند, تفاصيل التسعير, إدارة المخزون, خيارات أكثر:.  
  evidence: `p-product-add-1.probe.json`
- **pw-forms-57** (observed) Product (type 1) fields: الاسم * (name 255), الرقم التسلسلي SKU (product_code default 000010), الوصف, الصور (multi file), التصنيف (multi-select widget #ProductCategory, strict suggest, several categories allowed), الماركة (single-select widget #ProductBrand), المورد (supplier_id; picking one reveals كود المورد للمنتج supplier_code), باركود (barcode 255 + button Auto Fill calling GET /owner/products/get_auto_barcode/3/1, confirm «سوف يتم استبدال القيمة» if filled), سعر الشراء (buy_price), سعر البيع (unit_price), الضريبة 1 (tax1 default 1=القيمة المضافة; placeholder اختر ضريبة; -1=إعدادات الضرائب), الضريبة 2, ضريبة الشراء 1/2 (purchasing_tax1/2: options مطابق لضريبه المبيعات 1/2, 1=القيمة المضافة, -1=بدون ضريبة; revealed by the link "متقدم" which sets is_multi_tax_enabled=1), أقل سعر بيع (minimum_price), الخصم (discount) + نوع الخصم (discout_type 1=% default, 2=$), هامش الربح نسبة مئوية (profit_margin), تتبع المخزون (track_stock, checked), الكمية بالمخزون (initial_stock_level default 0), نبهني عند وصول الكمية إلى أقل من (low_stock_thershold default 0), ملاحظات داخلية, وسوم (multi tag widget), الحالة (status 0=نشط default, 2=موقوف, 1=غير نشط).  
  evidence: `p-product-add-1.probe.json`
- **pw-forms-58** (observed) Product form conditional rules (probe + product-add.js): unticking تتبع المخزون hides الكمية بالمخزون and نبهني عند وصول الكمية; clicking "متقدم" reveals the two purchase-tax selects; with advanced pricing enabled (setting advanced_pricing_options checked) a live check shows error «The discount would reduce the selling price below the minimum price» plus "Price after discount: x, Minimum price: y" when price − discount (percent or amount) < minimum price, skipped when price, discount or minimum is 0; tax select value -1 opens the taxes settings iframe and resets the select; submit rules input.required, input.numeric, .quantity.  
  evidence: `js/product-add.js`
- **pw-forms-59** (observed) Service form (type 2) differs from product: code label كود الخدمة, price label سعر الوحدة, and it has no الماركة, no باركود, no تتبع المخزون, no الكمية بالمخزون, no نبهني عند وصول الكمية; it keeps category, supplier, taxes, minimum price, discount, margin, notes, tags and status.  
  evidence: `p-product-add-2.probe.json`
- **pw-forms-60** (observed) Product edit form (/owner/products/edit/1) = add form without initial_stock_level and hidden store_id; buttons Auto Fill, إلغاء, تحديث. Product status filter in the list: 1=متاح, 2=مخزون منخفض, 3=غير متوفر, 4=غير نشط, 5=موقوف.  
  evidence: `product-edit-1.json`
- **pw-forms-61** (observed) Product page JS (inline) contains handlers for controls absent in this account: #unit_template (unit template select), #stock_factor (stock unit factor option attribute factor), #ProductTrackingType (value "quantity_only" vs other tracking types), #virtual_stock (initial stock typed in the chosen unit, converted: ProductInitialStockLevel = factor × virtual stock) and a multi-barcode section where every barcode row needs a unit factor (alert «معامل الوحدة مطلوب لكل باركود صنف»).  
  evidence: `src-product-add-1.inline.js`
- **pw-forms-62** (observed) Unit template add form (/owner/unit_templates/add): اسم الوحدة الاساسية (main_unit_name, ex جرام), التمييز (unit_small_name, ex جم, max 25), القالب (template_name, ex الوزن), نشط (checked) and repeatable larger-unit rows: اسم الوحدة الأكبر (factor_name), معامل التحويل (factor, ex 1000), التمييز (small_name); hidden update_all_costs. Category add (/owner/categories/add?type=1|4): الاسم, التصنيف الرئيسي (parent_id), الوصف, المرفقات (types 1=products, 4=clients); expense/income category add (/owner/expense_categories/add/1|2): الاسم, الوصف, تعطيل. Brand create (/v2/owner/entity/brand/create): الاسم * only.  
  evidence: `unit-template-add.json`
- **pw-forms-63** (observed) Extra fields builder (/owner/CustomForms/custom_fields/1 for products, /2 for invoices; React builders under /v2/owner/local_entities/builder/le_custom_data_<entity> for client, supplier, journal, asset, purchase_order, staff) offers 20 field types: سطر واحد، سطور متعددة، رقم، رقم عشري، البريد الإلكتروني، التاريخ، الوقت، تاريخ ووقت، القائمة المنسدلة، القائمة متعددة الخيارات، خيار وحيد، مربع الإختيار، مربعات الاختيار، الموقع، العملة، رفع ملف، رفع صورة، تقييم، فاصل، كتابة فاصلة.  
  evidence: `st-custom-products.txt`
- **pw-forms-84** (observed) Barcode/product settings (/owner/settings/product_settings): barcode_type Code 128 selected (alternative EAN13), embedded_barcode "باركود متضمن الوزن" unchecked with hidden embedded_barcode_format, weight_unit_divider, currency_divider, scale_barcode_behavior (merge default / separate). Inventory defaults page: default tax 1 = القيمة المضافة, tax 2 none, default warehouse none, refund_receipt_calculation_method = حسب سعر البيع (0) vs آخر متوسط للتكلفة (1), sold_item_type = منتجات وخدمات.  
  evidence: `st-product-settings.json`

## 16. Expense (مصروف) add/edit

URL /owner/expenses/add; edit /owner/expenses/edit/1.

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | المبلغ | data[Expense][amount] | number | cls |  | ph="0.00" | shown |
|  | المبلغ | data[Expense][currency_code] | select-one |  | SAR | =[افتراضي] ; AED=AED ; AFN=AFN ; ALL=ALL ; AMD=AMD ; ANG=ANG ; ... (161 options) | hidden/other tab |
|  | التصنيف | (widget, no name) | text |  |  | ph="إضافة تصنيف" | shown |
|  | رقم المصروف * | data[Expense][expense_number] | text | * | 000003 |  | shown |
|  | خزينة | data[Expense][treasury_id] | select-one |  | Main Treasury | 1=Main Treasury ; 3=افتراضي-S35 بنك | shown |
|  | التاريخ | data[Expense][date] | text |  | 27/10/2026 |  | shown |
|  | متكرر | data[Expense][recurring] | checkbox |  | unchecked |  | shown |
|  | التكرار | data[RecurringExpense][unit_name] | select-one |  | Weekly | weekly=Weekly ; 2 Week=2 Week ; 4 Weeks=4 Weeks ; Monthly=Monthly ; 2 Months=2 Months ; 3 Months=3 Months ; 6 Months=6 Months ; Yearly=Yearly ; 2 Year=2 Year | hidden/other tab |
|  | تاريخ الإنتهاء | data[RecurringExpense][end_date] | text |  |  |  | hidden/other tab |
|  | الوصف | data[Expense][note] | textarea |  |  |  | shown |
|  | المرفقات | (widget, no name) | file |  |  |  | hidden/other tab |
|  | تحديث بيانات المورد | (widget, no name) | checkbox |  | unchecked |  | hidden/other tab |
|  | الحساب الفرعي | data[Expense][journal_account_id] | select-one |  | آلي | =آلي | hidden/other tab |
|  | حسابات متعددة | data[Expense][showmultiAccounts] | checkbox |  | unchecked |  | shown |
|  | تعيين مراكز التكلفة | data[Expense][manual_cost_centers] | checkbox |  | unchecked |  | hidden/other tab |
| خيارات اخرى | الضريبة الاولى | data[Expense][tax1_id] | select-one |  | Tax Type | =Tax Type ; 1=القيمة المضافة | shown |
| خيارات اخرى | الضريبة الاولى | data[Expense][tax1_amount] | text |  |  | ph="المبلغ" | shown |
| خيارات اخرى | البائع | (widget, no name) | text |  |  | ph="البائع" | shown |

Probe:
- data[Expense][recurring] [متكرر] checkbox=toggle: shows ["data[RecurringExpense][unit_name](التكرار)","data[RecurringExpense][end_date](تاريخ الإنتهاء)"]; hides []
- data[Expense][showmultiAccounts] [حسابات متعددة] checkbox=toggle: shows ["data[JournalTransaction][0][description](textarea)","data[JournalTransaction][0][amount](text)","data[JournalTransaction][0][tax_id](الضرائب)","(لا شئ)"]; hides ["data[Expense][tax1_id](الضريبة الاولى)","data[Expense][tax1_amount](الضريبة الاولى)"]
- collapse "خيارات اخرى": shows []; hides ["data[Expense][tax1_id](الضريبة الاولى)","data[Expense][tax1_amount](الضريبة الاولى)","(البائع)"]


**Facts**

- **pw-forms-64** (observed) Expense add (/owner/expenses/add, ExpenseForm multipart): المبلغ (amount, required class, ph 0.00; currency link SAR with "[افتراضي]" + 160 codes), التصنيف (widget ph إضافة تصنيف; only category in account: إيجار), رقم المصروف * (default 000003), خزينة (treasury_id: Main Treasury default, افتراضي-S35 بنك), التاريخ, متكرر (recurring checkbox), الوصف (note), المرفقات, المورد (supplier_id select + inline new supplier fields business name *, first/last name, email, address, phone, country SA, tax numbers, UpdateSupplierData), الحساب الفرعي (journal_account_id default "آلي"), حسابات متعددة (showmultiAccounts), تعيين مراكز التكلفة (manual_cost_centers), collapse "خيارات اخرى": الضريبة الاولى (tax1_id: Tax Type | القيمة المضافة) + tax1_amount (المبلغ), البائع.  
  evidence: `p-expense-add.probe.json`
- **pw-forms-65** (observed) Expense recurrence: ticking متكرر reveals data[RecurringExpense][unit_name] (التكرار; options shown in English: weekly default, 2 Week, 4 Weeks, Monthly, 2 Months, 3 Months, 6 Months, Yearly, 2 Year) and data[RecurringExpense][end_date] (تاريخ الإنتهاء). Ticking حسابات متعددة hides the single tax controls and shows repeater rows data[JournalTransaction][n] (account default الحساب الافتراضي, description, amount, tax_id الضرائب, cost_center لا شئ). Client rules: .amount notEmpty + isNotMinus + isNumeric; select.journal_account_id validAccount «مطلوب» (setting expenses_mandatory "اختيار إلزامي لحساب المصروفات" unchecked).  
  evidence: `p-expense-add.probe.json`
- **pw-forms-66** (observed) Expense statuses: list filter status = issued (اصدر) | draft (مسودة); expense category type 1 and income category type 2 (expense_categories/index/1|2); edit form /owner/expenses/edit/1 adds hidden data[Expense][category][] = «إيجار» and button "تحديث" (menu "تحديث & طباعة").  
  evidence: `list-filters.json`

## 17. Revenue / receipt voucher (سند قبض / إيراد) add

URL /owner/incomes/add (list /owner/incomes/index?reset=1 is empty).

Controls differ from the expense form only by the missing supplier block and the number label رقم الإيراد *. Hidden: data[Expense][default_expense_number]=000001 ; data[Expense][id]= ; data[Expense][recurring]=0 ; data[RecurringExpense][id]= ; data[Expense][attachment]= ; data[Expense][showmultiAccounts]=0 ; data[Expense][manual_cost_centers]=0 ; data[Expense][tax1_name]= ; data[Expense][tax1_value]= ; data[Expense][tax1_included]= ; data[Expense][tax2_name]= ; data[Expense][tax2_value]= ; data[Expense][tax2_included]= ; data[JournalTransaction][0][percent]= ; data[JournalTransaction][0][included]= ; next_action=go_index


**Facts**

- **pw-forms-67** (observed) Revenue/receipt voucher add (/owner/incomes/add, same ExpenseForm class and field names data[Expense][...], title "إضافة إيراد") equals the expense form minus the whole supplier block: no client/payer field; number label رقم الإيراد * (default 000001); same amount, currency, category, treasury, date, recurring, note, attachments, journal account (آلي), multi accounts, cost centres and tax1 options; setting income_mandatory "اختيار إلزامي لحساب الدخل" unchecked. No receipt voucher exists (list empty).  
  evidence: `p-income-add.probe.json`

## 18. Treasury and bank account add/edit

URLs /owner/treasuries/add, /owner/treasuries/add?bank_account=1, /owner/treasuries/edit/1, /owner/treasuries/edit/3.

### Treasury

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| الصلاحيات | (radio group) | Treasury[active] | radio | | none preselected unless marked | CHECKED:1=نشط (default) ; 0=غير نشط | |
| الصلاحيات | اسم الخزينه * | Treasury[name] | text | * |  |  | shown |
| الصلاحيات | الوصف | Treasury[description] | textarea |  |  |  | shown |
| الصلاحيات | إيداع | ItemPermission[group_type][1]  | select-one |  | الكل | -1=الكل ; ph="من" | hidden/other tab |
| الصلاحيات | إيداع | ItemPermission[group_id][1][1][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | إيداع | ItemPermission[group_id][1][2][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | إيداع | ItemPermission[group_id][1][3][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | سحب | ItemPermission[group_type][2]  | select-one |  | الكل | -1=الكل ; ph="من" | hidden/other tab |
| الصلاحيات | سحب | ItemPermission[group_id][2][1][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | سحب | ItemPermission[group_id][2][2][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | سحب | ItemPermission[group_id][2][3][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |

### Bank account

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| الصلاحيات | الاسم * | Treasury[name] | text | * |  |  | shown |
| الصلاحيات |  | portal_bank_id | text |  |  |  | hidden/other tab |
| الصلاحيات |  | portal_bank_provider_id | text |  |  |  | hidden/other tab |
| الصلاحيات |  | portal_provider_internal_bank_id | text |  |  |  | hidden/other tab |
| الصلاحيات | اسم البنك * | Treasury[type_name] | select-one | * | (empty) | = ; ph="اسم البنك" | hidden/other tab |
| الصلاحيات | رقم الحساب البنكي * | Treasury[type_account_number] | number | * |  |  | shown |
| الصلاحيات | العملة | Treasury[currency_code] | select-one |  | SAR | SAR=SAR ; ph="من فضلك اختر" | hidden/other tab |
| الصلاحيات | (radio group) | Treasury[active] | radio | | none preselected unless marked | CHECKED:1=نشط (default) ; 0=غير نشط | |
| الصلاحيات | الوصف | Treasury[description] | textarea |  |  |  | shown |
| الصلاحيات | إيداع | ItemPermission[group_type][1]  | select-one |  | الكل | -1=الكل ; ph="من" | hidden/other tab |
| الصلاحيات | إيداع | ItemPermission[group_id][1][1][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | إيداع | ItemPermission[group_id][1][2][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | إيداع | ItemPermission[group_id][1][3][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | سحب | ItemPermission[group_type][2]  | select-one |  | الكل | -1=الكل ; ph="من" | hidden/other tab |
| الصلاحيات | سحب | ItemPermission[group_id][2][1][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | سحب | ItemPermission[group_id][2][2][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |
| الصلاحيات | سحب | ItemPermission[group_id][2][3][] | select-multiple |  |  | ph="من فضلك اختر" ; DIS | hidden/other tab |


**Facts**

- **pw-forms-68** (observed) Treasury add (/owner/treasuries/add, hidden Treasury[type]=3, title "إضافة خزينة"): نشط/غير نشط radios (Treasury[active] 1 default/0), اسم الخزينه * (Treasury[name]), الوصف, section "الصلاحيات": إيداع and سحب each a select ItemPermission[group_type][1|2] (-1=الكل, the only option rendered) plus three multi-selects ItemPermission[group_id][n][1..3][] (page comments GROUP_STAFF, GROUP_ROLE, GROUP_BRANCH). Button حفظ. No account selector: ledger accounts are created automatically (account search lists 120101 - الخزينة الأساسية, 120102 - افتراضي-S35 خزينة, 1202001 - افتراضي-S35 بنك).  
  evidence: `p-treasury-add.probe.json`
- **pw-forms-69** (observed) Bank account add (/owner/treasuries/add?bank_account=1, hidden Treasury[type]=1, title "إضافة حساب بنكي"): الاسم * (Treasury[name]), اسم البنك * (Treasury[type_name], searchable bank directory with hidden portal_bank_id, portal_bank_provider_id, portal_provider_internal_bank_id), رقم الحساب البنكي * (Treasury[type_account_number], number), العملة (Treasury[currency_code], SAR), نشط/غير نشط, الوصف, same إيداع/سحب permissions. Edit form of bank 3 shows type_name "Riyad Bank", portal_bank_id 11, account number 3500000001.  
  evidence: `p-bank-add.probe.json`
- **pw-forms-86** (observed) Treasury list filters and types: Treasury[type] 3 = cash treasury, 1 = bank account; list columns filter by الاسم, النوع, عملة and active; treasuries 1 (Main Treasury, balance 84624.25 SAR in currency_balances) and 3 (افتراضي-S35 بنك, 40 SAR) exist; permission selects only render "الكل" (-1).  
  evidence: `list-treasuries.txt`

## 19. Fixed asset (أصل) add

URL /owner/assets/add (list empty; no edit form available).

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
| البيانات الأساسية | الاسم * | data[Asset][name] | text | * |  | ph="مثال: كرسي مكتب" ; maxlen 255 | shown |
| البيانات الأساسية | الكود * | data[Asset][code] | text | * | 000001 | maxlen 255 | shown |
| البيانات الأساسية | الحساب الرئيسي * | data[Asset][journal_cat_id] | select-one | * | (empty) | = | hidden/other tab |
| البيانات الأساسية | الموقع الفعلي | data[AssetUnit][0][asset_location_id] | select-one |  | (empty) | = | hidden/other tab |
| البيانات الأساسية | العمر الإنتاجي | data[Asset][useful_life_value] | number |  |  | ph="ادخل العمر الانتاجى" | shown |
| البيانات الأساسية | العمر الإنتاجي | data[Asset][useful_life_unit] | select-one |  | سنوات | year=سنوات | hidden/other tab |
| البيانات الأساسية | الرقم التسلسلي | data[Asset][serial_number_hidden] | text |  |  | ph="أدخل الرقم التسلسلي" | hidden/other tab |
| البيانات الأساسية | باركود | data[Asset][barcode_hidden] | text |  |  | ph="أدخل الباركود" | hidden/other tab |
| البيانات الأساسية | الرقم التسلسلي | data[AssetUnit][0][serial_number] | text |  |  | ph="أدخل الرقم التسلسلي" | shown |
| البيانات الأساسية | باركود | data[AssetUnit][0][barcode] | text |  |  | ph="أدخل الباركود" | shown |
| البيانات الأساسية | الكمية | data[Asset][quantity] | text |  | 1 | maxlen 11 | shown |
| البيانات الأساسية | تحديد تفاصيل الوحدة الفردية | data[Asset][define_individual_unit] | checkbox |  | unchecked |  | shown |
| البيانات الأساسية | مستخدم بواسطة | data[AssetUnit][0][used_by] | select-one |  | غير مخصص | not_assigned=غير مخصص | hidden/other tab |
| البيانات الأساسية | مخزن | data[AssetUnit][0][storage_id] | select-one |  | (empty) | = | hidden/other tab |
| البيانات الأساسية | موظف | data[AssetUnit][0][employee_id] | select-one |  | (empty) | = | hidden/other tab |
| تفاصيل الشراء | سعر الشراء * | data[Asset][asset_purchase_value] | text | * |  | ph="00.00" | shown |
| تفاصيل الشراء | سعر الشراء * | data[Asset][asset_currency] | select-one | * | SAR | SAR=SAR | hidden/other tab |
| تفاصيل الشراء | حساب النقدية * | data[Asset][journal_account_id] | select-one | * | (empty) | = | hidden/other tab |
| تفاصيل الشراء | الضريبة 1 | data[Asset][tax_1] | select-one |  | (empty) | = | hidden/other tab |
| تفاصيل الشراء | الضريبة 2 | data[Asset][tax_2] | select-one |  | (empty) | = | hidden/other tab |
| الملاحظات والمرفقات | المرفقات | (widget, no name) | file |  |  |  | hidden/other tab |
| الملاحظات والمرفقات | الوصف | data[Asset][description] | textarea |  |  | ph="أدخل وصفًا تفصيليًا للأصل، حالته، أو أي ملاحظة ذات صلة." | shown |
| إهلاك الأصل | طريقة الإهلاك * | data[Asset][deprecation_method] | select-one | * | (empty) | = | hidden/other tab |
| إهلاك الأصل | قيمة الخردة | data[Asset][salvage_value] | text |  |  | ph="00.00" | shown |
| إهلاك الأصل | قيمة الخردة | data[Asset][asset_currency] | select-one |  | SAR | SAR=SAR ; DIS | hidden/other tab |
| إهلاك الأصل | السعر بالوحدة * | data[Asset][cost_per_unit] | text | * |  |  | hidden/other tab |
| إهلاك الأصل | اسم الوحدة الأكبر | data[Asset][deprecation_unit] | text |  |  | maxlen 255 | hidden/other tab |
| إهلاك الأصل | إجمالي وحدات الأصل | data[Asset][asset_total_units] | text |  |  | maxlen 11 | hidden/other tab |
| إهلاك الأصل | تكرار الإهلاك التلقائي * | data[Asset][deprecation_period] | number | * | 1 |  | hidden/other tab |
| إهلاك الأصل | تكرار الإهلاك التلقائي * | data[Asset][deprecation_interval] | select-one | * | الشهر | 1=الشهر | hidden/other tab |
| إهلاك الأصل | قيمة الإهلاك الآلية لكل 1 شهر * | data[Asset][deprecation_amount] | text | * |  | ph="00.00" | hidden/other tab |
| إهلاك الأصل | قيمة الإهلاك الآلية لكل 1 شهر * | data[Asset][asset_currency] | select-one | * | SAR | SAR=SAR ; DIS | hidden/other tab |
| إهلاك الأصل | نسبة الإهلاك الآلية لكل 1 شهر * | data[Asset][deprecation_percentage] | text | * |  |  | hidden/other tab |
| إهلاك الأصل | حساب مجمع الإهلاك | data[Asset][asset_deprecation_account] | select-one |  | (empty) | = | hidden/other tab |
| إهلاك الأصل | حساب مصروف الإهلاك | data[Asset][asset_deprecation_expense_account] | select-one |  | (empty) | = | hidden/other tab |

Depreciation method probe (interactive, `act-asset.json`):
- القسط الثابت: shows deprecation_period + interval, deprecation_amount, next-depreciation date, end date
- القسط المتناقص (نسبة): shows period + interval, deprecation_percentage, next date, end date
- وحدات الإنتاج: shows cost_per_unit, deprecation_unit, asset_total_units, end date
- بدون إهلاك: hides salvage value, accumulated depreciation account, expense account


**Facts**

- **pw-forms-70** (observed) Fixed asset add (/owner/assets/add, AssetOwnerAddForm multipart): sections البيانات الأساسية (الاسم * ph مثال: كرسي مكتب, الكود * default 000001, الحساب الرئيسي * = fixed-asset accounts 111001 - أثاث, 111002 - الأجهزة والمعدات, 111003 - وسائل النقل, 111004 - مباني, 111005 - أراضي, الموقع الفعلي, العمر الإنتاجي + unit سنوات|أشهر, تاريخ بداية الخدمة *, الرقم التسلسلي, باركود with button انشئ باركود, الكمية default 1, تحديد تفاصيل الوحدة الفردية), تفاصيل الشراء (سعر الشراء * + currency, تاريخ الشراء *, حساب النقدية * (a searchable account list grouped under parent paths such as «الخزينة > الأصول المتداولة > الأصول»; query 1 returned 50 rows incl. treasuries, warehouses, banks and suppliers), الضريبة 1, الضريبة 2), الملاحظات والمرفقات (الوصف, المرفقات), إهلاك الأصل.  
  evidence: `p-asset-add.probe.json`
- **pw-forms-71** (observed) Asset depreciation card: طريقة الإهلاك * options القسط الثابت (1), القسط المتناقص (نسبة) (2), وحدات الإنتاج (3), بدون إهلاك (4). Fixed: تكرار الإهلاك التلقائي * (period number default 1 + interval الشهر|يوم|الأسبوع), قيمة الإهلاك الآلية لكل n * (amount), تاريخ بدء الإهلاك التلقائي * (default today), تاريخ انتهاء الإهلاك, قيمة الخردة, حساب مجمع الإهلاك, حساب مصروف الإهلاك. Declining: same but نسبة الإهلاك الآلية لكل n * (percentage) instead of amount. Units of production: السعر بالوحدة *, اسم الوحدة الأكبر, إجمالي وحدات الأصل and end date only. None: hides salvage value and both depreciation accounts.  
  evidence: `act-asset.json`
- **pw-forms-72** (observed) Asset JS rules: next depreciation amount shown as "قيمة اول إهلاك" = fixed: amount × (firstPeriod ÷ period); percentage: pct/100 × (purchase value − salvage) × (firstPeriod ÷ period); capped at (purchase value − salvage); firstPeriod = months difference (server call /assets/get_diff_in_months), days, or weeks between in-service date and first depreciation date; default first depreciation date = in-service date + period (month addition clamps to month end). Field rules (data-form-rules): required on name, code, in-service date, purchase value, currency, purchase date, cash account, depreciation method, cost per unit, period, interval, amount, percentage, next date; requireAssetUnitsWhenToggleOn on quantity; distinctSerialNumber|requireSerialOrBarcode on unit serial; distinctBarcode on unit barcode; requiredIfCustodyTypeEmployee on unit employee. Messages: «يرجى إدخال الرقم التسلسلي أو الباركود. لا يمكن ترك الحقلين فارغين», «هذا الرقم التسلسلي مستخدم مسبقًا…», «هذا الباركود مستخدم مسبقًا…», «.يرجى اختيار الموظف عند إسناد الأصل إلى موظف».  
  evidence: `js/assets_next_dep.js`
- **pw-forms-73** (observed) Asset unit rows (define_individual_unit checked): per unit serial number, barcode, used_by (غير مخصص default; employee or storage custody types), employee (موظف), storage (مخزن), physical location (الموقع الفعلي). No asset exists in the account (list empty), no asset category or location records.  
  evidence: `act-asset.json`

## 20. General journal (قيد يومية) add/edit

URL /owner/journals/add; edit /owner/journals/edit/134 (automatic entry from a treasury transfer).

| where | label (AR) | name | type | req | default | options / notes | at load |
|---|---|---|---|---|---|---|---|
|  | التاريخ | data[Journal][date] | text |  | 27/10/2026 |  | shown |
|  | العملة | data[Journal][currency_code] | select-one | cls | SAR ريال سعودي | AED=AED درهم إماراتي ; AFN=AFN أفغاني ; ALL=ALL ليك ألباني ; AMD=AMD درام أرميني ; ANG=ANG غويلدر الأنتيل الهولندية ; AOA=AOA كوانزا أنغولي ; ... (160 options) | hidden/other tab |
|  | رقم | data[Journal][number] | text |  | 000135 | maxlen 255 | shown |
|  | الوصف | data[Journal][description] | textarea |  |  |  | shown |
|  | المرفقات | (widget, no name) | file |  |  |  | hidden/other tab |
|  |  | data[JournalTransaction][0][journal_account_id] | select-one |  | (empty) | = ; ph="الحساب الافتراضي" | hidden/other tab |
|  |  | data[JournalTransaction][0][description] | textarea | cls |  | ph="الوصف" | shown |
|  |  | data[JournalTransaction][0][cost_center] | select-one |  | (empty) | = ; ph="مركز التكلفة" | hidden/other tab |
|  |  | data[JournalTransaction][0][tax_id] | select-one |  | - | =- ; 1=القيمة المضافة ; ph="الضرائب" | shown |
|  |  | data[JournalTransaction][0][currency_debit] | number | cls |  | ph="مدين" | shown |
|  |  | data[JournalTransaction][0][currency_credit] | number | cls |  | ph="دائن" | shown |
|  |  | data[JournalTransaction][1][journal_account_id] | select-one |  | (empty) | = ; ph="الحساب الافتراضي" | hidden/other tab |
|  |  | data[JournalTransaction][1][description] | textarea | cls |  | ph="الوصف" | shown |
|  |  | data[JournalTransaction][1][cost_center] | select-one |  | (empty) | = ; ph="مركز التكلفة" | hidden/other tab |
|  |  | data[JournalTransaction][1][tax_id] | select-one |  | - | =- ; 1=القيمة المضافة ; ph="الضرائب" | shown |
|  |  | data[JournalTransaction][1][currency_debit] | number | cls |  | ph="مدين" | shown |
|  |  | data[JournalTransaction][1][currency_credit] | number | cls |  | ph="دائن" | shown |


**Facts**

- **pw-forms-74** (observed) Manual journal add (/owner/journals/add, JournalOwnerAddForm): header التاريخ (default today), العملة (select default SAR ريال سعودي, 160 options without a placeholder), رقم (default 000135), الوصف, المرفقات; lines table columns اسم الحساب * (placeholder الحساب الافتراضي, ajax search; query "1" returned 33 accounts such as 120101 - الخزينة الأساسية, 411 - المبيعات, 12041001 - شركة النور للتجارة and no parent group account like 12 or 11), الوصف (class required), مركز التكلفة, الضرائب (options - and القيمة المضافة), مدين *, دائن *; two lines by default; total row; buttons حفظ كـمسودة, إلغاء, حفظ, خيارات اخرى (حفظ & إضافة جديدة), row menu "نسخ من جدول البيانات".  
  evidence: `p-journal-add.probe.json`
- **pw-forms-75** (observed) Journal client rules: entering a debit zeroes the credit of the same line and vice versa; empty debit/credit become 0 on submit and must be non-negative («يجب إدخال رقم موجب»); date required and valid; at least 2 lines (#transactionsCount > 1, «يجب أن تضيف على الأقل حركتان للقيد»); |debit_total − credit_total| < 0.0001 else «إجمالي المدين يجب أن يكون مساوياَ لإجمالي الدائن»; if totals differ but match after re-rounding to the currency decimals the form appends hidden data[force_rounding]=1 and accepts; cost-centre selects marked data-required must be filled when distribution is required.  
  evidence: `src-journal-add.inline.js`
- **pw-forms-76** (observed) Journal line tax logic (inline JS): per line tax id from the tax select; if the tax is included (included=1) net = amount ÷ (1 + p/100) rounded to the currency decimals and tax = amount − net; otherwise tax = round(amount × p/100); line total = net + tax; debit_total and credit_total are sums of those line totals (subtotals shown separately); currency decimals come from number_formats[currency][0] (default 2).  
  evidence: `src-journal-add.inline.js`
- **pw-forms-77** (observed) Journal settings (/owner/settings/accounting_general): tax_in_journals "الضريبة" checked, display_cost_centers_in_journals "مركز التكلفة" checked, enable_tags_in_journal "وسوم" unchecked, separate_manual_journal_numbering unchecked (next journal number 000135), update_journal_entries_currency_rates = "عرض" (show currency-rate field) selected there but the same key shows "اخفاء" selected on /owner/invoices/settings, line account/cost-centre-distribution toggles for sales invoices, purchase invoices and stock permits unchecked, allow_cost_center_distribution_exceed_100 and require_cost_center_distribution unchecked. Changing the currency select to USD revealed no additional visible field.  
  evidence: `st-accounting-general.json`
- **pw-forms-78** (observed) Journal list filters: source (entity_type) has 33 values: 0=قيد يدوي, invoice, requisition, stock_transaction, invoice_payment, income, sales_cost, refund_receipt, expense, stock_transfer, purchase_refund, asset, asset_operation, asset_deprecation, treasury_transfer, credit_note, asset_write_off, asset_re_evaluate, asset_sell, pay_run, delivered_pay_cheque, rejected_pay_cheque, collected_pay_cheque, receivable_cheque_received, receivable_cheque_deposited, receivable_cheque_collected, receivable_cheque_deposit_collected, receivable_cheque_reject, receivable_cheque_deposit_rejected, product_cost_sales, manufacturing_order, purchase_order_payment, purchase_order; status draft[] 0=اصدر 1=مسودة.  
  evidence: `list-filters.json`
- **pw-forms-79** (observed) Journal edit of an automatic entry (/owner/journals/edit/134, form JournalOwnerEditForm): line debit/credit inputs are replaced by *_currency_debit2/credit2 read-only displays, description is edited through alter_description, a button "عرض المصدر" links to the source document, and page JS alerts «يمكنك تعديل وصف القيود الآلية فقط» when a disabled control is clicked; buttons حفظ كـمسودة, إلغاء, تحديث.  
  evidence: `journal-edit-134.json`
- **pw-forms-80** (observed) Inline cost-centre distribution (journal and documents): rows with percentage and credit amount (data[CostCenterTransaction*][...][percentage|credit]); selecting accounts triggers POST /owner/cost_centers/get_auto_for_account/<id> and /owner/cost_centers/add_cost_center/<id>/<credit|debit>?ajax=1 which our read-only guard blocked (no data written).  
  evidence: `_nonget-requests.log`
- **pw-forms-87** (observed) Journal numbering: automatic and manual journals share one counter, next number 000135 (separate_manual_journal_numbering unchecked; the "الرقم التسلسلي للقيد اليومي التالي" field is disabled), and the manual journal form default رقم is that number.  
  evidence: `st-accounting-general.json`

## 21. Shared settings pages

Taxes, numbering, product barcode and the settings mentioned above. See `st-*.txt`.

**Facts**

- **pw-forms-83** (observed) Taxes settings page (/owner/taxes/index): table columns الضريبة / قيمة الضريبة (%) / متضمنة (select غير متضمن=0 | متضمن=1) with one active tax «القيمة المضافة» 15, not included; the tax selects in all forms list only this tax plus the shortcut «إعدادات الضرائب» (-1) (documents) or «بدون ضريبة» (purchase tax on product).  
  evidence: `st-taxes.json`
- **pw-forms-84** (observed) Barcode/product settings (/owner/settings/product_settings): barcode_type Code 128 selected (alternative EAN13), embedded_barcode "باركود متضمن الوزن" unchecked with hidden embedded_barcode_format, weight_unit_divider, currency_divider, scale_barcode_behavior (merge default / separate). Inventory defaults page: default tax 1 = القيمة المضافة, tax 2 none, default warehouse none, refund_receipt_calculation_method = حسب سعر البيع (0) vs آخر متوسط للتكلفة (1), sold_item_type = منتجات وخدمات.  
  evidence: `st-product-settings.json`

## 22. Gaps versus Expro (summary; the structured result carries the matrix row ids)

1. Sales order, recurring invoice and recurring expense documents do not exist in Expro (SAL-22, SAL-23, FIN-06; WP35). Daftra's recurring invoice is the invoice form plus an "issue schedule" card (name, every n days/weeks/months/years, max repeats, first date, issue-before days < period, active, notify copy, show from/to, auto payment) and up to 10 reminders.
2. Document header charges: invoice-level discount as percent or amount (amount converted to percent), adjustment (label + value), shipping (+ shipping tax and display option), deposit (amount or percent), retention (in script), terms template, extra fields, 500-line cap, 10 attachments, drafts kept in the browser (SAL-08, SAL-09, SAL-35, WP34/WP36).
3. Estimate statuses (8 values) and fields (validity days, no payment/delivery/deposit blocks) (SAL-21; WP14).
4. Client master data: type radios, identifier type list (12), government entity, secondary address, contact list, billing method that makes e-mail mandatory, language, currency, categories; opening balance/credit limit/credit period are settings-gated (CUS-01..05, CUS-11; WP11, WP37).
5. Supplier: opening balance with date on add only; contact list; no tax-type/category (PUR-10, G-66).
6. Product: purchase taxes, minimum price + live discount-vs-minimum check, margin, multi categories/brand/tags, service form without stock fields, barcode auto-fill, supplier code; units, bundles and tracking only by settings (INV-01..06; WP37, WP42).
7. Purchase documents: one form for invoice/refund/debit note with different defaults for paid/received, deposit tab, shipping tax, inline supplier, multi payment rows (PUR-01, PUR-05; WP17, WP43).
8. Purchase cycle documents: not observable (PUR-07; WP39).
9. Stock permits (add and issue, sub-account, per-line stock before/after) and stocktaking add (INV-10, INV-11; WP27).
10. Price list (name + status, items elsewhere) (SAL-24; WP36).
11. Expense/receipt voucher: shared form, recurring options in English, multi accounts, cost centres, tax, inline supplier, draft status (FIN-02, FIN-03, FIN-06; WP3, WP26).
12. Treasury/bank forms: permission model staff/role/branch for deposit and withdraw, bank directory, auto account (FIN-01; WP29).
13. Fixed assets: complete form and four depreciation methods with JS first-period formula (FIN-08; WP47).
14. Manual journal: per-line tax with included/excluded math, force_rounding, draft, copy from spreadsheet, cost-centre rows; 33 journal source types (ACC-17, ACC-18, ACC-20, ACC-21; WP19, WP38).

## 23. Blockers / not observed

- Add forms of purchase request, quotation request, purchase quotation, purchase order, sales order (official form), bundles, multi-unit and tracking product fields, client credit-limit/opening-balance fields in the legacy form: gated by disabled apps/settings (not enabled: read-only batch).
- Edit forms for recurring invoice, stock permit, stocktaking, receipt voucher, asset, price list: no record exists.
- Server-side validation messages after a real submit, statuses after save, and numbering behaviour on save were not observed (no submit allowed).
- Selectize widgets loaded by ajax (journal accounts, asset cash account, cost centres, bank directory) show only what loads on focus/typing 1 character.
