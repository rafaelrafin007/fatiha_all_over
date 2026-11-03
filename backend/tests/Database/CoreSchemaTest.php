<?php

namespace Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Opt-in PostgreSQL migration verification; never RefreshDatabase or migrate:fresh.
 * All DDL and synthetic constraint fixtures are confined to a rolled-back schema.
 */
class CoreSchemaTest extends TestCase
{
    private const TABLES = [
        'categories', 'products', 'product_variants', 'product_options',
        'product_option_values', 'variant_option_values', 'product_images', 'pricing_rules',
    ];

    private array $migrationFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_SCHEMA_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_SCHEMA_TESTS=1 to opt in to local PostgreSQL schema verification.');
        }

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $identity = DB::selectOne('SELECT current_database() AS database, current_user AS role');
        $this->assertSame('fatihaallover_dev', $identity->database);
        $this->assertSame('fatihaallover_app', $identity->role);

        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement("SET LOCAL statement_timeout = '15s'");
        $schema = 'verify_core_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA '.$schema);
        DB::statement('SET LOCAL search_path TO '.$schema.', public');
        $this->migrationFiles = glob(database_path('migrations/2026_11_01_*.php'));
        sort($this->migrationFiles);
        $this->assertCount(6, $this->migrationFiles);
        foreach ($this->migrationFiles as $file) {
            (require $file)->up();
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app && DB::connection()->transactionLevel() > 0) {
                DB::rollBack(0);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_tables_columns_types_nullability_and_identity_match_the_plan(): void
    {
        $this->assertEqualsCanonicalizing(self::TABLES, $this->tables());
        $expected = [
            'categories' => ['parent_id?', 'name', 'slug', 'description?', 'is_active', 'sort_order', 'deleted_at?'],
            'products' => ['category_id', 'name', 'slug', 'short_description?', 'description', 'status', 'seo_title?', 'seo_description?', 'published_at?', 'deleted_at?'],
            'product_variants' => ['product_id', 'sku', 'name', 'option_signature', 'base_price_minor', 'currency', 'weight_grams?', 'is_active', 'pricing_revision', 'deleted_at?'],
            'product_options' => ['product_id', 'name', 'sort_order'],
            'product_option_values' => ['product_id', 'product_option_id', 'value', 'sort_order'],
            'variant_option_values' => ['product_id', 'product_variant_id', 'product_option_id', 'product_option_value_id'],
            'product_images' => ['product_id', 'product_variant_id?', 'cloudinary_asset_id', 'cloudinary_public_id', 'resource_type', 'version', 'secure_url', 'alt_text?', 'sort_order', 'is_primary'],
            'pricing_rules' => ['product_variant_id', 'min_quantity', 'max_quantity?', 'unit_price_minor', 'is_active'],
        ];

        foreach ($expected as $table => $fields) {
            $fields = array_merge(['id', 'created_at', 'updated_at'], $fields);
            $columns = collect(DB::select('SELECT column_name, data_type, is_nullable, is_identity, identity_generation, datetime_precision FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [$table]))->keyBy('column_name');
            $this->assertEqualsCanonicalizing(array_map(fn ($field) => rtrim($field, '?'), $fields), $columns->keys()->all());
            foreach ($fields as $field) {
                $name = rtrim($field, '?');
                $column = $columns[$name];
                $this->assertSame(str_ends_with($field, '?') ? 'YES' : 'NO', $column->is_nullable, $table.'.'.$name);
                $type = match (true) {
                    $name === 'id', str_ends_with($name, '_id'), str_ends_with($name, '_minor'), $name === 'version' => str_starts_with($name, 'cloudinary_') ? 'character varying' : 'bigint',
                    str_ends_with($name, '_at') => 'timestamp with time zone',
                    str_starts_with($name, 'is_') => 'boolean',
                    in_array($name, ['sort_order', 'weight_grams', 'pricing_revision', 'min_quantity', 'max_quantity']) => 'integer',
                    $name === 'currency' => 'character',
                    in_array($name, ['description', 'short_description', 'secure_url']) => 'text',
                    default => 'character varying',
                };
                $this->assertSame($type, $column->data_type, $table.'.'.$name);
                if (str_ends_with($name, '_at')) {
                    $this->assertSame(6, $column->datetime_precision);
                }
            }
            $this->assertSame('YES', $columns['id']->is_identity);
            $this->assertSame('BY DEFAULT', $columns['id']->identity_generation);
        }
    }

    public function test_foreign_keys_uniqueness_checks_and_indexes_are_present(): void
    {
        $constraints = collect(DB::select('SELECT c.conname, c.contype, c.confdeltype, c.confupdtype, c.convalidated, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname=current_schema()'));
        foreach (['p' => 8, 'f' => 10, 'u' => 11, 'c' => 27, 'x' => 1] as $type => $count) {
            $this->assertCount($count, $constraints->where('contype', $type));
        }
        foreach ($constraints->where('contype', 'f') as $foreign) {
            $this->assertSame('r', $foreign->confdeltype, $foreign->conname);
            $this->assertSame('a', $foreign->confupdtype, $foreign->conname);
            $this->assertTrue($foreign->convalidated);
        }
        $definitions = $constraints->keyBy('conname');
        $this->assertStringContainsString('product_option_values(id, product_option_id, product_id)', $definitions['variant_values_value_option_product_fk']->definition);
        $this->assertStringContainsString('product_variants(id, product_id)', $definitions['product_images_variant_product_fk']->definition);
        $this->assertStringContainsString('EXCLUDE USING gist', $definitions['pricing_rules_active_ranges_exclude']->definition);
        $this->assertStringContainsString('WHERE (is_active)', $definitions['pricing_rules_active_ranges_exclude']->definition);

        $indexes = collect(DB::select('SELECT indexname, indexdef FROM pg_indexes WHERE schemaname=current_schema()'))->keyBy('indexname');
        $this->assertCount(34, $indexes);
        foreach (['products_category_status_id_live_index', 'product_images_primary_product_unique', 'product_images_primary_variant_unique'] as $name) {
            $this->assertStringContainsString('WHERE', $indexes[$name]->indexdef);
        }
        $this->assertStringContainsString('deleted_at IS NULL', $indexes['products_category_status_id_live_index']->indexdef);
        $this->assertStringContainsString('product_variant_id IS NULL', $indexes['product_images_primary_product_unique']->indexdef);
        $this->assertStringContainsString('product_variant_id IS NOT NULL', $indexes['product_images_primary_variant_unique']->indexdef);
    }

    public function test_catalog_identity_defaults_checks_and_restrict_deletes(): void
    {
        [$category, $product, $variant] = $this->catalog();
        $this->assertSame('draft', DB::table('products')->where('id', $product)->value('status'));
        $this->assertSame(1, DB::table('product_variants')->where('id', $variant)->value('pricing_revision'));
        $this->assertFalse(DB::table('categories')->where('id', $category)->value('is_active'));
        $this->reject('23001', fn () => DB::table('categories')->where('id', $category)->delete());
        $this->reject('23001', fn () => DB::table('products')->where('id', $product)->delete());
        $this->reject('23514', fn () => DB::table('categories')->where('id', $category)->update(['parent_id' => $category]));
        $this->reject('23514', fn () => DB::table('categories')->where('id', $category)->update(['sort_order' => -1]));
        $this->reject('23514', fn () => DB::table('products')->where('id', $product)->update(['status' => 'unknown']));
        $this->reject('23514', fn () => DB::table('product_variants')->where('id', $variant)->update(['sku' => ' lower ']));
        foreach (['base_price_minor' => -1, 'weight_grams' => 0, 'pricing_revision' => 0, 'currency' => 'USD'] as $column => $value) {
            $this->reject('23514', fn () => DB::table('product_variants')->where('id', $variant)->update([$column => $value]));
        }
        DB::table('product_variants')->where('id', $variant)->update(['base_price_minor' => 0]);
        $this->reject('23514', fn () => DB::table('product_variants')->where('id', $variant)->update(['is_active' => true]));
        $this->reject('23505', fn () => $this->insert('product_variants', ['product_id' => $product, 'sku' => 'OTHER-SKU', 'name' => 'Duplicate option combination', 'option_signature' => 'default', 'base_price_minor' => 100]));
        $otherProduct = $this->insert('products', ['category_id' => $category, 'name' => 'Other', 'slug' => 'other', 'description' => 'Test only']);
        $this->reject('23505', fn () => $this->insert('product_variants', ['product_id' => $otherProduct, 'sku' => 'TEST-SKU', 'name' => 'Duplicate SKU', 'option_signature' => 'default', 'base_price_minor' => 100]));
        $this->assertSame(0, DB::table('variant_option_values')->count());
    }

    public function test_composite_foreign_keys_prevent_cross_product_option_assignments(): void
    {
        [$category, $product, $variant] = $this->catalog();
        $other = $this->insert('products', ['category_id' => $category, 'name' => 'Other', 'slug' => 'other', 'description' => 'Test only']);
        $option = $this->insert('product_options', ['product_id' => $product, 'name' => 'Size']);
        $value = $this->insert('product_option_values', ['product_id' => $product, 'product_option_id' => $option, 'value' => 'Large']);
        $this->reject('23503', fn () => $this->insert('product_option_values', ['product_id' => $other, 'product_option_id' => $option, 'value' => 'Cross product']));
        $selection = ['product_id' => $product, 'product_variant_id' => $variant, 'product_option_id' => $option, 'product_option_value_id' => $value];
        $this->insert('variant_option_values', $selection);
        $this->reject('23505', fn () => $this->insert('variant_option_values', $selection));
        $this->reject('23503', fn () => DB::table('variant_option_values')->update(['product_id' => $other]));
        $this->reject('23001', fn () => DB::table('product_option_values')->where('id', $value)->delete());
        $this->assertSame(1, DB::table('variant_option_values')->count());
    }

    public function test_media_primary_uniqueness_and_same_product_variant_integrity(): void
    {
        [$category, $product, $variant] = $this->catalog();
        $image = ['product_id' => $product, 'cloudinary_asset_id' => 'test-asset', 'cloudinary_public_id' => 'test/image', 'resource_type' => 'image', 'version' => 1, 'secure_url' => 'https://example.invalid/image', 'is_primary' => true];
        $id = $this->insert('product_images', $image);
        $this->reject('23505', fn () => $this->insert('product_images', array_replace($image, ['cloudinary_asset_id' => 'test-second'])));
        $variantImage = array_replace($image, ['cloudinary_asset_id' => 'test-variant', 'product_variant_id' => $variant]);
        $this->insert('product_images', $variantImage);
        $this->reject('23505', fn () => $this->insert('product_images', array_replace($variantImage, ['cloudinary_asset_id' => 'test-variant-duplicate'])));
        $other = $this->insert('products', ['category_id' => $category, 'name' => 'Other', 'slug' => 'other', 'description' => 'Test only']);
        $this->reject('23503', fn () => $this->insert('product_images', array_replace($variantImage, ['product_id' => $other, 'cloudinary_asset_id' => 'cross-product', 'is_primary' => false])));
        $this->reject('23514', fn () => DB::table('product_images')->where('id', $id)->update(['secure_url' => 'http://example.invalid/image']));
        $this->reject('23514', fn () => DB::table('product_images')->where('id', $id)->update(['resource_type' => 'raw']));
        $this->assertSame(2, DB::table('product_images')->count());
    }

    public function test_pricing_ranges_support_adjacent_unbounded_and_inactive_tiers(): void
    {
        [, $product, $variant] = $this->catalog();
        $tier = ['product_variant_id' => $variant, 'unit_price_minor' => 80000, 'is_active' => true];
        $this->insert('pricing_rules', $tier + ['min_quantity' => 1, 'max_quantity' => 5]);
        $this->insert('pricing_rules', $tier + ['min_quantity' => 6, 'max_quantity' => 49]);
        $this->insert('pricing_rules', $tier + ['min_quantity' => 50, 'max_quantity' => null]);
        $this->reject('23P01', fn () => $this->insert('pricing_rules', $tier + ['min_quantity' => 5, 'max_quantity' => 6]));
        $this->reject('23P01', fn () => $this->insert('pricing_rules', $tier + ['min_quantity' => 1000, 'max_quantity' => null]));
        $inactive = $this->insert('pricing_rules', array_replace($tier, ['min_quantity' => 1, 'max_quantity' => null, 'is_active' => false, 'unit_price_minor' => 0]));
        $this->reject('23P01', fn () => DB::table('pricing_rules')->where('id', $inactive)->update(['is_active' => true, 'unit_price_minor' => 1]));
        $other = $this->insert('product_variants', ['product_id' => $product, 'sku' => 'OTHER-SKU', 'name' => 'Other package', 'option_signature' => 'package:2', 'base_price_minor' => 100]);
        $this->insert('pricing_rules', array_replace($tier, ['product_variant_id' => $other, 'min_quantity' => 1, 'max_quantity' => 2147483647]));
        $this->assertSame(5, DB::table('pricing_rules')->count());
        $this->reject('23514', fn () => $this->insert('pricing_rules', ['product_variant_id' => $other, 'min_quantity' => 0, 'unit_price_minor' => 1]));
        $this->reject('23514', fn () => $this->insert('pricing_rules', ['product_variant_id' => $other, 'min_quantity' => 1, 'max_quantity' => 0, 'unit_price_minor' => 1]));
        $this->reject('23514', fn () => $this->insert('pricing_rules', ['product_variant_id' => $other, 'min_quantity' => 1, 'unit_price_minor' => -1]));
        $this->reject('23514', fn () => $this->insert('pricing_rules', ['product_variant_id' => $other, 'min_quantity' => 1, 'unit_price_minor' => 0, 'is_active' => true]));
    }

    public function test_all_new_migrations_reverse_and_reapply_without_dropping_the_database(): void
    {
        $before = $this->tables();
        foreach (array_reverse($this->migrationFiles) as $file) {
            (require $file)->down();
        }
        $this->assertSame([], $this->tables());
        $this->assertNotNull(DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'btree_gist'"));
        foreach ($this->migrationFiles as $file) {
            (require $file)->up();
        }
        $this->assertSame($before, $this->tables());
    }

    public function test_deployed_schema_and_migration_history_match_the_verified_migrations(): void
    {
        $isolated = DB::selectOne('SELECT current_schema() AS name')->name;
        $this->assertSame($this->structure($isolated), $this->structure('public'));
        $files = array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php')));
        $recorded = array_column(DB::select('SELECT migration FROM public.migrations ORDER BY migration'), 'migration');
        $this->assertEqualsCanonicalizing($files, $recorded);
        $this->assertNotNull(DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'btree_gist'"));
    }

    private function structure(string $schema): array
    {
        $placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));
        $bindings = array_merge([$schema], self::TABLES);
        $columns = DB::select("SELECT table_name, column_name, data_type, character_maximum_length, numeric_precision, numeric_scale, datetime_precision, is_nullable, column_default, is_identity, identity_generation FROM information_schema.columns WHERE table_schema = ? AND table_name IN ($placeholders) ORDER BY table_name, ordinal_position", $bindings);
        $constraints = DB::select("SELECT t.relname AS table_name, c.conname, c.contype, c.confdeltype, c.confupdtype, c.convalidated, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname = ? AND t.relname IN ($placeholders) ORDER BY t.relname, c.conname", $bindings);
        $indexes = DB::select("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = ? AND tablename IN ($placeholders) ORDER BY tablename, indexname", $bindings);
        $foreignParents = DB::select("SELECT pn.nspname AS parent_schema FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace JOIN pg_class p ON p.oid=c.confrelid JOIN pg_namespace pn ON pn.oid=p.relnamespace WHERE c.contype='f' AND n.nspname = ? AND t.relname IN ($placeholders)", $bindings);
        foreach ($foreignParents as $parent) {
            $this->assertSame($schema, $parent->parent_schema);
        }

        // Ignore schema qualification only; compare every actual column, constraint and index definition.
        return array_map(function ($rows) {
            return array_map(function ($row) {
                return array_map(fn ($value) => is_string($value) ? preg_replace('/\b(?:public|verify_core_[a-f0-9]+)\./', '', $value) : $value, (array) $row);
            }, $rows);
        }, [$columns, $constraints, $indexes]);
    }

    private function tables(): array
    {
        return array_column(DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'), 'tablename');
    }

    private function insert(string $table, array $values): int
    {
        // Explicit timestamps keep fixtures independent of Eloquent/defaults; no persisted seed data.
        return DB::table($table)->insertGetId($values + ['created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-01T00:00:00Z']);
    }

    private function catalog(): array
    {
        $category = $this->insert('categories', ['name' => 'Constraint fixture', 'slug' => 'constraint-fixture']);
        $product = $this->insert('products', ['category_id' => $category, 'name' => 'Constraint fixture', 'slug' => 'constraint-fixture', 'description' => 'Rolled back after test']);
        $variant = $this->insert('product_variants', ['product_id' => $product, 'sku' => 'TEST-SKU', 'name' => 'Default', 'option_signature' => 'default', 'base_price_minor' => 80000]);

        return [$category, $product, $variant];
    }

    private function reject(string $sqlState, callable $operation): void
    {
        DB::beginTransaction(); // Savepoint keeps the outer transaction usable after a rejected statement.
        try {
            $operation();
            $this->fail('Expected database rejection '.$sqlState);
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
    }
}
