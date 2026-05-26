<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->string('name');
            $table->text('header_html')->nullable();
            $table->text('body_html')->nullable();
            $table->text('footer_html')->nullable();
            $table->string('page_size', 20)->default('A4');
            $table->string('orientation', 20)->default('portrait');
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('document_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
