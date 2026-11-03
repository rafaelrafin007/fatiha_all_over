<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs();
            $table->primary('id');
            $table->bigInteger('parent_id')->nullable();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('deleted_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->foreign('parent_id')->references('id')->on('categories')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->index(['parent_id', 'sort_order', 'id']);
        });

        // PostgreSQL CHECKs enforce row invariants; ancestry cycles need the later locked workflow.
        DB::statement(<<<'SQL'
            ALTER TABLE categories
                ADD CONSTRAINT categories_parent_check CHECK (parent_id IS NULL OR parent_id <> id),
                ADD CONSTRAINT categories_sort_order_check CHECK (sort_order >= 0),
                ADD CONSTRAINT categories_slug_check CHECK (slug <> '' AND slug = lower(btrim(slug)) AND slug !~ '(^[[:space:]]|[[:space:]]$)'),
                ADD CONSTRAINT categories_name_check CHECK (name <> '' AND name !~ '(^[[:space:]]|[[:space:]]$)')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
