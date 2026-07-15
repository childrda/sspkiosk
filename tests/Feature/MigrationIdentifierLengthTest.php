<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrated_indexes_and_foreign_keys_fit_mysql_identifier_limit(): void
    {
        $limit = 64;
        $violations = [];

        foreach (Schema::getTableListing() as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $name = (string) ($index['name'] ?? '');
                if ($name !== '' && strlen($name) > $limit) {
                    $violations[] = "index {$table}.{$name} (".strlen($name).' chars)';
                }
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $name = (string) ($foreignKey['name'] ?? '');
                if ($name !== '' && strlen($name) > $limit) {
                    $violations[] = "foreign {$table}.{$name} (".strlen($name).' chars)';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "MySQL identifiers must be <= {$limit} characters:\n".implode("\n", $violations),
        );
    }

    public function test_password_reset_revisions_uses_short_explicit_constraint_names(): void
    {
        $indexNames = collect(Schema::getIndexes('password_reset_revisions'))
            ->pluck('name')
            ->all();
        $foreignNames = collect(Schema::getForeignKeys('password_reset_revisions'))
            ->pluck('name')
            ->all();

        $this->assertContains('prr_revision_number_unique', $indexNames);
        $this->assertContains('prr_active_unique', $indexNames);

        foreach (array_merge($indexNames, $foreignNames) as $name) {
            if ($name === null || $name === '') {
                continue;
            }
            $this->assertLessThanOrEqual(64, strlen((string) $name), (string) $name);
            $this->assertStringNotContainsString(
                'password_reset_revisions_password_reset_request_id_revision_number',
                (string) $name,
            );
        }

        // Static confirmation the migration defines the short FK/index names (MySQL deploy path).
        $migration = file_get_contents(database_path('migrations/2026_05_29_000017_create_password_reset_revisions_table.php'));
        $this->assertStringContainsString("'prr_request_fk'", $migration);
        $this->assertStringContainsString("'prr_revision_number_unique'", $migration);
        $this->assertStringContainsString("'prr_active_unique'", $migration);
    }

    public function test_active_for_request_id_is_nullable_scalar_not_generated_column(): void
    {
        $columns = collect(Schema::getColumns('password_reset_revisions'))
            ->keyBy('name');

        $this->assertTrue($columns->has('active_for_request_id'));
        $column = $columns->get('active_for_request_id');
        $this->assertTrue((bool) ($column['nullable'] ?? false));

        // Guard is app-managed + unique index, not a MySQL/SQLite generated expression.
        $this->assertTrue(
            empty($column['generation']) && empty($column['extra']),
            'active_for_request_id must remain a plain nullable column for MySQL compatibility',
        );
    }
}
