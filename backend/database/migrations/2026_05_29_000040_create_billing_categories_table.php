<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->integer('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sort_order', 'name']);
            $table->index('is_active');
        });

        // Seed default categories — urut sesuai alur layanan kasir umum.
        $now  = now();
        $seed = [];
        $rows = [
            ['Registrasi',    10],
            ['Konsultasi',    20],
            ['Pemeriksaan',   30],
            ['Tindakan',      40],
            ['Penunjang',     50],
            ['Obat',          60],
            ['BHP',           70],
            ['IOL',           80],
            ['Alat Kesehatan', 90],
            ['Lainnya',       999],
        ];
        foreach ($rows as [$name, $order]) {
            $seed[] = [
                'id'         => (string) Str::uuid(),
                'name'       => $name,
                'sort_order' => $order,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('billing_categories')->insert($seed);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_categories');
    }
};
