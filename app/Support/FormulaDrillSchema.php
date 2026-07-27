<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

class FormulaDrillSchema
{
    public static function isReady(): bool
    {
        return Schema::hasTable('formula_drill_sessions')
            && Schema::hasTable('formula_question_stats')
            && Schema::hasColumn('questions', 'formula_drill_scope');
    }
}
