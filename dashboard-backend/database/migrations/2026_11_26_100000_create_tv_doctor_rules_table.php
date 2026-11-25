<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent accountant rules for a doctor in a clinic (not tied to a day):
 *  - 'frozen'   = the doctor does not work in this clinic any more: hidden from the TVs until restored.
 *  - 'migrated' = the doctor moved to another clinic (target_clinic_code): hidden here, shown there.
 * Names are copied in so the accountant can list the rules on days the clinic is not scheduled.
 *
 * SAFE FOR THE LIVE DATABASE: creates one NEW table, only when it is missing. It never alters or drops
 * anything that already exists (down() removes just this table and is never run by a deploy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tv_doctor_rules')) {
            return;
        }
        Schema::create('tv_doctor_rules', function (Blueprint $table) {
            $table->id();
            $table->string('clinic_code', 20);
            $table->string('doctor_code', 40);
            $table->string('rule', 10); // frozen | migrated
            $table->string('target_clinic_code', 20)->nullable();
            $table->string('doctor_name')->nullable();
            $table->string('clinic_name')->nullable();
            $table->string('target_clinic_name')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['clinic_code', 'doctor_code'], 'tv_doctor_rules_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_doctor_rules');
    }
};
