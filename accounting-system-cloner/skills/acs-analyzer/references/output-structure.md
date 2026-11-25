# هيكل المخرجات

```
<slug>-spec/                      ← اسم المستخدم إن حدده، وإلا slug رمزي محايد
  progress.md                     ← orchestrator (+ السكربت يحدّث كتلة الحالة)
  00-executive-summary.md         ← spec-writer
  01-modules.md                   ← explorer (مدير الوحدات: كل الوحدات مفعّلة ومعطّلة)
  01-sitemap.md                   ← explorer (عرض مقروء للشجرة)
  01-sitemap.json                 ← explorer (المصدر الآلي؛ السكربت يحدّث status/file)
  01-api-map.md                   ← explorer (+ analyzer يضيف) — بنية نقاط النهاية فقط
  02-screens/<module>/<screen-id>.md ← analyzer (ملف لكل شاشة، بواجهة أمامية YAML)
  03-data-model.md                ← analyzer
  03-erd.mmd                      ← analyzer (Mermaid erDiagram)
  03-schema.sql                   ← analyzer (PostgreSQL مقترح)
  04-business-rules.md            ← analyzer (+ قسم "تناقضات في النظام المرجعي — لا تُنسخ")
  04a-scenario-matrix.json        ← analyzer (المرحلة 4.5، وضع التجربة فقط) — حالة كل خلية
  04b-posting-matrix.md           ← spec-writer (من المصفوفة + توجيه الحسابات + أدوار الحسابات)
  05-roles-permissions.md         ← analyzer
  06-reports.md                   ← analyzer
  07-ux-guidelines.md             ← spec-writer
  08-build-brief.md               ← spec-writer
  09-open-questions.md            ← الجميع يضيف؛ spec-writer يرتّب ويُنهي
  10-coverage-report.md           ← السكربت (coverage --write) + تعليق spec-writer
  11-test-records-ledger.md       ← analyzer يضيف فور كل إنشاء؛ spec-writer يُنهي (وضع التجربة فقط)
  _private/                       ← لا يُسلَّم للمنفّذ: اسم النظام الأصلي ورابطه والوضع
    project.json
```

## مخطط `01-sitemap.json`

```json
{
  "schema_version": 1,
  "system": {"label": "النظام المرجعي", "base_url_pattern": "https://<host>"},
  "generated_at": "2025-01-01 10:00",
  "modules": [
    {
      "id": "gl", "name": "المحاسبة العامة", "priority": 1,
      "enabled": true, "category": "المالية",
      "sections": [
        {
          "id": "gl-masters", "name": "البيانات الأساسية",
          "screens": [
            {
              "id": "gl-coa", "name": "دليل الحسابات",
              "url_pattern": "/accounting/accounts",
              "type": "list",
              "rendering": "spa",
              "extraction": "browse",
              "visual_check": true,
              "priority": 1,
              "status": "pending",
              "file": null,
              "skip_reason": null,
              "menu_path": "المحاسبة > دليل الحسابات",
              "notes": "عرض شجري + جدول"
            }
          ]
        }
      ]
    },
    {
      "id": "payroll", "name": "الرواتب", "priority": 5, "enabled": false, "category": "الموارد البشرية",
      "sections": []
    }
  ]
}
```

- **كل وحدات مدير الوحدات تُسجَّل**، بما فيها المعطّلة (`"enabled": false`، وأقسام فارغة أو شاشات `skipped` بسبب
  "وحدة معطّلة"). تقرير التغطية يُحسب من إجمالي الوحدات لا من المفعّل فقط.
- `id`: ASCII صغير بشَرطات، فريد على مستوى النظام كله، ويُستخدم اسماً لملف الشاشة.
- `type`: `list` | `form` | `detail` | `report` | `settings` | `dashboard` | `wizard`.
- `rendering`: `server` | `spa` · `extraction`: `fetch` | `browse` · `visual_check`: هل تحققت بلقطة شاشة.
- `status`: `pending` | `in_progress` | `done` | `partial` | `skipped` (مع `skip_reason`).
- `url_pattern`: الرابط كما ظهر في الواجهة مع استبدال المعرّفات بمتغيرات (`/sales/invoices/{id}/edit`)، و`null` لنافذة
  منبثقة. لا أنماط مخمّنة.
- `priority`: عدد صحيح؛ الأصغر أولاً (1 بيانات أساسية، 2 مستندات، 3 تقارير، 4 إعدادات).

## الواجهة الأمامية لملف الشاشة

```yaml
---
screen_id: gl-coa
module: gl
status: done          # done | partial | skipped | in_progress
skip_reason:          # إلزامي إن كانت partial أو skipped
---
```

## مخطط `04a-scenario-matrix.json` (وضع التجربة)

```json
{
  "cells": [
    {
      "id": "SM-001",
      "document": "sales_invoice",
      "variables": {"item_type": "stock", "payment": "none", "status": "issued", "currency": "base",
                    "tax": "exclusive", "discount": "none", "opening_balance": "no"},
      "status": "tested",
      "reason": null,
      "test_record": "TR-003",
      "expected_entry": [{"role": "clients", "side": "debit", "amount": "total"}],
      "actual_entry": [{"role": "clients", "side": "debit", "amount": "total"}],
      "diff": null,
      "anomaly_ref": null
    }
  ]
}
```
- `status`: `tested` | `untested` | `blocked` (مع `reason`: "وضع قراءة فقط"، "يحتاج إذناً خاصاً"، "الوحدة معطّلة"...).
- `diff` غير فارغ ⇒ `anomaly_ref` يشير إلى بند في قسم التناقضات بـ `04-business-rules.md` (مثل `AN-03`).
- السكربت: `acs_tool.py matrix` يلخّص الحالة، و`progress` و`coverage --write` يعرضانها.

## `progress.md`

يحوي: الوضع (قراءة فقط / تجربة + وقت الإذن)، جدول المراحل (⬜ / 🔄 / ✅ / ⏸)، نقطة الاستئناف، كتلة حالة يولّدها
السكربت بين `<!-- ACS:STATUS:BEGIN -->` و`<!-- ACS:STATUS:END -->` (الشاشات + الوحدات + مصفوفة الاختبار + عدد السجلات
التجريبية — لا تعدّلها يدوياً)، وسجلاً زمنياً. لا يحوي بيانات دخول أو أسماء أشخاص من جهة العميل أو بيانات حقيقية.
