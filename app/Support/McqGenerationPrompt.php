<?php

namespace App\Support;

class McqGenerationPrompt
{
    /**
     * Instruction block for AI/Cursor MCQ generation — avoids every question using option A.
     */
    public const VARY_CORRECT_OPTION_RULE = <<<'TXT'
- Vary the correct option across A–H — do NOT default every question to option A (correct_index 0). Spread answers roughly evenly; avoid long runs of the same letter unless the source PDF explicitly marks them that way.
TXT;

    public const EIGHT_OPTION_RULE = <<<'TXT'
- Exactly 8 options per MCQ (A through H). Include plausible distractors so students must work out the answer — not guess from 4 choices.
TXT;
}
