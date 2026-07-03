<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class QuestionDiagramService
{
    private const DISK = 'public';

    private const DIRECTORY = 'question-diagrams';

    public function attachFromPath(Question $question, string $sourcePath): string
    {
        $this->deletePath($question->diagram_path);

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'png');
        $filename = Str::uuid()->toString().'.'.$extension;
        $destination = self::DIRECTORY.'/'.$question->id.'/'.$filename;

        Storage::disk(self::DISK)->copy($sourcePath, $destination);
        $question->update(['diagram_path' => $destination]);

        return $destination;
    }

    public function attach(Question $question, UploadedFile $file): string
    {
        $this->deletePath($question->diagram_path);

        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs(
            self::DIRECTORY.'/'.$question->id,
            $filename,
            self::DISK,
        );

        $question->update(['diagram_path' => $path]);

        return $path;
    }

    public function deleteForQuestion(Question $question): void
    {
        $this->deletePath($question->diagram_path);
        $question->update(['diagram_path' => null]);
    }

    public function deletePath(?string $path): void
    {
        if (! $path) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
