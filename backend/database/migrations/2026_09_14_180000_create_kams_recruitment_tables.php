<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kams_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->string('member_code', 64);
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('trainer_name')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('team_name')->nullable();
            $table->string('rank')->nullable();
            $table->enum('origin', ['new_recruit', 'migrated', 'staff'])->default('new_recruit');
            $table->enum('status', ['pending_verification', 'verified', 'active', 'inactive', 'disputed'])->default('active');
            $table->date('joined_on')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->boolean('first_week_completed')->default(false);
            $table->timestamp('first_week_completed_at')->nullable();
            $table->json('documents')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'member_code']);
            $table->index(['company_id', 'origin', 'status']);
            $table->index(['office_id', 'status']);
            $table->index('phone');
        });

        Schema::create('kams_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('kams_members')->nullOnDelete();
            $table->foreignId('sponsor_member_id')->nullable()->constrained('kams_members')->nullOnDelete();
            $table->string('candidate_id', 64)->unique();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->enum('source', ['poster', 'qr_code', 'online_form', 'sponsorship', 'other'])->default('online_form');
            $table->string('sponsor_name')->nullable();
            $table->enum('status', [
                'received',
                'under_review',
                'shortlisted',
                'rejected',
                'on_hold',
                'interview_admitted',
                'observation',
                'approved',
                'registered',
            ])->default('received');
            $table->timestamp('applied_at');
            $table->json('documents')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['office_id', 'status']);
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->foreign('candidate_id')->references('id')->on('kams_candidates')->nullOnDelete();
        });

        Schema::create('kams_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('candidate_id')->constrained('kams_candidates')->cascadeOnDelete();
            $table->date('interview_date');
            $table->string('interviewer_name')->nullable();
            $table->boolean('attended')->default(false);
            $table->text('notes')->nullable();
            $table->enum('result', ['admitted', 'rejected', 'on_hold'])->default('on_hold');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_observation_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('candidate_id')->constrained('kams_candidates')->cascadeOnDelete();
            $table->date('observation_date');
            $table->boolean('attended')->default(false);
            $table->string('supervisor_name')->nullable();
            $table->text('evaluation')->nullable();
            $table->text('notes')->nullable();
            $table->enum('decision', ['approved', 'rejected', 'on_hold'])->default('on_hold');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_member_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('kams_members')->cascadeOnDelete();
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('kams_member_opening_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('kams_members')->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('target_module', 64)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->boolean('is_opening_balance')->default(true);
            $table->boolean('verified')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_member_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['registry', 'database', 'excel', 'google_sheets', 'other'])->default('excel');
            $table->string('source_label')->nullable();
            $table->enum('status', ['imported', 'pending_verification', 'completed'])->default('imported');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('issue_count')->default(0);
            $table->timestamp('imported_at');
            $table->timestamps();
        });

        Schema::create('kams_member_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('kams_member_imports')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('kams_members')->nullOnDelete();
            $table->unsignedInteger('line_number')->default(0);
            $table->json('payload');
            $table->json('issues')->nullable();
            $table->enum('status', ['imported', 'pending_verification', 'verified', 'active', 'rejected'])->default('imported');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('communicable_type');
            $table->unsignedBigInteger('communicable_id');
            $table->string('recipient_type', 32);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_contact')->nullable();
            $table->string('message_type', 64);
            $table->string('trigger', 64);
            $table->string('channel', 32)->default('in_app');
            $table->string('locale', 8)->default('fr');
            $table->string('category', 32)->default('recruitment');
            $table->text('subject')->nullable();
            $table->text('body');
            $table->enum('delivery_status', ['queued', 'sent', 'failed', 'logged'])->default('logged');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['communicable_type', 'communicable_id']);
            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_communications');
        Schema::dropIfExists('kams_member_import_rows');
        Schema::dropIfExists('kams_member_imports');
        Schema::dropIfExists('kams_member_opening_positions');
        Schema::dropIfExists('kams_member_history');
        Schema::dropIfExists('kams_observation_days');
        Schema::dropIfExists('kams_interviews');
        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropForeign(['candidate_id']);
        });
        Schema::dropIfExists('kams_candidates');
        Schema::dropIfExists('kams_members');
    }
};
