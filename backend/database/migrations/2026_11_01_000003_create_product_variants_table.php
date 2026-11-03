<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs();
            $table->primary('id');
            $table->bigInteger('product_id');
            $table->string('sku', 100)->unique();
            $table->string('name', 150);
            $table->string('option_signature', 255);
            $table->bigInteger('base_price_minor');
            $table->char('currency', 3)->default('BDT');
            $table->integer('weight_grams')->nullable();
            $table->boolean('is_active')->default(false);
            $table->integer('pricing_revision')->default(1);
            $table->timestampTz('deleted_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->foreign('product_id')->references('id')->on('products')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->unique(['product_id', 'option_signature']);
            $table->unique(['id', 'product_id']);
            $table->index(['product_id', 'is_active', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_variants
                ADD CONSTRAINT product_variants_price_check CHECK (base_price_minor >= 0 AND (NOT is_active OR base_price_minor > 0)),
                ADD CONSTRAINT product_variants_currency_check CHECK (currency = 'BDT'),
                ADD CONSTRAINT product_variants_weight_check CHECK (weight_grams IS NULL OR weight_grams > 0),
                ADD CONSTRAINT product_variants_revision_check CHECK (pricing_revision >= 1),
                ADD CONSTRAINT product_variants_sku_check CHECK (sku <> '' AND sku = upper(btrim(sku)) AND sku !~ '(^[[:space:]]|[[:space:]]$)'),
                ADD CONSTRAINT product_variants_signature_check CHECK (option_signature <> '' AND option_signature !~ '(^[[:space:]]|[[:space:]]$)'),
                ADD CONSTRAINT product_variants_name_check CHECK (name <> '' AND name !~ '(^[[:space:]]|[[:space:]]$)')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
