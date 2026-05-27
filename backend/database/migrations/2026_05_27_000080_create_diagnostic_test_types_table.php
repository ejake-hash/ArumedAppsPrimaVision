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
        Schema::create('diagnostic_test_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();   // dipakai relasi tarif penunjang nanti
            $table->string('name', 150);
            $table->string('category', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('category');
        });

        // Seed default — jenis yang sebelumnya hardcoded di DokterView.
        $now  = now();
        $rows = [
            ['OCT',   'OCT Macula / Saraf',     'Imaging'],
            ['FP',    'Foto Fundus',            'Imaging'],
            ['VF',    'Visual Field Test',      'Fungsional'],
            ['USG',   'USG B-Scan',             'Imaging'],
            ['TOPOG', 'Topografi Kornea',       'Imaging'],
            ['FFA',   'Fluorescein Angiografi', 'Vaskular'],
            ['ORA',   'ORA Biomekanikal',       'Biomekanikal'],
            ['GDX',   'GDx Fiber Analysis',     'Glaukoma'],
        ];
        $seed = [];
        foreach ($rows as $i => [$code, $name, $category]) {
            $seed[] = [
                'id'         => (string) Str::uuid(),
                'code'       => $code,
                'name'       => $name,
                'category'   => $category,
                'is_active'  => true,
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('diagnostic_test_types')->insert($seed);
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_test_types');
    }
};
