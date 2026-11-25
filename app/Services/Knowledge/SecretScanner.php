<?php

namespace App\Services\Knowledge;

/**
 * Garde-fou de l'import : une fiche qui ressemble à un secret n'entre pas en base
 * (la base sera partagée avec d'autres personnes, cf. ADR-4). Faux positifs acceptés :
 * il vaut mieux sauter une fiche et la relire à la main que publier une clé.
 */
class SecretScanner
{
    private const PATTERNS = [
        'clé privée' => '~-----BEGIN [A-Z ]*PRIVATE KEY-----~',
        'clé OpenAI/Anthropic' => '~\bsk-(?:ant-|proj-)?[A-Za-z0-9_-]{20,}~',
        'token GitHub' => '~\b(?:ghp|gho|ghu|ghs|github_pat)_[A-Za-z0-9_]{20,}~',
        'clé AWS' => '~\bAKIA[0-9A-Z]{16}\b~',
        'token Hugging Face' => '~\bhf_[A-Za-z0-9]{30,}~',
        'token Slack' => '~\bxox[abprs]-[A-Za-z0-9-]{10,}~',
        'token Telegram' => '~\b\d{8,10}:[A-Za-z0-9_-]{35}\b~',
        // PASSWORD=valeur / api_key: "valeur" (une valeur vide ou un placeholder <…>/${…} passe).
        'mot de passe en clair' => '~(?<![a-z])(?:password|passwd|secret|api[_-]?key|token)\s*[=:]\s*["\']?(?![<$*{]|\s|["\']?$)[^\s"\']{8,}~i',
    ];

    /** @return list<string> types de secrets trouvés (vide = rien de suspect) */
    public function scan(string $text): array
    {
        $found = [];
        foreach (self::PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $text)) {
                $found[] = $label;
            }
        }

        return $found;
    }
}
