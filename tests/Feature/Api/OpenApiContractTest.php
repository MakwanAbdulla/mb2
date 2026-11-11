<?php

/**
 * OpenAPI contract tests.
 *
 * Three layers:
 *
 *  1. Spec validity   — the YAML file is parseable and structurally sound
 *  2. Route coverage  — every path in the spec matches a registered Laravel route
 *  3. Contract        — live HTTP responses are validated against the spec schema
 *
 * The validator is built once per file (shared across all tests via a lazy
 * singleton stored in a module-level variable).
 */

use App\Models\Events\Event;
use App\Models\Membership\Member;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\Content\Page;
use App\Models\Shop\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Path to the OpenAPI spec file. */
function specPath(): string
{
    return public_path('api/openapi.yaml');
}

/** Build (and lazily cache) the validator from the spec file. */
function openApiValidator(): ValidatorInterface
{
    static $validator = null;
    if ($validator === null) {
        $validator = ValidatorBuilder::fromYamlFile(specPath())->getValidator();
    }
    return $validator;
}

/**
 * Validate a TestResponse against the spec for the given path and method.
 * Throws ValidationException on mismatch — Pest will catch and fail the test.
 */
function assertMatchesSpec(
    \Illuminate\Testing\TestResponse $response,
    string $specPath,
    string $method,
): void {
    openApiValidator()->validate($response->baseResponse, $specPath, $method);
}

// ── 1. Spec validity ──────────────────────────────────────────────────────────

test('openapi spec file exists', function () {
    expect(file_exists(specPath()))->toBeTrue();
});

test('openapi spec is valid yaml', function () {
    $content = file_get_contents(specPath());
    $parsed  = \Symfony\Component\Yaml\Yaml::parse($content);

    expect($parsed)->toBeArray();
});

test('openapi spec has required top-level fields', function () {
    $parsed = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(specPath()));

    expect($parsed)
        ->toHaveKey('openapi')
        ->toHaveKey('info')
        ->toHaveKey('paths')
        ->toHaveKey('components');

    expect($parsed['info'])->toHaveKey('title')->toHaveKey('version');
});

test('openapi spec version is 3.x', function () {
    $parsed = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(specPath()));

    expect($parsed['openapi'])->toStartWith('3.');
});

test('every path in spec has at least one http method defined', function () {
    $parsed       = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(specPath()));
    $validMethods = ['get','post','put','patch','delete','head','options','trace'];
    $emptyPaths   = [];

    foreach ($parsed['paths'] as $path => $definition) {
        $methods = array_intersect(array_keys($definition), $validMethods);
        if (empty($methods)) {
            $emptyPaths[] = $path;
        }
    }

    expect($emptyPaths)->toBeEmpty(
        'Paths with no HTTP methods: ' . implode(', ', $emptyPaths)
    );
});

test('every operation has an operationId', function () {
    $parsed      = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(specPath()));
    $validMethods = ['get','post','put','patch','delete','head','options','trace'];
    $missing      = [];

    foreach ($parsed['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! in_array($method, $validMethods)) continue;
            if (! isset($operation['operationId'])) {
                $missing[] = strtoupper($method) . ' ' . $path;
            }
        }
    }

    expect($missing)->toBeEmpty(
        'Operations missing operationId: ' . implode(', ', $missing)
    );
});

test('every $ref in spec points to a defined schema', function () {
    $content = file_get_contents(specPath());
    $parsed  = \Symfony\Component\Yaml\Yaml::parse($content);

    preg_match_all('/#\/components\/schemas\/(\w+)/', $content, $matches);
    $referencedSchemas = array_unique($matches[1]);
    $definedSchemas    = array_keys($parsed['components']['schemas'] ?? []);
    $missing           = array_diff($referencedSchemas, $definedSchemas);

    expect($missing)->toBeEmpty(
        'Schemas referenced but not defined: ' . implode(', ', $missing)
    );
});

test('every tag used in operations is declared in the tags list', function () {
    $parsed      = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(specPath()));
    $declaredTags = array_column($parsed['tags'] ?? [], 'name');
    $validMethods = ['get','post','put','patch','delete','head','options','trace'];
    $undeclared   = [];

    foreach ($parsed['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! in_array($method, $validMethods)) continue;
            foreach ($operation['tags'] ?? [] as $tag) {
                if (! in_array($tag, $declaredTags)) {
                    $undeclared[] = "'{$tag}' (used in {$method} {$path})";
                }
            }
        }
    }

    expect($undeclared)->toBeEmpty(
        'Undeclared tags: ' . implode(', ', $undeclared)
    );
});

test('openapi validator can be built from spec without errors', function () {
    // This also validates that the spec is semantically sound enough for the library
    $validator = openApiValidator();
    expect($validator)->toBeInstanceOf(ValidatorInterface::class);
});

