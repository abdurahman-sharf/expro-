# هيكل المخرجات

```
<slug>-spec/                      ← slug رمزي محايد، لا يحمل اسم المنتج
  progress.md                     ← orchestrator (+ السكربت يحدّث كتلة الحالة)
  00-executive-summary.md         ← spec-writer
  01-sitemap.md                   ← explorer (عرض مقروء للشجرة)
  01-sitemap.json                 ← explorer (المصدر الآلي؛ السكربت يحدّث status/file)
  02-screens/<module>/<screen-id>.md ← analyzer (ملف لكل شاشة، بواجهة أمامية YAML)
  03-data-model.md                ← analyzer
  03-erd.mmd                      ← analyzer (Mermaid erDiagram)
  03-schema.sql                   ← analyzer (PostgreSQL مقترح)
  04-business-rules.md            ← analyzer
  05-roles-permissions.md         ← analyzer
  06-reports.md                   ← analyzer
  07-ux-guidelines.md             ← spec-writer
  08-build-brief.md               ← spec-writer
  09-open-questions.md            ← الجميع يضيف؛ spec-writer يرتّب ويُنهي
  10-coverage-report.md           ← السكربت (coverage --write) + تعليق spec-writer
  _private/                       ← لا يُسلَّم للمنفّذ: اسم النظام الأصلي ورابطه فقط
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
      "sections": [
        {
          "id": "gl-masters", "name": "البيانات الأساسية",
          "screens": [
            {
              "id": "gl-coa", "name": "دليل الحسابات",
              "url_pattern": "/accounting/accounts",
              "type": "list",
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
    }
  ]
}
```

- `id`: ASCII صغير بشَرطات، فريد على مستوى النظام كله، ويُستخدم اسماً لملف الشاشة.
- `type`: `list` | `form` | `detail` | `report` | `settings` | `dashboard` | `wizard`.
- `status`: `pending` | `in_progress` | `done` | `partial` | `skipped` (مع `skip_reason`).
- `url_pattern`: استبدل المعرّفات بمتغيرات: `/sales/invoices/{id}/edit`، و`null` إن لم يكن للشاشة رابط خاص (نافذة منبثقة).
- `priority`: عدد صحيح؛ الأصغر يُدرس أولاً (1 = بيانات أساسية، 2 = مستندات، 3 = تقارير، 4 = إعدادات).

## الواجهة الأمامية لملف الشاشة

```yaml
---
screen_id: gl-coa
module: gl
status: done          # done | partial | skipped | in_progress
skip_reason:          # إلزامي إن كانت partial أو skipped
---
```

## `progress.md`

يحوي: جدول المراحل (⬜ لم تبدأ / 🔄 جارية / ✅ مكتملة / ⏸ متوقفة بانتظار المستخدم)، نقطة الاستئناف
(الوحدة الحالية، آخر شاشة مكتملة، الخطوة التالية)، كتلة حالة يولّدها السكربت بين
`<!-- ACS:STATUS:BEGIN -->` و`<!-- ACS:STATUS:END -->` (لا تعدّلها يدوياً)، وسجلاً زمنياً.
لا يحوي أبداً بيانات دخول أو أسماء أشخاص من جهة العميل أو بيانات حقيقية.
