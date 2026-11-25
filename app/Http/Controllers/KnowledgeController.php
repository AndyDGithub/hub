<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Services\Knowledge\ResolutionRecorder;
use App\Services\Knowledge\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KnowledgeController extends Controller
{
    public function search(Request $request, SearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:500'],
            'type' => ['nullable', 'string', 'max:32'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        return response()->json([
            'results' => $search->search($validated['q'], $validated['type'] ?? null, (int) ($validated['limit'] ?? 10)),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $content = Content::query()
            ->where('slug', $slug)
            ->where('status', '!=', 'archived')
            ->with(['tags:id,name', 'resolution', 'author:id,name'])
            ->firstOrFail();

        return response()->json(['content' => $content]);
    }

    /** Signale une erreur rencontrée : renvoie la solution connue, ou l'enregistre si on en fournit une. */
    public function record(Request $request, ResolutionRecorder $recorder): JsonResponse
    {
        $validated = $request->validate([
            'error_message' => ['required', 'string', 'max:10000'],
            'context' => ['nullable', 'array'],
            'context.*' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:250'],
            'cause' => ['nullable', 'string', 'max:20000'],
            'solution' => ['nullable', 'string', 'max:50000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:100'],
            'verification' => ['nullable', Rule::in(['planned', 'in_code', 'executed', 'validated'])],
        ]);

        $result = $recorder->record($validated, $request->user());

        return response()->json($result, $result['status'] === 'created' ? 201 : 200);
    }
}
