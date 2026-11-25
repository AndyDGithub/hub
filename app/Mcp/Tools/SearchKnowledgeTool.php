<?php

namespace App\Mcp\Tools;

use App\Services\Knowledge\SearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-knowledge')]
#[Description('Recherche plein texte dans la megadoc (concepts, résolutions d\'erreurs, projets, cours). Renvoie les fiches les plus pertinentes avec un extrait ; utiliser get-fiche pour lire une fiche entière.')]
#[IsReadOnly]
class SearchKnowledgeTool extends Tool
{
    use AuthorizesTool;

    protected function permission(): string
    {
        return 'content.read';
    }

    protected function ability(): string
    {
        return 'knowledge:read';
    }

    public function handle(Request $request, SearchService $search): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:500'],
            'type' => ['nullable', 'string', 'in:concept,resolution,project,note,doc,course,exercise'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        $results = $search->search($validated['query'], $validated['type'] ?? null, $validated['limit'] ?? 5);

        return Response::structured(['results' => $results->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Mots-clés ou message d\'erreur.')->required(),
            'type' => $schema->string()->enum(['concept', 'resolution', 'project', 'note', 'doc', 'course', 'exercise'])
                ->description('Filtrer sur un type de fiche (optionnel).'),
            'limit' => $schema->integer()->description('Nombre de résultats (1–20).')->default(5),
        ];
    }
}
