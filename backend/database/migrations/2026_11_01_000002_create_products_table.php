<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs();
            $table->primary('id');
            $table->bigInteger('category_id');
            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->text('short_description')->nullable();
            $table->text('description');
            $table->string('status', 32)->default('draft');
            $table->string('seo_title', 200)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestampTz('published_at', 6)->nullable();
            $table->timestampTz('deleted_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->foreign('category_id')->references('id')->on('categories')
                ->restrictOnDelete()->noActionOnUpdate();
            // Full FK coverage includes archived rows excluded from the browsing index.
            $table->index('category_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'archived')),
                ADD CONSTRAINT products_slug_check CHECK (slug <> '' AND slug = lower(btrim(slug)) AND slug !~ '(^[[:space:]]|[[:space:]]$)'),
                ADD CONSTRAINT products_name_check CHECK (name <> '' AND name !~ '(^[[:space:]]|[[:space:]]$)')
            SQL);
        DB::statement('CREATE INDEX products_category_status_id_live_index ON products (category_id, status, id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
