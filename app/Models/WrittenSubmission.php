<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class WrittenSubmission extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_GRADED = 'graded';

    public const STATUS_FAILED = 'failed';

    public const HANDWRITING_VERY_GOOD = 'very_good';

    public const HANDWRITING_GOOD = 'good';

    public const HANDWRITING_OK = 'ok';

    public const HANDWRITING_POOR = 'poor';

    public const HANDWRITING_VERY_POOR = 'very_poor';

    /**
     * @return list<string>
     */
    public static function handwritingRatings(): array
    {
        return [
            self::HANDWRITING_VERY_GOOD,
            self::HANDWRITING_GOOD,
            self::HANDWRITING_OK,
            self::HANDWRITING_POOR,
            self::HANDWRITING_VERY_POOR,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function handwritingLabels(): array
    {
        return [
            self::HANDWRITING_VERY_GOOD => 'Very good',
            self::HANDWRITING_GOOD => 'Good',
            self::HANDWRITING_OK => 'OK',
            self::HANDWRITING_POOR => 'Poor',
            self::HANDWRITING_VERY_POOR => 'Very poor',
        ];
    }

    public function handwritingLabel(): ?string
    {
        if (! $this->handwriting_rating) {
            return null;
        }

        return self::handwritingLabels()[$this->handwriting_rating] ?? $this->handwriting_rating;
    }

    protected $fillable = [
        'set_assignment_id',
        'status',
        'upload_paths',
        'score',
        'max_score',
        'ai_summary',
        'handwriting_rating',
        'teacher_remarks',
        'grading_error',
        'uploaded_at',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'upload_paths' => 'array',
            'uploaded_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SetAssignment::class, 'set_assignment_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WrittenSubmissionItem::class)->orderBy('question_number');
    }

    /**
     * @return list<string>
     */
    public function uploadUrls(): array
    {
        return collect($this->uploadFiles())
            ->pluck('url')
            ->values()
            ->all();
    }

    /**
     * @return list<array{url: string, kind: string, label: string}>
     */
    public function uploadFiles(): array
    {
        return collect($this->upload_paths ?? [])
            ->values()
            ->map(function (string $path, int $index) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
                $kind = $extension === 'pdf' ? 'pdf' : 'image';

                return [
                    'url' => Storage::disk('public')->url($path),
                    'kind' => $kind,
                    'label' => 'Page '.($index + 1).($kind === 'pdf' ? ' (PDF)' : ''),
                    'page' => $index + 1,
                ];
            })
            ->all();
    }

    /**
     * Persisted raster pages used for AI grading (photos + PDF page renders).
     *
     * @return list<array{url: string, kind: string, label: string, page: int, path: string}>
     */
    public function gradingPageFiles(): array
    {
        $directory = 'written-submissions/'.$this->id.'/grading-pages';

        if (! Storage::disk('public')->exists($directory)) {
            return [];
        }

        return collect(Storage::disk('public')->files($directory))
            ->filter(fn (string $path) => preg_match('/\.(jpe?g|png|webp|gif)$/i', $path) === 1)
            ->sort()
            ->values()
            ->map(function (string $path, int $index) {
                return [
                    'url' => Storage::disk('public')->url($path),
                    'kind' => 'image',
                    'label' => 'Page '.($index + 1),
                    'page' => $index + 1,
                    'path' => $path,
                ];
            })
            ->all();
    }
}
