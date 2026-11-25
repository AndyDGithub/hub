<?php

namespace App\Mcp\Tools;

use App\Models\Content;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-fiche')]
#[Description('Lit une fiche complète de la megadoc par son slug (obtenu avec search-knowledge).')]
#[IsReadOnly]
class GetFicheTool extends Tool
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

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['slug' => ['required', 'string', 'max:255']]);

        $content = Content::query()
            ->where('slug', $validated['slug'])
            ->where('status', '!=', 'archived')
            ->with(['tags:id,name', 'resolution'])
            ->first();

        if (! $content) {
            return Response::error("Aucune fiche « {$validated['slug']} ».");
        }

        return Response::text("# {$content->title}\n\n"
            ."type: {$content->type} · tags: ".$content->tags->pluck('name')->implode(', ')
            .($content->resolution ? " · vue {$content->resolution->occurrences} fois" : '')
            ."\n\n{$content->body}");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Slug de la fiche.')->required(),
        ];
    }
}
