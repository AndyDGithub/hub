<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use App\Services\PasswordService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function token(string $role, array $abilities): string
    {
        $user = User::query()->create([
            'name' => $role, 'email' => "{$role}-".count($abilities).'@example.test',
            'password' => app(PasswordService::class)->hash('correct-horse-battery'),
        ]);
        $user->assignRole($role);

        return $user->createToken('test', $abilities)->plainTextToken;
    }

    private function rpc(?string $token, string $method, array $params = [])
    {
        // Dans un test, l'app (et le guard qui a mémorisé l'utilisateur) survit d'une requête à l'autre :
        // on l'oublie pour que chaque appel soit authentifié par SON token, comme en production.
        $this->app['auth']->forgetGuards();

        return $this->postJson('/mcp/hub', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            $token ? ['Authorization' => "Bearer {$token}"] : []);
    }

    private function toolNames(string $token): array
    {
        return array_column($this->rpc($token, 'tools/list')->assertOk()->json('result.tools'), 'name');
    }

    public function test_requires_a_token(): void
    {
        $this->rpc(null, 'tools/list')->assertUnauthorized();
        $this->rpc('1|not-a-real-token', 'tools/list')->assertUnauthorized();
        // Client sans en-tête Accept JSON : 401 aussi, pas une redirection vers un login inexistant (500).
        $this->post('/mcp/hub', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $this->get('/api/me')->assertUnauthorized();
    }

    public function test_tools_visible_depend_on_token_abilities_and_role(): void
    {
        $this->assertEqualsCanonicalizing(
            ['search-knowledge', 'get-fiche', 'record-resolution'],
            $this->toolNames($this->token('maintainer', ['knowledge:read', 'knowledge:write'])),
        );
        // Token lecture seule : pas d'écriture, même pour un maintainer.
        $this->assertNotContains('record-resolution', $this->toolNames($this->token('maintainer', ['knowledge:read'])));
        // Ability d'écriture mais compte reader : le rôle l'emporte.
        $this->assertNotContains('record-resolution', $this->toolNames($this->token('reader', ['knowledge:read', 'knowledge:write'])));
    }

    public function test_get_fiche_and_hidden_tool_cannot_be_called(): void
    {
        Content::query()->create(['type' => 'concept', 'title' => 'Circuit breaker', 'slug' => 'circuit-breaker', 'body' => 'Coupe les appels.', 'status' => 'published']);
        $reader = $this->token('reader', ['knowledge:read']);

        $this->rpc($reader, 'tools/call', ['name' => 'get-fiche', 'arguments' => ['slug' => 'circuit-breaker']])
            ->assertOk()->assertSee('Coupe les appels.');

        $response = $this->rpc($reader, 'tools/call', ['name' => 'record-resolution', 'arguments' => ['error_message' => 'x', 'solution' => 'y']]);
        $this->assertTrue($response->json('error') !== null || $response->json('result.isError') === true);
        $this->assertDatabaseCount('resolutions', 0);
    }

    public function test_record_resolution_through_mcp(): void
    {
        $token = $this->token('maintainer', ['knowledge:read', 'knowledge:write']);
        $args = ['error_message' => 'model was not found: Basic', 'cause' => 'Anki en français', 'solution' => 'Utiliser le modèle « Basique » (Recto/Verso).'];

        $this->rpc($token, 'tools/call', ['name' => 'record-resolution', 'arguments' => $args])->assertOk()->assertSee('created');
        $this->rpc($token, 'tools/call', ['name' => 'record-resolution', 'arguments' => ['error_message' => 'model was not found: Basic']])
            ->assertOk()->assertSee('known');
        $this->assertDatabaseHas('resolutions', ['occurrences' => 2]);
    }

    public function test_banned_account_token_is_refused(): void
    {
        $token = $this->token('maintainer', ['knowledge:read']);
        User::query()->where('email', 'maintainer-1@example.test')->update(['is_banned' => true]);

        $this->rpc($token, 'tools/list')->assertForbidden();
    }

    public function test_token_command_refuses_abilities_beyond_role(): void
    {
        User::query()->create(['name' => 'r', 'email' => 'r@example.test', 'password' => 'x'])->assignRole('reader');

        $this->artisan('hub:token:create', ['email' => 'r@example.test', '--name' => 'claude-code', '--ability' => ['knowledge:write']])
            ->assertFailed();
        $this->artisan('hub:token:create', ['email' => 'r@example.test', '--name' => 'claude-code', '--ability' => ['knowledge:read']])
            ->expectsOutputToContain('Affiché une seule fois')->assertSuccessful();
        $this->assertDatabaseHas('audit_logs', ['action' => 'token.created']);
    }
}
