<?php

namespace App\Services\Knowledge;

/**
 * Ramène un message d'erreur à sa « forme » pour reconnaître la même erreur d'une fois sur l'autre.
 * Ex. "File /home/a/x.py, line 12" et "File /tmp/y.py, line 40" → "file <path>, line <n>".
 * Les noms entre guillemets sont gardés : dans "No module named 'numpy'", numpy est l'information utile.
 */
class ErrorNormalizer
{
    private const RULES = [
        '~https?://\S+~' => '<url>',
        '~\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b~' => '<uuid>',
        '~\b\d{4}-\d{2}-\d{2}[t ]\d{2}:\d{2}(:\d{2})?(\.\d+)?z?\b~' => '<time>',
        '~[a-z]:\\\\[^\s\'"]+~' => '<path>',                 // C:\Users\...
        '~(?<![\w<])(?:\~|\.{1,2})?/[^\s\'"():,]+~' => '<path>', // /home/..., ./x, ~/x
        '~\b0x[0-9a-f]+\b~' => '<hex>',
        '~\b[0-9a-f]{12,}\b~' => '<hex>',                    // hashes, ids
        '~\b\d+\b~' => '<n>',
    ];

    public function normalize(string $message): string
    {
        $text = mb_strtolower(trim($message));
        $text = preg_replace(array_keys(self::RULES), array_values(self::RULES), $text);

        return mb_substr(preg_replace('~\s+~', ' ', $text), 0, 2000);
    }

    public function fingerprint(string $message): string
    {
        return hash('sha256', $this->normalize($message));
    }
}
