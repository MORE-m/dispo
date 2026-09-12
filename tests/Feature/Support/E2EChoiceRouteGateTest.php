<?php

namespace Tests\Feature\Support;

use App\Enums\Role;
use App\Http\Controllers\E2E\E2EChoiceSnapshotController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * DF-3-REST-C2: /e2e-Routen nur unter testing + E2E_SERVER, fail-closed im Controller.
 */
class E2EChoiceRouteGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_e2e_routes_absent_in_default_pest_runtime(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertFalse((bool) config('app.e2e_server'));

        $names = collect(Route::getRoutes())->map->getName()->filter()->values();
        $this->assertFalse($names->contains('e2e.snapshot-field-visible'));
        $this->assertFalse($names->contains('e2e.snapshot-choice-option-active'));
        $this->assertFalse($names->contains('e2e.calculation-choice-value'));
        $this->assertFalse($names->contains('e2e.dispo-choice-value'));
        $this->assertFalse($names->contains('e2e.dispo-positions'));
        $this->assertFalse($names->contains('e2e.dispo-snapshot-choice-options'));
        $this->assertFalse($names->contains('e2e.calculation-positions'));
    }

    public function test_authenticated_user_cannot_reach_e2e_endpoints_without_isolated_server(): void
    {
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->postJson('/e2e/snapshot-field-visible', [
                'calculation_id' => 1,
                'field_key' => 'any',
                'visible' => false,
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson('/e2e/calculation-choice-value?calculation_id=1&field_key=any')
            ->assertNotFound();
    }

    public function test_controller_assert_e2e_is_fail_closed_without_flag(): void
    {
        config(['app.e2e_server' => false]);
        $this->assertTrue(app()->environment('testing'));

        $controller = app(E2EChoiceSnapshotController::class);
        $request = Request::create('/e2e/snapshot-field-visible', 'POST', [
            'calculation_id' => 1,
            'field_key' => 'any',
            'visible' => false,
        ]);
        $request->setUserResolver(fn () => User::factory()->role(Role::Sales)->make());

        try {
            $controller->setFieldVisible($request);
            $this->fail('Erwartete 404 ohne E2E_SERVER.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_route_registration_requires_testing_and_e2e_server(): void
    {
        $this->assertSame(
            7,
            $this->countE2eRoutesViaArtisan([
                'APP_ENV' => 'testing',
                'E2E_SERVER' => '1',
            ]),
            'testing + E2E_SERVER=1 muss die sieben /e2e-Routen registrieren.',
        );

        $this->assertSame(
            0,
            $this->countE2eRoutesViaArtisan([
                'APP_ENV' => 'production',
                'E2E_SERVER' => '1',
            ]),
            'production + E2E_SERVER=1 darf keine /e2e-Routen registrieren.',
        );

        $this->assertSame(
            0,
            $this->countE2eRoutesViaArtisan([
                'APP_ENV' => 'testing',
                'E2E_SERVER' => '0',
            ]),
            'testing ohne E2E_SERVER darf keine /e2e-Routen registrieren.',
        );
    }

    public function test_production_route_cache_does_not_include_e2e_routes(): void
    {
        $cacheEnv = array_merge($this->inheritMinimalEnv(), [
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=',
            'E2E_SERVER' => '1',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);

        $cache = new Process(
            [PHP_BINARY, 'artisan', 'route:cache', '--no-interaction'],
            base_path(),
            $cacheEnv,
        );
        $cache->setTimeout(60);
        $cache->run();
        $this->assertTrue(
            $cache->isSuccessful(),
            $cache->getOutput()."\n".$cache->getErrorOutput(),
        );

        try {
            $this->assertSame(
                0,
                $this->countE2eRoutesViaArtisan([
                    'APP_ENV' => 'production',
                    'E2E_SERVER' => '1',
                ]),
                'Production Route-Cache darf keine /e2e-Routen enthalten.',
            );
        } finally {
            $clear = new Process(
                [PHP_BINARY, 'artisan', 'route:clear', '--no-interaction'],
                base_path(),
                $cacheEnv,
            );
            $clear->setTimeout(60);
            $clear->run();
            $this->assertTrue(
                $clear->isSuccessful(),
                $clear->getOutput()."\n".$clear->getErrorOutput(),
            );
        }
    }

    /**
     * @param  array<string, string>  $envOverrides
     */
    private function countE2eRoutesViaArtisan(array $envOverrides): int
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'route:list', '--json', '--no-interaction'],
            base_path(),
            array_merge($this->inheritMinimalEnv(), [
                'APP_KEY' => 'base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
            ], $envOverrides),
        );
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getOutput()."\n".$process->getErrorOutput(),
        );

        $routes = json_decode($process->getOutput(), true);
        $this->assertIsArray($routes);

        return count(array_filter(
            $routes,
            static fn (array $route): bool => str_starts_with((string) ($route['uri'] ?? ''), 'e2e'),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function inheritMinimalEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'USER', 'TMPDIR', 'TMP', 'TEMP', 'SYSTEMROOT'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
