<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital schema: mirrors docs/ARCHITECTURE.md (MySQL 8 / MariaDB 10.4+).
 * Status and type columns are plain strings validated by PHP enums, so adding a value never needs an ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- access control ----
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('module', 40)->index();
            $t->string('description')->default('');
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 80);
            $t->string('description')->default('');
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('user_roles', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'role_id']);
        });

        // ---- organisation ----
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('slug', 120)->unique();
            $t->string('short_description')->nullable();
            $t->text('description')->nullable();
            $t->string('image_url', 500)->nullable();
            $t->string('icon', 50)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->integer('display_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('doctors', function (Blueprint $t) {
            $t->id();
            $t->string('doctor_code', 20)->unique();
            $t->foreignId('user_id')->unique()->constrained();
            $t->string('qualification');
            $t->string('specialization', 120)->index();
            $t->unsignedSmallInteger('experience_years')->default(0);
            $t->string('registration_number', 60)->unique();
            $t->decimal('consultation_fee', 12, 2)->default(0);
            $t->string('gender', 16)->nullable();
            $t->text('bio')->nullable();
            $t->string('photo_url', 500)->nullable();
            $t->string('languages')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->boolean('is_featured')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('doctor_departments', function (Blueprint $t) {
            $t->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->constrained()->cascadeOnDelete();
            $t->boolean('is_primary')->default(false);
            $t->primary(['doctor_id', 'department_id']);
        });
        Schema::create('staff', function (Blueprint $t) {
            $t->id();
            $t->string('staff_code', 20)->unique();
            $t->foreignId('user_id')->unique()->constrained();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('designation', 100);
            $t->string('gender', 16)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->text('address')->nullable();
            $t->string('photo_url', 500)->nullable();
            $t->date('joining_date');
            $t->text('salary_encrypted')->nullable();
            $t->string('employment_status', 20)->default('active')->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('patients', function (Blueprint $t) {
            $t->id();
            $t->string('patient_code', 20)->unique();
            $t->foreignId('user_id')->nullable()->unique()->constrained();
            $t->string('full_name', 150)->index();
            $t->string('email')->nullable()->index();
            $t->string('mobile', 15)->index();
            $t->date('date_of_birth');
            $t->string('gender', 16);
            $t->string('blood_group', 3)->nullable();
            $t->text('address')->nullable();
            $t->string('city', 80)->nullable();
            $t->string('state', 80)->nullable();
            $t->string('pincode', 10)->nullable();
            $t->string('emergency_contact_name', 150)->nullable();
            $t->string('emergency_contact_phone', 15)->nullable();
            $t->timestamp('consent_at')->nullable();
            $t->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        // ---- scheduling ----
        Schema::create('doctor_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->constrained();
            $t->unsignedTinyInteger('day_of_week'); // 0 = Monday
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedSmallInteger('slot_duration_min')->default(15);
            $t->unsignedSmallInteger('capacity_per_slot')->default(1);
            $t->decimal('consultation_fee', 12, 2)->nullable();
            $t->string('appointment_type', 20)->default('in_person');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['doctor_id', 'day_of_week']);
        });
        Schema::create('slot_breaks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('schedule_id')->constrained('doctor_schedules')->cascadeOnDelete();
            $t->time('start_time');
            $t->time('end_time');
            $t->string('reason', 120)->nullable();
            $t->timestamps();
        });
        Schema::create('doctor_leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->index(['doctor_id', 'start_date', 'end_date']);
        });
        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->date('holiday_date')->unique();
            $t->string('name', 120);
            $t->timestamps();
        });
        Schema::create('appointment_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('doctor_id')->constrained();
            $t->foreignId('department_id')->constrained();
            $t->foreignId('schedule_id')->nullable()->constrained('doctor_schedules')->nullOnDelete();
            $t->date('slot_date');
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedSmallInteger('capacity')->default(1);
            $t->unsignedInteger('booked_count')->default(0);
            $t->decimal('consultation_fee', 12, 2);
            $t->string('appointment_type', 20)->default('in_person');
            $t->string('status', 20)->default('available'); // available | blocked | cancelled
            $t->boolean('is_published')->default(false);
            $t->string('block_reason')->nullable();
            $t->timestamps();
            $t->unique(['doctor_id', 'slot_date', 'start_time'], 'uq_slots_doctor_start');
            $t->index(['slot_date', 'status']);
            $t->index(['department_id', 'slot_date']);
        });

        // ---- appointments & clinical ----
        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->string('appointment_number', 30)->unique();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('doctor_id')->constrained();
            $t->foreignId('department_id')->constrained();
            $t->foreignId('slot_id')->constrained('appointment_slots');
            // Equals slot_id while the booking is active and NULL once cancelled,
            // so a patient holds at most one active booking per slot.
            $t->unsignedBigInteger('active_slot_key')->nullable();
            $t->date('appointment_date');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('appointment_type', 20);
            $t->text('reason')->nullable();
            $t->string('status', 20)->default('confirmed');
            $t->string('payment_status', 20)->default('unpaid');
            $t->decimal('consultation_fee', 12, 2);
            $t->unsignedInteger('queue_number')->nullable();
            $t->string('booking_source', 20)->default('online');
            $t->foreignId('booked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('checked_in_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->unsignedBigInteger('rescheduled_from_id')->nullable();
            $t->timestamps();
            $t->unique(['patient_id', 'active_slot_key'], 'uq_appointments_patient_active_slot');
            $t->index(['doctor_id', 'appointment_date']);
            $t->index(['appointment_date', 'status']);
            $t->index(['patient_id', 'appointment_date']);
        });
        Schema::create('appointment_status_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note')->nullable();
            $t->timestamps();
        });
        Schema::create('consultations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->unique()->constrained();
            $t->foreignId('doctor_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->text('chief_complaint')->nullable();
            $t->json('vitals')->nullable();
            $t->text('diagnosis')->nullable();
            $t->text('notes_encrypted')->nullable();
            $t->text('advice')->nullable();
            $t->date('follow_up_date')->nullable();
            $t->timestamps();
        });
        Schema::create('prescriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $t->string('medicine_name', 200);
            $t->string('dosage', 100)->nullable();
            $t->string('frequency', 100)->nullable();
            $t->string('duration', 100)->nullable();
            $t->string('route', 50)->nullable();
            $t->string('instructions', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('medical_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('record_type', 20);
            $t->string('title', 200);
            $t->text('content_encrypted')->nullable();
            $t->json('data')->nullable();
            $t->string('file_path', 500)->nullable();
            $t->string('file_name')->nullable();
            $t->string('mime_type', 100)->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->string('visibility', 20)->default('clinical');
            $t->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['patient_id', 'record_type']);
        });

        // ---- billing ----
        Schema::create('bills', function (Blueprint $t) {
            $t->id();
            $t->string('bill_number', 30)->unique();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $t->date('bill_date');
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->decimal('paid_amount', 12, 2)->default(0);
            $t->string('status', 20)->default('pending');
            $t->text('notes')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamps();
            $t->index(['patient_id', 'bill_date']);
            $t->index(['status', 'bill_date']);
        });
        Schema::create('bill_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $t->string('item_type', 20);
            $t->string('description');
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_price', 12, 2);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('tax_pct', 5, 2)->default(0);
            $t->decimal('line_total', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_number', 30)->unique();
            $t->foreignId('bill_id')->constrained();
            $t->decimal('amount', 12, 2); // negative = refund
            $t->string('method', 20);
            $t->string('reference_no', 100)->nullable();
            $t->string('status', 20)->default('success');
            $t->timestamp('paid_at');
            $t->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('refund_of_id')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->index(['paid_at', 'method']);
        });

        // ---- operations ----
        Schema::create('staff_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $t->date('shift_date')->index();
            $t->time('start_time');
            $t->time('end_time');
            $t->string('shift_type', 20)->default('general');
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->unique(['staff_id', 'shift_date', 'start_time'], 'uq_shifts_staff_date_start');
        });
        Schema::create('staff_attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $t->date('attendance_date')->index();
            $t->timestamp('check_in')->nullable();
            $t->timestamp('check_out')->nullable();
            $t->string('status', 20);
            $t->string('remarks')->nullable();
            $t->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['staff_id', 'attendance_date'], 'uq_attendance_staff_date');
        });
        Schema::create('hospital_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->json('value')->nullable();
            $t->string('group', 50)->index();
            $t->boolean('is_public')->default(false);
            $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('hms_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('recipient');
            $t->string('channel', 20);
            $t->string('event', 60);
            $t->string('subject')->nullable();
            $t->text('body');
            $t->string('status', 20)->default('queued');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->text('error')->nullable();
            $t->string('related_type', 40)->nullable();
            $t->unsignedBigInteger('related_id')->nullable();
            $t->timestamps();
            $t->index(['status', 'channel']);
            $t->index(['user_id', 'status']);
            $t->index(['related_type', 'related_id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 60);
            $t->string('entity_type', 60);
            $t->string('entity_id', 40)->nullable();
            $t->json('changes')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->index(['entity_type', 'entity_id']);
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('contact_messages', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('email');
            $t->string('phone', 15)->nullable();
            $t->string('subject', 200)->nullable();
            $t->text('message');
            $t->string('status', 20)->default('new');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'contact_messages', 'audit_logs', 'hms_notifications', 'hospital_settings', 'staff_attendance',
            'staff_shifts', 'payments', 'bill_items', 'bills', 'medical_records', 'prescriptions', 'consultations',
            'appointment_status_history', 'appointments', 'appointment_slots', 'holidays', 'doctor_leaves',
            'slot_breaks', 'doctor_schedules', 'patients', 'staff', 'doctor_departments', 'doctors', 'departments',
            'user_roles', 'role_permissions', 'roles', 'permissions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
