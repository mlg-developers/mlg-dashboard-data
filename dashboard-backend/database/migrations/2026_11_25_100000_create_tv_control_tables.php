<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accountant controls for the TV dashboards:
 *  - tv_doctor_overrides: per clinic and day, an assigned doctor switched 'on' (shown) or 'off' (absent).
 *  - tv_waiting_moves:    "move N waiting patients from doctor A to doctor B" for a clinic and day.
 *                         Only counts are stored, never patient identifiers.
 *
 * SAFE FOR THE LIVE DATABASE: it only creates two NEW tables, and only when they are missing. It never
 * alters, truncates or drops anything that already exists (down() removes just these two tables and is
 * never run by a deploy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tv_doctor_overrides')) {
            Schema::create('tv_doctor_overrides', function (Blueprint $table) {
                $table->id();
                $table->date('date');
                $table->string('clinic_code', 20);
                $table->string('doctor_code', 40);
                // 'on'  = show this assigned doctor in the clinic today even without patients
                // 'off' = the doctor is not in the clinic today (the TV shows "absent")
                $table->string('state', 3);
                $table->string('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['date', 'clinic_code', 'doctor_code'], 'tv_doctor_overrides_unique');
            });
        }

        if (! Schema::hasTable('tv_waiting_moves')) {
            Schema::create('tv_waiting_moves', function (Blueprint $table) {
                $table->id();
                $table->date('date');
                $table->string('clinic_code', 20);
                $table->string('from_doctor_code', 40);
                $table->string('to_doctor_code', 40);
                $table->unsignedInteger('count');
                $table->string('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['date', 'clinic_code', 'from_doctor_code', 'to_doctor_code'], 'tv_waiting_moves_unique');
                $table->index(['date', 'clinic_code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_waiting_moves');
        Schema::dropIfExists('tv_doctor_overrides');
    }
};
