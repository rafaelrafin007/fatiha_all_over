<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs();
            $table->primary('id');
            $table->bigInteger('product_id');
            $table->bigInteger('product_variant_id')->nullable();
            $table->string('cloudinary_asset_id', 255)->unique();
            $table->string('cloudinary_public_id', 255);
            $table->string('resource_type', 30);
            $table->bigInteger('version');
            $table->text('secure_url');
            $table->string('alt_text', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->foreign('product_id')->references('id')->on('products')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->foreign(['product_variant_id', 'product_id'], 'product_images_variant_product_fk')
                ->references(['id', 'product_id'])->on('product_variants')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->index(['product_id', 'sort_order', 'id']);
            $table->index(['product_variant_id', 'sort_order', 'id']);
        });

        // Filtered uniqueness permits independent product-wide and per-SKU primary images.
        DB::statement('CREATE UNIQUE INDEX product_images_primary_product_unique ON product_images (product_id) WHERE is_primary AND product_variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX product_images_primary_variant_unique ON product_images (product_variant_id) WHERE is_primary AND product_variant_id IS NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE product_images
                ADD CONSTRAINT product_images_version_check CHECK (version >= 0),
                ADD CONSTRAINT product_images_sort_order_check CHECK (sort_order >= 0),
                ADD CONSTRAINT product_images_resource_type_check CHECK (resource_type IN ('image')),
                ADD CONSTRAINT product_images_secure_url_check CHECK (secure_url ~ '^https://[^[:space:]/?#]+[^[:space:]]*$'),
                ADD CONSTRAINT product_images_asset_id_check CHECK (cloudinary_asset_id <> '' AND cloudinary_asset_id !~ '(^[[:space:]]|[[:space:]]$)'),
                ADD CONSTRAINT product_images_public_id_check CHECK (cloudinary_public_id <> '' AND cloudinary_public_id !~ '(^[[:space:]]|[[:space:]]$)')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
