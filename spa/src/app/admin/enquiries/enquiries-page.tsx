"use client";

import { ChevronLeft, ChevronRight, Inbox, Mail, Phone } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError, api } from "@/lib/api";
import { RequireAuth } from "@/lib/auth/require-auth";
import { formatDateTime } from "@/lib/format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Enquiry, EnquiryStatus, Page } from "@/types/api";

const STATUS: Record<EnquiryStatus, { label: string; className: string }> = {
  new: { label: "New", className: "bg-warning/15 text-amber-700 ring-warning/40 dark:text-warning" },
  in_progress: { label: "In progress", className: "bg-info/12 text-info ring-info/30" },
  closed: { label: "Closed", className: "bg-muted text-muted-foreground ring-border" },
};
const PAGE_SIZE = 20;

export function EnquiriesPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("enquiries.manage")}>
      <EnquiriesView />
    </RequireAuth>
  );
}

function EnquiriesView() {
  const [status, setStatus] = useState<EnquiryStatus | "">("new");
  const [page, setPage] = useState(1);
  const list = useApi<Page<Enquiry>>("/enquiries", { status: status || undefined, page, page_size: PAGE_SIZE });
  const data = list.data;
  const pages = data ? Math.max(1, Math.ceil(data.total / PAGE_SIZE)) : 1;

  async function setEnquiryStatus(e: Enquiry, next: EnquiryStatus) {
    try {
      await api(`/enquiries/${e.id}`, { method: "PATCH", body: { status: next } });
      toast.success(`Marked as ${STATUS[next].label.toLowerCase()}`);
      list.reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update");
    }
  }

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        title="Enquiries"
        description="Messages sent through the website contact form."
        actions={
          <NativeSelect aria-label="Filter by status" value={status} onChange={(e) => { setStatus(e.target.value as EnquiryStatus | ""); setPage(1); }} className="w-40">
            <option value="new">New</option>
            <option value="in_progress">In progress</option>
            <option value="closed">Closed</option>
            <option value="">All</option>
          </NativeSelect>
        }
      />
      {list.error && <p role="alert" className="text-sm text-destructive">{list.error.message}</p>}
      {!data ? (
        <div className="grid gap-3">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-36 rounded-xl" />)}</div>
      ) : data.items.length === 0 ? (
        <Card>
          <EmptyState icon={Inbox} title={status === "new" ? "No new enquiries" : "Nothing here"} description="New website messages will show up here." />
        </Card>
      ) : (
        <div className={cn("grid gap-3 transition-opacity", list.loading && "opacity-60")}>
          {data.items.map((e) => (
            <Card key={e.id} className="gap-3 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-medium">{e.subject || "(no subject)"}</p>
                  <p className="text-sm text-muted-foreground">
                    {e.name} · {formatDateTime(e.created_at)}
                  </p>
                </div>
                <span className={cn("rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset", STATUS[e.status].className)}>
                  {STATUS[e.status].label}
                </span>
              </div>
              <p className="text-sm whitespace-pre-line">{e.message}</p>
              <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                <div className="flex flex-wrap gap-3 text-sm">
                  <a href={`mailto:${e.email}?subject=${encodeURIComponent(`Re: ${e.subject || "your enquiry"}`)}`} className="inline-flex items-center gap-1.5 text-primary hover:underline">
                    <Mail className="size-4" aria-hidden /> {e.email}
                  </a>
                  {e.phone && (
                    <a href={`tel:${e.phone}`} className="inline-flex items-center gap-1.5 text-primary hover:underline">
                      <Phone className="size-4" aria-hidden /> {e.phone}
                    </a>
                  )}
                </div>
                <div className="flex gap-2">
                  {e.status !== "in_progress" && e.status !== "closed" && (
                    <Button variant="outline" size="sm" onClick={() => setEnquiryStatus(e, "in_progress")}>Mark in progress</Button>
                  )}
                  {e.status !== "closed" ? (
                    <Button size="sm" onClick={() => setEnquiryStatus(e, "closed")}>Close</Button>
                  ) : (
                    <Button variant="ghost" size="sm" onClick={() => setEnquiryStatus(e, "new")}>Reopen</Button>
                  )}
                </div>
              </div>
            </Card>
          ))}
          {pages > 1 && (
            <div className="flex items-center justify-end gap-2 text-sm text-muted-foreground">
              Page {page} of {pages}
              <Button variant="outline" size="icon-sm" aria-label="Previous page" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}><ChevronLeft /></Button>
              <Button variant="outline" size="icon-sm" aria-label="Next page" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}><ChevronRight /></Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
