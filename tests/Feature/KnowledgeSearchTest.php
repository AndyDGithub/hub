<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use App\Services\JwtService;
use App\Services\PasswordService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * DatabaseTruncation et pas RefreshDatabase : InnoDB n'ajoute une ligne à l'index FULLTEXT
 * qu'au COMMIT. Dans la transaction jamais commitée de RefreshDatabase, la recherche ne trouverait rien.
 */
class KnowledgeSearchTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        // Les lignes de ce test sont commitées : on les efface pour ne pas polluer les tests suivants.
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    private function as(string $role): array
    {
        $user = User::query()->create([
            'name' => $role, 'email' => "{$role}@example.test",
            'password' => app(PasswordService::class)->hash('correct-horse-battery'),
        ]);
        $user->assignRole($role);

        return ['Authorization' => 'Bearer '.app(JwtService::class)->issueToken($user)];
    }

    private function fiche(string $title, string $body, string $type = 'concept'): Content
    {
        return Content::query()->create([
            'type' => $type, 'title' => $title, 'slug' => str($title)->slug(), 'body' => $body, 'status' => 'published',
        ]);
    }

    public function test_search_ranks_the_relevant_fiche_first(): void
    {
        $this->fiche('Docker volumes', 'Un volume Docker persiste les données hors du conteneur.');
        $this->fiche('Kubernetes pods', 'Un pod regroupe des conteneurs qui partagent le réseau.');
        $this->fiche('Circuit breaker', 'Coupe les appels vers un service en panne.');

        $results = $this->getJson('/api/knowledge/search?q=volume+docker', $this->as('reader'))->assertOk()->json('results');

        $this->assertNotEmpty($results);
        $this->assertSame('Docker volumes', $results[0]['title']);
        $this->assertNotContains('Circuit breaker', array_column($results, 'title'));
    }
}
