<?php

// Réglages propres au hub. Seul endroit où ces variables d'environnement sont lues :
// ailleurs, toujours passer par config('hub.…') (env() renvoie null une fois la config mise en cache).

return [

    'jwt' => [
        // Secret de signature HS256. Par défaut APP_KEY, mais un secret dédié permet de le
        // changer (et d'invalider tous les tokens) sans toucher au chiffrement de Laravel.
        'secret' => env('JWT_SECRET', env('APP_KEY')),
        'ttl_minutes' => (int) env('JWT_TTL', 60),
        'refresh_ttl_minutes' => (int) env('JWT_REFRESH_TTL', 10080),
    ],

    'passwords' => [
        // Secret ajouté à chaque mot de passe avant hachage (HMAC). Stocké hors de la base :
        // une fuite de la base seule ne suffit pas pour attaquer les hashs.
        // Le changer invalide tous les mots de passe existants.
        'pepper' => env('PASSWORD_PEPPER'),

        // Durée de validité d'un mot de passe temporaire remis à une personne invitée.
        'temporary_ttl_hours' => (int) env('TEMP_PASSWORD_TTL_HOURS', 72),
    ],

    // Rôle => rôles qu'il a le droit d'attribuer en créant un compte.
    // Un maintainer ne peut pas créer d'admin (sinon il pourrait s'élever lui-même).
    'assignable_roles' => [
        'admin' => ['admin', 'maintainer', 'premium', 'user', 'reader'],
        // Pas premium : ce rôle donne accès aux outils qui coûtent de l'argent, c'est à l'admin de décider.
        'maintainer' => ['user', 'reader'],
    ],

];
