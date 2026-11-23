import { Loader2 } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { useApi } from "@/lib/use-api";
import { todayIST } from "@/types/booking";
import type { IpdLookups } from "@/types/ipd";
import type { OpdVisit } from "@/types/opd";

const TYPES = [["planned", "Planned"], ["emergency", "Emergency"], ["transfer", "Transfer"]] as const;

/** Admit an OPD patient to the IPD register: the patient, doctor and diagnosis come over from the visit. */
export function AdmitDialog({ visit, onClose, onDone }: { visit: OpdVisit; onClose: () => void; onDone: (admissionId: number) => void }) {
  const lookups = useApi<IpdLookups>("/opd/admit-options");
  const now = new Date(Date.now() + 5.5 * 3600_000).toISOString().slice(11, 16); // hospital clock (IST)
  const [date, setDate] = useState(todayIST());
  const [time, setTime] = useState(now);
  const [type, setType] = useState("planned");
  const [doctorId, setDoctorId] = useState(String(visit.doctor_id));
  const [deptId, setDeptId] = useState<string | null>(null);
  const [bedId, setBedId] = useState("");
  const [room, setRoom] = useState("");
  const [title, setTitle] = useState(visit.consultation?.diagnosis || visit.reason || "");
  const [attName, setAttName] = useState("");
  const [attMobile, setAttMobile] = useState("");
  const [attRelation, setAttRelation] = useState("");
  const [notes, setNotes] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const depts = lookups.data?.departments ?? [];
  const dept = deptId ?? String(depts.find((d) => d.name === visit.department)?.id ?? "");
  const beds = (lookups.data?.beds ?? []).filter((b) => b.is_active);
  const free = beds.filter((b) => !b.occupied_by && (!dept || !b.department_id || String(b.department_id) === dept));
  const taken = beds.filter((b) => b.occupied_by).length;

  async function submit() {
    setBusy(true);
    setError("");
    try {
      const r = await api<{ admission_id: number }>(`/opd/visits/${visit.id}/admit`, {
        method: "POST",
        body: {
          admission_date: date, admission_time: time || null, admission_type: type, doctor_ids: [Number(doctorId)], department_id: dept ? Number(dept) : null,
          bed_id: bedId ? Number(bedId) : null, room: room || null, case_title: title || null, notes: notes || null,
          attendant_name: attName || null, attendant_mobile: attMobile || null, attendant_relation: attRelation || null,
        },
      });
      toast.success(`${visit.patient_name} is admitted to IPD`);
      onDone(r.admission_id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not admit the patient");
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Admit to IPD</DialogTitle>
          <DialogDescription>{visit.patient_name} · {visit.patient_code}. The same patient record is used; this OPD visit stays in the history.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-1.5"><Label htmlFor="ad-date">Admission date</Label><Input id="ad-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} /></div>
          <div className="grid gap-1.5"><Label htmlFor="ad-time">Admission time</Label><Input id="ad-time" type="time" value={time} onChange={(e) => setTime(e.target.value)} /></div>
          <div className="grid gap-1.5">
            <Label htmlFor="ad-type">Admission type</Label>
            <NativeSelect id="ad-type" value={type} onChange={(e) => setType(e.target.value)}>{TYPES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</NativeSelect>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="ad-doc">Admitting doctor</Label>
            <NativeSelect id="ad-doc" value={doctorId} onChange={(e) => setDoctorId(e.target.value)}>
              {(lookups.data?.doctors ?? []).filter((d) => d.is_active || String(d.id) === doctorId).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
              {!lookups.data && <option value={doctorId}>{visit.doctor_name}</option>}
            </NativeSelect>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="ad-dept">Department / ward</Label>
            <NativeSelect id="ad-dept" value={dept} onChange={(e) => { setDeptId(e.target.value); setBedId(""); }}>
              <option value="">—</option>
              {depts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </NativeSelect>
          </div>
          <div className="grid gap-1.5"><Label htmlFor="ad-room">Room number</Label><Input id="ad-room" value={room} maxLength={30} onChange={(e) => setRoom(e.target.value)} placeholder="e.g. 204" /></div>
          <div className="grid gap-1.5 sm:col-span-2">
            <Label htmlFor="ad-bed">Bed</Label>
            <NativeSelect id="ad-bed" value={bedId} onChange={(e) => setBedId(e.target.value)}>
              <option value="">{lookups.data ? (free.length ? "Choose a free bed" : "No free bed here") : "Loading…"}</option>
              {free.map((b) => <option key={b.id} value={b.id}>{b.code}{b.ward ? ` · ${b.ward}` : ""}</option>)}
            </NativeSelect>
            <p className="text-xs text-muted-foreground">{free.length} free · {taken} occupied. A bed that is taken is refused when you confirm.</p>
          </div>
          <div className="grid gap-1.5 sm:col-span-2"><Label htmlFor="ad-title">Diagnosis / reason for admission</Label><Input id="ad-title" value={title} maxLength={200} onChange={(e) => setTitle(e.target.value)} /></div>
          <div className="grid gap-1.5"><Label htmlFor="ad-an">Attendant name</Label><Input id="ad-an" value={attName} maxLength={150} onChange={(e) => setAttName(e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div className="grid gap-1.5"><Label htmlFor="ad-am">Attendant mobile</Label><Input id="ad-am" inputMode="numeric" maxLength={10} value={attMobile} onChange={(e) => setAttMobile(e.target.value.replace(/\D/g, ""))} /></div>
            <div className="grid gap-1.5"><Label htmlFor="ad-ar">Relation</Label><Input id="ad-ar" value={attRelation} maxLength={60} onChange={(e) => setAttRelation(e.target.value)} /></div>
          </div>
          <div className="grid gap-1.5 sm:col-span-2"><Label htmlFor="ad-notes">Notes</Label><Textarea id="ad-notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} /></div>
        </div>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button onClick={submit} disabled={busy || !doctorId || !date}>{busy && <Loader2 className="animate-spin" aria-hidden />} Confirm admission</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
