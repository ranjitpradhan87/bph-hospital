<?php

namespace App\Support;

/**
 * Permission catalogue and default role → permission mapping (ported from the FastAPI version).
 * Permissions are what the API checks; roles only bundle them. The seeder syncs this into the database.
 */
final class Permissions
{
    public const ALL = [
        // system
        'settings.manage' => 'Manage hospital settings and holidays',
        'roles.manage' => 'Manage roles and permissions',
        'users.manage' => 'Manage user accounts and role assignment',
        'audit.read' => 'View audit logs',
        'dashboard.view' => 'View admin dashboard',
        'uploads.create' => 'Upload files',
        'enquiries.manage' => 'Read and respond to website contact enquiries',
        // master data
        'departments.read' => 'View departments',
        'departments.manage' => 'Create, edit, activate/deactivate departments',
        'doctors.read' => 'View doctors',
        'doctors.manage' => 'Create, edit, deactivate doctors',
        'staff.read' => 'View staff',
        'staff.manage' => 'Create, edit, deactivate staff; manage shifts',
        'staff.salary' => 'View and edit staff salary',
        'attendance.manage' => 'Record and edit staff attendance',
        // patients
        'patients.read' => 'Search and view patient profiles',
        'patients.create' => 'Register patients',
        'patients.update' => 'Edit patient details',
        'medical_records.read' => 'View patient medical records',
        'medical_records.create' => 'Add medical notes and documents',
        'observations.create' => 'Record clinical observations (vitals)',
        // scheduling
        'slots.read' => 'View schedules and slots',
        'slots.manage' => 'Create schedules, generate/close/reopen slots',
        // appointments
        'appointments.read' => 'View appointments',
        'appointments.read_own' => 'View only own (doctor/patient) appointments',
        'appointments.create' => 'Book appointments',
        'appointments.cancel' => 'Cancel appointments',
        'appointments.reschedule' => 'Reschedule appointments',
        'appointments.update_status' => 'Change appointment status',
        'appointments.check_in' => 'Check patients in',
        'reception.queue' => 'Manage front-desk queue',
        'consultations.read' => 'View consultations and prescriptions',
        'consultations.manage' => 'Record consultations and prescriptions',
        // out-patient (OPD) visits
        'opd.read' => 'View OPD visits, tokens and the daily register',
        'opd.manage' => 'Register OPD visits, collect the consultation fee and cancel visits',
        // in-patient (IPD) register
        'ipd.read' => 'View the in-patient (IPD) register and its totals',
        'ipd.manage' => 'Record IPD admissions, receipts and expenses',
        // testing lab
        'lab.read' => 'View lab orders and results',
        'lab.manage' => 'Create lab orders, collect lab payments and cancel orders',
        'lab.results' => 'Collect samples, enter results and release reports',
        'lab.catalogue' => 'Manage the lab test list and prices',
        // billing
        'billing.read' => 'View bills',
        'billing.manage' => 'Create, edit and cancel bills',
        'payments.collect' => 'Collect payments',
        'payments.refund' => 'Issue refunds',
        // reports
        'reports.view' => 'View operational reports',
        'reports.financial' => 'View financial reports',
        // self-service
        'portal.access' => 'Use the patient portal',
    ];

    /** @return array<string, array{name: string, description: string, permissions: list<string>}> */
    public static function roles(): array
    {
        $all = array_keys(self::ALL);

        return [
            'super_admin' => [
                'name' => 'Super Admin', 'description' => 'Complete system access',
                'permissions' => $all,
            ],
            'admin' => [
                'name' => 'Admin', 'description' => 'Manages hospital operations',
                'permissions' => array_values(array_diff($all, ['roles.manage', 'portal.access'])),
            ],
            'manager' => [
                'name' => 'Manager', 'description' => 'Runs day-to-day operations: patients, appointments, OPD, IPD, lab and billing. No settings, roles, users, staff or reports',
                'permissions' => [
                    'departments.read', 'doctors.read', 'slots.read', 'patients.read', 'patients.create', 'patients.update',
                    'appointments.read', 'appointments.create', 'appointments.cancel', 'appointments.reschedule',
                    'appointments.check_in', 'appointments.update_status', 'reception.queue', 'billing.read', 'billing.manage',
                    'payments.collect', 'payments.refund', 'uploads.create', 'enquiries.manage', 'consultations.read',
                    'ipd.read', 'ipd.manage', 'lab.read', 'lab.manage', 'opd.read', 'opd.manage',
                ],
            ],
            'doctor' => [
                'name' => 'Doctor', 'description' => 'Own schedule, consultations and assigned patients',
                'permissions' => [
                    'departments.read', 'doctors.read', 'slots.read', 'appointments.read_own', 'appointments.update_status',
                    'patients.read', 'medical_records.read', 'medical_records.create', 'observations.create',
                    'consultations.read', 'consultations.manage', 'uploads.create', 'lab.read', 'opd.read',
                ],
            ],
            'receptionist' => [
                'name' => 'Receptionist', 'description' => 'Front desk: registration, booking, check-in, billing',
                'permissions' => [
                    'departments.read', 'doctors.read', 'slots.read', 'patients.read', 'patients.create', 'patients.update',
                    'appointments.read', 'appointments.create', 'appointments.cancel', 'appointments.reschedule',
                    'appointments.check_in', 'appointments.update_status', 'reception.queue', 'billing.read',
                    'billing.manage', 'payments.collect', 'uploads.create', 'enquiries.manage', 'ipd.read', 'ipd.manage',
                    'lab.read', 'lab.manage', 'opd.read', 'opd.manage',
                ],
            ],
            'accountant' => [
                'name' => 'Accountant', 'description' => 'Billing, payments and financial reports',
                'permissions' => [
                    'patients.read', 'appointments.read', 'billing.read', 'billing.manage', 'payments.collect',
                    'payments.refund', 'reports.view', 'reports.financial', 'ipd.read', 'ipd.manage', 'lab.read', 'lab.manage', 'opd.read',
                ],
            ],
            'nurse' => [
                'name' => 'Nurse', 'description' => 'Assigned patients and clinical observations',
                'permissions' => [
                    'departments.read', 'doctors.read', 'patients.read', 'appointments.read', 'appointments.check_in',
                    'medical_records.read', 'observations.create', 'consultations.read', 'opd.read',
                ],
            ],
            'lab_technician' => [
                'name' => 'Lab Technician', 'description' => 'Collects samples, enters results and releases lab reports',
                'permissions' => ['patients.read', 'doctors.read', 'lab.read', 'lab.results'],
            ],
            'patient' => [
                'name' => 'Patient', 'description' => 'Self-service portal',
                'permissions' => ['portal.access', 'appointments.read_own', 'appointments.create'],
            ],
        ];
    }

    /** Admin-level roles that only a holder of roles.manage may grant or modify. */
    public const PRIVILEGED_ROLES = ['super_admin', 'admin'];
}
