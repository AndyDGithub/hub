<?php

namespace App\Console\Commands;

use App\Services\Knowledge\KnowledgeImporter;
use App\Services\Knowledge\MegadocParser;
use Illuminate\Console\Command;

/**
 * Importe la megadoc dans le hub. Par défaut, simulation : rien n'est écrit sans --write.
 *
 *   php artisan hub:knowledge:import ~/hesso/megadoc_skills.md ~/megadoc
 *   php artisan hub:knowledge:import ~/hesso/megadoc_skills.md ~/megadoc --write
 */
class ImportKnowledge extends Command
{
    protected $signature = 'hub:knowledge:import
        {paths* : Fichiers .md ou dossiers (non récursif)}
        {--write : Écrire en base (sinon simulation)}
        {--max-bytes=200000 : Ignorer les pages plus grosses (exports bruts, dumps)}';

    protected $description = 'Importe des fiches Markdown (megadoc) de façon idempotente';

    public function handle(MegadocParser $parser, KnowledgeImporter $importer): int
    {
        $fiches = [];
        $ignored = [];

        foreach ($this->argument('paths') as $path) {
            $path = realpath($path) ?: $path;
            if (! file_exists($path)) {
                $this->error("Introuvable : {$path}");

                return self::FAILURE;   // on s'arrête avant d'avoir écrit quoi que ce soit
            }
            $files = is_dir($path) ? glob($path.'/*.md') : [$path];

            foreach ($files as $file) {
                $name = basename($file);
                $size = filesize($file);
                // La clé source = dossier parent + nom : stable quel que soit le chemin absolu de la machine.
                $source = basename(dirname($file)).'/'.$name;

                $reason = match (true) {
                    str_starts_with($name, '.') => 'fichier caché',
                    $name === 'README.md' => 'index',
                    $size < 200 => 'presque vide',
                    $size > (int) $this->option('max-bytes') => 'trop gros ('.round($size / 1024).' Ko)',
                    default => null,
                };
                if ($reason) {
                    $ignored[] = [$source, $reason];

                    continue;
                }

                $markdown = file_get_contents($file);
                array_push($fiches, ...($name === 'megadoc_skills.md'
                    ? $parser->parseSkills($markdown, $source)
                    : [$parser->parsePage($markdown, $source, pathinfo($name, PATHINFO_FILENAME))]));
            }
        }

        $write = (bool) $this->option('write');
        $report = $importer->sync($fiches, $write);

        $counts = array_count_values(array_column($report, 'action'));
        $this->table(['Action', 'Source', 'Titre'], array_map(
            fn ($r) => [$r['action'].(isset($r['reason']) ? " ({$r['reason']})" : ''), $r['source'], $r['title']],
            array_filter($report, fn ($r) => $r['action'] !== 'unchanged')
        ));
        foreach ($ignored as [$source, $reason]) {
            $this->line("<comment>ignoré</comment> {$source} — {$reason}");
        }
        $this->newLine();
        $this->info(sprintf(
            '%s : %d à créer, %d à mettre à jour, %d inchangées, %d sautées (secret possible), %d fichiers ignorés.',
            $write ? 'Import terminé' : 'SIMULATION (ajouter --write pour écrire)',
            $counts['create'] ?? 0, $counts['update'] ?? 0, $counts['unchanged'] ?? 0, $counts['skipped'] ?? 0, count($ignored),
        ));

        return self::SUCCESS;
    }
}
