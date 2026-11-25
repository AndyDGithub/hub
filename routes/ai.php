<?php

use App\Mcp\Servers\HubServer;
use Laravel\Mcp\Facades\Mcp;

// Serveur MCP du hub. Authentification par token d'outil Sanctum (php artisan hub:token:create),
// chaque outil vérifie en plus la permission du compte et l'ability du token (AuthorizesTool).
Mcp::web('/mcp/hub', HubServer::class)
    ->middleware(['auth:sanctum', 'account.usable', 'throttle:60,1']);
