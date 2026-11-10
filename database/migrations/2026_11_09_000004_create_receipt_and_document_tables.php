<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_number', 60)->nullable()->index();
            $t->date('receipt_date')->nullable()->index();
            $t->foreignId('payee_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('description', 500)->nullable();
            $t->decimal('amount', 15, 2)->nullable();
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->nullable();
            $t->foreignId('category_id')->nullable()->constrained('receipt_categories')->nullOnDelete();
            $t->foreignId('cheque_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reference_no', 100)->nullable();
            $t->text('remarks')->nullable();
            $t->string('status', 20)->default('draft')->index();
            $t->text('duplicate_override_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('document_files', function (Blueprint $t) {
            $t->id();
            $t->uuid('group_uuid')->index();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_current')->default(true);
            $t->string('category', 30)->default('other');
            $t->string('original_name');
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->nullableMorphs('documentable');
            $t->text('replace_reason')->nullable();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['is_current', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
        Schema::dropIfExists('receipts');
    }
};
