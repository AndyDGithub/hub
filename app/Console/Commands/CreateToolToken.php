<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Crée un token d'outil (Sanctum) pour un compte : un token par outil, limité à ce dont il a besoin.
 *
 *   php artisan hub:token:create andy@example.fr --name=claude-code --ability=knowledge:read --ability=knowledge:write
 */
class CreateToolToken extends Command
{
    /** Ability du token => permission que le compte doit déjà avoir. */
    public const ABILITIES = [
        'knowledge:read' => 'content.read',
        'knowledge:write' => 'content.create',
    ];

    protected $signature = 'hub:token:create
        {email : Compte propriétaire du token}
        {--name= : Nom de l\'outil (ex. claude-code, dashboard)}
        {--ability=* : knowledge:read, knowledge:write (répéter l\'option)}
        {--days=90 : Durée de validité en jours (0 = sans expiration, déconseillé)}';

    protected $description = 'Crée un token d\'outil limité (affiché une seule fois)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        $name = (string) $this->option('name');
        $abilities = array_values(array_unique($this->option('ability')));
        $days = (int) $this->option('days');

        // Toutes les vérifications avant de créer quoi que ce soit.
        $errors = array_filter([
            $user ? null : 'Compte introuvable.',
            preg_match('~^[a-z0-9][a-z0-9_-]{1,49}$~', $name) ? null : '--name requis : minuscules, chiffres, - ou _ (2 à 50).',
            $abilities ? null : 'Au moins une --ability.',
            $days >= 0 && $days <= 365 ? null : '--days entre 0 et 365.',
        ]);
        foreach ($abilities as $ability) {
            if (! isset(self::ABILITIES[$ability])) {
                $errors[] = "Ability inconnue : {$ability} (connues : ".implode(', ', array_keys(self::ABILITIES)).').';
            } elseif ($user && ! $user->can(self::ABILITIES[$ability])) {
                // Un token ne peut jamais donner plus que le compte lui-même.
                $errors[] = "Le compte n'a pas la permission ".self::ABILITIES[$ability]." requise pour {$ability}.";
            }
        }
        if ($user && $user->is_banned) {
            $errors[] = 'Compte banni.';
        }
        if ($errors) {
            array_map(fn ($e) => $this->error($e), $errors);

            return self::FAILURE;
        }

        $token = $user->createToken($name, $abilities, $days ? now()->addDays($days) : null);

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'token.created',
            'auditable_type' => $token->accessToken::class,
            'auditable_id' => $token->accessToken->id,
            'meta' => ['name' => $name, 'abilities' => $abilities, 'days' => $days, 'via' => 'console'],
        ]);

        $this->info("Token « {$name} » créé pour {$user->email} : ".implode(', ', $abilities)
            .($days ? " — expire dans {$days} jours." : ' — sans expiration.'));
        $this->line('Affiché une seule fois (la base ne garde que son hash) :');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
