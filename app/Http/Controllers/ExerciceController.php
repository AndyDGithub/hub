<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExerciceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Content::query()
            ->where('type', 'exercise')
            ->with('author:id,name,email')
            ->latest('published_at')
            ->latest('id');

        if (! $request->user()) {
            $query->where('status', 'published');
        }

        return response()->json([
            'exercices' => $query->paginate((int) $request->query('per_page', 10)),
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

        $exercise = Content::query()->create([
            ...$validated,
            'type' => 'exercise',
            'author_id' => $request->user()?->id,
            'published_at' => $validated['status'] === 'published' ? now() : null,
        ]);

        $this->audit($request, 'exercise.created', $exercise, ['slug' => $exercise->slug]);

        return response()->json(['exercise' => $exercise], 201);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $query = Content::query()
            ->where('type', 'exercise')
            ->where('slug', $slug)
            ->with(['author:id,name,email', 'attachments']);

        if (! $request->user()) {
            $query->where('status', 'published');
        }

        $exercise = $query->firstOrFail();

        return response()->json(['exercise' => $exercise]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $exercise = Content::query()->where('type', 'exercise')->findOrFail($id);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('contents', 'slug')->ignore($exercise->id)],
            'excerpt' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'url' => ['nullable', 'url', 'max:2048'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'published', 'archived'])],
        ]);

        if (array_key_exists('status', $validated) && $validated['status'] === 'published' && ! $exercise->published_at) {
            $validated['published_at'] = now();
        }

        $exercise->update($validated);

        $this->audit($request, 'exercise.updated', $exercise, ['changes' => array_keys($validated)]);

        return response()->json(['exercise' => $exercise->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $exercise = Content::query()->where('type', 'exercise')->findOrFail($id);
        $exercise->delete();

        $this->audit($request, 'exercise.deleted', $exercise, ['id' => $exercise->id]);

        return response()->json(['message' => 'Exercise deleted']);
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
