import { ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/components/ui/button";

export function Pagination({ page, total, pageSize, onPage, noun = "record" }: {
  page: number;
  total: number;
  pageSize: number;
  onPage: (page: number) => void;
  noun?: string;
}) {
  const pages = Math.max(1, Math.ceil(total / pageSize));
  return (
    <div className="flex items-center justify-between border-t px-4 py-3 text-sm text-muted-foreground">
      <span>
        {total.toLocaleString("en-IN")} {noun}{total === 1 ? "" : "s"} · page {page} of {pages}
      </span>
      <div className="flex gap-1">
        <Button variant="outline" size="icon-sm" aria-label="Previous page" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          <ChevronLeft />
        </Button>
        <Button variant="outline" size="icon-sm" aria-label="Next page" disabled={page >= pages} onClick={() => onPage(page + 1)}>
          <ChevronRight />
        </Button>
      </div>
    </div>
  );
}
