import { siteConfig } from "@/lib/config";
import { formatDay } from "@/lib/public-format";
import type { LabOrder, PortalLabOrder } from "@/types/lab";

const esc = (s: string | number | null | undefined) =>
  String(s ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

const STYLE = `
  body{font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#111;margin:32px}
  h1{font-size:20px;margin:0}h2{font-size:15px;margin:22px 0 6px}
  .muted{color:#555}.head{display:flex;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:14px}
  table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top}
  th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#444}td.r,th.r{text-align:right}
  .flag-high,.flag-low,.flag-critical{font-weight:700}.flag-critical{color:#b00020}
  .foot{margin-top:28px;font-size:12px;color:#555}@media print{body{margin:12mm}}`;

/** Open a print-ready page in a new window. Plain HTML, so it prints the same on any device. */
function openPrint(title: string, body: string) {
  const w = window.open("", "_blank", "width=900,height=900");
  if (!w) return false;
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>${STYLE}</style></head><body>${body}<script>window.onload=function(){window.print()}<\/script></body></html>`);
  w.document.close();
  return true;
}

const when = (iso: string | null) => (iso ? formatDay(iso.slice(0, 10), { day: "numeric", month: "short", year: "numeric" }) : "—");
const inr = (n: number) => `Rs. ${n.toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function header(o: { order_number: string; created_at: string; patient_name: string; patient_code: string; doctor_name: string | null }) {
  return `<div class="head"><div><h1>${esc(siteConfig.name)}</h1><div class="muted">Laboratory</div></div>
    <div style="text-align:right"><b>${esc(o.order_number)}</b><br><span class="muted">${when(o.created_at)}</span></div></div>
    <p><b>${esc(o.patient_name)}</b> <span class="muted">${esc(o.patient_code)}</span>${o.doctor_name ? `<br><span class="muted">Referred by ${esc(o.doctor_name)}</span>` : ""}</p>`;
}

/** The released lab report: one row per test with value, unit, reference range and a high/low flag. */
export function printReport(o: LabOrder | PortalLabOrder) {
  const rows = o.items.map((i) => `<tr><td>${esc(i.test_name)}</td><td class="${i.flag ? `flag-${i.flag}` : ""}">${esc(i.result_value)}${i.flag && i.flag !== "normal" ? ` (${esc(i.flag)})` : ""}</td><td>${esc(i.result_unit)}</td><td>${esc(i.reference_range)}</td></tr>${i.result_notes ? `<tr><td colspan="4" class="muted">${esc(i.result_notes)}</td></tr>` : ""}`).join("");
  return openPrint(`Lab report ${o.order_number}`, `${header(o)}<h2>Report</h2><table><thead><tr><th>Test</th><th>Result</th><th>Unit</th><th>Reference range</th></tr></thead><tbody>${rows}</tbody></table>
    <p class="foot">Reported ${when(o.completed_at)}. Please discuss these results with your doctor.</p>`);
}

/** The bill and receipts for an order. */
export function printBill(o: LabOrder) {
  const rows = o.items.map((i) => `<tr><td>${esc(i.test_name)}</td><td class="r">${inr(i.price)}</td></tr>`).join("");
  const pays = o.payments.map((p) => `<tr><td>${esc(p.receipt_number)}</td><td>${when(p.paid_at)}</td><td>${esc(p.method)}${p.reference_no ? ` · ${esc(p.reference_no)}` : ""}</td><td class="r">${inr(p.amount)}</td></tr>`).join("");
  const b = o.bill;
  return openPrint(`Bill ${b.number ?? o.order_number}`, `${header(o)}<h2>Bill ${esc(b.number)}</h2><table><thead><tr><th>Test</th><th class="r">Amount</th></tr></thead><tbody>${rows}
    <tr><td class="r">Subtotal</td><td class="r">${inr(b.subtotal)}</td></tr>${b.discount ? `<tr><td class="r">Discount</td><td class="r">- ${inr(b.discount)}</td></tr>` : ""}
    <tr><td class="r"><b>Total</b></td><td class="r"><b>${inr(b.total)}</b></td></tr><tr><td class="r">Paid</td><td class="r">${inr(b.paid)}</td></tr><tr><td class="r"><b>Balance</b></td><td class="r"><b>${inr(b.balance)}</b></td></tr></tbody></table>
    ${pays ? `<h2>Payments</h2><table><thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th class="r">Amount</th></tr></thead><tbody>${pays}</tbody></table>` : ""}`);
}
