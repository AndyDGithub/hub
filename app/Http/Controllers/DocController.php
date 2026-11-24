<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Content::query()
            ->where('type', 'doc')
            ->with('author:id,name,email')
            ->latest('published_at')
            ->latest('id');

        if (! $request->user()) {
            $query->where('status', 'published');
        }

        return response()->json([
            'docs' => $query->paginate((int) $request->query('per_page', 10)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('contents', 'slug')],
            'excerpt' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'url' => ['nullable', 'url', 'max:2048'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
        ]);

        $doc = Content::query()->create([
            ...$validated,
            'type' => 'doc',
            'author_id' => $request->user()?->id,
            'published_at' => $validated['status'] === 'published' ? now() : null,
        ]);

        $this->audit($request, 'doc.created', $doc, ['slug' => $doc->slug]);

        return response()->json(['doc' => $doc], 201);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $query = Content::query()
            ->where('type', 'doc')
            ->where('slug', $slug)
            ->with(['author:id,name,email', 'attachments']);

        if (! $request->user()) {
            $query->where('status', 'published');
        }

        $doc = $query->firstOrFail();

        return response()->json(['doc' => $doc]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $doc = Content::query()->where('type', 'doc')->findOrFail($id);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('contents', 'slug')->ignore($doc->id)],
            'excerpt' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'url' => ['nullable', 'url', 'max:2048'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'published', 'archived'])],
        ]);

        if (array_key_exists('status', $validated) && $validated['status'] === 'published' && ! $doc->published_at) {
            $validated['published_at'] = now();
        }

        $doc->update($validated);

        $this->audit($request, 'doc.updated', $doc, ['changes' => array_keys($validated)]);

        return response()->json(['doc' => $doc->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $doc = Content::query()->where('type', 'doc')->findOrFail($id);
        $doc->delete();

        $this->audit($request, 'doc.deleted', $doc, ['id' => $doc->id]);

        return response()->json(['message' => 'Doc deleted']);
    }

    private function audit(Request $request, string $action, Content $content, array $meta = []): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'auditable_type' => Content::class,
            'auditable_id' => $content->id,
            'meta' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1024),
        ]);
    }
}
