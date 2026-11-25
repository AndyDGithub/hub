<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetFicheTool;
use App\Mcp\Tools\RecordResolutionTool;
use App\Mcp\Tools\SearchKnowledgeTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Hub d\'Andy')]
#[Version('0.1.0')]
#[Instructions('Base de connaissance personnelle (megadoc). Devant une erreur : appeler record-resolution sans solution pour savoir si elle est déjà connue ; une fois corrigée et vérifiée, rappeler avec cause et solution. Pour une question de cours ou de concept : search-knowledge puis get-fiche.')]
class HubServer extends Server
{
    protected array $tools = [
        SearchKnowledgeTool::class,
        GetFicheTool::class,
        RecordResolutionTool::class,
    ];
}
