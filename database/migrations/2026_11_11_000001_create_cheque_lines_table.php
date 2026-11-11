<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row of the breakdown printed on the cheque's Payment Advice.
        Schema::create('cheque_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cheque_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->date('line_date')->nullable();
            $t->string('reference_no', 60)->nullable();   // OR / invoice no.
            $t->string('vendor')->nullable();
            $t->string('particulars')->nullable();
            $t->decimal('amount', 15, 2);
            $t->foreignId('receipt_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['cheque_id', 'line_no']);
            $t->index('reference_no');
            $t->index('receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_lines');
    }
};
