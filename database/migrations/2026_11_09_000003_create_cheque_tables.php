<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $t->string('cheque_number', 30);
            $t->date('cheque_date');
            $t->foreignId('payee_id')->constrained()->restrictOnDelete();
            $t->string('payee_line', 500)->nullable();
            $t->boolean('is_cash')->default(false);
            $t->decimal('amount', 15, 2);
            $t->string('amount_words', 500)->nullable();
            $t->string('purpose', 500)->nullable();
            $t->string('reference_no', 100)->nullable();
            $t->text('remarks')->nullable();
            $t->string('status', 20)->default('draft')->index();
            $t->text('stop_payment_note')->nullable();
            $t->timestamp('prepared_at')->nullable();
            $t->timestamp('printed_at')->nullable();
            $t->unsignedInteger('print_count')->default(0);
            $t->timestamp('stale_flagged_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['bank_account_id', 'cheque_number']);
            $t->index('cheque_date');
            $t->index('reference_no');
        });

        Schema::create('status_histories', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 40);
            $t->unsignedBigInteger('subject_id');
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->text('reason')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('cheque_releases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cheque_id')->unique()->constrained()->restrictOnDelete();
            $t->date('released_at');
            $t->string('released_to');
            $t->string('released_by');
            $t->text('remarks')->nullable();
            $t->string('supporting_reference')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('cheque_clearings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cheque_id')->unique()->constrained()->restrictOnDelete();
            $t->string('result', 10); // cleared | bounced
            $t->date('cleared_at');
            $t->text('remarks')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['cheque_clearings', 'cheque_releases', 'status_histories', 'cheques'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
