<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi Stock Opname + detail per item (Berita Acara).
 *
 * Layer PEREKAMAN murni di atas InventoryStockService::opname() — mutasi stok
 * (inventory_stocks/FEFO/batch OPNAME/system_logs) TIDAK berubah, hanya dipanggil.
 * Header: satu kegiatan opname (lokasi + jenis + tanggal). Detail: baris item yang
 * BERSELISIH (sistem vs fisik) dengan status LEBIH/KURANG + catatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_number', 30)->unique();
            $table->enum('location', ['INVENTORI', 'FARMASI', 'BEDAH'])->index();
            $table->enum('item_type', ['MEDICATION', 'BHP'])->index();
            $table->date('opname_date')->index();
            $table->enum('status', ['DRAFT', 'APPLIED'])->default('APPLIED');
            $table->integer('total_items')->default(0);
            $table->integer('total_plus')->default(0);
            $table->integer('total_minus')->default(0);
            $table->text('notes')->nullable();
            $table->uuid('counted_by')->nullable();
            $table->uuid('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_opname_session_id');
            $table->enum('item_type', ['MEDICATION', 'BHP']);
            $table->uuid('item_id');
            $table->string('item_code')->nullable();   // snapshot (BA stabil walau master di-rename)
            $table->string('item_name')->nullable();    // snapshot
            $table->decimal('system_qty', 12, 2);
            $table->decimal('physical_qty', 12, 2);
            $table->decimal('delta', 12, 2);            // physical - system
            $table->enum('status', ['LEBIH', 'KURANG']);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('stock_opname_session_id')
                  ->references('id')->on('stock_opname_sessions')->cascadeOnDelete();
            $table->index('stock_opname_session_id');
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opname_sessions');
    }
};
