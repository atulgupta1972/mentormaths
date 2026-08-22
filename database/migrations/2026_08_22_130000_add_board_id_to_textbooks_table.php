<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('textbooks', 'board_id')) {
            Schema::table('textbooks', function (Blueprint $table) {
                $table->foreignId('board_id')
                    ->nullable()
                    ->after('grade_level_id')
                    ->constrained('boards')
                    ->nullOnDelete();
            });
        }

        $this->replaceGradeCodeUniqueWithBoardScopedUnique();

        // Claim each legacy book for the board used by most of its chapters.
        $rows = DB::table('textbook_chapters as tc')
            ->join('syllabus_chapters as sc', 'sc.id', '=', 'tc.syllabus_chapter_id')
            ->join('syllabus_versions as sv', 'sv.id', '=', 'sc.syllabus_version_id')
            ->whereNotNull('tc.textbook_id')
            ->whereNotNull('sv.board_id')
            ->groupBy('tc.textbook_id', 'sv.board_id')
            ->orderByDesc(DB::raw('count(*)'))
            ->get(['tc.textbook_id', 'sv.board_id', DB::raw('count(*) as c')]);

        $chosen = [];
        foreach ($rows as $row) {
            $textbookId = (int) $row->textbook_id;
            if (isset($chosen[$textbookId])) {
                continue;
            }
            $chosen[$textbookId] = (int) $row->board_id;
        }

        foreach ($chosen as $textbookId => $boardId) {
            DB::table('textbooks')->where('id', $textbookId)->update(['board_id' => $boardId]);
        }
    }

    public function down(): void
    {
        if ($this->hasUniqueIndex(['grade_level_id', 'board_id', 'code'])) {
            Schema::table('textbooks', function (Blueprint $table) {
                $table->dropUnique(['grade_level_id', 'board_id', 'code']);
            });
        }

        if (Schema::hasColumn('textbooks', 'board_id')) {
            Schema::table('textbooks', function (Blueprint $table) {
                $table->dropConstrainedForeignId('board_id');
            });
        }

        if (! $this->hasUniqueIndex(['grade_level_id', 'code'])) {
            Schema::table('textbooks', function (Blueprint $table) {
                $table->unique(['grade_level_id', 'code']);
            });
        }
    }

    private function replaceGradeCodeUniqueWithBoardScopedUnique(): void
    {
        if ($this->hasUniqueIndex(['grade_level_id', 'code'])) {
            // MySQL: unique on (grade_level_id, code) can be tied to the grade_level FK.
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                Schema::table('textbooks', function (Blueprint $table) {
                    $table->dropForeign(['grade_level_id']);
                });
            }

            Schema::table('textbooks', function (Blueprint $table) {
                $table->dropUnique(['grade_level_id', 'code']);
            });

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                Schema::table('textbooks', function (Blueprint $table) {
                    $table->foreign('grade_level_id')
                        ->references('id')
                        ->on('grade_levels')
                        ->cascadeOnDelete();
                });
            }
        }

        if (! $this->hasUniqueIndex(['grade_level_id', 'board_id', 'code'])) {
            Schema::table('textbooks', function (Blueprint $table) {
                $table->unique(['grade_level_id', 'board_id', 'code']);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasUniqueIndex(array $columns): bool
    {
        foreach (Schema::getIndexes('textbooks') as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }

            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
};
