<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListFilesRequest;
use App\Http\Requests\Api\V1\UploadFileRequest;
use App\Http\Resources\ProtectedFileResource;
use App\Services\AuditTrail;
use App\Services\ProtectedFiles;
use App\Services\IdempotentCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProtectedFileController extends Controller
{
    public function index(ListFilesRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $page = DB::table('files')->where('uploaded_by', $request->user('web')->id)->orderByDesc('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))
            ->appends($request->safe()->except('page'));
        $audit->record('file.listed', $request->user('web')->id, 'file');

        return ProtectedFileResource::collection($page);
    }

    public function store(Request $request, ProtectedFiles $files, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $files): JsonResponse {
            app(UploadFileRequest::class)->validated();
            $file = $files->upload($request->user('web'), $request->file('file'));

            return (new ProtectedFileResource($file))->response()->setStatusCode(201);
        });
    }

    public function show(Request $request, int $file, ProtectedFiles $files, AuditTrail $audit): ProtectedFileResource
    {
        $record = $files->owned($request->user('web'), $file);
        $audit->record('file.viewed', $request->user('web')->id, 'file', $file);

        return new ProtectedFileResource($record);
    }

    public function download(Request $request, int $file, ProtectedFiles $files): StreamedResponse
    {
        ['file' => $record, 'stream' => $stream] = $files->openDownload($request->user('web'), $file);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $record->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $record->byte_size,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
