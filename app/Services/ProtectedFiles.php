<?php

namespace App\Services;

use App\Http\Requests\ProtectedFileRules;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use RuntimeException;
use stdClass;
use Throwable;

class ProtectedFiles
{
    public function __construct(private AuditTrail $audit) {}

    public function upload(User $actor, UploadedFile $upload): stdClass
    {
        Validator::make(['file' => $upload], ['file' => ProtectedFileRules::make()])->validate();
        $mime = ProtectedFileRules::mime($upload);
        $extension = ProtectedFileRules::MIME_EXTENSIONS[$mime][0];
        $path = now('UTC')->format('Y/m/').Str::uuid().'.'.$extension;
        $disk = $this->disk();

        try {
            return DB::transaction(function () use ($actor, $upload, $mime, $extension, $path, $disk): stdClass {
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                Gate::forUser($actor)->authorize('files.upload');
                Gate::forUser($actor)->authorize('files.view_own');
                if (app()->bound('request')) {
                    request()->attributes->set('private_upload_path', $path);
                }
                $stored = $disk->putFileAs(dirname($path), $upload, basename($path), ['visibility' => 'private']);
                if ($stored !== $path) {
                    throw new RuntimeException('Protected file storage failed.');
                }
                $hash = hash_file('sha256', $upload->getRealPath());
                if ($hash === false) {
                    throw new RuntimeException('Unable to calculate the file checksum.');
                }
                $id = DB::table('files')->insertGetId([
                    'storage_path' => $path,
                    'original_name' => $this->safeName($upload->getClientOriginalName(), $extension),
                    'mime_type' => $mime,
                    'byte_size' => $upload->getSize(),
                    'sha256' => $hash,
                    'scan_status' => 'not_scanned',
                    'uploaded_by' => $actor->id,
                    'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
                $file = DB::table('files')->find($id);
                $stream = $this->verifiedStream($file);
                fclose($stream);
                $this->audit->record('file.uploaded', $actor->id, 'file', $id, [
                    'byte_size' => $upload->getSize(), 'mime_type' => $mime,
                ]);

                return $file;
            });
        } catch (Throwable $exception) {
            $this->cleanupUnreferenced($path);

            if ($exception instanceof QueryException || $exception instanceof FilesystemException) {
                throw new RuntimeException('A private file operation could not complete.');
            }

            throw $exception;
        }
    }

    public function cleanupUnreferenced(string $path): void
    {
        try {
            if (DB::table('files')->where('storage_path', $path)->exists()) {
                return;
            }
            $disk = $this->disk();
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException('Private upload cleanup failed.');
            }
        } catch (Throwable) {
            report(new RuntimeException('An incomplete private upload requires storage verification.'));
        }
    }

    public function owned(User $actor, int $id): stdClass
    {
        Gate::forUser($actor)->authorize('files.view_own');
        $file = DB::table('files')->where('uploaded_by', $actor->id)->where('id', $id)->first();
        abort_if($file === null, 404);

        return $file;
    }

    /** @return array{file: stdClass, stream: resource} */
    public function openDownload(User $actor, int $id): array
    {
        $file = $this->owned($actor, $id);
        abort_unless(in_array($file->scan_status, ['not_scanned', 'clean'], true), 409);
        $stream = $this->verifiedStream($file);
        try {
            $this->audit->record('file.download_authorized', $actor->id, 'file', $id);
        } catch (Throwable $exception) {
            fclose($stream);

            throw $exception;
        }

        return ['file' => $file, 'stream' => $stream];
    }

    /** @return array{checked: int, missing: int, corrupt: int, orphaned: int} */
    public function verify(): array
    {
        $disk = $this->disk();
        $counts = ['checked' => 0, 'missing' => 0, 'corrupt' => 0, 'orphaned' => 0];
        foreach (DB::table('files')->orderBy('id')->lazyById(100) as $file) {
            $counts['checked']++;
            if (! $disk->exists($file->storage_path)) {
                $counts['missing']++;

                continue;
            }
            try {
                $stream = $this->verifiedStream($file);
                fclose($stream);
            } catch (Throwable) {
                $counts['corrupt']++;
            }
        }
        foreach ($disk->getDriver()->listContents('', true) as $item) {
            if ($item->isFile() && ! DB::table('files')->where('storage_path', $item->path())->exists()) {
                $counts['orphaned']++;
            }
        }
        $this->audit->record('file.integrity_checked', null, 'file', summary: $counts,
            outcome: array_sum(array_slice($counts, 1)) === 0 ? 'succeeded' : 'failed', channel: 'console');

        return $counts;
    }

    public function disk(): FilesystemAdapter
    {
        $disk = Storage::disk('protected');
        $root = realpath($disk->path(''));
        $public = realpath(public_path());
        if ($root === false || $public === false || ($disk->getConfig()['serve'] ?? false)) {
            throw new RuntimeException('Protected file storage is not privately configured.');
        }
        $root = mb_strtolower(str_replace('\\', '/', $root));
        $public = mb_strtolower(str_replace('\\', '/', $public));
        if ($root === $public || str_starts_with($root.'/', $public.'/')) {
            throw new RuntimeException('Protected files cannot be stored under the web root.');
        }

        return $disk;
    }

    /** @return resource */
    private function verifiedStream(stdClass $file): mixed
    {
        $disk = $this->disk();
        abort_unless(preg_match('/^\d{4}\/\d{2}\/[a-f0-9-]{36}\.(pdf|jpg|png)$/D', $file->storage_path) === 1, 503);
        $root = realpath($disk->path(''));
        $path = realpath($disk->path($file->storage_path));
        abort_if($path === false || ! str_starts_with(mb_strtolower(str_replace('\\', '/', $path)),
            mb_strtolower(str_replace('\\', '/', $root)).'/'), 503);
        abort_unless(array_key_exists($file->mime_type, ProtectedFileRules::MIME_EXTENSIONS), 503);
        $stream = $disk->readStream($file->storage_path);
        abort_unless(is_resource($stream), 503);
        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            abort_unless($bytes === (int) $file->byte_size && hash_equals($file->sha256, hash_final($hash)), 503);
            abort_unless(rewind($stream), 503);
        } catch (Throwable $exception) {
            fclose($stream);

            throw $exception;
        }

        return $stream;
    }

    private function safeName(string $original, string $extension): string
    {
        $base = basename(str_replace('\\', '/', $original));
        $stem = preg_replace('/[^\pL\pN ._-]/u', '_', pathinfo($base, PATHINFO_FILENAME)) ?? '';
        $stem = trim(mb_strcut($stem, 0, 180, 'UTF-8'), ' .');
        if ($stem === '' || preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])$/iD', $stem)) {
            $stem = 'document';
        }

        return $stem.'.'.$extension;
    }
}
