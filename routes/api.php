<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\EnquiryController;
use App\Http\Controllers\Api\GuestBookingController;
use App\Http\Controllers\Api\LabController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\IpdController;
use App\Http\Controllers\Api\OpdController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| JSON API under /api/v1. Uses the web middleware group, so the browser session cookie + CSRF protect it
| (the React admin is served from the same domain). Every protected route declares `permission:`.
*/
Route::get('/health', fn () => response()->json(['status' => 'ok', 'database' => \Illuminate\Support\Facades\DB::select('select 1') ? 'ok' : 'down']));

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,60');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,5');
    Route::post('/set-password', [AuthController::class, 'setPassword'])->middleware('throttle:set-password');
    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:5,15');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.manage');
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:users.manage');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.manage');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'setPermissions'])->middleware('permission:roles.manage');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage');

    Route::middleware('permission:users.manage')->prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{user}', [UserController::class, 'show']);
        Route::put('/{user}/roles', [UserController::class, 'setRoles']);
        Route::patch('/{user}/status', [UserController::class, 'setStatus']);
    });

    // billing and reports
    Route::prefix('billing')->group(function () {
        Route::get('/bills', [BillingController::class, 'index'])->middleware('permission:billing.read');
        Route::post('/bills', [BillingController::class, 'store'])->middleware('permission:billing.manage');
        Route::get('/bills/{bill}', [BillingController::class, 'show'])->middleware('permission:billing.read')->whereNumber('bill');
        Route::post('/bills/{bill}/payments', [BillingController::class, 'addPayment'])->middleware('permission:payments.collect')->whereNumber('bill');
        Route::post('/bills/{bill}/cancel', [BillingController::class, 'cancel'])->middleware('permission:billing.manage')->whereNumber('bill');
    });
    Route::prefix('reports')->group(function () {
        Route::get('/overview', [ReportController::class, 'overview'])->middleware('permission:reports.view');
        Route::get('/collections/export', [ReportController::class, 'exportCollections'])->middleware('permission:reports.financial');
    });

    // testing lab
    Route::prefix('lab')->group(function () {
        Route::get('/lookups', [LabController::class, 'lookups'])->middleware('permission:lab.read');
        Route::get('/admission-for/{patient}', [LabController::class, 'admissionFor'])->middleware('permission:lab.read')->whereNumber('patient');
        Route::get('/tests', [LabController::class, 'tests'])->middleware('permission:lab.read');
        Route::post('/tests', [LabController::class, 'storeTest'])->middleware('permission:lab.catalogue');
        Route::put('/tests/{test}', [LabController::class, 'updateTest'])->middleware('permission:lab.catalogue')->whereNumber('test');
        Route::get('/orders', [LabController::class, 'orders'])->middleware('permission:lab.read');
        Route::post('/orders', [LabController::class, 'store'])->middleware('permission:lab.manage|consultations.manage');
        Route::get('/orders/{order}', [LabController::class, 'show'])->middleware('permission:lab.read')->whereNumber('order');
        Route::post('/orders/{order}/payments', [LabController::class, 'addPayment'])->middleware('permission:lab.manage')->whereNumber('order');
        Route::post('/orders/{order}/status', [LabController::class, 'setStatus'])->middleware('permission:lab.results|lab.manage')->whereNumber('order');
        Route::put('/orders/{order}/results', [LabController::class, 'saveResults'])->middleware('permission:lab.results')->whereNumber('order');
        Route::post('/orders/{order}/cancel', [LabController::class, 'cancel'])->middleware('permission:lab.manage')->whereNumber('order');
    });
    Route::get('/portal/lab-orders', [LabController::class, 'mine'])->middleware('permission:portal.access');

    // OPD visits
    Route::prefix('opd')->group(function () {
        Route::get('/doctors', [OpdController::class, 'doctors'])->middleware('permission:opd.read');
        Route::get('/summary', [OpdController::class, 'summary'])->middleware('permission:opd.read');
        Route::get('/export', [OpdController::class, 'export'])->middleware('permission:opd.read');
        Route::get('/visits', [OpdController::class, 'index'])->middleware('permission:opd.read');
        Route::post('/visits', [OpdController::class, 'store'])->middleware('permission:opd.manage');
        Route::get('/visits/{visit}', [OpdController::class, 'show'])->middleware('permission:opd.read')->whereNumber('visit');
        Route::put('/visits/{visit}/vitals', [OpdController::class, 'vitals'])->middleware('permission:observations.create|consultations.manage')->whereNumber('visit');
        Route::put('/visits/{visit}/consultation', [OpdController::class, 'consultation'])->middleware('permission:consultations.manage')->whereNumber('visit');
        Route::post('/visits/{visit}/status', [OpdController::class, 'setStatus'])->middleware('permission:opd.manage|consultations.manage')->whereNumber('visit');
        Route::post('/visits/{visit}/cancel', [OpdController::class, 'cancel'])->middleware('permission:opd.manage')->whereNumber('visit');
        Route::post('/visits/{visit}/payments', [OpdController::class, 'addPayment'])->middleware('permission:payments.collect')->whereNumber('visit');
    });

    // IPD register
    Route::prefix('ipd')->group(function () {
        Route::get('/heads', [IpdController::class, 'heads'])->middleware('permission:ipd.read');
        Route::post('/heads', [IpdController::class, 'storeHead'])->middleware('permission:ipd.manage');
        Route::put('/heads/{head}', [IpdController::class, 'updateHead'])->middleware('permission:ipd.manage')->whereNumber('head');
        Route::get('/lookups', [IpdController::class, 'lookups'])->middleware('permission:ipd.read');
        Route::get('/beds', [IpdController::class, 'beds'])->middleware('permission:ipd.read');
        Route::post('/beds', [IpdController::class, 'storeBed'])->middleware('permission:ipd.manage');
        Route::put('/beds/{bed}', [IpdController::class, 'updateBed'])->middleware('permission:ipd.manage')->whereNumber('bed');
        Route::get('/summary', [IpdController::class, 'summary'])->middleware('permission:ipd.read');
        Route::get('/export', [IpdController::class, 'export'])->middleware('permission:ipd.read');
        Route::get('/days', [IpdController::class, 'days'])->middleware('permission:ipd.read');
        Route::get('/admissions', [IpdController::class, 'admissions'])->middleware('permission:ipd.read');
        Route::post('/admissions', [IpdController::class, 'storeAdmission'])->middleware('permission:ipd.manage');
        Route::get('/admissions/{admission}', [IpdController::class, 'billAdmission'])->middleware('permission:ipd.read')->whereNumber('admission');
        Route::put('/admissions/{admission}', [IpdController::class, 'updateAdmission'])->middleware('permission:ipd.manage')->whereNumber('admission');
        Route::delete('/admissions/{admission}', [IpdController::class, 'deleteAdmission'])->middleware('permission:ipd.manage')->whereNumber('admission');
        Route::post('/admissions/{admission}/entries', [IpdController::class, 'addEntry'])->middleware('permission:ipd.manage')->whereNumber('admission');
        Route::put('/entries/{entry}', [IpdController::class, 'updateEntry'])->middleware('permission:ipd.manage')->whereNumber('entry');
        Route::delete('/entries/{entry}', [IpdController::class, 'deleteEntry'])->middleware('permission:ipd.manage')->whereNumber('entry');
    });

    // schedules, leave, slots
    Route::get('/doctors/{doctor}/schedules', [ScheduleController::class, 'schedules'])->middleware('permission:slots.read');
    Route::post('/doctors/{doctor}/schedules', [ScheduleController::class, 'storeSchedule'])->middleware('permission:slots.manage');
    Route::put('/schedules/{schedule}', [ScheduleController::class, 'updateSchedule'])->middleware('permission:slots.manage')->whereNumber('schedule');
    Route::delete('/schedules/{schedule}', [ScheduleController::class, 'destroySchedule'])->middleware('permission:slots.manage')->whereNumber('schedule');
    Route::get('/doctors/{doctor}/leaves', [ScheduleController::class, 'leaves'])->middleware('permission:slots.read');
    Route::post('/doctors/{doctor}/leaves', [ScheduleController::class, 'addLeave'])->middleware('permission:slots.manage');
    Route::delete('/leaves/{leave}', [ScheduleController::class, 'deleteLeave'])->middleware('permission:slots.manage')->whereNumber('leave');
    Route::get('/slots', [ScheduleController::class, 'slots'])->middleware('permission:slots.read');
    Route::post('/slots', [ScheduleController::class, 'createSlot'])->middleware('permission:slots.manage');
    Route::post('/slots/generate', [ScheduleController::class, 'generate'])->middleware('permission:slots.manage');
    Route::post('/slots/bulk', [ScheduleController::class, 'bulk'])->middleware('permission:slots.manage');
    Route::delete('/slots/{slot}', [ScheduleController::class, 'deleteSlot'])->middleware('permission:slots.manage')->whereNumber('slot');

    // appointments
    Route::get('/appointments', [AppointmentController::class, 'index'])->middleware('permission:appointments.read|appointments.read_own');
    Route::post('/appointments', [AppointmentController::class, 'store'])->middleware('permission:appointments.create');
    Route::get('/appointments/{id}', [AppointmentController::class, 'show'])->middleware('permission:appointments.read|appointments.read_own')->whereNumber('id');
    Route::post('/appointments/{id}/pay', [AppointmentController::class, 'pay'])->middleware('permission:portal.access')->whereNumber('id');
    Route::post('/appointments/{id}/collect-payment', [AppointmentController::class, 'collectPayment'])->middleware('permission:payments.collect')->whereNumber('id');
    Route::post('/appointments/{id}/cancel', [AppointmentController::class, 'cancel'])->middleware('permission:appointments.cancel|portal.access')->whereNumber('id');
    Route::post('/appointments/{id}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('permission:appointments.reschedule|portal.access')->whereNumber('id');
    Route::post('/appointments/{id}/status', [AppointmentController::class, 'setStatus'])->middleware('permission:appointments.update_status|appointments.check_in')->whereNumber('id');

    // dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary'])->middleware('permission:dashboard.view');
        Route::get('/department-stats', [DashboardController::class, 'departmentStats'])->middleware('permission:dashboard.view');
        Route::get('/appointment-trend', [DashboardController::class, 'appointmentTrend'])->middleware('permission:dashboard.view');
        Route::get('/revenue-trend', [DashboardController::class, 'revenueTrend'])->middleware('permission:reports.financial');
    });

    // patients, medical records, portal profile
    Route::get('/patients', [PatientController::class, 'index'])->middleware('permission:patients.read');
    Route::post('/patients', [PatientController::class, 'store'])->middleware('permission:patients.create');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->middleware('permission:patients.read')->whereNumber('patient');
    Route::put('/patients/{patient}', [PatientController::class, 'update'])->middleware('permission:patients.update')->whereNumber('patient');
    Route::post('/patients/{patient}/password-link', [PatientController::class, 'sendPasswordLink'])->middleware(['permission:patients.update', 'throttle:20,5'])->whereNumber('patient');
    Route::get('/patients/{patient}/appointments', [PatientController::class, 'appointments'])->middleware('permission:appointments.read|appointments.read_own')->whereNumber('patient');
    Route::get('/patients/{patient}/bills', [PatientController::class, 'bills'])->middleware('permission:billing.read')->whereNumber('patient');
    Route::get('/patients/{patient}/medical-records', [PatientController::class, 'records'])->middleware('permission:medical_records.read')->whereNumber('patient');
    Route::post('/patients/{patient}/medical-records', [PatientController::class, 'addRecord'])->middleware('permission:medical_records.create|observations.create')->whereNumber('patient');
    Route::post('/patients/{patient}/documents', [PatientController::class, 'addDocument'])->middleware(['permission:medical_records.create', 'throttle:30,5'])->whereNumber('patient');
    Route::get('/medical-records/{record}/file', [PatientController::class, 'download'])->middleware('permission:medical_records.read')->whereNumber('record');
    Route::get('/portal/profile', [PatientController::class, 'portalProfile'])->middleware('permission:portal.access');
    Route::put('/portal/profile', [PatientController::class, 'updatePortalProfile'])->middleware('permission:portal.access');

    // doctors
    Route::prefix('doctors')->group(function () {
        Route::get('/', [DoctorController::class, 'index'])->middleware('permission:doctors.read|doctors.manage');
        Route::post('/', [DoctorController::class, 'store'])->middleware('permission:doctors.manage');
        Route::get('/{doctor}', [DoctorController::class, 'show'])->middleware('permission:doctors.read|doctors.manage');
        Route::put('/{doctor}', [DoctorController::class, 'update'])->middleware('permission:doctors.manage');
        Route::patch('/{doctor}/status', [DoctorController::class, 'setStatus'])->middleware('permission:doctors.manage');
        Route::patch('/{doctor}/fees', [DoctorController::class, 'setFees'])->middleware('permission:doctors.manage');
        Route::delete('/{doctor}', [DoctorController::class, 'destroy'])->middleware('permission:doctors.manage');
        Route::get('/{doctor}/appointments', [DoctorController::class, 'appointments'])->middleware('permission:appointments.read');
        Route::get('/{doctor}/stats', [DoctorController::class, 'stats'])->middleware('permission:reports.view|doctors.manage');
    });

    // staff, shifts, attendance
    Route::get('/staff', [StaffController::class, 'index'])->middleware('permission:staff.read|staff.manage');
    Route::post('/staff', [StaffController::class, 'store'])->middleware('permission:staff.manage');
    Route::delete('/staff/shifts/{shift}', [StaffController::class, 'deleteShift'])->middleware('permission:staff.manage')->whereNumber('shift');
    Route::get('/staff/{staff}', [StaffController::class, 'show'])->middleware('permission:staff.read|staff.manage');
    Route::put('/staff/{staff}', [StaffController::class, 'update'])->middleware('permission:staff.manage');
    Route::patch('/staff/{staff}/employment', [StaffController::class, 'setEmployment'])->middleware('permission:staff.manage');
    Route::get('/staff/{staff}/shifts', [StaffController::class, 'shifts'])->middleware('permission:staff.read|staff.manage');
    Route::post('/staff/{staff}/shifts', [StaffController::class, 'addShift'])->middleware('permission:staff.manage');
    Route::get('/staff/{staff}/attendance', [StaffController::class, 'staffMonth'])->middleware('permission:attendance.manage|staff.read');
    Route::get('/attendance', [StaffController::class, 'attendanceDay'])->middleware('permission:attendance.manage');
    Route::put('/attendance', [StaffController::class, 'recordAttendance'])->middleware('permission:attendance.manage');

    // settings, holidays, uploads
    Route::get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.manage');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage');
    Route::get('/holidays', [SettingsController::class, 'holidays'])->middleware('permission:settings.manage|slots.read');
    Route::post('/holidays', [SettingsController::class, 'addHoliday'])->middleware('permission:settings.manage');
    Route::delete('/holidays/{holiday}', [SettingsController::class, 'deleteHoliday'])->middleware('permission:settings.manage');
    Route::post('/uploads/images', [SettingsController::class, 'uploadImage'])->middleware(['permission:uploads.create', 'throttle:30,5']);

    Route::middleware('permission:enquiries.manage')->prefix('enquiries')->group(function () {
        Route::get('/', [EnquiryController::class, 'index']);
        Route::patch('/{enquiry}', [EnquiryController::class, 'update']);
    });

    Route::get('/departments', [DepartmentController::class, 'index'])->middleware('permission:departments.read|departments.manage');
    Route::middleware('permission:departments.manage')->prefix('departments')->group(function () {
        Route::post('/', [DepartmentController::class, 'store']);
        Route::put('/{department}', [DepartmentController::class, 'update']);
        Route::patch('/{department}/status', [DepartmentController::class, 'setStatus']);
        Route::delete('/{department}', [DepartmentController::class, 'destroy']);
    });
});

