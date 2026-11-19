"use client";

import { ChevronDown, KeyRound, LogOut } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { ChangePasswordDialog } from "@/components/layout/change-password-dialog";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useAuth } from "@/lib/auth/auth-provider";
import { initials, roleLabel } from "@/lib/format";

export function UserMenu() {
  const { user, signOut } = useAuth();
  const router = useRouter();
  const [passwordOpen, setPasswordOpen] = useState(false);
  if (!user) return null;

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger
          render={<Button variant="ghost" className="h-9 gap-2 px-1.5" aria-label={`Account menu for ${user.full_name}`} />}
        >
          <Avatar className="size-7">
            <AvatarFallback className="bg-primary/10 text-xs font-semibold text-primary">
              {initials(user.full_name)}
            </AvatarFallback>
          </Avatar>
          <span className="hidden max-w-36 truncate text-sm font-medium sm:inline">{user.full_name}</span>
          <ChevronDown className="size-4 text-muted-foreground" aria-hidden />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-60">
          <DropdownMenuGroup>
            <DropdownMenuLabel className="grid gap-0.5">
              <span className="truncate font-medium text-foreground">{user.full_name}</span>
              <span className="truncate text-xs font-normal">{user.email ?? user.mobile}</span>
              <span className="text-xs font-normal">
                {user.patient_code ?? user.roles.map(roleLabel).join(", ")}
              </span>
            </DropdownMenuLabel>
          </DropdownMenuGroup>
          <DropdownMenuSeparator />
          <DropdownMenuItem onClick={() => setPasswordOpen(true)}>
            <KeyRound aria-hidden /> Change password
          </DropdownMenuItem>
          <DropdownMenuItem
            variant="destructive"
            onClick={async () => {
              await signOut();
              router.replace("/login");
            }}
          >
            <LogOut aria-hidden /> Sign out
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <ChangePasswordDialog open={passwordOpen} onOpenChange={setPasswordOpen} />
    </>
  );
}
