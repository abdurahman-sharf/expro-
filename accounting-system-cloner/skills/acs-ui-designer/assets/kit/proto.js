/* proto.js — behaviour for acs-ui-designer prototypes. No styling, no colours: everything visual
 * comes from tokens.css + proto.css. Declarative hooks (data-* attributes) keep every screen
 * prototype short and identical in behaviour, so a change here reaches every screen.
 *
 * Hooks
 *   Tabs ............ [role=tablist] > [role=tab][aria-controls=<panel id>]  (click, ←/→, Home/End)
 *   Conditional ..... [data-show-when="name=value1|value2"]  (shown when the control named `name` has one of the values;
 *                     "name=!value" negates; checkboxes use "on"/"off")
 *   Line editor ..... table[data-line-editor] with <template> row; inputs [data-col=qty|price|discount|tax];
 *                     outputs [data-col=net|tax-amount|total]; sums [data-sum=net|tax|total|count];
 *                     [data-add-line], [data-remove-line]; Enter on the last input of the last row adds a row;
 *                     Ctrl/Cmd+Enter anywhere adds a row; checkbox [data-tax-inclusive] switches the formula;
 *                     input [data-doc-discount] subtracts a document-level discount before tax.
 *   Item search ..... input[data-item-search][list] fills sibling [data-col=price] from <option data-price>.
 *   Modal ........... [data-open-modal=<dialog id>], [data-close-modal]; dialog[data-confirm] returns value.
 *   Toast ........... [data-toast="text"] shows an aria-live message.
 *   Role preview .... select[data-role-switch] sets <body data-role>; [data-hide-for~="role"] hidden,
 *                     [data-disable-for~="role"] disabled.
 *   State preview ... select[data-state-switch] sets [data-state] on its target; [data-state-view~="state"] shown.
 *   Theme ........... [data-theme-toggle] cycles light / dark / system on <html data-theme>.
 *   Numbers ......... [data-money] formats on blur with thousands separator and 2 decimals.
 */
