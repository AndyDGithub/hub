<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Content::query()
            ->where('type', 'course')
            ->with('author:id,name,email')
            ->latest('published_at')
            ->latest('id');

        if (! $request->user()) {
            $query->where('status', 'published');
        }

        return response()->json([
            'courses' => $query->paginate((int) $request->query('per_page', 10)),
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

        $course = Content::query()->create([
            ...$validated,
            'type' => 'course',
            'author_id' => $request->user()?->id,
            'published_at' => $validated['status'] === 'published' ? now() : null,
        ]);

        $this->audit($request, 'course.created', $course, ['slug' => $course->slug]);

        return response()->json(['course' => $course], 201);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $query = Content::query()
            ->where('type', 'course')
            ->where('slug', $slug)
            ->with(['author:id,name,email', 'attachments']);

        if (! $request->user()) {
            $query->where('status', 'published');
        }

        $course = $query->firstOrFail();

        return response()->json(['course' => $course]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $course = Content::query()->where('type', 'course')->findOrFail($id);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('contents', 'slug')->ignore($course->id)],
            'excerpt' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'url' => ['nullable', 'url', 'max:2048'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'published', 'archived'])],
        ]);

        if (array_key_exists('status', $validated) && $validated['status'] === 'published' && ! $course->published_at) {
            $validated['published_at'] = now();
        }

        $course->update($validated);

        $this->audit($request, 'course.updated', $course, ['changes' => array_keys($validated)]);

        return response()->json(['course' => $course->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $course = Content::query()->where('type', 'course')->findOrFail($id);
        $course->delete();

        $this->audit($request, 'course.deleted', $course, ['id' => $course->id]);

        return response()->json(['message' => 'Course deleted']);
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
