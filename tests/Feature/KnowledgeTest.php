<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use App\Services\JwtService;
use App\Services\PasswordService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
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

    public function test_knowledge_requires_an_account(): void
    {
        $this->getJson('/api/knowledge/search?q=docker')->assertUnauthorized();
        $this->postJson('/api/knowledge/resolutions', ['error_message' => 'x'])->assertUnauthorized();
    }

    public function test_reader_can_search_but_not_record(): void
    {
        $this->postJson('/api/knowledge/resolutions', ['error_message' => 'boom'], $this->as('reader'))->assertForbidden();
    }

    public function test_error_lifecycle_unknown_then_created_then_known(): void
    {
        $headers = $this->as('maintainer');
        $error = "File \"/home/a/app.py\", line 3\nModuleNotFoundError: No module named 'flask_cors'";

        // 1. Inconnue, sans solution : rien n'est écrit.
        $this->postJson('/api/knowledge/resolutions', ['error_message' => $error], $headers)
            ->assertOk()->assertJson(['status' => 'unknown']);
        $this->assertDatabaseCount('resolutions', 0);

        // 2. On fournit la solution : fiche créée.
        $this->postJson('/api/knowledge/resolutions', [
            'error_message' => $error,
            'context' => ['language' => 'python', 'version' => '3.12'],
            'cause' => 'Dépendance absente du venv Passenger.',
            'solution' => 'pip install flask-cors dans le venv de l\'app, puis restart.',
            'tags' => ['python', 'passenger'],
        ], $headers)->assertCreated()->assertJson(['status' => 'created']);

        // 3. Même erreur, autre chemin et autre ligne : reconnue, compteur incrémenté.
        $this->postJson('/api/knowledge/resolutions', [
            'error_message' => "File \"/srv/x/main.py\", line 88\nModuleNotFoundError: No module named 'flask_cors'",
        ], $headers)->assertOk()->assertJson(['status' => 'known'])
            ->assertJsonPath('content.resolution.occurrences', 2);
    }

    public function test_import_is_idempotent_and_skips_secrets(): void
    {
        $dir = sys_get_temp_dir().'/megadoc-test-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/projet.md", "# Projet X\n\n**Source :** `/x`\n\n".str_repeat('Texte utile. ', 30));
        file_put_contents("$dir/fuite.md", "# Notes\n\nOPENAI_API_KEY=sk-proj-abcdefghijklmnopqrstuvwxyz123456\n".str_repeat('a ', 120));

        try {
            $this->artisan('hub:knowledge:import', ['paths' => [$dir]])->assertSuccessful();
            $this->assertDatabaseCount('contents', 0);   // simulation par défaut

            $this->artisan('hub:knowledge:import', ['paths' => [$dir], '--write' => true])->assertSuccessful();
            $this->artisan('hub:knowledge:import', ['paths' => [$dir], '--write' => true])->assertSuccessful();

            $this->assertSame(1, Content::query()->count());
            $this->assertSame('project', Content::query()->first()->type);
            $this->assertDatabaseMissing('contents', ['title' => 'Notes']);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function test_import_stops_on_missing_path(): void
    {
        $this->artisan('hub:knowledge:import', ['paths' => ['/nonexistent/megadoc'], '--write' => true])->assertFailed();
    }
}