// ── 2. Route coverage ─────────────────────────────────────────────────────────

test('all spec paths are registered as laravel routes', function () {
    $parsed   = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(specPath()));
    $specPaths = array_keys($parsed['paths']);

    // Convert OpenAPI path params {param} to Laravel {param} (same format actually)
    // Prefix with /api/v1 to match Laravel's route URIs
    $laravelRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => '/' . ltrim($r->uri(), '/'))
        ->unique()
        ->values()
        ->toArray();

    $missing = [];

    foreach ($specPaths as $specPath) {
        $laravelPath = '/api/v1' . $specPath;

        // Normalise {param} syntax (OpenAPI and Laravel are identical)
        $found = collect($laravelRoutes)->contains(fn ($route) =>
            // exact match or same parameterised structure
            $route === $laravelPath ||
            preg_replace('/\{[^}]+\}/', '{x}', $route) ===
            preg_replace('/\{[^}]+\}/', '{x}', $laravelPath)
        );

        if (! $found) {
            $missing[] = $laravelPath;
        }
    }

    expect($missing)->toBeEmpty(
        'These spec paths have no matching Laravel route: ' . implode(', ', $missing)
    );
});

test('every api/v1 route is documented in the spec', function () {
    $parsed = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(specPath()));
    $norm   = fn (string $path) => preg_replace('/\{[^}]+\}/', '{x}', rtrim($path, '/')) ?: '/';

    $documented = [];
    foreach ($parsed['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[strtoupper($method).' '.$norm($path)] = true;
        }
    }

    $undocumented = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        $path = $norm(substr($route->uri(), strlen('api/v1')));
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            if (! isset($documented[$method.' '.$path])) {
                $undocumented[] = $method.' '.$path;
            }
        }
    }

    expect($undocumented)->toBeEmpty(
        'These routes are missing from public/api/openapi.yaml: ' . implode(', ', $undocumented)
    );
});

test('all spec http methods for each path are registered in laravel', function () {
    $parsed      = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(specPath()));
    $validMethods = ['get','post','put','patch','delete'];
    $missing      = [];

    foreach ($parsed['paths'] as $specPath => $operations) {
        $laravelPath = '/api/v1' . $specPath;

        foreach ($operations as $method => $operation) {
            if (! in_array($method, $validMethods)) continue;

            $found = collect(Route::getRoutes()->getRoutes())->contains(function ($route) use ($laravelPath, $method) {
                $routeUri = '/' . ltrim($route->uri(), '/');
                $uriMatch  = preg_replace('/\{[^}]+\}/', '{x}', $routeUri) ===
                             preg_replace('/\{[^}]+\}/', '{x}', $laravelPath);
                return $uriMatch && in_array(strtoupper($method), $route->methods());
            });

            if (! $found) {
                $missing[] = strtoupper($method) . ' ' . $laravelPath;
            }
        }
    }

    expect($missing)->toBeEmpty(
        'These spec operations have no matching Laravel route: ' . implode(', ', $missing)
    );
});

// ── 3. Contract tests ─────────────────────────────────────────────────────────
// Each test hits a real endpoint and verifies the response matches the spec.

// Auth

test('POST /auth/tokens response matches spec', function () {
    $user = User::factory()->create(['email' => 'test@example.com', 'password' => bcrypt('password')]);
    $user->givePermissionTo($this->createPermission('api.access'));

    $response = $this->postJson('/api/v1/auth/tokens', [
        'email'    => 'test@example.com',
        'password' => 'password',
        'device_name' => 'test',
    ]);

    $response->assertStatus(201);
    assertMatchesSpec($response, '/auth/tokens', 'post');
});

test('GET /auth/me response matches spec', function () {
    Sanctum::actingAs(User::factory()->create());
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertOk();
    assertMatchesSpec($response, '/auth/me', 'get');
});

// Events

test('GET /events response matches spec', function () {
    Event::factory()->published()->count(2)->create();
    $response = $this->getJson('/api/v1/events');

    $response->assertOk();
    assertMatchesSpec($response, '/events', 'get');
});

test('GET /events/{slug} response matches spec', function () {
    $event = Event::factory()->published()->create();
    $response = $this->getJson("/api/v1/events/{$event->slug}");

    $response->assertOk();
    assertMatchesSpec($response, '/events/{slug}', 'get');
});

// Shop products

test('GET /shop/products response matches spec', function () {
    Product::factory()->published()->count(3)->create();
    $response = $this->getJson('/api/v1/shop/products');

    $response->assertOk();
    assertMatchesSpec($response, '/shop/products', 'get');
});

test('GET /shop/products response matches spec when empty', function () {
    $response = $this->getJson('/api/v1/shop/products');

    $response->assertOk();
    assertMatchesSpec($response, '/shop/products', 'get');
});

