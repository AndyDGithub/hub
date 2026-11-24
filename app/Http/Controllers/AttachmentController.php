<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content_id' => ['required', 'integer', 'exists:contents,id'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $content = Content::query()->findOrFail($validated['content_id']);
        $file = $request->file('file');
        $disk = config('filesystems.default', 'local');
        $path = $file->store('attachments/'.$content->id, $disk);

        $attachment = Attachment::query()->create([
            'content_id' => $content->id,
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => 'attachment.created',
            'auditable_type' => Attachment::class,
            'auditable_id' => $attachment->id,
            'meta' => ['content_id' => $content->id],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1024),
        ]);

        return response()->json(['attachment' => $attachment], 201);
    }

    public function show(Request $request, string $id)
    {
        $attachment = Attachment::query()->with('content')->findOrFail($id);

        if ($attachment->content && $attachment->content->status !== 'published') {
            $user = $request->user();
            if (! $user || ! $user->hasAnyRole(['admin', 'maintainer'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->filename);
    }
}
