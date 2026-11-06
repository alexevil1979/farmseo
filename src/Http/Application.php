<?php

declare(strict_types=1);

namespace Farm\Http;

use Farm\Controllers\AccountsController;
use Farm\Controllers\ApiTokensController;
use Farm\Controllers\AuthController;
use Farm\Controllers\CampaignsController;
use Farm\Controllers\HealthController;
use Farm\Controllers\InternalController;
use Farm\Controllers\KeywordsController;
use Farm\Controllers\ProxiesController;
use Farm\Controllers\RolesController;
use Farm\Controllers\ScenarioSchedulesController;
use Farm\Controllers\ScenariosController;
use Farm\Controllers\TargetsController;
use Farm\Controllers\TasksController;
use Farm\Controllers\UsersController;
use Farm\Middleware\Auth as AuthMiddleware;
use Farm\Middleware\Csrf;
use Farm\Middleware\InternalToken;
use Farm\Middleware\Json;
use Farm\Middleware\RateLimit;
use Farm\Middleware\Rbac as RbacMiddleware;
use Farm\Services\ServiceFactory;
use Throwable;

final class Application
{
    private Router $router;

    public function __construct(private readonly ServiceFactory $factory)
    {
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->match($request->method(), $request->path());
            if ($match === null) {
                return Response::error('not_found', 'Route not found', 404);
            }

            foreach ($match['params'] as $k => $v) {
                $request->setAttribute('route_' . $k, $v);
            }

            $handler = $this->resolveHandler($match['handler'], $match['params']);
            // Internal token middleware runs before JSON validation (401 > 400).
            $isInternal = str_starts_with($request->path(), '/internal');
            $pipeline = $isInternal
                ? array_merge($match['middleware'], [new Json()], [$handler])
                : array_merge([new Json()], $match['middleware'], [$handler]);

            return $this->runPipeline($pipeline, $request);
        } catch (HttpException $e) {
            $response = $e->toResponse();
            if ($e->status() === 429) {
                $retry = (string) ($e->extra()['retry_after'] ?? 60);
                $response = $response->withHeader('Retry-After', $retry);
            }

            return $response;
        } catch (Throwable $e) {
            $debug = (bool) $this->factory->configValue('api.debug', false);

            return Response::error(
                'server_error',
                $debug ? $e->getMessage() : 'Internal server error',
                500,
                $debug ? ['type' => $e::class] : []
            );
        }
    }

    /**
     * @param list<mixed> $pipeline
     */
    private function runPipeline(array $pipeline, Request $request): Response
    {
        $runner = null;
        $runner = function (Request $req) use (&$pipeline, &$runner): Response {
            if ($pipeline === []) {
                return Response::error('server_error', 'Empty pipeline', 500);
            }
            $next = array_shift($pipeline);
            if ($next instanceof \Closure && $pipeline === []) {
                return $next($req);
            }
            if (is_callable($next) && !$next instanceof \Closure) {
                // Middleware object __invoke or last closure
            }
            if ($pipeline === [] && $next instanceof \Closure) {
                return $next($req);
            }
            if (is_object($next) && is_callable($next) && !$next instanceof \Closure) {
                return $next($req, $runner);
            }
            if ($next instanceof \Closure) {
                return $next($req);
            }

            return Response::error('server_error', 'Invalid middleware', 500);
        };

        return $runner($request);
    }

    /**
     * @param array<string, string> $params
     */
    private function resolveHandler(mixed $handler, array $params): \Closure
    {
        return function (Request $request) use ($handler, $params): Response {
            if ($handler instanceof \Closure) {
                return $handler($request, ...array_values($params));
            }
            if (is_array($handler) && count($handler) === 2) {
                [$ctrl, $method] = $handler;
                if (is_string($ctrl)) {
                    $ctrl = new $ctrl($this->factory);
                }
                $args = [$request];
                foreach ($params as $value) {
                    $args[] = $value;
                }

                return $ctrl->{$method}(...$args);
            }

            throw new HttpException('server_error', 'Invalid route handler', 500);
        };
    }

    private function registerRoutes(): void
    {
        $f = $this->factory;
        $auth = fn (bool $required = true) => new AuthMiddleware($f->auth(), $required);
        $csrf = fn () => new Csrf($f->auth());
        $rl = fn () => new RateLimit(
            $this->safeRedis(),
            (int) $f->configValue('api.rate_limit', 120),
            (int) $f->configValue('api.rate_window', 60),
        );
        $rbac = fn (string $resource, string $action) => new RbacMiddleware($f->rbac(), $resource, $action);
        $internal = fn () => new InternalToken((string) $f->configValue('internal_token', ''));

        $api = ['auth' => $auth, 'csrf' => $csrf, 'rl' => $rl, 'rbac' => $rbac];

        // Public
        $this->router->get('/api/v1/health', [HealthController::class, 'publicHealth']);
        $this->router->post('/api/v1/auth/login', [AuthController::class, 'login']);

        // Auth session helpers
        $this->router->post('/api/v1/auth/logout', [AuthController::class, 'logout'], [
            $auth(), $csrf(), $rl(),
        ]);
        $this->router->get('/api/v1/auth/me', [AuthController::class, 'me'], [
            $auth(), $rl(),
        ]);
        $this->router->post('/api/v1/auth/totp/enable', [AuthController::class, 'enableTotp'], [
            $auth(), $csrf(), $rl(),
        ]);
        $this->router->post('/api/v1/auth/totp/confirm', [AuthController::class, 'confirmTotp'], [
            $auth(), $csrf(), $rl(),
        ]);

        $this->router->get('/api/v1/stats', [HealthController::class, 'stats'], [
            $auth(), $rl(), $rbac('stats', 'read'),
        ]);

        $this->crud('accounts', AccountsController::class, $api);
        $this->router->post('/api/v1/accounts/{id}/session', [AccountsController::class, 'startSession'], [
            $auth(), $csrf(), $rl(), $rbac('accounts', 'run'),
        ]);
        $this->router->post('/api/v1/accounts/{id}/session/stop', [AccountsController::class, 'stopSession'], [
            $auth(), $csrf(), $rl(), $rbac('accounts', 'run'),
        ]);
        $this->router->post('/api/v1/accounts/{id}/session/reset', [AccountsController::class, 'resetSession'], [
            $auth(), $csrf(), $rl(), $rbac('accounts', 'run'),
        ]);
        $this->router->get('/api/v1/accounts/{id}/live', [AccountsController::class, 'liveView'], [
            $auth(), $rl(), $rbac('accounts', 'read'),
        ]);
        $this->router->get('/api/v1/accounts/{id}/sessions', [AccountsController::class, 'sessions'], [
            $auth(), $rl(), $rbac('accounts', 'read'),
        ]);
        $this->router->get('/api/v1/accounts/{id}/screenshot', [AccountsController::class, 'screenshot'], [
            $auth(), $rl(), $rbac('accounts', 'read'),
        ]);
        $this->router->post('/api/v1/accounts/{id}/screenshot', [AccountsController::class, 'screenshot'], [
            $auth(), $csrf(), $rl(), $rbac('accounts', 'run'),
        ]);
        $this->router->get('/api/v1/accounts/{id}/fingerprint', [AccountsController::class, 'fingerprint'], [
            $auth(), $rl(), $rbac('accounts', 'read'),
        ]);
        $this->router->post('/api/v1/accounts/{id}/fingerprint', [AccountsController::class, 'generateFingerprint'], [
            $auth(), $csrf(), $rl(), $rbac('accounts', 'write'),
        ]);
        $this->router->post('/api/v1/accounts/{id}/assign-proxy', [AccountsController::class, 'assignProxy'], [
            $auth(), $csrf(), $rl(), $rbac('accounts', 'write'),
        ]);
        $this->router->post('/api/v1/accounts/bulk-provision', [AccountsController::class, 'bulkProvision'], [
            $auth(), $csrf(), $rl(), $rbac('accounts', 'write'),
        ]);

        $this->crud('proxies', ProxiesController::class, $api);
        $this->crud('scenarios', ScenariosController::class, $api);
        $this->router->post('/api/v1/scenarios/{id}/run', [ScenariosController::class, 'run'], [
            $auth(), $csrf(), $rl(), $rbac('scenarios', 'run'),
        ]);
        $this->crud('scenario_schedules', ScenarioSchedulesController::class, $api);

        $this->crud('tasks', TasksController::class, $api);
        $this->router->get('/api/v1/tasks/{id}/events', [TasksController::class, 'events'], [
            $auth(), $rl(), $rbac('tasks', 'read'),
        ]);
        $this->router->get('/api/v1/tasks/{id}/runs', [TasksController::class, 'runs'], [
            $auth(), $rl(), $rbac('tasks', 'read'),
        ]);
        $this->crud('api_tokens', ApiTokensController::class, $api);
        $this->crud('campaigns', CampaignsController::class, $api);
        $this->crud('keywords', KeywordsController::class, $api);
        $this->crud('targets', TargetsController::class, $api);
        $this->crud('users', UsersController::class, $api);
        $this->crud('roles', RolesController::class, $api);

        // Internal (Node ↔ PHP) — token first (401 before body checks)
        $this->router->post('/internal/task/result', [InternalController::class, 'taskResult'], [
            $internal(),
        ]);
        $this->router->post('/internal/session/start', [InternalController::class, 'sessionStart'], [
            $internal(),
        ]);
        $this->router->post('/internal/session/stop', [InternalController::class, 'sessionStop'], [
            $internal(),
        ]);
        $this->router->get('/internal/health', [InternalController::class, 'health'], [
            $internal(),
        ]);
    }

    /**
     * @param array{
     *     auth: callable(bool=): AuthMiddleware,
     *     csrf: callable(): Csrf,
     *     rl: callable(): RateLimit,
     *     rbac: callable(string, string): RbacMiddleware
     * } $api
     */
    private function crud(string $resource, string $controller, array $api): void
    {
        $auth = $api['auth'];
        $csrf = $api['csrf'];
        $rl = $api['rl'];
        $rbac = $api['rbac'];
        $base = '/api/v1/' . $resource;

        $this->router->get($base, [$controller, 'index'], [
            $auth(), $rl(), $rbac($resource, 'read'),
        ]);
        $this->router->get($base . '/{id}', [$controller, 'show'], [
            $auth(), $rl(), $rbac($resource, 'read'),
        ]);
        $this->router->post($base, [$controller, 'store'], [
            $auth(), $csrf(), $rl(), $rbac($resource, 'write'),
        ]);
        $this->router->put($base . '/{id}', [$controller, 'update'], [
            $auth(), $csrf(), $rl(), $rbac($resource, 'write'),
        ]);
        $this->router->patch($base . '/{id}', [$controller, 'update'], [
            $auth(), $csrf(), $rl(), $rbac($resource, 'write'),
        ]);
        $this->router->delete($base . '/{id}', [$controller, 'destroy'], [
            $auth(), $csrf(), $rl(), $rbac($resource, 'delete'),
        ]);
    }

    private function safeRedis(): ?\Farm\Support\RedisClient
    {
        try {
            return $this->factory->redis();
        } catch (Throwable) {
            return null;
        }
    }
}
