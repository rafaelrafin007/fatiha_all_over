<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Public, disposable test key; never used for a deployed application.
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('test', 8)),
            'cors.allowed_origins' => ['http://localhost:3000', 'http://localhost:3001'],
            'sanctum.stateful' => ['localhost:3000', 'localhost:3001'],
        ]);
    }

    public function test_application_and_versioned_api_boot_without_a_database(): void
    {
        $this->get('/up')->assertOk();

        $this->getJson('/api/v1/health')->assertOk()->assertExactJson([
            'status' => 'ok',
            'service' => 'fatihaallover-api',
        ]);
    }

    public function test_unknown_api_paths_return_json_even_when_html_is_requested(): void
    {
        $this->withHeader('Accept', 'text/html')
            ->get('/api/v1/not-a-route')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_unversioned_and_example_user_routes_are_not_exposed(): void
    {
        foreach (['/api/health', '/api/user', '/api/v1/user', '/api/v1/products'] as $path) {
            $this->getJson($path)->assertNotFound();
        }
    }

    public function test_both_spa_origins_receive_credentialed_cors_preflight(): void
    {
        foreach (['http://localhost:3000', 'http://localhost:3001'] as $origin) {
            $this->withHeaders([
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
            ])->options('/api/v1/health')
                ->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    public function test_an_untrusted_origin_receives_no_cors_allow_origin(): void
    {
        $this->withHeaders([
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/health')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_sanctum_bootstrap_sets_csrf_and_session_cookies(): void
    {
        $this->withHeader('Origin', 'http://localhost:3000')
            ->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN')
            ->assertCookie(config('session.cookie'))
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    public function test_stateful_api_rejects_a_browser_post_without_csrf(): void
    {
        // Laravel normally bypasses forgery protection in the testing environment.
        $this->app->instance('env', 'local');
        Route::middleware('api')->post('/api/v1/_test/csrf', fn () => response()->json(['ok' => true]));

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/v1/_test/csrf')
            ->assertStatus(419);
    }

    public function test_stateful_api_accepts_a_matching_csrf_token(): void
    {
        $this->app->instance('env', 'local');
        Route::middleware('api')->post('/api/v1/_test/csrf', fn () => response()->json(['ok' => true]));

        $this->withSession(['_token' => 'foundation-test-csrf'])
            ->withHeaders([
                'Origin' => 'http://localhost:3000',
                'X-CSRF-TOKEN' => 'foundation-test-csrf',
            ])->postJson('/api/v1/_test/csrf')->assertOk();
    }

    public function test_sanctum_protected_route_rejects_an_anonymous_client(): void
    {
        Route::middleware(['api', 'auth:sanctum'])
            ->get('/api/v1/_test/protected', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/v1/_test/protected')->assertUnauthorized();
    }
}
