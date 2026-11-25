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
  scrub     Scan the deliverables for privacy / credential / brand leaks.

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
    ("2", "دراسة الشاشات", "acs-analyzer"),
    ("3", "نموذج البيانات", "acs-analyzer"),
    ("4", "المنطق المحاسبي وقواعد العمل", "acs-analyzer"),
    ("5", "الصلاحيات وسير العمل", "acs-analyzer"),
    ("6", "التقارير", "acs-analyzer"),
    ("7", "حزمة المواصفات والتعليمات للمنفّذ", "acs-spec-writer"),
]
STUB_FILES = [
    "00-executive-summary.md",
    "01-sitemap.md",
    "03-data-model.md",
    "03-erd.mmd",
    "03-schema.sql",
    "04-business-rules.md",
    "05-roles-permissions.md",
    "06-reports.md",
    "07-ux-guidelines.md",
    "08-build-brief.md",
    "09-open-questions.md",
    "10-coverage-report.md",
]
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
        "mode": "read-only",
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

    progress = root / "progress.md"
    if not progress.exists() or args.force:
        phase_rows = "\n".join(f"| {p} | {n} | {s} | ⬜ لم تبدأ | |" for p, n, s in PHASES)
        progress.write_text(
            f"# تقدّم دراسة النظام المرجعي\n\n"
            f"- أُنشئ: {now()}\n- الوضع: قراءة فقط\n"
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


def validate_sitemap(sitemap: dict) -> list[str]:
    errors = []
    if not isinstance(sitemap.get("modules"), list):
        return ["`modules` must be a list"]
    seen = set()
    for m in sitemap["modules"]:
        for key in ("id", "name", "sections"):
            if key not in m:
                errors.append(f"module missing `{key}`: {m.get('id', m)}")
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
                if "url_pattern" not in sc:
                    errors.append(f"{sid}: missing url_pattern (use null if the screen has no own URL)")
                if not isinstance(sc.get("priority", 1), int):
                    errors.append(f"{sid}: priority must be an integer")
    return errors


def cmd_validate(args) -> None:
    root = project_path(args)
    errors = validate_sitemap(load_json(root / "01-sitemap.json"))
    print(json.dumps({"valid": not errors, "errors": errors}, ensure_ascii=False, indent=2))
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
            f"| متبقية | {summary['remaining']} |", "",
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
    findings = []
    for path in sorted(root.rglob("*")):
        if not path.is_file() or "_private" in path.parts or path.suffix not in {".md", ".json", ".sql", ".mmd", ".txt", ".csv"}:
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
    print(json.dumps({"clean": not findings, "count": len(findings), "findings": findings[:200]},
                     ensure_ascii=False, indent=2))
    sys.exit(1 if findings else 0)


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
