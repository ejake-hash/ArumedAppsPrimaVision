<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop classification column from 4 per-insurer tariff tables.
 * Unique constraint changes from (item_id, insurer_id, classification)
 * to (item_id, insurer_id). Assumes tables are empty (truncated by user before run).
 */
return new class extends Migration {
    private array $tables = [
        'procedure_tariffs'  => 'procedure_id',
        'medication_tariffs' => 'medication_id',
        'bhp_tariffs'        => 'bhp_item_id',
        'iol_tariffs'        => 'iol_item_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $itemFk) {
            Schema::table($table, function (Blueprint $t) use ($table, $itemFk) {
                $t->dropUnique($table.'_'.$itemFk.'_insurer_id_classification_unique');
                $t->dropIndex($table.'_classification_index');
                $t->dropColumn('classification');
            });
            Schema::table($table, function (Blueprint $t) use ($itemFk) {
                $t->unique([$itemFk, 'insurer_id']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $itemFk) {
            Schema::table($table, function (Blueprint $t) use ($itemFk) {
                $t->dropUnique([$itemFk, 'insurer_id']);
            });
            Schema::table($table, function (Blueprint $t) use ($itemFk) {
                $t->string('classification', 20)->default('UMUM')->after('insurer_id');
                $t->index('classification');
                $t->unique([$itemFk, 'insurer_id', 'classification']);
            });
        }
    }
};
