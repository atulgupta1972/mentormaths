<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Worksheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WrittenSheetPdfService
{
    /**
     * Generate and store a printable PDF for a written worksheet.
     */
    public function generate(Worksheet $worksheet): string
    {
        $worksheet->load([
            'questions.options',
            'questions.blankAnswer',
            'topic.chapter.syllabusVersion.gradeLevel',
            'topic.chapter.syllabusVersion.board',
            'chapter.syllabusVersion.gradeLevel',
            'chapter.syllabusVersion.board',
        ]);

        $questions = $worksheet->questions->values()->map(function (Question $question, int $index) {
            $options = $question->isMcq()
                ? $question->options->values()->map(fn ($option, $optionIndex) => [
                    'letter' => chr(65 + $optionIndex),
                    'text' => $this->plainText($option->option_text),
                ])->all()
                : [];

            return [
                'number' => $index + 1,
                'text' => $this->plainText($question->question_text),
                'diagram_path' => $question->diagram_path
                    ? storage_path('app/public/'.$question->diagram_path)
                    : null,
                'type' => $question->type,
                'options' => $options,
            ];
        })->all();

        $chapter = $worksheet->isChapterScope()
            ? $worksheet->chapter
            : $worksheet->topic?->chapter;

        $syllabus = $chapter?->syllabusVersion;

        $pdf = Pdf::loadView('reports.written-sheet-pdf', [
            'worksheet' => $worksheet,
            'questions' => $questions,
            'className' => $syllabus?->gradeLevel?->name,
            'boardCode' => $syllabus?->board?->code,
            'chapterName' => $chapter?->name,
            'topicName' => $worksheet->topic?->name,
            'kindLabel' => $worksheet->isChapterTest() ? 'Test' : 'Practice',
        ]);

        $directory = 'written-sheets/'.$worksheet->id;
        Storage::disk('public')->makeDirectory($directory);

        $filename = Str::slug($worksheet->set_code ?: 'sheet').'-'.now()->format('YmdHis').'.pdf';
        $path = $directory.'/'.$filename;

        if ($worksheet->written_pdf_path) {
            Storage::disk('public')->delete($worksheet->written_pdf_path);
        }

        Storage::disk('public')->put($path, $pdf->output());

        return $path;
    }

    public function plainText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