test('GET /shop/products/{slug} response matches spec', function () {
    $product = Product::factory()->published()->withVariants('Storlek', ['S', 'M', 'L'])->create();
    $response = $this->getJson("/api/v1/shop/products/{$product->slug}");

    $response->assertOk();
    assertMatchesSpec($response, '/shop/products/{slug}', 'get');
});

test('GET /shop/products/{slug} 403 response matches spec for member-only product', function () {
    $product  = Product::factory()->published()->membersOnly()->create();
    $response = $this->getJson("/api/v1/shop/products/{$product->slug}");

    $response->assertForbidden();
    assertMatchesSpec($response, '/shop/products/{slug}', 'get');
});

test('GET /shop/products/{slug} 404 response matches spec for missing product', function () {
    $response = $this->getJson('/api/v1/shop/products/does-not-exist');

    $response->assertNotFound();
    assertMatchesSpec($response, '/shop/products/{slug}', 'get');
});

// Shop cart

test('GET /shop/cart response matches spec when empty', function () {
    $response = $this->getJson('/api/v1/shop/cart');

    $response->assertOk();
    assertMatchesSpec($response, '/shop/cart', 'get');
});

test('GET /shop/cart response matches spec with items', function () {
    $product = Product::factory()->published()->create();
    $this->postJson('/api/v1/shop/cart/items', [
        'variant_id' => $product->defaultVariant()->id,
        'quantity'   => 2,
    ]);

    $response = $this->getJson('/api/v1/shop/cart');
    $response->assertOk();
    assertMatchesSpec($response, '/shop/cart', 'get');
});

test('POST /shop/cart/items response matches spec', function () {
    $product  = Product::factory()->published()->create();
    $response = $this->postJson('/api/v1/shop/cart/items', [
        'variant_id' => $product->defaultVariant()->id,
        'quantity'   => 1,
    ]);

    $response->assertStatus(201);
    assertMatchesSpec($response, '/shop/cart/items', 'post');
});

test('POST /shop/cart/items 422 response matches spec for missing variant', function () {
    $response = $this->postJson('/api/v1/shop/cart/items', ['variant_id' => 9999]);

    $response->assertUnprocessable();
    assertMatchesSpec($response, '/shop/cart/items', 'post');
});

test('PATCH /shop/cart/items/{id} response matches spec', function () {
    $product = Product::factory()->published()->create();
    $add     = $this->postJson('/api/v1/shop/cart/items', [
        'variant_id' => $product->defaultVariant()->id,
        'quantity'   => 1,
    ]);
    $itemId   = $add->json('data.id');
    $response = $this->patchJson("/api/v1/shop/cart/items/{$itemId}", ['quantity' => 3]);

    $response->assertOk();
    assertMatchesSpec($response, '/shop/cart/items/{id}', 'patch');
});

test('DELETE /shop/cart/items/{id} response matches spec', function () {
    $product = Product::factory()->published()->create();
    $add     = $this->postJson('/api/v1/shop/cart/items', [
        'variant_id' => $product->defaultVariant()->id,
        'quantity'   => 1,
    ]);
    $itemId   = $add->json('data.id');
    $response = $this->deleteJson("/api/v1/shop/cart/items/{$itemId}");

    $response->assertOk();
    assertMatchesSpec($response, '/shop/cart/items/{id}', 'delete');
});

test('DELETE /shop/cart response matches spec', function () {
    $response = $this->deleteJson('/api/v1/shop/cart');

    $response->assertOk();
    assertMatchesSpec($response, '/shop/cart', 'delete');
});

// Orders (auth)

test('GET /orders response matches spec', function () {
    $user = User::factory()->create();
    Order::factory()->count(2)->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/orders');
    $response->assertOk();
    assertMatchesSpec($response, '/orders', 'get');
});

test('GET /orders/{order} response matches spec', function () {
    $this->createPermission('members.manage');

    $user  = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    OrderLine::factory()->create(['order_id' => $order->id]);
    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/orders/{$order->id}");
    $response->assertOk();
    assertMatchesSpec($response, '/orders/{id}', 'get');
});

// Pages / Navigation (CMS)

test('GET /pages response matches spec', function () {
    Page::create([
        'title'        => 'Test page',
        'slug'         => 'test-page',
        'content'      => '<p>Test</p>',
        'is_published' => true,
        'published_at' => now()->subDay(),
    ]);
    $response = $this->getJson('/api/v1/pages');

    $response->assertOk();
    assertMatchesSpec($response, '/pages', 'get');
});

test('GET /navigation response matches spec', function () {
    $response = $this->getJson('/api/v1/navigation');

    $response->assertOk();
    assertMatchesSpec($response, '/navigation', 'get');
});
