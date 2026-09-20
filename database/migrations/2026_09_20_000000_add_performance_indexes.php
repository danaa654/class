<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERFORMANCE — indexes for the columns that the app's slowest list
 * pages and Dashboard aggregates actually filter, sort, or join on.
 * Every index below is guarded with hasIndex()/hasColumn() checks so
 * this migration is safe to run even if a column already picked up
 * an index elsewhere (e.g. section_subjects already has some from
 * 2026_09_10_090000_add_conflict_check_indexes_to_section_subjects_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexes('sections', [
            'academic_year',
            'semester',
            'year_level',
            'major_id',
            'curriculum_id',
            'is_finalized',
        ]);

        $this->addIndexes('section_subjects', [
            'section_id',
            'subject_id',
            'faculty_id',
            'room_id',
            'status',
            'edp_code',
        ]);

        $this->addIndexes('faculty', [
            'college_id',
            'department_id',
            'status',
        ]);

        $this->addIndexes('rooms', [
            'college_id',
            'status',
        ]);

        $this->addIndexes('users', [
            'college_id',
            'is_active',
        ]);

        $this->addIndexes('subjects', [
            'category',
            'status',
        ]);

        $this->addIndexes('curriculum_items', [
            'curriculum_id',
            'subject_id',
            'year_level',
        ]);

        $this->addIndexes('notifications', [
            'user_id',
            'read_at',
            'priority',
        ]);

        $this->addIndexes('activity_logs', [
            'user_id',
            'created_at',
        ]);

        $this->addIndexes('faculty_load_requests', [
            'faculty_id',
            'status',
        ]);
    }

    public function down(): void
    {
        $this->dropIndexes('sections', [
            'academic_year', 'semester', 'year_level', 'major_id', 'curriculum_id', 'is_finalized',
        ]);
        $this->dropIndexes('section_subjects', [
            'section_id', 'subject_id', 'faculty_id', 'room_id', 'status', 'edp_code',
        ]);
        $this->dropIndexes('faculty', ['college_id', 'department_id', 'status']);
        $this->dropIndexes('rooms', ['college_id', 'status']);
        $this->dropIndexes('users', ['college_id', 'is_active']);
        $this->dropIndexes('subjects', ['category', 'status']);
        $this->dropIndexes('curriculum_items', ['curriculum_id', 'subject_id', 'year_level']);
        $this->dropIndexes('notifications', ['user_id', 'read_at', 'priority']);
        $this->dropIndexes('activity_logs', ['user_id', 'created_at']);
        $this->dropIndexes('faculty_load_requests', ['faculty_id', 'status']);
    }

    /**
     * Add a single-column index for each column that exists on the
     * table and doesn't already have one, named the Laravel-default
     * way ({table}_{column}_index) so hasIndex() checks are reliable
     * on re-run.
     */
    private function addIndexes(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $indexName = "{$table}_{$column}_index";

            if ($this->hasIndex($table, $indexName)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName) {
                $blueprint->index($column, $indexName);
            });
        }
    }

    private function dropIndexes(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            $indexName = "{$table}_{$column}_index";

            if (! $this->hasIndex($table, $indexName)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $schemaManager = $connection->getDoctrineSchemaManager() ?? null;

        // Doctrine DBAL may not be present on newer Laravel installs —
        // fall back to a raw information_schema check for MySQL, which
        // is what this app runs on (config/database.php default).
        if ($schemaManager) {
            foreach ($schemaManager->listTableIndexes($table) as $index) {
                if (strtolower($index->getName()) === strtolower($indexName)) {
                    return true;
                }
            }

            return false;
        }

        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list(\"{$table}\")");

            foreach ($rows as $row) {
                if (strtolower($row->name) === strtolower($indexName)) {
                    return true;
                }
            }

            return false;
        }

        // MySQL / MariaDB
        $rows = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $indexName],
        );

        return count($rows) > 0;
    }
};