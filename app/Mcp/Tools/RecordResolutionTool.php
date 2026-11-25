<?php

namespace App\Mcp\Tools;

use App\Services\Knowledge\ResolutionRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('record-resolution')]
#[Description('Signale une erreur rencontrée. Si elle est connue : renvoie la solution et incrémente son compteur. Sinon, sans solution : renvoie les fiches proches sans rien écrire. Avec une solution (vérifiée) : crée la fiche. Appeler d\'abord SANS solution, puis à nouveau AVEC une fois le problème réellement corrigé.')]
#[IsDestructive(false)]
class RecordResolutionTool extends Tool
{
    use AuthorizesTool;

    protected function permission(): string
    {
        return 'content.create';
    }

    protected function ability(): string
    {
        return 'knowledge:write';
    }

    public function handle(Request $request, ResolutionRecorder $recorder): Response|ResponseFactory
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
            'verification' => ['nullable', 'in:planned,in_code,executed,validated'],
        ]);

        $result = $recorder->record($validated, $request->user());

        return Response::structured([
            'status' => $result['status'],
            'fiche' => isset($result['content']) ? [
                'slug' => $result['content']->slug,
                'title' => $result['content']->title,
                'occurrences' => $result['content']->resolution?->occurrences,
                'body' => $result['content']->body,
            ] : null,
            'suggestions' => isset($result['suggestions']) ? $result['suggestions']->all() : [],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'error_message' => $schema->string()->description('Le message d\'erreur exact (la ligne significative, pas toute la trace).')->required(),
            'context' => $schema->object()->description('Contexte : language, library, version, os…'),
            'title' => $schema->string()->description('Titre court (sinon : première ligne de l\'erreur).'),
            'cause' => $schema->string()->description('Cause identifiée.'),
            'solution' => $schema->string()->description('Correction appliquée ET vérifiée. Ne pas remplir pour une simple recherche.'),
            'tags' => $schema->array()->items($schema->string())->description('Mots-clés.'),
            'verification' => $schema->string()->enum(['planned', 'in_code', 'executed', 'validated'])
                ->description('Niveau de preuve de la solution.'),
        ];
    }
}
