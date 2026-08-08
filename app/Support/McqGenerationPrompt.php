<?php

namespace App\Support;

class McqGenerationPrompt
{
    /**
     * Instruction block for AI/Cursor MCQ generation — avoids every question using option A.
     */
    public const VARY_CORRECT_OPTION_RULE = <<<'TXT'
- Vary the correct option across A, B, C, and D — do NOT default every question to option A (correct_index 0). Spread answers roughly evenly (~25% each letter); avoid long runs of the same letter unless the source PDF explicitly marks them that way.
TXT;
}
