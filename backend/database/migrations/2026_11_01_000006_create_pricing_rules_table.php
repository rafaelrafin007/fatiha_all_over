<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Required PostgreSQL operator classes; fail rather than weaken overlap protection.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public');

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs();
            $table->primary('id');
            $table->bigInteger('product_variant_id');
            $table->integer('min_quantity');
            $table->integer('max_quantity')->nullable();
            $table->bigInteger('unit_price_minor');
            $table->boolean('is_active')->default(false);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->foreign('product_variant_id')->references('id')->on('product_variants')
                ->restrictOnDelete()->noActionOnUpdate();
            $table->index(['product_variant_id', 'min_quantity']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE pricing_rules
                ADD CONSTRAINT pricing_rules_min_quantity_check CHECK (min_quantity >= 1),
                ADD CONSTRAINT pricing_rules_max_quantity_check CHECK (max_quantity IS NULL OR max_quantity >= min_quantity),
                ADD CONSTRAINT pricing_rules_price_check CHECK (unit_price_minor >= 0 AND (NOT is_active OR unit_price_minor > 0)),
                ADD CONSTRAINT pricing_rules_active_ranges_exclude EXCLUDE USING gist (
                    product_variant_id WITH =,
                    int8range(min_quantity::bigint, CASE WHEN max_quantity IS NULL THEN NULL ELSE max_quantity::bigint + 1 END, '[)') WITH &&
                ) WHERE (is_active)
            SQL);
        // Complete coverage, base-tier equality and revisioned replacement need later locked services.
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
        // Keep the shared extension: it may predate this migration or serve other schemas.
    }
};
