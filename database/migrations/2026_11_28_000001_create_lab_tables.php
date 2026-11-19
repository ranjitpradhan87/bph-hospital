<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The testing lab: a catalogue of tests with prices, lab orders for a patient, per-test results, and
 * payment through the shared bills/payments tables (so a future billing screen sees lab money too).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_tests', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 150);
            $t->string('category', 60)->index();
            $t->string('sample_type', 40)->nullable();
            $t->decimal('price', 12, 2);
            $t->unsignedSmallInteger('turnaround_hours')->nullable();
            $t->string('unit', 30)->nullable();
            $t->string('reference_range', 150)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->integer('display_order')->default(0);
            $t->timestamps();
        });

        Schema::create('lab_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_number', 30)->unique();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('admission_id')->nullable()->constrained('ipd_admissions')->nullOnDelete();
            $t->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $t->string('status', 20)->default('ordered'); // ordered | sample_collected | processing | completed | cancelled
            $t->timestamp('sample_collected_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['patient_id', 'created_at']);
        });

        Schema::create('lab_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('lab_orders')->cascadeOnDelete();
            $t->foreignId('test_id')->nullable()->constrained('lab_tests')->nullOnDelete();
            $t->string('test_name', 150); // what was ordered, even if the catalogue changes later
            $t->decimal('price', 12, 2);
            $t->text('result_value')->nullable();
            $t->string('result_unit', 30)->nullable();
            $t->string('reference_range', 150)->nullable();
            $t->string('flag', 10)->nullable(); // normal | high | low | critical
            $t->text('result_notes')->nullable();
            $t->foreignId('resulted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resulted_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_order_items');
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('lab_tests');
    }
};
