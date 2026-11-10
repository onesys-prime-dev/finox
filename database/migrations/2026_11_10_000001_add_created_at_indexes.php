<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', fn (Blueprint $t) => $t->index('created_at'));
        Schema::table('receipts', fn (Blueprint $t) => $t->index('created_at'));
    }

    public function down(): void
    {
        Schema::table('cheques', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        Schema::table('receipts', fn (Blueprint $t) => $t->dropIndex(['created_at']));
    }
};
