import {
  Accessibility,
  Ambulance,
  Baby,
  BedDouble,
  Bone,
  Brain,
  CreditCard,
  HeartPulse,
  Hospital,
  type LucideIcon,
  type LucideProps,
  Microscope,
  Pill,
  Scan,
  ScanFace,
  Scissors,
  Siren,
  Smile,
  Stethoscope,
  Venus,
  Video,
} from "lucide-react";

/** Icon names stored in settings / departments → components. Unknown names fall back. */
const ICONS: Record<string, LucideIcon> = {
  accessibility: Accessibility,
  ambulance: Ambulance,
  baby: Baby,
  "bed-double": BedDouble,
  bone: Bone,
  brain: Brain,
  "credit-card": CreditCard,
  "heart-pulse": HeartPulse,
  microscope: Microscope,
  pill: Pill,
  scan: Scan,
  "scan-face": ScanFace,
  scissors: Scissors,
  siren: Siren,
  smile: Smile,
  stethoscope: Stethoscope,
  venus: Venus,
  video: Video,
};

export function ContentIcon({ icon, ...props }: { icon?: string | null } & Omit<LucideProps, "name">) {
  const Icon = (icon && ICONS[icon]) || Hospital;
  return <Icon aria-hidden {...props} />;
}

/** Names admins can pick; must match CONTENT_ICONS in backend/app/core/settings_registry.py. */
export const CONTENT_ICON_NAMES = Object.keys(ICONS);
