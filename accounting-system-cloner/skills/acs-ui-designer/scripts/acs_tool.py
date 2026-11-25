#!/usr/bin/env python3
"""acs_tool.py - deterministic helpers for the accounting-system-cloner skills.

Standard library only. Subcommands:

  init      Create the <slug>-spec/ project folder, project.json and progress.md.
  validate  Check 01-sitemap.json against the expected schema.
  sync      Merge the front matter of 02-screens/**/*.md into 01-sitemap.json.
  next      Print the next screen to study (resume point), as JSON.
  coverage  Compute coverage; with --write, regenerate 10-coverage-report.md.
  progress  Regenerate the status block of progress.md (sync first).
  log       Append a timestamped line to the log section of progress.md.
  matrix    Summarise 04a-scenario-matrix.json (sandbox scenario matrix).
  urlcheck  Refuse action-like URLs before any GET / fetch.
  scrub     Scan the deliverables for privacy / credential / brand / raw-artifact leaks.
  uiscaffold  Write a ui/screens/<module>/<screen>.map.md skeleton listing every spec element.
  uicheck     Compare every spec screen with its UI map; write ui/coverage.md; fail on gaps.
  uicontrast  Check WCAG contrast of ui/tokens.json contrast_pairs (light and dark).

Run `python3 acs_tool.py <cmd> --help` for options. All commands take
--project <path-to-spec-folder> (default: current directory) except init.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import sys
from pathlib import Path

SCREEN_TYPES = {"list", "form", "detail", "report", "settings", "dashboard", "wizard"}
STATUSES = {"pending", "in_progress", "done", "partial", "skipped"}
PHASES = [
    ("0", "التحضير والتفويض", "acs-orchestrator"),
    ("1", "رسم الخريطة", "acs-explorer"),
    ("1.5", "الوحدات والـ API", "acs-explorer"),
    ("2", "دراسة الشاشات", "acs-analyzer"),
    ("3", "نموذج البيانات", "acs-analyzer"),
    ("4", "المنطق المحاسبي وقواعد العمل", "acs-analyzer"),
    ("4.5", "مصفوفة الاختبار (وضع التجربة فقط)", "acs-analyzer"),
    ("5", "الصلاحيات وسير العمل", "acs-analyzer"),
    ("6", "التقارير", "acs-analyzer"),
    ("7", "حزمة المواصفات والتعليمات للمنفّذ", "acs-spec-writer"),
    ("8", "تصميم الواجهات (اختياري)", "acs-ui-designer"),
]
STUB_FILES = [
    "00-executive-summary.md",
    "01-modules.md",
    "01-sitemap.md",
    "01-api-map.md",
    "03-data-model.md",
    "03-erd.mmd",
    "03-schema.sql",
    "04-business-rules.md",
    "04b-posting-matrix.md",
    "05-roles-permissions.md",
    "06-reports.md",
    "07-ux-guidelines.md",
    "08-build-brief.md",
    "09-open-questions.md",
    "10-coverage-report.md",
    "11-test-records-ledger.md",
]
RENDERINGS = {"server", "spa"}
MATRIX_STATUSES = {"tested", "untested", "blocked"}
ACTION_WORDS = [
    "delete", "remove", "destroy", "cancel", "void", "close", "post", "approve", "make_", "mark_", "send", "email",
    "sms", "submit", "sync", "revalue", "reverse", "undo", "toggle", "enable", "disable", "activate", "deactivate",
    "reset", "run", "process", "logout", "signout", "unpost", "unlock", "lock", "archive", "restore",
    "duplicate", "clone", "convert", "pay", "refund", "deliver", "undeliver", "confirm", "reject", "execute",
]
ACTION_WORDS_AR = ["حذف", "إلغاء", "الغاء", "إقفال", "اقفال", "ترحيل", "اعتماد", "إرسال", "ارسال", "تفعيل", "تعطيل",
                   "تنفيذ", "معالجة", "خروج", "تأكيد", "رفض", "عكس", "مزامنة", "تحويل إلى", "أرشفة"]
STATUS_BEGIN = "<!-- ACS:STATUS:BEGIN -->"
STATUS_END = "<!-- ACS:STATUS:END -->"
LOG_HEADER = "## السجل"


def now() -> str:
    return dt.datetime.now().strftime("%Y-%m-%d %H:%M")


def slugify(text: str) -> str:
    slug = re.sub(r"[^\w\-]+", "-", text.strip().lower(), flags=re.UNICODE)
    slug = re.sub(r"-{2,}", "-", slug).strip("-")
    return slug or "system"


def load_json(path: Path) -> dict:
    with path.open(encoding="utf-8") as fh:
        return json.load(fh)


def save_json(path: Path, data: dict) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def project_path(args) -> Path:
    path = Path(args.project).resolve()
    if not (path / "progress.md").exists():
        sys.exit(f"error: {path} does not look like a spec project (no progress.md). Run init first.")
    return path


# ---------------------------------------------------------------- init

def cmd_init(args) -> None:
    if args.mode == "sandbox" and not args.sandbox_confirmed_at:
        sys.exit("error: --mode sandbox requires --sandbox-confirmed-at (the user's explicit confirmation time)")
    slug = slugify(args.slug or args.system_name)
    root = Path(args.root).resolve() / f"{slug}-spec"
    if (root / "progress.md").exists() and not args.force:
        print(json.dumps({"status": "exists", "project": str(root),
                          "hint": "Project already exists - resume with `next` instead of re-initialising."},
                         ensure_ascii=False))
        return
    (root / "02-screens").mkdir(parents=True, exist_ok=True)
    (root / "_private").mkdir(exist_ok=True)

    config = {
        "schema_version": 1,
        "created_at": now(),
        "system_label": "النظام المرجعي",
        "system_name_private": args.system_name,
        "base_url": args.url,
        "demo_environment": args.demo,
        "language": args.language,
        "direction": args.direction,
        "country": args.country,
        "scope": args.scope,
        "target_stack": args.stack,
        "authorization_confirmed_at": args.authorized_at,
        "mode": args.mode,
        "sandbox_confirmed_at": args.sandbox_confirmed_at,
        "test_prefix": args.test_prefix,
        "identity": {"terms": [], "colors": [],
                     "note": "Reference-system identity (name, wordmark, distinctive labels, brand hex colours) - "
                             "recorded by acs-explorer for scrub only; never copied into deliverables or ui/."},
        "note": "Never store usernames, passwords, OTP codes, cookies or tokens in this project.",
    }
    save_json(root / "_private" / "project.json", config)
    (root / "_private" / "README.md").write_text(
        "# _private\n\nإعدادات داخلية لا تُسلَّم للوكيل المنفّذ (اسم النظام الأصلي ورابطه).\n"
        "لا تضع هنا أي بيانات دخول أو رموز جلسة.\n", encoding="utf-8")

    sitemap = root / "01-sitemap.json"
    if not sitemap.exists() or args.force:
        save_json(sitemap, {
            "schema_version": 1,
            "system": {"label": "النظام المرجعي", "base_url_pattern": "https://<host>"},
            "generated_at": None,
            "modules": [],
        })
    for name in STUB_FILES:
        target = root / name
        if not target.exists():
            if name.endswith(".md"):
                target.write_text(f"<!-- stub: يُملأ في مرحلته -->\n", encoding="utf-8")
            elif name.endswith(".mmd"):
                target.write_text("erDiagram\n", encoding="utf-8")
            else:
                target.write_text("-- stub: يُملأ في المرحلة 3\n", encoding="utf-8")

    matrix = root / "04a-scenario-matrix.json"
    if not matrix.exists() or args.force:
        save_json(matrix, {"schema_version": 1, "mode": args.mode, "cells": []})
    ledger = root / "11-test-records-ledger.md"
    if ledger.read_text(encoding="utf-8").startswith("<!-- stub"):
        ledger.write_text(
            "# سجل السجلات التجريبية\n\n"
            + (f"الوضع: تجربة · البادئة: `{args.test_prefix}`\n\n" if args.mode == "sandbox"
               else "الوضع: قراءة فقط — لا يُنشأ أي سجل.\n\n")
            + "## سجلات أنشأتها هذه الدراسة\n\n"
            "| # | النوع | الاسم | الرقم | الرابط | القيود الناتجة | الآثار الجانبية | الوقت |\n|---|---|---|---|---|---|---|---|\n\n"
            "## موجودة مسبقاً (بالبادئة نفسها — لم تُعدَّل ولم تُستخدم)\n\n"
            "| النوع | الاسم | الرقم | الرابط | الحالة |\n|---|---|---|---|---|\n\n"
            "## ترتيب الحذف المقترح\n\n(يُملأ في المرحلة 7: الدفعات ← المرتجعات/الإشعارات ← الفواتير ← الحركات ← الأطراف ← المنتجات)\n",
            encoding="utf-8")
    mode_label = (f"تجربة (Sandbox Write) — إذن {args.sandbox_confirmed_at}" if args.mode == "sandbox" else "قراءة فقط")
    progress = root / "progress.md"
    if not progress.exists() or args.force:
        phase_rows = "\n".join(f"| {p} | {n} | {s} | ⬜ لم تبدأ | |" for p, n, s in PHASES)
        progress.write_text(
            f"# تقدّم دراسة النظام المرجعي\n\n"
            f"- أُنشئ: {now()}\n- الوضع: {mode_label}\n"
            f"- التفويض: {'مؤكَّد ' + args.authorized_at if args.authorized_at else 'غير مؤكَّد — لا تبدأ'}\n"
            f"- البيئة التجريبية: {args.demo}\n- النطاق: {args.scope}\n\n"
            f"## المراحل\n\n| # | المرحلة | السكيل | الحالة | ملاحظات |\n|---|---|---|---|---|\n{phase_rows}\n\n"
            f"## نقطة الاستئناف\n\n- الوحدة الحالية: —\n- آخر شاشة مكتملة: —\n- الخطوة التالية: المرحلة 1 (رسم الخريطة)\n\n"
            f"{STATUS_BEGIN}\n(يُولَّد تلقائياً بـ `acs_tool.py progress`)\n{STATUS_END}\n\n"
            f"{LOG_HEADER}\n\n- {now()} — إنشاء المشروع.\n",
            encoding="utf-8")
    print(json.dumps({"status": "created", "project": str(root)}, ensure_ascii=False))


# ---------------------------------------------------------------- sitemap helpers

def iter_screens(sitemap: dict):
    for module in sitemap.get("modules", []):
        for section in module.get("sections", []):
            for screen in section.get("screens", []):
                yield module, section, screen


def validate_sitemap(sitemap: dict, warnings: list | None = None) -> list[str]:
    errors = []
    warnings = warnings if warnings is not None else []
    if not isinstance(sitemap.get("modules"), list):
        return ["`modules` must be a list"]
    seen = set()
    for m in sitemap["modules"]:
        for key in ("id", "name", "sections"):
            if key not in m:
                errors.append(f"module missing `{key}`: {m.get('id', m)}")
        if "enabled" in m and not isinstance(m["enabled"], bool):
            errors.append(f"module {m.get('id')}: `enabled` must be true/false")
        for s in m.get("sections", []):
            for key in ("id", "name", "screens"):
                if key not in s:
                    errors.append(f"section missing `{key}` in module {m.get('id')}")
            for sc in s.get("screens", []):
                sid = sc.get("id")
                if not sid:
                    errors.append(f"screen without id in {m.get('id')}/{s.get('id')}")
                    continue
                if sid in seen:
                    errors.append(f"duplicate screen id: {sid}")
                seen.add(sid)
                if not re.fullmatch(r"[a-z0-9][a-z0-9\-]*", sid):
                    errors.append(f"screen id must be lowercase-kebab ascii: {sid}")
                if sc.get("type") not in SCREEN_TYPES:
                    errors.append(f"{sid}: type `{sc.get('type')}` not in {sorted(SCREEN_TYPES)}")
                if sc.get("status", "pending") not in STATUSES:
                    errors.append(f"{sid}: status `{sc.get('status')}` not in {sorted(STATUSES)}")
                if sc.get("rendering") not in (None, *RENDERINGS):
                    errors.append(f"{sid}: rendering `{sc.get('rendering')}` not in {sorted(RENDERINGS)}")
                if sc.get("url_pattern") and is_action_url(str(sc["url_pattern"])):
                    warnings.append(f"{sid}: url_pattern looks like an action URL - keep the screen in the map but never "
                                    f"request it (set \"never_request\": true): {sc['url_pattern']}")
                if "url_pattern" not in sc:
                    errors.append(f"{sid}: missing url_pattern (use null if the screen has no own URL)")
                if not isinstance(sc.get("priority", 1), int):
                    errors.append(f"{sid}: priority must be an integer")
    return errors


def is_action_url(url: str, label: str = "") -> list[str]:
    path = re.sub(r"^https?://[^/]+", "", url).lower()
    tokens = re.split(r"[/?&=#._\-]+", path)
    hits = [w for w in ACTION_WORDS if (w.endswith("_") and w in path) or w in tokens]
    hits += [w for w in ACTION_WORDS_AR if w in label]
    return hits


def cmd_urlcheck(args) -> None:
    hits = is_action_url(args.url, args.label or "")
    print(json.dumps({"url": args.url, "allowed": not hits, "matched": hits,
                      "note": ("لا تطلبه أبداً، ولا حتى بـ GET" if hits else
                               "مسموح فقط إن ظهر في الواجهة كرابط تنقّل (ليس زر إجراء ولا داخل قائمة إجراءات)")},
                     ensure_ascii=False))
    sys.exit(1 if hits else 0)


def load_matrix(root: Path) -> dict:
    path = root / "04a-scenario-matrix.json"
    return load_json(path) if path.exists() else {"cells": []}


def matrix_stats(root: Path) -> dict:
    cells = load_matrix(root).get("cells", [])
    stats = {"total": len(cells), "tested": 0, "untested": 0, "blocked": 0, "diffs": 0, "diffs_without_anomaly": []}
    for c in cells:
        st = c.get("status", "untested")
        stats[st if st in MATRIX_STATUSES else "untested"] += 1
        if c.get("diff"):
            stats["diffs"] += 1
            if not c.get("anomaly_ref"):
                stats["diffs_without_anomaly"].append(c.get("id"))
    return stats


def ledger_count(root: Path) -> int:
    path = root / "11-test-records-ledger.md"
    if not path.exists():
        return 0
    return sum(1 for line in path.read_text(encoding="utf-8").splitlines() if re.match(r"\|\s*TR-\d+", line))


def cmd_matrix(args) -> None:
    root = project_path(args)
    cells = load_matrix(root).get("cells", [])
    errors = []
    for c in cells:
        if c.get("status") not in MATRIX_STATUSES:
            errors.append(f"{c.get('id')}: status must be one of {sorted(MATRIX_STATUSES)}")
        if c.get("status") in ("untested", "blocked") and not c.get("reason"):
            errors.append(f"{c.get('id')}: untested/blocked cells need a reason")
        if c.get("status") == "tested" and not c.get("actual_entry"):
            errors.append(f"{c.get('id')}: tested cells need actual_entry")
        if c.get("diff") and not c.get("anomaly_ref"):
            errors.append(f"{c.get('id')}: has a diff but no anomaly_ref - add it to the anomalies section of 04-business-rules.md")
    print(json.dumps({**matrix_stats(root), "test_records": ledger_count(root), "errors": errors},
                     ensure_ascii=False, indent=2))
    sys.exit(1 if errors else 0)


def cmd_validate(args) -> None:
    root = project_path(args)
    warnings: list[str] = []
    errors = validate_sitemap(load_json(root / "01-sitemap.json"), warnings)
    print(json.dumps({"valid": not errors, "errors": errors, "warnings": warnings}, ensure_ascii=False, indent=2))
    sys.exit(1 if errors else 0)


FRONT_RE = re.compile(r"^---\s*\n(.*?)\n---\s*\n", re.DOTALL)


def read_front_matter(path: Path) -> dict:
    text = path.read_text(encoding="utf-8")
    match = FRONT_RE.match(text)
    if not match:
        return {}
    data = {}
    for line in match.group(1).splitlines():
        if ":" in line and not line.lstrip().startswith("#"):
            key, _, value = line.partition(":")
            data[key.strip()] = value.strip().strip('"').strip("'") or None
    return data


def sync(root: Path) -> dict:
    sitemap_path = root / "01-sitemap.json"
    sitemap = load_json(sitemap_path)
    index = {sc["id"]: (m, sc) for m, _, sc in iter_screens(sitemap) if sc.get("id")}
    orphans, updated = [], 0
    for path in sorted((root / "02-screens").rglob("*.md")):
        fm = read_front_matter(path)
        sid = fm.get("screen_id") or path.stem
        rel = path.relative_to(root).as_posix()
        status = fm.get("status") or "done"
        if status not in STATUSES:
            status = "partial"
        if sid in index:
            _, sc = index[sid]
            if sc.get("status") != status or sc.get("file") != rel:
                updated += 1
            sc["status"] = status
            sc["file"] = rel
            if fm.get("skip_reason"):
                sc["skip_reason"] = fm["skip_reason"]
        else:
            orphans.append({"id": sid, "file": rel, "module": fm.get("module")})
    if orphans:
        unmapped = next((m for m in sitemap["modules"] if m["id"] == "unmapped"), None)
        if unmapped is None:
            unmapped = {"id": "unmapped", "name": "شاشات غير موجودة في الخريطة", "priority": 99,
                        "sections": [{"id": "unmapped", "name": "غير مصنّفة", "screens": []}]}
            sitemap["modules"].append(unmapped)
        known = {sc["id"] for sc in unmapped["sections"][0]["screens"]}
        for o in orphans:
            if o["id"] not in known:
                unmapped["sections"][0]["screens"].append({
                    "id": o["id"], "name": o["id"], "url_pattern": None, "type": "detail",
                    "priority": 99, "status": "done", "file": o["file"], "skip_reason": None,
                    "notes": f"أُضيفت تلقائياً من ملف شاشة (الوحدة المعلنة: {o['module']})"})
    sitemap["generated_at"] = sitemap.get("generated_at") or now()
    sitemap["synced_at"] = now()
    save_json(sitemap_path, sitemap)
    return {"updated": updated, "orphans": orphans}


def cmd_sync(args) -> None:
    print(json.dumps(sync(project_path(args)), ensure_ascii=False, indent=2))


# ---------------------------------------------------------------- next / coverage

def module_order(sitemap: dict) -> list[dict]:
    return sorted(sitemap.get("modules", []), key=lambda m: (m.get("priority", 50), m.get("id", "")))


def cmd_next(args) -> None:
    root = project_path(args)
    sync(root)
    sitemap = load_json(root / "01-sitemap.json")
    for module in module_order(sitemap):
        if args.module and module["id"] != args.module:
            continue
        screens = [(sec, sc) for sec in module.get("sections", []) for sc in sec.get("screens", [])]
        screens.sort(key=lambda pair: pair[1].get("priority", 50))
        for want in ("in_progress", "pending"):
            for sec, sc in screens:
                if sc.get("status", "pending") == want:
                    print(json.dumps({"module": module["id"], "module_name": module["name"],
                                      "section": sec["id"], "screen": sc,
                                      "suggested_file": f"02-screens/{module['id']}/{sc['id']}.md"},
                                     ensure_ascii=False, indent=2))
                    return
    print(json.dumps({"done": True, "message": "لا شاشات معلّقة" + (f" في {args.module}" if args.module else "")},
                     ensure_ascii=False))


def coverage_stats(sitemap: dict) -> dict:
    total = {"total": 0, "done": 0, "partial": 0, "skipped": 0, "pending": 0, "in_progress": 0}
    modules = []
    skipped, remaining = [], []
    for module in module_order(sitemap):
        stats = {k: 0 for k in total}
        for sec in module.get("sections", []):
            for sc in sec.get("screens", []):
                status = sc.get("status", "pending")
                stats["total"] += 1
                stats[status] = stats.get(status, 0) + 1
                if status == "skipped":
                    skipped.append((module["name"], sc))
                elif status in ("pending", "in_progress"):
                    remaining.append((module["name"], sc))
        for k in total:
            total[k] += stats[k]
        modules.append((module, stats))
    return {"total": total, "modules": modules, "skipped": skipped, "remaining": remaining}


def pct(part: int, whole: int) -> str:
    return f"{(100.0 * part / whole):.1f}%" if whole else "0.0%"


def cmd_coverage(args) -> None:
    root = project_path(args)
    sync(root)
    sitemap = load_json(root / "01-sitemap.json")
    st = coverage_stats(sitemap)
    t = st["total"]
    out_of_scope = sum(1 for _, sc in st["skipped"] if "النطاق" in (sc.get("skip_reason") or "")
                       or "out of scope" in (sc.get("skip_reason") or "").lower())
    summary = {
        "screens_total": t["total"],
        "done": t["done"], "partial": t["partial"], "skipped": t["skipped"],
        "remaining": t["pending"] + t["in_progress"],
        "coverage_full": pct(t["done"], t["total"]),
        "coverage_any": pct(t["done"] + t["partial"], t["total"]),
        "coverage_in_scope": pct(t["done"] + t["partial"], t["total"] - out_of_scope),
    }
    mods = sitemap.get("modules", [])
    disabled = [m for m in mods if m.get("enabled") is False]
    summary["modules_total"] = len(mods)
    summary["modules_enabled"] = len(mods) - len(disabled)
    summary["modules_disabled"] = len(disabled)
    studied_modules = [m for m in mods if any(sc.get("status") in ("done", "partial")
                                            for sec in m.get("sections", []) for sc in sec.get("screens", []))]
    summary["module_coverage"] = pct(len(studied_modules), len(mods))
    fetch_only = [(m["name"], sc) for m, _, sc in iter_screens(sitemap)
                  if sc.get("extraction") == "fetch" and not sc.get("visual_check")]
    summary["fetch_only_screens"] = len(fetch_only)
    mstats = matrix_stats(root)
    summary["matrix"] = {k: mstats[k] for k in ("total", "tested", "untested", "blocked", "diffs")}
    if args.write:
        lines = [
            "# تقرير التغطية", "",
            f"_وُلّد تلقائياً بـ `acs_tool.py coverage --write` في {now()}._", "",
            "## الملخص", "",
            "| المؤشر | القيمة |", "|---|---|",
            f"| إجمالي الشاشات في الخريطة | {t['total']} |",
            f"| مدروسة بالكامل | {t['done']} ({summary['coverage_full']}) |",
            f"| مدروسة جزئياً | {t['partial']} |",
            f"| التغطية (كاملة + جزئية) | {summary['coverage_any']} |",
            f"| التغطية داخل النطاق (باستبعاد «خارج النطاق») | {summary['coverage_in_scope']} |",
            f"| متخطّاة | {t['skipped']} |",
            f"| متبقية | {summary['remaining']} |",
            f"| الوحدات (الإجمالي / المفعّلة / المعطّلة) | {len(mods)} / {len(mods) - len(disabled)} / {len(disabled)} |",
            f"| تغطية الوحدات (من الإجمالي، بما فيها المعطّلة) | {summary['module_coverage']} |",
            f"| شاشات قُرئت بـ fetch دون تحقق بصري | {len(fetch_only)} |",
            f"| خلايا مصفوفة الاختبار (مختبرة / غير مختبرة / محجوبة) | {mstats['tested']} / {mstats['untested']} / {mstats['blocked']} |",
            f"| فروق بين القيد المتوقع والفعلي | {mstats['diffs']} |", "",
            "## حسب الوحدة", "",
            "| الوحدة | الإجمالي | كاملة | جزئية | متخطّاة | متبقية | التغطية |",
            "|---|---|---|---|---|---|---|",
        ]
        for module, s in st["modules"]:
            lines.append(f"| {module['name']} | {s['total']} | {s['done']} | {s['partial']} | {s['skipped']} | "
                         f"{s['pending'] + s['in_progress']} | {pct(s['done'] + s['partial'], s['total'])} |")
        lines += ["", "## الشاشات المتخطّاة وسبب التخطي", ""]
        if st["skipped"]:
            lines += ["| الوحدة | الشاشة | السبب |", "|---|---|---|"]
            lines += [f"| {mn} | {sc['name']} (`{sc['id']}`) | {sc.get('skip_reason') or 'غير مذكور'} |"
                      for mn, sc in st["skipped"]]
        else:
            lines.append("لا يوجد.")
        lines += ["", "## الشاشات المتبقية", ""]
        if st["remaining"]:
            lines += ["| الوحدة | الشاشة | الأولوية |", "|---|---|---|"]
            lines += [f"| {mn} | {sc['name']} (`{sc['id']}`) | {sc.get('priority', '-')} |"
                      for mn, sc in st["remaining"]]
        else:
            lines.append("لا يوجد.")
        partial = [(m["name"], sc) for m in module_order(sitemap) for sec in m.get("sections", [])
                   for sc in sec.get("screens", []) if sc.get("status") == "partial"]
        lines += ["", "## الشاشات المدروسة جزئياً", ""]
        lines += ([f"- {mn}: {sc['name']} (`{sc['id']}`) — {sc.get('skip_reason') or 'راجع أسئلتها المفتوحة'}"
                   for mn, sc in partial] or ["لا يوجد."])
        lines += ["", "## الوحدات المعطّلة التي لم تُدرس", ""]
        lines += ([f"- {m['name']} (`{m['id']}`) — {m.get('category') or ''}" for m in disabled
                   if m not in studied_modules] or ["لا يوجد."])
        cells = [c for c in load_matrix(root).get("cells", []) if c.get("status") in ("untested", "blocked")]
        lines += ["", "## خلايا مصفوفة الاختبار غير المختبرة", ""]
        if cells:
            lines += ["| الخلية | المستند | المتغيرات | الحالة | السبب |", "|---|---|---|---|---|"]
            lines += [f"| {c.get('id')} | {c.get('document')} | "
                      f"{', '.join(f'{k}={v}' for k, v in (c.get('variables') or {}).items())} | {c.get('status')} | "
                      f"{c.get('reason') or 'غير مذكور'} |" for c in cells]
        else:
            lines.append("لا يوجد." if mstats["total"] else "لم تُبنَ مصفوفة اختبار (وضع القراءة فقط أو لم تُنفَّذ المرحلة 4.5).")
        lines += ["", "## شاشات قُرئت بالاستخراج السريع (fetch) دون تحقق بصري", ""]
        lines += ([f"- {mn}: {sc['name']} (`{sc['id']}`)" for mn, sc in fetch_only] or ["لا يوجد."])
        (root / "10-coverage-report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
        summary["written"] = "10-coverage-report.md"
    print(json.dumps(summary, ensure_ascii=False, indent=2))


# ---------------------------------------------------------------- progress / log

def cmd_progress(args) -> None:
    root = project_path(args)
    sync(root)
    sitemap = load_json(root / "01-sitemap.json")
    st = coverage_stats(sitemap)
    t = st["total"]
    block = [STATUS_BEGIN, f"### حالة الشاشات (آخر تحديث {now()})", "",
             f"الإجمالي {t['total']} · كاملة {t['done']} · جزئية {t['partial']} · متخطّاة {t['skipped']} · "
             f"متبقية {t['pending'] + t['in_progress']} · التغطية {pct(t['done'] + t['partial'], t['total'])}", "",
             "| الوحدة | الإجمالي | منجز | متبقٍ |", "|---|---|---|---|"]
    for module, s in st["modules"]:
        block.append(f"| {module['name']} (`{module['id']}`) | {s['total']} | "
                     f"{s['done'] + s['partial'] + s['skipped']} | {s['pending'] + s['in_progress']} |")
    mstats = matrix_stats(root)
    mods = sitemap.get("modules", [])
    block += ["", f"**الوحدات:** {len(mods)} (مفعّلة {sum(1 for m in mods if m.get('enabled') is not False)}، "
                  f"معطّلة {sum(1 for m in mods if m.get('enabled') is False)})",
              f"**مصفوفة الاختبار:** {mstats['total']} خلية · مختبرة {mstats['tested']} · غير مختبرة {mstats['untested']} · "
              f"محجوبة {mstats['blocked']} · فروق {mstats['diffs']}"
              + (f" · ⚠ فروق بلا بند تناقض: {', '.join(mstats['diffs_without_anomaly'])}" if mstats['diffs_without_anomaly'] else ""),
              f"**السجلات التجريبية:** {ledger_count(root)}"]
    ui = root / "ui"
    if ui.exists():
        maps = list((ui / "screens").rglob("*.map.md")) if (ui / "screens").exists() else []
        protos = [p for p in (ui / "screens").rglob("*.html")] if (ui / "screens").exists() else []
        designed = sum(1 for _ in designed_screens(root))
        cov_line = ""
        cov = ui / "coverage.md"
        if cov.exists():
            m = re.search(r"تغطية العناصر[^|]*\|\s*([\d.]+%)", cov.read_text(encoding="utf-8"))
            cov_line = f" · تغطية العناصر {m.group(1)}" if m else ""
        approvals = ui / "approvals.md"
        block += ["", "### الواجهات (المرحلة 8)", "",
                  f"الشاشات المطلوبة {designed} · ملفات ربط {len(maps)} · نماذج تفاعلية {len(protos)}{cov_line}",
                  f"نظام التصميم: {'✓' if (ui / 'tokens.json').exists() and (ui / 'components.md').exists() else '⬜'} · "
                  f"التنقل: {'✓' if (ui / 'navigation.json').exists() else '⬜'} · "
                  f"الموافقات: {'راجع ui/approvals.md' if approvals.exists() else 'لا شيء بعد'}"]
    block.append(STATUS_END)
    path = root / "progress.md"
    text = path.read_text(encoding="utf-8")
    if STATUS_BEGIN in text and STATUS_END in text:
        head, _, rest = text.partition(STATUS_BEGIN)
        _, _, tail = rest.partition(STATUS_END)
        text = head + "\n".join(block) + tail
    else:
        text = text.rstrip() + "\n\n" + "\n".join(block) + "\n"
    path.write_text(text, encoding="utf-8")
    print(json.dumps({"updated": "progress.md", "coverage": pct(t["done"] + t["partial"], t["total"])},
                     ensure_ascii=False))


def cmd_log(args) -> None:
    root = project_path(args)
    path = root / "progress.md"
    text = path.read_text(encoding="utf-8")
    line = f"- {now()} — {args.message}"
    if LOG_HEADER not in text:
        text = text.rstrip() + f"\n\n{LOG_HEADER}\n\n"
    path.write_text(text.rstrip() + "\n" + line + "\n", encoding="utf-8")
    print(line)


# ---------------------------------------------------------------- scrub

LEAK_PATTERNS = [
    ("email", re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+")),
    ("saudi_iban", re.compile(r"\bSA\d{2}\s?(?:\d{4}\s?){4}\d{2,4}\b")),
    ("iban_generic", re.compile(r"\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}\b")),
    ("vat_number_unmasked", re.compile(r"\b3\d{13}3\b")),
    ("phone_sa", re.compile(r"(?:\+966|00966|\b05)\d{8}\b")),
    ("card_number", re.compile(r"\b(?:\d{4}[ -]?){3}\d{4}\b")),
    ("jwt_token", re.compile(r"eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}")),
    ("bearer_token", re.compile(r"(?i)bearer\s+[A-Za-z0-9._~+/=-]{16,}")),
    ("cookie_header", re.compile(r"(?i)(?:set-)?cookie\s*:\s*\S+=\S{8,}")),
    ("credential_value", re.compile(
        r"(?i)(?:password|passwd|pwd|كلمة\s*(?:المرور|السر)|otp)\s*[:=]\s*[^\s|`<>\-]{3,}")),
    ("api_key_param", re.compile(r"(?i)(?:api[_-]?key|access[_-]?token|session[_-]?id)=[A-Za-z0-9._-]{12,}")),
]
HEX_RE = re.compile(r"#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b")
UI_SUFFIXES = {".html", ".css", ".js", ".svg", ".png", ".webp", ".json"}
TEXT_SUFFIXES = {".md", ".json", ".sql", ".mmd", ".txt", ".csv", ".html", ".css", ".js", ".svg"}


def normalize_hex(value) -> str | None:
    if not isinstance(value, str):
        return None
    m = re.fullmatch(r"#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})", value.strip())
    if not m:
        return None
    h = m.group(1).lower()
    return "#" + ("".join(c * 2 for c in h) if len(h) == 3 else h)


TYPE_WORDS = {"string", "str", "text", "number", "int", "integer", "bigint", "float", "decimal", "numeric", "money",
              "boolean", "bool", "date", "datetime", "timestamp", "time", "array", "object", "null", "uuid", "enum",
              "id", "url", "email", "phone", "json", "nullable", "optional", "file", "any"}
API_VALUE_RE = re.compile(r'"([\w.\-]+)"\s*:\s*("([^"]*)"|-?\d+(?:\.\d+)?|true|false)')
RAW_SUFFIXES = {".html", ".htm", ".png", ".jpg", ".jpeg", ".webp", ".har", ".pdf", ".xlsx", ".csv"}
ALLOWED_JSON = {"01-sitemap.json", "04a-scenario-matrix.json", "ui/tokens.json", "ui/navigation.json"}
EXAMPLE_ALLOW = re.compile(r"x{3,}|example\.(?:com|org)|<[^>]+>|\.\.\.|\*{3,}", re.IGNORECASE)


def cmd_scrub(args) -> None:
    root = project_path(args)
    brands = [b for b in (args.brand or []) if b.strip()]
    config_path = root / "_private" / "project.json"
    if config_path.exists() and not args.no_config_brand:
        cfg = load_json(config_path)
        for key in ("system_name_private",):
            if cfg.get(key):
                brands.append(cfg[key])
        if cfg.get("base_url"):
            host = re.sub(r"^https?://", "", cfg["base_url"]).split("/")[0]
            if host:
                brands.append(host)
        identity = cfg.get("identity") or {}
        brands += [t for t in identity.get("terms", []) if isinstance(t, str) and len(t.strip()) >= 3]
        colors = [normalize_hex(c) for c in identity.get("colors", []) if normalize_hex(c)]
    else:
        colors = []
    findings = []
    for path in sorted(root.rglob("*")):
        if path.is_file() and "_private" not in path.parts:
            rel = path.relative_to(root).as_posix()
            if rel.startswith("ui/") and path.suffix.lower() in UI_SUFFIXES:
                continue
            if path.suffix.lower() in RAW_SUFFIXES or (path.suffix == ".json" and rel not in ALLOWED_JSON):
                findings.append({"file": rel, "line": 0, "kind": "raw_artifact",
                                 "match": "نسخة خام من النظام (HTML/لقطة/JSON) لا تُحفظ في المخرجات"})
    api_map = root / "01-api-map.md"
    if api_map.exists():
        for lineno, line in enumerate(api_map.read_text(encoding="utf-8").splitlines(), 1):
            for m in API_VALUE_RE.finditer(line):
                value = (m.group(3) if m.group(3) is not None else m.group(2)).strip().lower()
                words = set(re.split(r"[\s|,()<>\[\]/]+", value)) - {""}
                if value.startswith(("enum(", "enum:", "one of")):
                    continue
                if value in ("true", "false") or not words or not words <= TYPE_WORDS:
                    if not EXAMPLE_ALLOW.search(value):
                        findings.append({"file": "01-api-map.md", "line": lineno, "kind": "api_map_value",
                                         "match": m.group(0)[:60] + " — اكتب النوع لا القيمة"})
    for path in sorted(root.rglob("*")):
        if not path.is_file() or "_private" in path.parts or path.suffix not in TEXT_SUFFIXES:
            continue
        rel = path.relative_to(root).as_posix()
        for lineno, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
            for kind, pattern in LEAK_PATTERNS:
                for match in pattern.finditer(line):
                    if EXAMPLE_ALLOW.search(match.group(0)):
                        continue
                    findings.append({"file": rel, "line": lineno, "kind": kind, "match": match.group(0)[:60]})
            for brand in brands:
                if brand.lower() in line.lower():
                    findings.append({"file": rel, "line": lineno, "kind": "original_brand_or_host",
                                     "match": brand})
            if colors:
                for m in HEX_RE.finditer(line):
                    if normalize_hex(m.group(0)) in colors:
                        findings.append({"file": rel, "line": lineno, "kind": "original_identity_color",
                                         "match": m.group(0)})
    print(json.dumps({"clean": not findings, "count": len(findings), "findings": findings[:200]},
                     ensure_ascii=False, indent=2))
    sys.exit(1 if findings else 0)


# ---------------------------------------------------------------- UI (acs-ui-designer)

UI_KINDS = {"field": "حقل", "action": "إجراء", "state": "حالة"}
KIND_ALIASES = {"حقل": "field", "عمود": "field", "field": "field", "column": "field",
                "إجراء": "action", "اجراء": "action", "زر": "action", "action": "action",
                "حالة": "state", "state": "state"}
SPEC_SECTIONS = [("## الحقول", "field"), ("## الجداول الفرعية", "field"),
                 ("## الأزرار والإجراءات", "action"), ("## الحالات", "state")]
SKIP_CELLS = {"", "—", "-", "لا ينطبق", "لا يوجد", "الحقل", "العمود", "الإجراء", "الحالة", "field", "action", "state"}


def norm_label(text: str) -> str:
    text = re.sub(r"\[(?:مُ|م)ستنتج\]|\[تحسين\]", "", text)
    text = re.sub(r"[`*_]|<[^>]+>", "", text)
    text = re.sub(r"\([^)]*\)", "", text)
    text = re.sub(r"[\u064B-\u0652\u0640]", "", text)  # harakat + tatweel
    text = text.replace("أ", "ا").replace("إ", "ا").replace("آ", "ا").replace("ى", "ي").replace("ة", "ه")
    return re.sub(r"\s+", " ", text).strip(" :.-—").lower()


def md_sections(text: str) -> dict[str, str]:
    parts, current, buf = {}, None, []
    for line in text.splitlines():
        if line.startswith("## "):
            if current is not None:
                parts[current] = "\n".join(buf)
            current, buf = line.strip(), []
        elif current is not None:
            buf.append(line)
    if current is not None:
        parts[current] = "\n".join(buf)
    return parts


def first_column(block: str) -> list[str]:
    cells = []
    for line in block.splitlines():
        line = line.strip()
        if not line.startswith("|") or re.match(r"^\|[\s:|-]+\|?$", line):
            continue
        first = line.strip("|").split("|")[0].strip()
        if norm_label(first) not in {norm_label(x) for x in SKIP_CELLS}:
            cells.append(first)
    return cells


def table_rows(block: str) -> list[list[str]]:
    rows = []
    for line in block.splitlines():
        line = line.strip()
        if line.startswith("|") and not re.match(r"^\|[\s:|-]+\|?$", line):
            rows.append([c.strip() for c in line.strip("|").split("|")])
    return rows[1:] if rows else []


ARROW_RE = re.compile(r"-{1,2}\([^)]*\)-{1,2}>|<-{1,2}>|<-{1,2}|-{1,2}>|→|←|↔|⇄|⟶|⟵")


def chain_states(block: str) -> list[str]:
    """States written as an arrow chain, e.g. `مسودة --(اعتماد)--> معتمدة → مدفوعة`."""
    states = []
    for line in block.splitlines():
        if not ARROW_RE.search(line) or line.strip().startswith("|"):
            continue
        for piece in ARROW_RE.sub("|", line).split("|"):
            piece = re.sub(r"\[[^\]]*\]|\([^)]*\)|`", "", piece).strip()
            if ":" in piece:
                piece = piece.split(":")[-1].strip()
            if piece and len(piece.split()) <= 3 and not re.search(r"[=<>{}]", piece):
                states.append(piece)
    return states


def spec_elements(spec_text: str) -> list[tuple[str, str]]:
    """(kind, label) for every field, sub-table column, action and lifecycle state in a screen spec."""
    out, seen = [], set()
    for heading, block in md_sections(spec_text).items():
        for prefix, kind in SPEC_SECTIONS:
            if heading.startswith(prefix):
                if prefix == "## الحقول" and "الشرطية" in heading:
                    continue
                labels = first_column(block) + (chain_states(block) if kind == "state" else [])
                for label in labels:
                    key = (kind, norm_label(label))
                    if key[1] and key not in seen:
                        seen.add(key)
                        out.append((kind, label))
    return out


def designed_screens(root: Path):
    sitemap = load_json(root / "01-sitemap.json")
    for module, _, sc in iter_screens(sitemap):
        if sc.get("status") in ("done", "partial") and sc.get("file"):
            yield module, sc


def map_path(root: Path, module_id: str, screen_id: str) -> Path:
    return root / "ui" / "screens" / module_id / f"{screen_id}.map.md"


def cmd_uiscaffold(args) -> None:
    root = project_path(args)
    targets = [(m, sc) for m, sc in designed_screens(root) if not args.screen or sc["id"] == args.screen]
    if args.module:
        targets = [(m, sc) for m, sc in targets if m["id"] == args.module]
    written = []
    for module, sc in targets:
        path = map_path(root, module["id"], sc["id"])
        if path.exists() and not args.force:
            continue
        elements = spec_elements((root / sc["file"]).read_text(encoding="utf-8"))
        rows = "\n".join(f"| {label} | {UI_KINDS[kind]} | ؟ | |" for kind, label in elements)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(
            f"# ربط الواجهة: {sc['name']} (`{sc['id']}`)\n\n"
            f"- ملف المواصفات: `{sc['file']}`\n- النموذج التفاعلي: `{sc['id']}.html`\n- النمط: ؟\n"
            f"- موقعه في التنقل الجديد: ؟\n\n"
            "## جدول الربط\n\n"
            "انسخ اسم العنصر كما هو من المواصفات. استبدل كل `؟` بالمكوّن المستخدم (من `ui/components.md`).\n\n"
            "| عنصر المواصفات | النوع | المكوّن في التصميم | ملاحظات |\n|---|---|---|---|\n"
            f"{rows}\n\n"
            "## عناصر غير مصممة\n\nكل عنصر نُقل من جدول الربط إلى هنا يحتاج تبريراً مكتوباً.\n\n"
            "| العنصر | النوع | التبرير |\n|---|---|---|\n\n"
            "## الحالات المصممة\n\n- فارغة: ؟\n- تحميل: ؟\n- خطأ: ؟\n- أخطاء التحقق: ؟\n- حالات المستند: ؟\n\n"
            "## حسب الصلاحيات\n\n| الدور | يُخفى | يُعطَّل |\n|---|---|---|\n\n"
            "## التحسينات\n\n- [تحسين] … — السبب: …\n",
            encoding="utf-8")
        written.append(path.relative_to(root).as_posix())
    print(json.dumps({"written": written, "count": len(written)}, ensure_ascii=False, indent=2))


def parse_map(text: str):
    sections = md_sections(text)
    mapped, justified, unfilled = {}, {}, []
    for heading, block in sections.items():
        if heading.startswith("## جدول الربط"):
            for row in table_rows(block):
                if len(row) < 3:
                    continue
                kind = KIND_ALIASES.get(norm_label(row[1]).replace("ه", "ة"), KIND_ALIASES.get(row[1].strip(), "field"))
                comp = row[2].strip()
                key = (kind, norm_label(row[0]))
                if comp in ("", "؟", "?", "—", "-"):
                    unfilled.append(row[0])
                else:
                    mapped[key] = comp
        elif heading.startswith("## عناصر غير مصممة"):
            for row in table_rows(block):
                if len(row) >= 3 and row[2].strip() not in ("", "؟", "?", "—", "-"):
                    kind = KIND_ALIASES.get(row[1].strip(), "field")
                    justified[(kind, norm_label(row[0]))] = row[2].strip()
    return mapped, justified, unfilled


def cmd_uicheck(args) -> None:
    root = project_path(args)
    ui = root / "ui"
    if not ui.exists():
        sys.exit("error: no ui/ folder - run acs-ui-designer first")
    nav_ids = set()
    nav_path = ui / "navigation.json"
    nav_warnings = []
    if nav_path.exists():
        nav = load_json(nav_path)
        for sec in nav.get("sections", []):
            for item in sec.get("items", []):
                nav_ids.update(item.get("screen_ids", []))
        sitemap_order = [norm_label(m["name"]) for m in module_order(load_json(root / "01-sitemap.json"))]
        nav_order = [norm_label(sec.get("label", "")) for sec in nav.get("sections", [])]
        if nav_order and nav_order == sitemap_order[:len(nav_order)]:
            nav_warnings.append("أقسام التنقل تطابق ترتيب وحدات النظام المرجعي وأسماءها — أعد صياغة معمارية المعلومات.")
    else:
        nav_warnings.append("ui/navigation.json غير موجود.")
    per_screen, totals = [], {"field": [0, 0], "action": [0, 0], "state": [0, 0]}
    problems = []
    for module, sc in designed_screens(root):
        elements = spec_elements((root / sc["file"]).read_text(encoding="utf-8"))
        mp = map_path(root, module["id"], sc["id"])
        html_path = mp.with_name(f"{sc['id']}.html")
        entry = {"screen": sc["id"], "module": module["id"], "elements": len(elements), "mapped": 0,
                 "justified": 0, "missing": [], "unfilled": [], "map": mp.exists(), "prototype": html_path.exists(),
                 "in_navigation": sc["id"] in nav_ids}
        mapped, justified, unfilled = parse_map(mp.read_text(encoding="utf-8")) if mp.exists() else ({}, {}, [])
        entry["unfilled"] = unfilled
        for kind, label in elements:
            key = (kind, norm_label(label))
            alt = [k for k in list(mapped) + list(justified) if k[1] == key[1]]
            totals[kind][1] += 1
            if key in mapped or (alt and alt[0] in mapped):
                entry["mapped"] += 1
                totals[kind][0] += 1
            elif key in justified or (alt and alt[0] in justified):
                entry["justified"] += 1
                totals[kind][0] += 1
            else:
                entry["missing"].append(f"{UI_KINDS[kind]}: {label}")
        if not entry["map"]:
            problems.append(f"{sc['id']}: لا يوجد ملف ربط")
        if not entry["prototype"]:
            problems.append(f"{sc['id']}: لا يوجد نموذج تفاعلي {html_path.name}")
        if entry["missing"]:
            problems.append(f"{sc['id']}: {len(entry['missing'])} عنصر بلا ربط ولا تبرير")
        if entry["unfilled"]:
            problems.append(f"{sc['id']}: {len(entry['unfilled'])} صف مكوّنه فارغ (؟)")
        if nav_path.exists() and not entry["in_navigation"]:
            problems.append(f"{sc['id']}: غير موجودة في ui/navigation.json")
        per_screen.append(entry)
    total_done = sum(v[0] for v in totals.values())
    total_all = sum(v[1] for v in totals.values())
    summary = {"screens": len(per_screen), "with_map": sum(e["map"] for e in per_screen),
               "with_prototype": sum(e["prototype"] for e in per_screen),
               "coverage": pct(total_done, total_all),
               "by_kind": {UI_KINDS[k]: pct(v[0], v[1]) for k, v in totals.items()},
               "justified": sum(e["justified"] for e in per_screen), "problems": problems, "warnings": nav_warnings}
    lines = ["# تغطية الواجهات", "", f"_وُلّد تلقائياً بـ `acs_tool.py uicheck` في {now()}._", "",
             "| المؤشر | القيمة |", "|---|---|",
             f"| الشاشات المطلوب تصميمها | {summary['screens']} |",
             f"| لها ملف ربط / نموذج تفاعلي | {summary['with_map']} / {summary['with_prototype']} |",
             f"| تغطية العناصر (مربوطة أو مبرَّرة) | {summary['coverage']} |",
             f"| الحقول / الإجراءات / الحالات | {summary['by_kind']['حقل']} / {summary['by_kind']['إجراء']} / {summary['by_kind']['حالة']} |",
             f"| عناصر غير مصممة بتبرير | {summary['justified']} |", "",
             "## حسب الشاشة", "", "| الشاشة | العناصر | مربوطة | مبرَّرة | ناقصة | نموذج | في التنقل |", "|---|---|---|---|---|---|---|"]
    for e in per_screen:
        lines.append(f"| `{e['module']}/{e['screen']}` | {e['elements']} | {e['mapped']} | {e['justified']} | "
                     f"{len(e['missing'])} | {'✓' if e['prototype'] else '✗'} | {'✓' if e['in_navigation'] else '✗'} |")
    lines += ["", "## مشكلات تمنع التسليم", ""] + ([f"- {p}" for p in problems] or ["لا يوجد."])
    missing_detail = [(e["screen"], m) for e in per_screen for m in e["missing"]]
    if missing_detail:
        lines += ["", "## العناصر الناقصة", ""] + [f"- `{sid}` — {m}" for sid, m in missing_detail]
    lines += ["", "## تحذيرات", ""] + ([f"- {w}" for w in nav_warnings] or ["لا يوجد."])
    (ui / "coverage.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    sys.exit(1 if problems else 0)


def _lum(hex_color: str) -> float:
    h = hex_color.strip().lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    rgb = [int(h[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    lin = [c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4 for c in rgb]
    return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2]


def contrast_ratio(a: str, b: str) -> float:
    la, lb = sorted((_lum(a), _lum(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)


def cmd_uicontrast(args) -> None:
    root = project_path(args)
    tokens = load_json(root / "ui" / "tokens.json")
    pairs = tokens.get("contrast_pairs") or []
    results, failures = [], []
    for palette_name in ("color", "color_dark"):
        palette = tokens.get(palette_name)
        if not palette:
            continue
        for fg, bg, minimum in pairs:
            if fg not in palette or bg not in palette:
                failures.append(f"{palette_name}: token missing for pair {fg}/{bg}")
                continue
            ratio = contrast_ratio(palette[fg], palette[bg])
            ok = ratio >= float(minimum)
            results.append({"palette": palette_name, "fg": fg, "bg": bg, "ratio": round(ratio, 2), "min": minimum, "ok": ok})
            if not ok:
                failures.append(f"{palette_name}: {fg} on {bg} = {ratio:.2f} < {minimum}")
    if not pairs:
        failures.append("tokens.json has no contrast_pairs")
    print(json.dumps({"pairs_checked": len(results), "failures": failures, "results": results}, ensure_ascii=False, indent=2))
    sys.exit(1 if failures else 0)


# ---------------------------------------------------------------- main

def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("init", help="create the spec project folder")
    p.add_argument("--root", default=".", help="parent directory for <slug>-spec/")
    p.add_argument("--system-name", required=True, help="original system name (kept in _private only)")
    p.add_argument("--slug", help="folder slug; defaults to a slug of --system-name. Use a neutral codename "
                                  "if the folder will be shared with the implementing agent.")
    p.add_argument("--url", default=None)
    p.add_argument("--demo", default="غير معروف")
    p.add_argument("--language", default="ar")
    p.add_argument("--direction", default="rtl", choices=["rtl", "ltr", "both"])
    p.add_argument("--country", default=None)
    p.add_argument("--scope", default="كامل النظام")
    p.add_argument("--stack", default="يُترك للوكيل المنفّذ")
    p.add_argument("--authorized-at", default=None, help="timestamp of the user's authorization confirmation")
    p.add_argument("--mode", default="read-only", choices=["read-only", "sandbox"],
                   help="sandbox only after the user explicitly confirms a test system AND permits creating records")
    p.add_argument("--sandbox-confirmed-at", default=None, help="timestamp of the sandbox confirmation")
    p.add_argument("--test-prefix", default="اختبار-Claude-", help="fixed prefix for every test record")
    p.add_argument("--force", action="store_true")
    p.set_defaults(func=cmd_init)

    for name, func, helptext in [
        ("validate", cmd_validate, "validate 01-sitemap.json"),
        ("sync", cmd_sync, "merge screen files into 01-sitemap.json"),
        ("progress", cmd_progress, "refresh the status block in progress.md"),
    ]:
        p = sub.add_parser(name, help=helptext)
        p.add_argument("--project", default=".")
        p.set_defaults(func=func)

    p = sub.add_parser("next", help="print the next screen to study")
    p.add_argument("--project", default=".")
    p.add_argument("--module", default=None, help="restrict to one module id (batch mode)")
    p.set_defaults(func=cmd_next)

    p = sub.add_parser("coverage", help="compute coverage")
    p.add_argument("--project", default=".")
    p.add_argument("--write", action="store_true", help="regenerate 10-coverage-report.md")
    p.set_defaults(func=cmd_coverage)

    p = sub.add_parser("matrix", help="summarise and check 04a-scenario-matrix.json")
    p.add_argument("--project", default=".")
    p.set_defaults(func=cmd_matrix)

    p = sub.add_parser("urlcheck", help="refuse action-like URLs before requesting them")
    p.add_argument("url")
    p.add_argument("--label", default="", help="visible text of the link/button")
    p.set_defaults(func=cmd_urlcheck)

    p = sub.add_parser("uiscaffold", help="write UI map skeletons from the screen specs")
    p.add_argument("--project", default=".")
    p.add_argument("--module", default=None)
    p.add_argument("--screen", default=None)
    p.add_argument("--force", action="store_true", help="overwrite existing map files")
    p.set_defaults(func=cmd_uiscaffold)

    p = sub.add_parser("uicheck", help="functional parity of ui/ against the specs")
    p.add_argument("--project", default=".")
    p.set_defaults(func=cmd_uicheck)

    p = sub.add_parser("uicontrast", help="WCAG contrast of ui/tokens.json")
    p.add_argument("--project", default=".")
    p.set_defaults(func=cmd_uicontrast)

    p = sub.add_parser("log", help="append to the progress log")
    p.add_argument("--project", default=".")
    p.add_argument("message")
    p.set_defaults(func=cmd_log)

    p = sub.add_parser("scrub", help="scan deliverables for leaks")
    p.add_argument("--project", default=".")
    p.add_argument("--brand", action="append", help="extra brand / product terms to flag (repeatable)")
    p.add_argument("--no-config-brand", action="store_true", help="do not flag the name/host stored in _private")
    p.set_defaults(func=cmd_scrub)

    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
