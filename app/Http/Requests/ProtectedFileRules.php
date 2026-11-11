<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

class ProtectedFileRules
{
    public const MIME_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ];

    public const MAX_KILOBYTES = 10240;

    /** @return list<mixed> */
    public static function make(): array
    {
        return [
            'bail', 'required',
            File::types(array_keys(self::MIME_EXTENSIONS))->extensions(['pdf', 'jpg', 'jpeg', 'png'])->max(self::MAX_KILOBYTES),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid() || $value->getSize() < 1) {
                    $fail('Choose a non-empty PDF, JPEG or PNG file.');

                    return;
                }
                $mime = self::mime($value);
                $extension = mb_strtolower($value->getClientOriginalExtension());
                if (! in_array($extension, self::MIME_EXTENSIONS[$mime] ?? [], true)) {
                    $fail('The file contents must match its PDF, JPEG or PNG extension.');
                } elseif ($mime !== null && str_starts_with($mime, 'image/') && @getimagesize($value->getRealPath()) === false) {
                    $fail('The image contents could not be verified.');
                }
            },
        ];
    }

    public static function mime(UploadedFile $file): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        return is_string($mime) ? $mime : null;
    }
}
