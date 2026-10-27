# D1 cleanup: what remains in opes.daftra.com after batch D1 (read on 27/10/2026, account clock ~17:20)

## Quota
- «الفواتير وعروض الاسعار»: **35 before (read at the start) -> 41 after** (6 documents used of the 7 allowed: invoices #000028, #000029, #000030 and credit notes #000007, #000008, #000009). One unit of the D1 quota is unused. Two refused attempts created nothing (confirmed by the plan counter, the credit-note list and the activity log).
- «العملاء»: 4 -> 5 (client «افتراضي-D1 عميل»). The other batches' counters (treasuries 2 -> 5 etc.) are theirs.
- The plan counter was read at the start (r01-account-info.txt, 35/50 and 4/15) and again at the end of the run (41/50 and 5/15).

## Settings touched
**None.** No global setting was read-for-change, toggled or reverted. (The only settings-like edit is the payment term of my own invoice #000028, see below.) Inclusive tax would need a tax record change: marked "needs C", not done.

## Records created by D1 (all tagged «افتراضي-D1» or numbered D1-001), all kept
| record | id / number | state now | reversible |
|---|---|---|---|
| client «افتراضي-D1 عميل» | id 5, #000005, account 12041005 | balance 275.02 | not attempted |
| stock item «افتراضي-D1 سلعة» | product 10, SKU D1-001, buy 60, sell 100 | stock 22, average cost 60.00 | not attempted (has movements) |
| invoice B | id 36, #000028, 25/10/2026, total 395.39, journal #000137 | «مدفوعة جزئياً», open 45.02, payment term edited 1 -> 2 -> **30 days** (due 24/11/2026) | no (issued invoice) |
| invoice A | id 37, #000029, 20/10/2026, total 420.13, journals #000139 #000140 | «مدفوعة مرتجع جزئياً», settled | no |
| invoice C | id 38, #000030, 27/10/2026, total 230.00, journals #000143 and #000156 | «غير مدفوعة مرتجع جزئياً», open 230.00, delivery confirmed at the end («سٌلم») | delivery can be cancelled («إلغاء التسليم»), not needed |
| credit note | id 39, #000007, 80.50 on #000029, journals #000145 #000146 | issued | no |
| credit note | id 40, #000008, 115.00 on #000030, journals #000147 #000148 | issued | no |
| credit note | id 41, #000009, 575.00 without invoice, journals #000151 #000152 | issued | no |
| automatic payments «رصيد العميل الائتماني» | #000019 80.50 and #000020 115.00 and #000021 224.63 on #000029; #000022 350.37 on #000028 | completed, journal-less | system-created |
| stock transactions | #62 (+20 initial), #63 (-3), #64 (-2), #65 (+1), #66 (+1), #67 (+5) | | |
| journals | #000136 (initial stock), #000137, #000139, #000140, #000143, #000145, #000146, #000147, #000148, #000151, #000152, #000156 | all automatic | |

Journal numbers between these (138, 141, 142, 144, 149, 150, 153-155 ...) belong to batch D2, which works in the same account.

## Non-GET requests (20 seen by the guard, mapped in write-log.json)
12 business POSTs (client, product, 3 invoices, 5 credit-note saves of which 2 refused, 2 due-term edits via /owner/invoices/partial_edit/36) = W-d1-1..W-d1-12; 8 `POST /api2/set_report_session` fired by report pages = W-d1-13. The delivery confirmation (W-d1-14) is a state-changing GET link, so it is not in the guard's non-GET log. No request to another host was allowed (cdn.daftra.com static GETs were allowed after the first guard run; analytics hosts blocked).

## Not done / not cleaned
- Issued invoices and credit notes cannot be deleted; they stay and keep consuming quota (6).
- The client and the item are left (no deletion attempted).
- Remaining D1 quota: 1 document (not used).
