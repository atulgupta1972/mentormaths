<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLoginDay extends Model
{
    protected $fillable = [
        'user_id',
        'login_date',
    ];

    protected function casts(): array
    {
        return [
            'login_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
