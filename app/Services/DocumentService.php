<?php

namespace App\Services;

use App\Models\DocumentFile;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public const DISK = 'local'; // storage/app/private — never web-accessible

    /** Validate size and real content type (not just the extension). Returns the detected mime. */
    public function validate(UploadedFile $file, string $field = 'file'): string
    {
        $max = (int) Settings::get('max_upload_mb', 10) * 1024 * 1024;
        if (! $file->isValid()) {
            throw ValidationException::withMessages([$field => 'The upload failed. Please try again.']);
        }
        if ($file->getSize() > $max) {
            throw ValidationException::withMessages([$field => 'File is too large (limit '.Settings::get('max_upload_mb', 10).' MB).']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! isset(self::ALLOWED[$mime])) {
            throw ValidationException::withMessages([$field => 'Only PDF, JPG, JPEG or PNG files are allowed.']);
        }

        return $mime;
    }

    public function store(UploadedFile $file, string $category, ?Model $for, User $user): DocumentFile
    {
        $mime = $this->validate($file);

        return DB::transaction(function () use ($file, $mime, $category, $for, $user) {
            $doc = DocumentFile::create($this->attributes($file, $mime, $category, $for, $user) + [
                'group_uuid' => (string) Str::uuid(), 'version' => 1, 'is_current' => true,
            ]);
            ActivityLogService::log('document.uploaded', 'documents', "Uploaded document {$doc->original_name}", $doc, user: $user);

            return $doc;
        });
    }

    /** Replace a file, keeping the old one as a prior version. */
    public function replace(DocumentFile $current, UploadedFile $file, string $reason, User $user): DocumentFile
    {
        $mime = $this->validate($file);

        return DB::transaction(function () use ($current, $file, $mime, $reason, $user) {
            $current->forceFill(['is_current' => false])->save();
            $doc = DocumentFile::create($this->attributes($file, $mime, $current->category, $current->documentable, $user) + [
                'group_uuid' => $current->group_uuid,
                'version' => $current->version + 1,
                'is_current' => true,
                'replace_reason' => $reason,
            ]);
            ActivityLogService::log('document.replaced', 'documents', "Replaced document {$current->original_name} with version {$doc->version}",
                $doc, ['version' => $current->version], ['version' => $doc->version], $reason, $user);

            return $doc;
        });
    }

    public function link(DocumentFile $doc, ?Model $to, User $user): void
    {
        DocumentFile::where('group_uuid', $doc->group_uuid)->update([
            'documentable_type' => $to?->getMorphClass(),
            'documentable_id' => $to?->getKey(),
        ]);
        ActivityLogService::log('document.linked', 'documents', $to ? "Linked {$doc->original_name} to ".class_basename($to).' #'.$to->getKey() : "Unlinked {$doc->original_name}", $doc, user: $user);
    }

    private function attributes(UploadedFile $file, string $mime, string $category, ?Model $for, User $user): array
    {
        $ext = self::ALLOWED[$mime];
        $path = $file->storeAs('documents/'.now()->format('Y/m'), Str::uuid().'.'.$ext, self::DISK);

        return [
            'category' => $category,
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
            'path' => $path,
            'mime' => $mime,
            'size' => $file->getSize(),
            'documentable_type' => $for?->getMorphClass(),
            'documentable_id' => $for?->getKey(),
            'uploaded_by' => $user->id,
        ];
    }

    public function disk()
    {
        return Storage::disk(self::DISK);
    }
}
