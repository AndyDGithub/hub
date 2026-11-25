<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Connaissance (ADR-2). Les fiches megadoc sont des `contents` comme les cours et docs :
 * un seul endroit à indexer, une seule logique de visibilité (phase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // enum → string : ajouter un type (concept, resolution, project…) ne demandera plus de migration.
            $table->string('type', 32)->change();
            // Origine d'une fiche importée, ex. "megadoc_skills.md#docker-concepts-fondamentaux".
            // Unique : relancer l'import met à jour la fiche au lieu de la dupliquer.
            $table->string('source', 512)->nullable()->unique()->after('url');
            // sha256 du texte source : permet de savoir si un import a quelque chose à changer.
            $table->char('source_hash', 64)->nullable()->after('source');
            // Niveau de preuve (megadoc-guide) : planned < in_code < executed < validated.
            $table->string('verification', 16)->nullable()->after('status');
            $table->json('metadata')->nullable()->after('verification');
        });

        // Recherche plein texte (ADR-3, étape 1). FULLTEXT n'existe pas en SQLite : SearchService
        // bascule alors sur LIKE, uniquement pour le développement.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('contents', fn (Blueprint $table) => $table->fullText(['title', 'excerpt', 'body'], 'contents_fulltext'));
        }

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('content_tag', function (Blueprint $table) {
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['content_id', 'tag_id']);
        });

        // Une fiche « résolution » = un problème rencontré : la partie structurée vit ici,
        // l'explication (cause, solution, preuve) dans contents.body.
        Schema::create('resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->unique()->constrained()->cascadeOnDelete();
            // sha256 du message normalisé (chemins, nombres, adresses remplacés) : deux occurrences
            // de la même erreur sur des fichiers différents donnent la même empreinte.
            $table->char('error_fingerprint', 64)->unique();
            $table->text('error_message');
            $table->json('context')->nullable();   // langage, bibliothèque, version, OS…
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resolutions');
        Schema::dropIfExists('content_tag');
        Schema::dropIfExists('tags');

        Schema::table('contents', function (Blueprint $table) {
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->dropFullText('contents_fulltext');
            }
            $table->dropUnique(['source']);
            $table->dropColumn(['source', 'source_hash', 'verification', 'metadata']);
        });
        // Retour à l'enum d'origine : échoue volontairement s'il reste des fiches d'un nouveau type,
        // plutôt que de les tronquer en silence.
        Schema::table('contents', fn (Blueprint $table) => $table->enum('type', ['doc', 'course', 'exercise'])->change());
    }
};
