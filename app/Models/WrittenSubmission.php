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

    protected $fillable = [
        'set_assignment_id',
        'status',
        'upload_paths',
        'score',
        'max_score',
        'ai_summary',
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
        return collect($this->upload_paths ?? [])
            ->map(fn (string $path) => Storage::disk('public')->url($path))
            ->values()
            ->all();
    }
}
