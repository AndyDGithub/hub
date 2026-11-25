<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;

/**
 * Un outil MCP n'est utilisable que si les DEUX conditions sont vraies :
 * - le compte a la permission (rôle Spatie), ex. content.read ;
 * - le token utilisé a l'ability correspondante, ex. knowledge:read.
 * Ainsi un token créé pour lire seulement ne peut jamais écrire, même si le compte est admin.
 */
trait AuthorizesTool
{
    abstract protected function permission(): string;

    abstract protected function ability(): string;

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->can($this->permission()) && $user->tokenCan($this->ability());
    }
}
