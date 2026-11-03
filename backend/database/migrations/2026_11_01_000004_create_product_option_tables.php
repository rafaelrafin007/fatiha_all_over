<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs();
            $table->primary('id');
            $table->bigInteger('product_id');
            $table->string('name', 80);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->foreign('product_id')->references('id')->on('products')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->unique(['product_id', 'name']);
            $table->unique(['id', 'product_id']);
            $table->index(['product_id', 'sort_order', 'id']);
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs();
            $table->primary('id');
            $table->bigInteger('product_id');
            $table->bigInteger('product_option_id');
            $table->string('value', 100);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->foreign(['product_option_id', 'product_id'], 'option_values_option_product_fk')
                ->references(['id', 'product_id'])->on('product_options')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->unique(['product_option_id', 'value']);
            $table->unique(['id', 'product_option_id', 'product_id'], 'option_values_id_option_product_unique');
            $table->index(['product_option_id', 'sort_order', 'id'], 'option_values_option_sort_id_index');
            $table->index('product_id');
        });

        Schema::create('variant_option_values', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs();
            $table->primary('id');
            $table->bigInteger('product_id');
            $table->bigInteger('product_variant_id');
            $table->bigInteger('product_option_id');
            $table->bigInteger('product_option_value_id');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->foreign(['product_variant_id', 'product_id'], 'variant_values_variant_product_fk')
                ->references(['id', 'product_id'])->on('product_variants')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->foreign(['product_option_value_id', 'product_option_id', 'product_id'], 'variant_values_value_option_product_fk')
                ->references(['id', 'product_option_id', 'product_id'])->on('product_option_values')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->unique(['product_variant_id', 'product_option_id'], 'variant_values_variant_option_unique');
            $table->index('product_option_value_id');
            $table->index('product_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_options
                ADD CONSTRAINT product_options_sort_order_check CHECK (sort_order >= 0),
                ADD CONSTRAINT product_options_name_check CHECK (name <> '' AND name !~ '(^[[:space:]]|[[:space:]]$)')
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE product_option_values
                ADD CONSTRAINT product_option_values_sort_order_check CHECK (sort_order >= 0),
                ADD CONSTRAINT product_option_values_value_check CHECK (value <> '' AND value !~ '(^[[:space:]]|[[:space:]]$)')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_option_values');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
    }
};
