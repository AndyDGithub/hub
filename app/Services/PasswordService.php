<?php

namespace App\Services;

use Illuminate\Support\Facades\Hash;

class PasswordService
{
    public function hash(string $plainPassword): string
    {
        return Hash::make($this->pepper($plainPassword));
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        if (Hash::check($this->pepper($plainPassword), $hash)) {
            return true;
        }

        // Backward compatibility for passwords hashed before pepper rollout.
        return Hash::check($plainPassword, $hash);
    }

    public function needsUpgrade(string $hash): bool
    {
        return Hash::needsRehash($hash);
    }

    private function pepper(string $plainPassword): string
    {
        $pepper = (string) env('PASSWORD_PEPPER', '');

        return $plainPassword.$pepper;
    }
}
