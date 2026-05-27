<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Override nama tampilan modul untuk matriks RBAC (Data Pengguna).
        // Hanya menyimpan label yang di-set admin; default tetap hardcoded di
        // PermissionService::defaultLabels(). Row absen = pakai default.
        Schema::create('module_label_settings', function (Blueprint $table) {
            $table->id();
            $table->string('module', 60)->unique();  // permission module key, mis. 'admisi'
            $table->string('label', 120);            // label tampilan override
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_label_settings');
    }
};
