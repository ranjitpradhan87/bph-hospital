import { siteConfig } from "@/lib/config";
import { formatDay } from "@/lib/public-format";
import type { BillDetail } from "@/types/billing";
import type { IpdAdmission, IpdHead } from "@/types/ipd";
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
function openPrint(title: string, body: string, extraStyle = "") {
  const w = window.open("", "_blank", "width=900,height=900");
  if (!w) return false;
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>${STYLE}${extraStyle}</style></head><body>${body}<script>window.onload=function(){window.print()}<\/script></body></html>`);
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

/** A bill raised at the counter: items, totals and the receipts so far. */
export function printInvoice(b: BillDetail) {
  const rows = b.items.map((i) => `<tr><td>${esc(i.description)}</td><td class="r">${i.quantity}</td><td class="r">${inr(i.unit_price)}</td><td class="r">${inr(i.line_total)}</td></tr>`).join("");
  const pays = b.payments.map((p) => `<tr><td>${esc(p.receipt_number)}</td><td>${when(p.paid_at)}</td><td>${esc(p.method)}${p.reference_no ? ` · ${esc(p.reference_no)}` : ""}</td><td class="r">${inr(p.amount)}</td></tr>`).join("");
  return openPrint(`Bill ${b.bill_number}`, `<div class="head"><div><h1>${esc(siteConfig.name)}</h1><div class="muted">Bill</div></div>
    <div style="text-align:right"><b>${esc(b.bill_number)}</b><br><span class="muted">${when(b.bill_date)}</span></div></div>
    <p><b>${esc(b.patient_name)}</b> <span class="muted">${esc(b.patient_code)}</span></p>
    <table><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead><tbody>${rows}
    <tr><td colspan="3" class="r">Subtotal</td><td class="r">${inr(b.subtotal)}</td></tr>${b.discount ? `<tr><td colspan="3" class="r">Discount</td><td class="r">- ${inr(b.discount)}</td></tr>` : ""}
    <tr><td colspan="3" class="r"><b>Total</b></td><td class="r"><b>${inr(b.total)}</b></td></tr><tr><td colspan="3" class="r">Paid</td><td class="r">${inr(b.paid)}</td></tr>
    <tr><td colspan="3" class="r"><b>Balance</b></td><td class="r"><b>${inr(b.balance)}</b></td></tr></tbody></table>
    ${pays ? `<h2>Payments</h2><table><thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th class="r">Amount</th></tr></thead><tbody>${pays}</tbody></table>` : ""}`);
}

const IPD_STYLE = `
  .ipd{font:12px/1.4 Arial,Helvetica,sans-serif;color:#000}.ipd .lh{text-align:center;border-bottom:1px solid #000;padding-bottom:6px}
  .ipd .lh h1{font-size:24px;letter-spacing:.04em;margin:0;font-variant:small-caps}.ipd .lh p{margin:2px 0;font-weight:600}
  .ipd .ttl{text-align:center;font-weight:700;text-decoration:underline;margin:8px 0}
  .ipd .info{display:flex;justify-content:space-between;gap:20px;border-top:1px solid #000;border-bottom:1px solid #000;padding:6px 0}.ipd .info div{flex:1}
  .ipd .info b{display:inline-block;min-width:100px}
  .ipd table{width:100%;margin-top:8px}.ipd th,.ipd td{border:0;padding:2px 4px}.ipd th{border-bottom:1px solid #000;text-transform:none;font-size:12px;color:#000}
  .ipd .grp td{font-weight:700;text-decoration:underline;padding-top:6px}.ipd .sub td{font-weight:700;border-top:1px solid #000;border-bottom:1px solid #000}
  .ipd .sum{margin-left:auto;width:55%;margin-top:10px;border-top:1px solid #000}.ipd .sum td{padding:3px 4px}.ipd .sum .big td{font-weight:700}
  .ipd .sig{display:flex;justify-content:space-between;margin-top:50px;border-bottom:1px solid #000;padding-bottom:6px}`;

/** The IPD bill summary: charges grouped by column with a subtotal each, then gross, deposit, refund and balance. */
export function printIpdBill(a: IpdAdmission, heads: IpdHead[]) {
  const info = a.bill;
  const money = (n: number) => n.toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const day = (d: string) => formatDay(d, { day: "2-digit", month: "short", year: "numeric" }).replace(/ /g, "-");
  const isLab = (h: IpdHead) => /^lab/i.test(h.code) || /^lab/i.test(h.name);
  const charges = heads.filter((h) => h.type === "charge");
  const group = (h: IpdHead) => {
    const rows = a.entries.filter((e) => e.head_id === h.id);
    const total = rows.reduce((s, e) => s + e.amount, 0);
    if (!rows.length) return { total: 0, html: "" };
    const body = rows.map((e) => `<tr><td>${esc(e.note || h.name)}</td><td class="r">1.00</td><td class="r">${money(e.amount)}</td><td class="r">${money(e.amount)}</td></tr>`).join("");
    return { total, html: `<tr class="grp"><td colspan="4">${esc(h.name.toUpperCase())}</td></tr>${body}<tr class="sub"><td colspan="3" class="r">Sub Total</td><td class="r">${money(total)}</td></tr>` };
  };
  const parts = charges.filter((h) => !isLab(h)).map(group);
  const gross = parts.reduce((s, p) => s + p.total, 0);
  const lab = charges.filter(isLab).reduce((s, h) => s + group(h).total, 0);
  const total = gross + lab;
  const deposit = a.received;
  const refund = a.payouts;
  const paid = deposit - refund;
  const doctors = a.doctors || "—";
  const sex = info?.patient.gender ? info.patient.gender[0].toUpperCase() + info.patient.gender.slice(1) : "";
  const sumRow = (l: string, v: number, cls = "") => `<tr class="${cls}"><td>${l}</td><td>:</td><td class="r">${money(v)}</td></tr>`;
  const html = `<div class="ipd">
    <div class="lh"><img src="${location.origin}/img/logo.svg" alt="" style="height:46px;margin-bottom:4px"><h1>${esc(info?.hospital.name ?? siteConfig.name)}</h1><p>${esc(info?.hospital.address ?? "")}</p>${info?.hospital.phone ? `<p>Contact No - ${esc(info.hospital.phone)}</p>` : ""}</div>
    <div class="ttl">Patient IPD Bills Summary</div>
    <div class="info"><div>
      <p><b>Patient Name</b>: ${esc(a.patient_name)}</p>
      <p><b>Age/Gender</b>: ${info?.patient.age != null ? `${info.patient.age} Years` : ""} ${esc(sex)}</p>
      <p><b>Address</b>: ${esc(info?.patient.address ?? "")}</p>
      <p><b>Consultant Dr.</b>: ${esc(doctors)}</p>
      <p><b>DOA</b>: ${day(a.admission_date)}</p></div>
      <div><p><b>Patient Reg No</b>: ${esc(a.patient_code ?? "")}</p><p><b>Patient IPD No</b>: ${a.id}</p>
      <p><b>Bill Date</b>: ${day(a.discharge_date ?? new Date().toISOString().slice(0, 10))}</p>${a.discharge_date ? `<p><b>DOD</b>: ${day(a.discharge_date)}</p>` : ""}</div></div>
    <table><thead><tr><th>ServiceName</th><th class="r">Quantity</th><th class="r">Rate</th><th class="r">TotalAmount</th></tr></thead><tbody>${parts.map((p) => p.html).join("")}</tbody></table>
    <table class="sum"><tbody>${sumRow("Gross Amount", gross)}${sumRow("Lab Bills Amount", lab)}${sumRow("Total Amount", total, "big")}${sumRow("Total Paid Amount", paid)}${sumRow("Balance Amount", total - paid, "big")}</tbody></table>
    <p><b>Deposit : ${money(deposit)}</b> &nbsp;&nbsp;&nbsp; <b>Refund : - ${money(refund)}</b></p>
    <div class="sig"><span>Bill Printed By : ${esc(info?.printed_by ?? "")}</span><span>Thank You.</span><span>Authorized Signatory</span></div></div>`;
  return openPrint(`IPD bill ${a.patient_name}`, html, IPD_STYLE);
}

const OPD_STYLE = `
  .opd{font:13px/1.45 Arial,Helvetica,sans-serif;color:#000;max-width:720px;margin:0 auto}.opd .lh{text-align:center;border-bottom:1px solid #000;padding-bottom:6px}
  .opd .lh h1{font-size:22px;letter-spacing:.04em;margin:0;font-variant:small-caps}.opd .lh p{margin:2px 0;font-weight:600}
  .opd .ttl{text-align:center;font-weight:700;text-decoration:underline;margin:8px 0}
  .opd .info{display:flex;justify-content:space-between;gap:20px;border-top:1px solid #000;border-bottom:1px solid #000;padding:6px 0}.opd .info div{flex:1}.opd .info b{display:inline-block;min-width:90px}
  .opd table{width:100%;margin-top:8px}.opd th,.opd td{border:0;padding:3px 4px}.opd th{border-bottom:1px solid #000;color:#000;font-size:12px;text-transform:none}
  .opd .box{border:1px solid #000;padding:6px 8px;margin-top:8px}.opd .tok{font-size:28px;font-weight:700;text-align:center;border:2px solid #000;padding:4px 14px;display:inline-block}
  .opd .sig{display:flex;justify-content:space-between;margin-top:50px;border-bottom:1px solid #000;padding-bottom:6px}`;

type OpdLike = import("@/types/opd").OpdVisit;
const vitalsLine = (v: import("@/types/opd").Vitals | null | undefined) =>
  v ? [v.bp && `BP ${v.bp}`, v.pulse && `Pulse ${v.pulse}/min`, v.temperature && `Temp ${v.temperature}°C`, v.spo2 && `SpO2 ${v.spo2}%`, v.weight && `Wt ${v.weight} kg`, v.height && `Ht ${v.height} cm`, v.respiratory_rate && `RR ${v.respiratory_rate}/min`].filter(Boolean).join(" · ") : "";

function opdHead(v: OpdLike, title: string) {
  const sex = v.patient.gender ? v.patient.gender[0].toUpperCase() + v.patient.gender.slice(1) : "";
  const day = formatDay(v.visit_date, { day: "2-digit", month: "short", year: "numeric" }).replace(/ /g, "-");
  return `<div class="lh"><img src="${location.origin}/img/logo.svg" alt="" style="height:46px;margin-bottom:4px"><h1>${esc(v.hospital.name)}</h1><p>${esc(v.hospital.address)}</p>${v.hospital.phone ? `<p>Contact No - ${esc(v.hospital.phone)}</p>` : ""}</div>
    <div class="ttl">${title}</div>
    <div class="info"><div><p><b>Patient Name</b>: ${esc(v.patient_name)}</p><p><b>Age/Gender</b>: ${v.patient.age != null ? `${v.patient.age} Years` : ""} ${esc(sex)}</p>
      <p><b>Patient ID</b>: ${esc(v.patient_code)}</p>${v.patient_mobile ? `<p><b>Mobile</b>: ${esc(v.patient_mobile)}</p>` : ""}</div>
      <div><p><b>Doctor</b>: ${esc(v.doctor_name)}</p>${v.department ? `<p><b>Department</b>: ${esc(v.department)}</p>` : ""}<p><b>Visit Date</b>: ${day}</p><p><b>Token No</b>: ${v.token_number}</p></div></div>`;
}

/** The OPD slip handed over at registration: token, doctor and the fee taken. */
export function printOpdSlip(v: OpdLike, printedBy?: string) {
  const money = (n: number) => n.toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const bill = v.bill;
  const pays = v.payments.map((p) => `<tr><td>${esc(p.receipt_number)}</td><td>${esc(p.method.toUpperCase())}${p.reference_no ? ` · ${esc(p.reference_no)}` : ""}</td><td class="r">${money(p.amount)}</td></tr>`).join("");
  return openPrint(`OPD slip ${v.token_number}`, `<div class="opd">${opdHead(v, "OPD Slip - Cum Receipt")}
    <p style="text-align:center;margin:10px 0"><span class="tok">Token ${v.token_number}</span></p>
    ${v.reason ? `<p><b>Reason for visit</b>: ${esc(v.reason)}</p>` : ""}
    ${bill ? `<table><thead><tr><th>Service</th><th class="r">Amount</th></tr></thead><tbody><tr><td>OPD consultation - ${esc(v.doctor_name)}</td><td class="r">${money(bill.total)}</td></tr>
      <tr><td class="r"><b>Paid</b></td><td class="r">${money(bill.paid)}</td></tr><tr><td class="r"><b>Balance</b></td><td class="r"><b>${money(bill.balance)}</b></td></tr></tbody></table>
      ${pays ? `<table><thead><tr><th>Receipt</th><th>Method</th><th class="r">Amount</th></tr></thead><tbody>${pays}</tbody></table>` : ""}` : `<p>No consultation fee.</p>`}
    <div class="sig"><span>Printed By : ${esc(printedBy ?? "")}</span><span>Thank You.</span><span>Authorized Signatory</span></div></div>`, OPD_STYLE);
}

/** The doctor's prescription: complaint, vitals, diagnosis, medicines, advice and follow-up. */
export function printPrescription(v: OpdLike) {
  const c = v.consultation;
  const rows = v.prescriptions.map((m, i) => `<tr><td>${i + 1}</td><td><b>${esc(m.medicine_name)}</b>${m.instructions ? `<br><span class="muted">${esc(m.instructions)}</span>` : ""}</td><td>${esc(m.dosage)}</td><td>${esc(m.frequency)}</td><td>${esc(m.duration)}</td></tr>`).join("");
  const vit = vitalsLine(c?.vitals);
  return openPrint(`Prescription ${v.patient_name}`, `<div class="opd">${opdHead(v, "Prescription")}
    ${vit ? `<div class="box"><b>Vitals</b>: ${esc(vit)}</div>` : ""}
    ${c?.chief_complaint ? `<p><b>Complaint</b>: ${esc(c.chief_complaint)}</p>` : ""}${c?.diagnosis ? `<p><b>Diagnosis</b>: ${esc(c.diagnosis)}</p>` : ""}
    ${rows ? `<table><thead><tr><th>#</th><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Duration</th></tr></thead><tbody>${rows}</tbody></table>` : ""}
    ${v.lab_orders.length ? `<p><b>Tests advised</b>: ${esc(v.lab_orders.map((l) => l.tests).join("; "))}</p>` : ""}
    ${c?.advice ? `<p><b>Advice</b>: ${esc(c.advice)}</p>` : ""}${c?.follow_up_date ? `<p><b>Follow-up</b>: ${esc(formatDay(c.follow_up_date, { day: "numeric", month: "short", year: "numeric" }))}</p>` : ""}
    <div class="sig"><span></span><span></span><span>${esc(v.doctor_name)}</span></div></div>`, OPD_STYLE);
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
