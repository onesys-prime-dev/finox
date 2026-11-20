<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('category', 20); // cheque | receipt
            $t->boolean('is_reprint')->default(false);
            $t->string('reason')->nullable();
            $t->string('status', 20)->default('initiated')->index(); // initiated|confirmed|failed|partial
            $t->unsignedInteger('item_count')->default(0);
            $t->json('settings')->nullable();
            $t->text('note')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('print_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('print_job_id')->constrained()->cascadeOnDelete();
            $t->string('printable_type', 40);
            $t->unsignedBigInteger('printable_id');
            $t->foreignId('template_id')->nullable()->constrained('print_templates')->nullOnDelete();
            $t->unsignedInteger('template_version')->nullable();
            $t->string('status', 20)->default('initiated'); // initiated|confirmed|failed
            $t->boolean('is_reprint')->default(false);
            $t->text('note')->nullable();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->index(['printable_type', 'printable_id']);
        });

        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 60)->index();
            $t->string('module', 30)->index();
            $t->string('subject_type', 40)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('subject_label')->nullable();
            $t->text('description');
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->text('reason')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('saved_filters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('scope', 30);
            $t->string('name');
            $t->json('filters');
            $t->timestamps();
        });

        Schema::create('problem_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('url', 500);
            $t->text('message');
            $t->boolean('is_resolved')->default(false);
            $t->timestamps();
        });

        $cat = fn ($p, $c) => DB::getDriverName() === 'sqlite' ? "'$p' || $c" : "CONCAT('$p', $c)";
        DB::statement('DROP VIEW IF EXISTS transaction_register');
        DB::statement("CREATE VIEW transaction_register AS
SELECT 'cheque' AS type, c.id AS record_id, {$cat('CHQ-', 'c.id')} AS transaction_id,
       c.cheque_number AS reference_number, p.name AS party, c.cheque_date AS transaction_date,
       c.amount AS amount, c.status AS status, c.bank_account_id AS bank_account_id,
       NULL AS category_id, c.created_by AS created_by, c.created_at AS created_at
FROM cheques c JOIN payees p ON p.id = c.payee_id
UNION ALL
SELECT 'receipt', r.id, {$cat('RCP-', 'r.id')},
       r.receipt_number, p.name, r.receipt_date, r.amount, r.status, NULL,
       r.category_id, r.created_by, r.created_at
FROM receipts r LEFT JOIN payees p ON p.id = r.payee_id");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS transaction_register');
        foreach (['problem_reports', 'saved_filters', 'activity_logs', 'print_logs', 'print_jobs'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
