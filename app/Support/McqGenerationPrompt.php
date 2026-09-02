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

    /**
     * NCERT Exemplar / textbook chapter import — prefer numeric fill-in-blank over MCQ.
     */
    public const FILL_BLANK_FIRST_RULE = <<<'TXT'
FILL-IN-BLANK FIRST (critical):
- Our platform auto-converts numeric answers to fill-in-blank. Maximize fill-blank, minimize MCQ.
- When the answer is a number (with or without a unit), write the question with "____" and put ONLY the bare number in the correct option — never "120 m" or "55 cm" as an option; use "120" or "55" and state the unit in the question text.
  Good: "The vertical distance is ____ metres." → correct option "55" (not "55 m")
  Good: "Temperature falls by ____ °C." → correct option "12"
  Bad: options ["55 m", "35 m", …] — strip units from every option; put unit in the question.
- Optional JSON field `"answer_unit": "m"` when the answer needs a unit label in the stem (m, cm, km, kg, g, L, °C, %, etc.).
- Rewrite simple "find the value" MCQs as fill-blank stems even if the PDF shows multiple choice.
- Reserve MCQ (8 options) for: names, words, True/False, "which statement", ordering without a single number, or mixed fractions like 2 1/3.
TXT;

    public const EXEMPLAR_DIFFICULTY_RULE = <<<'TXT'
DIFFICULTY (NCERT Exemplar — medium to hard):
- Target Medium and Hard. Avoid trivial one-step drills unless they are worked examples from the PDF.
- Mark routine exercises "Medium"; starred (*), challenge, or multi-step items "Hard".
- Do not downgrade hard PDF questions to Easy.
TXT;

    public const EXEMPLAR_EXCLUDE_RULE = <<<'TXT'
EXCLUDE entirely (do not convert to questions):
- Column matching / match the columns / match Column I with Column II
- Match the following (letter ↔ number grids)
- Crossword, maze, cut-and-paste, or purely reflective "discuss" prompts with no single calculable answer
- Assertion–Reason (A/R) format unless you rewrite as one solvable numeric or single-choice MCQ
- Repeating the same calculation with only numbers changed — keep one representative version
TXT;
}
