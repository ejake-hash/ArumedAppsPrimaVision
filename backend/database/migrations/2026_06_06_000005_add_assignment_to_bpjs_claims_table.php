<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tandai "dikerjakan oleh siapa" pada klaim BPJS (soft assignment, anti double-work).
 *  - assigned_to_id   : user yang mengerjakan klaim (nullable = belum ada).
 *  - assigned_at      : kapan ditandai.
 * Tidak mengunci — hanya penanda + badge di UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            $table->foreignUuid('assigned_to_id')->nullable()->after('rejected_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_id');
            $table->dropColumn('assigned_at');
        });
    }
};
