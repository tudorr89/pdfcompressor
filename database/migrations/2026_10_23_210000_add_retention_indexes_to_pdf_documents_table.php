<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            // Used by `pdfs:delete` (status + created_at) on every cleanup run.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });
    }
};
