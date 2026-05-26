<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->foreignUuid('parent_id')
                ->nullable()
                ->after('type')
                ->constrained('insurers')
                ->nullOnDelete();
            $table->boolean('is_system')
                ->default(false)
                ->after('is_active');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['parent_id', 'is_system']);
        });
    }
};
