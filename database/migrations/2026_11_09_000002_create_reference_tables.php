<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind', 20)->default('cheque'); // cheque | receipt
            $t->decimal('paper_width_mm', 7, 2);
            $t->decimal('paper_height_mm', 7, 2);
            $t->string('background_path')->nullable();
            $t->decimal('offset_x_mm', 6, 2)->default(0);
            $t->decimal('offset_y_mm', 6, 2)->default(0);
            $t->json('layout');
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('banks', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('remarks')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_id')->constrained()->restrictOnDelete();
            $t->string('branch')->nullable();
            $t->string('account_name');
            $t->string('account_number');
            $t->string('account_reference')->nullable(); // e.g. BRSTN
            $t->string('cheque_format')->nullable();
            $t->foreignId('print_template_id')->nullable()->constrained('print_templates')->nullOnDelete();
            $t->string('starting_number', 20)->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->unique(['bank_id', 'account_number']);
        });

        Schema::create('payees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('tin', 30)->nullable();
            $t->string('default_payee_line', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index('name');
        });

        Schema::create('receipt_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['system_settings', 'receipt_categories', 'payees', 'bank_accounts', 'banks', 'print_templates'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
