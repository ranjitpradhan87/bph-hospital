/** Mirrors backend/app/schemas/public.py. */

export interface DepartmentRef {
  id: number;
  name: string;
  slug: string;
}

export interface PublicDepartment {
  id: number;
  name: string;
  slug: string;
  short_description: string | null;
  description: string | null;
  image_url: string | null;
  icon: string | null;
  doctor_count: number;
}

export interface PublicDoctor {
  id: number;
  full_name: string;
  qualification: string;
  specialization: string;
  experience_years: number;
  consultation_fee: string;
  /** The advance paid online to confirm a booking; 0 when none is needed. */
  advance_amount: number;
  photo_url: string | null;
  languages: string | null;
  departments: DepartmentRef[];
  next_available_date: string | null;
  available_slot_count: number;
}

export interface WeeklyHours {
  day_of_week: number;
  start_time: string;
  end_time: string;
  appointment_type: "in_person" | "online";
}

export interface PublicDoctorDetail extends PublicDoctor {
  bio: string | null;
  registration_number: string;
  weekly_hours: WeeklyHours[];
}

export interface PublicSlot {
  id: number;
  start_time: string;
  end_time: string;
  remaining: number;
  appointment_type: "in_person" | "online";
  consultation_fee: string;
}

export interface ContentItem {
  icon: string;
  title: string;
  description: string;
}

export interface Testimonial {
  name: string;
  quote: string;
  context?: string;
}

export interface Address {
  line1?: string;
  line2?: string;
  city?: string;
  state?: string;
  pincode?: string;
  country?: string;
}

/** Public settings, nested by prefix. Every field may be missing or empty until configured. */
export interface SiteSettings {
  hospital: {
    name?: string;
    tagline?: string;
    logo_url?: string | null;
    hero_image_url?: string | null;
    about_image_url?: string | null;
    address?: Address;
    phone?: string;
    emergency_phone?: string;
    email?: string;
    map_embed_url?: string;
    working_days?: number[];
    working_hours?: { start: string; end: string };
  };
  content: {
    hero?: { title?: string; subtitle?: string };
    about?: { summary?: string; mission?: string; vision?: string; values?: string[] };
    services?: ContentItem[];
    facilities?: ContentItem[];
    testimonials?: Testimonial[];
  };
  booking: {
    advance_booking_days?: number;
    cancellation_cutoff_hours?: number;
  };
  stats: { departments: number; doctors: number };
}
