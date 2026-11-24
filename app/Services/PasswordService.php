<?php

namespace App\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class PasswordService
{
    public function hash(string $plainPassword): string
    {
        return Hash::make($this->pepper($plainPassword));
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        return Hash::check($this->pepper($plainPassword), $hash);
    }

    public function needsUpgrade(string $hash): bool
    {
        return Hash::needsRehash($hash);
    }

    /** Mot de passe temporaire lisible à transmettre à une personne invitée. */
    public function generateTemporary(): string
    {
        return Str::password(20, symbols: false);
    }

    /**
     * HMAC-SHA256(mot de passe, pepper) puis bcrypt.
     * Concaténer le pepper ne marche pas avec bcrypt, qui ignore tout au-delà de 72 octets :
     * avec un long mot de passe, le pepper serait silencieusement tronqué. Le HMAC donne
     * toujours 64 caractères hexadécimaux, sous la limite.
     */
    private function pepper(string $plainPassword): string
    {
        $pepper = (string) config('hub.passwords.pepper');

        // On refuse de hacher plutôt que de créer des hashs sans pepper (irrécupérables ensuite).
        if (strlen($pepper) < 32) {
            throw new RuntimeException('PASSWORD_PEPPER manquant ou trop court (32 caractères minimum).');
        }

        return hash_hmac('sha256', $plainPassword, $pepper);
    }
}
