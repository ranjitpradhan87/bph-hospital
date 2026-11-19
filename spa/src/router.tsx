import { SearchX } from "lucide-react";
import { Suspense } from "react";
import { Outlet, createBrowserRouter } from "react-router-dom";

import { GateFallback, PatientGate, StaffGate } from "@/components/layout/gates";
import { buttonVariants } from "@/components/ui/button";
import { AdminHome } from "@/app/admin/admin-home";
import { IpdPage } from "@/app/admin/ipd/ipd-page";
import { LabPage } from "@/app/admin/lab/lab-page";
import { AppointmentsPage } from "@/app/admin/appointments/appointments-page";
import { SchedulesPage } from "@/app/admin/schedules/schedules-page";
import { AttendancePage } from "@/app/admin/attendance/attendance-page";
import { DepartmentsPage } from "@/app/admin/departments/departments-page";
import { DoctorDetailPage } from "@/app/admin/doctors/doctor-detail";
import { DoctorsPage } from "@/app/admin/doctors/doctors-page";
import { NewDoctorPage } from "@/app/admin/doctors/new/new-doctor";
import { EnquiriesPage } from "@/app/admin/enquiries/enquiries-page";
import { PatientDetailPage } from "@/app/admin/patients/patient-detail";
import { PatientsPage } from "@/app/admin/patients/patients-page";
import { RolesPage } from "@/app/admin/roles/roles-page";
import { SettingsPage } from "@/app/admin/settings/settings-page";
import { NewStaffPage } from "@/app/admin/staff/new/new-staff";
import { StaffDetailPage } from "@/app/admin/staff/staff-detail";
import { StaffPage } from "@/app/admin/staff/staff-page";
import { UsersPage } from "@/app/admin/users/users-page";
import AuthLayout from "@/app/auth/layout";
import LoginPage from "@/app/auth/login/page";
import RegisterPage from "@/app/auth/register/page";
import SetPasswordPage from "@/app/auth/set-password/page";
import { GuestBookPage } from "@/app/portal/guest-book-page";
import { BookPage } from "@/app/portal/book-page";
import { LabReports } from "@/app/portal/lab-reports";
import { MyAppointments } from "@/app/portal/my-appointments";
import { PortalHome } from "@/app/portal/portal-home";
import { ProfilePage } from "@/app/portal/profile/profile-page";

function NotFound() {
  return (
    <div className="mx-auto flex max-w-md flex-1 flex-col items-center justify-center gap-4 px-4 py-24 text-center">
      <SearchX className="size-12 text-muted-foreground" aria-hidden />
      <h1 className="font-heading text-2xl font-bold">We could not find that page</h1>
      <p className="text-muted-foreground">The page you are looking for may have moved.</p>
      <a href="/" className={buttonVariants()}>
        Go to home
      </a>
    </div>
  );
}

const staff = (
  <Suspense fallback={<GateFallback />}>
    <StaffGate>
      <Outlet />
    </StaffGate>
  </Suspense>
);

const patient = (
  <Suspense fallback={<GateFallback />}>
    <PatientGate>
      <Outlet />
    </PatientGate>
  </Suspense>
);

const auth = (
  <AuthLayout>
    <Outlet />
  </AuthLayout>
);

export const router = createBrowserRouter([
  {
    element: auth,
    children: [
      { path: "/login", element: <LoginPage /> },
      { path: "/register", element: <RegisterPage /> },
      { path: "/set-password", element: <SetPasswordPage /> },
      { path: "/appointment", element: <GuestBookPage /> },
    ],
  },
  {
    path: "/admin",
    element: staff,
    children: [
      { index: true, element: <AdminHome /> },
      { path: "appointments", element: <AppointmentsPage /> },
      { path: "slots", element: <SchedulesPage /> },
      { path: "ipd", element: <IpdPage /> },
      { path: "lab", element: <LabPage /> },
      { path: "attendance", element: <AttendancePage /> },
      { path: "departments", element: <DepartmentsPage /> },
      { path: "doctors", element: <DoctorsPage /> },
      { path: "doctors/new", element: <NewDoctorPage /> },
      { path: "doctors/:id", element: <DoctorDetailPage /> },
      { path: "enquiries", element: <EnquiriesPage /> },
      { path: "patients", element: <PatientsPage /> },
      { path: "patients/:id", element: <PatientDetailPage /> },
      { path: "roles", element: <RolesPage /> },
      { path: "settings", element: <SettingsPage /> },
      { path: "staff", element: <StaffPage /> },
      { path: "staff/new", element: <NewStaffPage /> },
      { path: "staff/:id", element: <StaffDetailPage /> },
      { path: "users", element: <UsersPage /> },
      { path: "*", element: <NotFound /> },
    ],
  },
  {
    path: "/portal",
    element: patient,
    children: [
      { index: true, element: <PortalHome /> },
      { path: "book", element: <BookPage /> },
      { path: "appointments", element: <MyAppointments /> },
      { path: "lab", element: <LabReports /> },
      { path: "profile", element: <ProfilePage /> },
      { path: "*", element: <NotFound /> },
    ],
  },
  { path: "*", element: <NotFound /> },
]);
