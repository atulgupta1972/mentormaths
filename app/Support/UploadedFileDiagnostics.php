<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UploadedFileDiagnostics
{
    public static function assertValid(UploadedFile $file, string $field = 'file'): void
    {
        if ($file->isValid()) {
            return;
        }

        $message = match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large for the server upload limit (often 2 MB on hosting). '
                .'Set PHP upload_max_filesize and post_max_size to at least 20M on the server, then try again.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was received. Please choose the PDF again.',
            default => 'The file did not reach the server. Please try again.',
        };

        throw ValidationException::withMessages([$field => $message]);
    }
}
