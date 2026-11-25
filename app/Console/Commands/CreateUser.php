<?php

namespace App\Console\Commands;

use App\Services\UserProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Crée un compte avec un mot de passe temporaire, depuis le serveur.
 * Sert à créer le premier admin (il n'y a plus d'inscription publique) et à scripter des créations.
 *
 *   php artisan hub:user:create alice@example.com --name="Alice" --role=reader
 *   php artisan hub:user:create alice@example.com --name="Alice" --role=reader --json   (pour un script)
 */
class CreateUser extends Command
{
    protected $signature = 'hub:user:create
        {email : Adresse e-mail (identifiant de connexion)}
        {--name= : Nom affiché}
        {--username= : Pseudo optionnel}
        {--role=reader : admin, maintainer, premium, user ou reader}
        {--json : Sortie JSON (pour un script d\'envoi)}';

    protected $description = 'Crée un compte avec un mot de passe temporaire (à changer à la première connexion)';

    public function handle(UserProvisioner $provisioner): int
    {
        $data = [
            'email' => $this->argument('email'),
            'name' => $this->option('name') ?: $this->argument('email'),
            'username' => $this->option('username'),
            'role' => $this->option('role'),
        ];

        // Mêmes règles que l'API ; on s'arrête avant toute écriture si quelque chose cloche.
        $validator = Validator::make($data, [
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'role' => ['required', Rule::in($provisioner->assignableRoles(null))],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        [$user, $temporary] = $provisioner->create($validator->validated(), creator: null);

        if ($this->option('json')) {
            $this->line(json_encode([
                'email' => $user->email,
                'role' => $data['role'],
                'temporary_password' => $temporary,
                'expires_at' => $user->temporary_password_expires_at?->toIso8601String(),
            ]));
        } else {
            $this->info("Compte créé : {$user->email} ({$data['role']})");
            $this->line("Mot de passe temporaire : {$temporary}");
            $this->line('Expire le : '.$user->temporary_password_expires_at?->toDateTimeString().' — affiché une seule fois.');
        }

        return self::SUCCESS;
    }
}