(() => {
  "use strict";
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const locale = document.documentElement.getAttribute("data-number-locale") || "en-US";
  const fmt = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const num = v => {
    const n = parseFloat(String(v ?? "").replace(/[٬,\s]/g, "").replace(/[٠-٩]/g, d => "٠١٢٣٤٥٦٧٨٩".indexOf(d)));
    return Number.isFinite(n) ? n : 0;
  };
  const round2 = n => Math.round((n + Number.EPSILON) * 100) / 100;

  /* Tabs */
  function initTabs(root = document) {
    $$('[role="tablist"]', root).forEach(list => {
      const tabs = $$('[role="tab"]', list);
      const select = tab => {
        tabs.forEach(t => {
          const on = t === tab;
          t.setAttribute("aria-selected", String(on));
          t.tabIndex = on ? 0 : -1;
          const panel = document.getElementById(t.getAttribute("aria-controls"));
          if (panel) panel.hidden = !on;
        });
      };
      tabs.forEach((t, i) => {
        t.addEventListener("click", () => select(t));
        t.addEventListener("keydown", e => {
          const rtl = getComputedStyle(list).direction === "rtl";
          const next = { ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1 }[e.key];
          let j = null;
          if (next !== undefined) j = (i + next + tabs.length) % tabs.length;
          if (e.key === "Home") j = 0;
          if (e.key === "End") j = tabs.length - 1;
          if (j !== null) { e.preventDefault(); tabs[j].focus(); select(tabs[j]); }
        });
      });
      select(tabs.find(t => t.getAttribute("aria-selected") === "true") || tabs[0]);
    });
  }

  /* Conditional fields */
  function controlValue(form, name) {
    const els = $$(`[name="${CSS.escape(name)}"]`, form);
    if (!els.length) return null;
    const el = els[0];
    if (el.type === "checkbox") return el.checked ? "on" : "off";
    if (el.type === "radio") return (els.find(r => r.checked) || {}).value ?? "";
    return el.value;
  }
  function applyConditions(root = document) {
    $$("[data-show-when]", root).forEach(el => {
      const [name, raw] = el.getAttribute("data-show-when").split("=");
      const negate = raw.startsWith("!");
      const wanted = (negate ? raw.slice(1) : raw).split("|");
      const scope = el.closest("form") || document;
      const value = controlValue(scope, name.trim());
      const match = wanted.includes(value ?? "");
      el.hidden = negate ? match : !match;
      $$("input, select, textarea", el).forEach(c => { c.disabled = el.hidden; });
    });
  }

  /* Line editor */
  function recalc(table) {
    const inclusive = !!$("[data-tax-inclusive]", table.closest("form") || document)?.checked;
    let net = 0, tax = 0, count = 0;
    $$("tbody tr", table).forEach(row => {
      const qty = num($('[data-col="qty"]', row)?.value);
      const price = num($('[data-col="price"]', row)?.value);
      const disc = num($('[data-col="discount"]', row)?.value);
      const rate = num($('[data-col="tax"]', row)?.value) / 100;
      const gross = qty * price * (1 - disc / 100);
      const lineNet = round2(inclusive ? gross / (1 + rate) : gross);
      const lineTax = round2(inclusive ? gross - lineNet : lineNet * rate);
      const out = (col, v) => { const o = $(`[data-col="${col}"]`, row); if (o) o.textContent = fmt.format(v); };
      out("net", lineNet); out("tax-amount", lineTax); out("total", lineNet + lineTax);
      if (qty > 0) count++;
      net += lineNet; tax += lineTax;
    });
    const docDisc = num($("[data-doc-discount]", table.closest("form") || document)?.value);
    if (docDisc > 0 && net > 0) { const ratio = Math.max(0, net - docDisc) / net; net = round2(net * ratio); tax = round2(tax * ratio); }
    const scope = table.closest("form") || document;
    const sum = (k, v) => $$(`[data-sum="${k}"]`, scope).forEach(o => { o.textContent = k === "count" ? String(v) : fmt.format(v); });
    sum("net", net); sum("tax", tax); sum("total", round2(net + tax)); sum("count", count);
  }
  function addLine(table, focus = true) {
    const tpl = $("template", table);
    if (!tpl) return;
    const row = tpl.content.firstElementChild.cloneNode(true);
    $("tbody", table).appendChild(row);
    renumber(table);
    recalc(table);
    if (focus) $("input, select", row)?.focus();
  }
  function renumber(table) { $$("tbody tr", table).forEach((r, i) => { const n = $("[data-line-no]", r); if (n) n.textContent = String(i + 1); }); }
  function initLineEditors(root = document) {
    $$("table[data-line-editor]", root).forEach(table => {
      if (!$$("tbody tr", table).length) addLine(table, false);
      table.addEventListener("input", () => recalc(table));
      table.addEventListener("click", e => {
        const rm = e.target.closest("[data-remove-line]");
        if (rm) { rm.closest("tr").remove(); if (!$$("tbody tr", table).length) addLine(table, false); renumber(table); recalc(table); }
      });
      table.addEventListener("keydown", e => {
        if ((e.ctrlKey || e.metaKey) && e.key === "Enter") { e.preventDefault(); addLine(table); return; }
        if (e.key !== "Enter" || e.target.tagName === "TEXTAREA") return;
        const rows = $$("tbody tr", table);
        const row = e.target.closest("tr");
        const inputs = $$("input:not([type=hidden]), select", row);
        if (row === rows[rows.length - 1] && e.target === inputs[inputs.length - 1]) { e.preventDefault(); addLine(table); }
      });
      const scope = table.closest("form") || document;
      $$("[data-add-line]", scope).forEach(b => b.addEventListener("click", () => addLine(table)));
      $$("[data-tax-inclusive], [data-doc-discount]", scope).forEach(c => c.addEventListener("input", () => recalc(table)));
      recalc(table);
    });
  }

  /* Item search fills price */
  document.addEventListener("change", e => {
    const input = e.target.closest("input[data-item-search][list]");
    if (!input) return;
    const opt = $$(`#${CSS.escape(input.getAttribute("list"))} option`).find(o => o.value === input.value);
    const price = opt?.dataset.price;
    const row = input.closest("tr");
    if (price && row) { const p = $('[data-col="price"]', row); if (p && !p.value) { p.value = price; p.dispatchEvent(new Event("input", { bubbles: true })); } }
  });

  /* Modals */
  document.addEventListener("click", e => {
    const opener = e.target.closest("[data-open-modal]");
    if (opener) { const d = document.getElementById(opener.getAttribute("data-open-modal")); if (d?.showModal) { d.showModal(); $("[autofocus], button, input", d)?.focus(); } }
    const closer = e.target.closest("[data-close-modal]");
    if (closer) { const d = closer.closest("dialog"); d?.close(closer.value || ""); }
    const toast = e.target.closest("[data-toast]");
    if (toast) showToast(toast.getAttribute("data-toast"));
  });
  function showToast(text) {
    let region = $("#proto-toasts");
    if (!region) {
      region = document.createElement("div");
      region.id = "proto-toasts"; region.className = "toast-region"; region.setAttribute("aria-live", "polite");
      document.body.appendChild(region);
    }
    const t = document.createElement("div");
    t.className = "toast"; t.textContent = text;
    region.appendChild(t);
    setTimeout(() => t.remove(), 3200);
  }
  window.protoToast = showToast;

  /* Role and state previews */
  function applyRole(role) {
    document.body.dataset.role = role;
    $$("[data-hide-for]").forEach(el => { el.hidden = el.getAttribute("data-hide-for").split(/\s+/).includes(role); });
    $$("[data-disable-for]").forEach(el => {
      const off = el.getAttribute("data-disable-for").split(/\s+/).includes(role);
      el.toggleAttribute("disabled", off); el.setAttribute("aria-disabled", String(off));
    });
  }
  function applyState(select) {
    const target = document.getElementById(select.getAttribute("data-target")) || document.body;
    target.dataset.state = select.value;
    $$("[data-state-view]", target).forEach(v => { v.hidden = !v.getAttribute("data-state-view").split(/\s+/).includes(select.value); });
  }

  /* Theme */
  document.addEventListener("click", e => {
    if (!e.target.closest("[data-theme-toggle]")) return;
    const html = document.documentElement;
    const order = ["light", "dark", ""];
    const next = order[(order.indexOf(html.getAttribute("data-theme") || "") + 1) % order.length];
    if (next) html.setAttribute("data-theme", next); else html.removeAttribute("data-theme");
  });

  /* Money fields */
  document.addEventListener("blur", e => {
    const el = e.target;
    if (el.matches?.("[data-money]") && el.value.trim() !== "") el.value = fmt.format(num(el.value));
  }, true);

  document.addEventListener("input", e => { if (e.target.closest("form, [data-conditions]")) applyConditions(); });
  document.addEventListener("change", e => {
    if (e.target.matches("[data-role-switch]")) applyRole(e.target.value);
    if (e.target.matches("[data-state-switch]")) applyState(e.target);
    applyConditions();
  });

  function init() {
    initTabs(); initLineEditors(); applyConditions();
    const role = $("[data-role-switch]"); if (role) applyRole(role.value);
    $$("[data-state-switch]").forEach(applyState);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
  window.proto = { recalc, addLine, applyConditions, applyRole };
})();
