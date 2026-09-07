<?php

use App\Models\FieldSetAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.3a1 / DYN-002: Field-Set-Assignments.
 *
 * Eindeutigkeit: normalisierte NOT-NULL-Spalte `target_identity`
 * (g | c:{id} | m:{id}) statt nullable Unique/COALESCE – SQLite und MySQL
 * erlauben sonst mehrere NULL-Duplikate. DB-Constraint fängt Race Conditions.
 *
 * Ziel-XOR: MySQL CHECK; SQLite BEFORE INSERT/UPDATE Trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_set_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_set_id')
                ->constrained('field_sets')
                ->restrictOnDelete();
            $table->string('target_layer', 32);
            $table->foreignId('advertising_category_id')
                ->nullable()
                ->constrained('advertising_categories')
                ->restrictOnDelete();
            $table->foreignId('advertising_medium_id')
                ->nullable()
                ->constrained('advertising_media')
                ->restrictOnDelete();
            /** @see FieldSetAssignment::buildTargetIdentity() */
            $table->string('target_identity', 64);
            $table->string('applies_to_process', 32);
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(
                ['field_set_id', 'applies_to_process', 'target_identity'],
                'field_set_assignment_target_unique',
            );
            $table->index(['target_layer', 'is_active', 'sort'], 'field_set_assignment_lookup_idx');
            $table->index(['advertising_category_id', 'is_active'], 'field_set_assignment_category_idx');
            $table->index(['advertising_medium_id', 'is_active'], 'field_set_assignment_medium_idx');
        });

        $this->installTargetXorGuards();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS field_set_assignment_target_xor_insert');
            DB::statement('DROP TRIGGER IF EXISTS field_set_assignment_target_xor_update');
        }

        // MySQL/MariaDB: CHECK fällt mit der Tabelle weg (kein separates DROP CHECK –
        // MariaDB < 10.5 kennt DROP CHECK nicht).
        Schema::dropIfExists('field_set_assignments');
    }

    private function installTargetXorGuards(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
ALTER TABLE field_set_assignments
ADD CONSTRAINT field_set_assignment_target_xor
CHECK (
    (
        target_layer = 'global'
        AND advertising_category_id IS NULL
        AND advertising_medium_id IS NULL
    )
    OR (
        target_layer = 'advertising_category'
        AND advertising_category_id IS NOT NULL
        AND advertising_medium_id IS NULL
    )
    OR (
        target_layer = 'advertising_medium'
        AND advertising_medium_id IS NOT NULL
        AND advertising_category_id IS NULL
    )
)
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            $predicate = <<<'SQL'
(
    (
        NEW.target_layer = 'global'
        AND NEW.advertising_category_id IS NULL
        AND NEW.advertising_medium_id IS NULL
        AND NEW.target_identity = 'g'
    )
    OR (
        NEW.target_layer = 'advertising_category'
        AND NEW.advertising_category_id IS NOT NULL
        AND NEW.advertising_medium_id IS NULL
        AND NEW.target_identity = ('c:' || CAST(NEW.advertising_category_id AS TEXT))
    )
    OR (
        NEW.target_layer = 'advertising_medium'
        AND NEW.advertising_medium_id IS NOT NULL
        AND NEW.advertising_category_id IS NULL
        AND NEW.target_identity = ('m:' || CAST(NEW.advertising_medium_id AS TEXT))
    )
)
SQL;

            DB::statement(<<<SQL
CREATE TRIGGER field_set_assignment_target_xor_insert
BEFORE INSERT ON field_set_assignments
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT {$predicate}
        THEN RAISE(ABORT, 'field_set_assignment_target_xor')
    END;
END;
SQL);

            DB::statement(<<<SQL
CREATE TRIGGER field_set_assignment_target_xor_update
BEFORE UPDATE ON field_set_assignments
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT {$predicate}
        THEN RAISE(ABORT, 'field_set_assignment_target_xor')
    END;
END;
SQL);
        }
    }
};