// Public website data: no authentication, generously rate limited; the contact form is tighter.
Route::prefix('public')->middleware('throttle:public')->group(function () {
    Route::post('/bookings', [GuestBookingController::class, 'store'])->middleware('throttle:guest-booking');
    Route::get('/booking-options', [PaymentController::class, 'options']);
    Route::post('/payments/verify', [PaymentController::class, 'verify'])->middleware('throttle:guest-booking');
    Route::get('/settings', [PublicController::class, 'settings']);
    Route::get('/departments', [PublicController::class, 'departments']);
    Route::get('/departments/{slug}', [PublicController::class, 'department']);
    Route::get('/specializations', [PublicController::class, 'specializations']);
    Route::get('/doctors', [PublicController::class, 'doctors']);
    Route::get('/doctors/{id}', [PublicController::class, 'doctor'])->whereNumber('id');
    Route::get('/doctors/{id}/slots', [PublicController::class, 'doctorSlots'])->whereNumber('id');
    Route::post('/contact', [PublicController::class, 'contact'])->middleware('throttle:contact');
});

// The payment gateway calls this itself (no session, so no CSRF token); the signature is what proves it.
Route::post('/webhooks/razorpay', [PaymentController::class, 'webhook']);

// Public: uploaded images are served to anyone (random, unguessable names).
Route::get('/media/{name}', [SettingsController::class, 'media']);
