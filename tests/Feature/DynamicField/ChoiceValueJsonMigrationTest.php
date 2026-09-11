<?php

namespace Tests\Feature\DynamicField;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DF-3-REST-C1: Migration value_json up/down auf SQLite und MySQL.
 */
class ChoiceValueJsonMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $tables = [
        'calculation_field_values',
        'calculation_position_field_values',
        'dispo_order_field_values',
        'dispo_order_position_field_values',
    ];

    public function test_value_json_columns_exist_and_are_nullable(): void
    {
        foreach ($this->tables as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'value_json'), $table);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            foreach ($this->tables as $table) {
                $column = DB::selectOne(
                    'SELECT IS_NULLABLE as is_nullable, DATA_TYPE as data_type
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                       AND COLUMN_NAME = ?',
                    [$table, 'value_json'],
                );
                $this->assertNotNull($column);
                $this->assertSame('YES', $column->is_nullable);
                // Laravel `json()` → MySQL `json`; unter MariaDB (XAMPP/CI-lokal)
                // wie bei options_json typischerweise `longtext`.
                $this->assertContains(
                    strtolower((string) $column->data_type),
                    ['json', 'longtext'],
                    $table.' value_json DATA_TYPE',
                );
            }
        }
    }

    public function test_migration_down_and_up_are_symmetric(): void
    {
        $migration = require database_path(
            'migrations/2026_09_11_180000_add_value_json_to_dynamic_field_value_tables.php',
        );

        $migration->down();
        foreach ($this->tables as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'value_json'), $table);
        }

        $migration->up();
        foreach ($this->tables as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'value_json'), $table);
        }
    }

    public function test_existing_scalar_rows_unchanged_when_value_json_null(): void
    {
        if (! Schema::hasTable('calculation_field_values')) {
            $this->markTestSkipped('Wertetabelle fehlt.');
        }

        // Spalte existiert nach RefreshDatabase; NULL ist Default ohne Backfill.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $default = DB::selectOne("PRAGMA table_info('calculation_field_values')");
            $this->assertNotNull($default);
        }

        foreach ($this->tables as $table) {
            $count = DB::table($table)->whereNotNull('value_json')->count();
            $this->assertSame(0, $count, $table);
        }
    }
}
