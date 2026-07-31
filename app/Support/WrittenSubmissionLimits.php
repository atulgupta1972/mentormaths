<?php

namespace App\Support;

class WrittenSubmissionLimits
{
    public const MAX_FILES = 15;

    public const MAX_FILE_KB = 20480;

    public static function maxFilesRule(): string
    {
        return 'max:'.self::MAX_FILES;
    }

    public static function maxFileSizeRule(): string
    {
        return 'max:'.self::MAX_FILE_KB;
    }
}
