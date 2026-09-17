<?php

declare(strict_types=1);

namespace App\Http;

use App\AuthManager;
use App\Csrf;
use App\Service\Actor;
use App\Service\ServiceException;
use Closure;
use Throwable;

/**
 * Wspólna obsługa endpointów JSON (wzorzec z docs/MAPA_PROJEKTU.md §4):
 * metoda → CSRF (dla zapisu) → uwierzytelnienie → obsługa → odpowiedź z przetłumaczonym błędem.
 *
 * Kernel nie wywołuje exit, więc endpointy i kontrolery da się testować w PHPUnit (§5 pkt 21).
 */
final class ApiKernel
{
    /** @var Closure(): (array<string, mixed>|false) */
    private readonly Closure $userResolver;

    /** @var Closure(?string): bool */
    private readonly Closure $csrfValidator;

    /**
     * @param (Closure(): (array<string, mixed>|false))|null $userResolver
     * @param (Closure(?string): bool)|null                 $csrfValidator
     */
    public function __construct(?Closure $userResolver = null, ?Closure $csrfValidator = null)
    {
        $this->userResolver = $userResolver ?? static fn (): array|false => (new AuthManager())->currentUser();
        $this->csrfValidator = $csrfValidator ?? static fn (?string $token): bool => Csrf::validate($token);
    }

    /**
     * @param callable(Request, Actor): (array<string, mixed>|Response) $handler
     * @param list<string>                                               $methods
     */
    public function handle(Request $request, callable $handler, array $methods = ['GET', 'POST']): Response
    {
        if (!in_array($request->method, $methods, true)) {
            return self::error(405, \__('api.error.method'));
        }

        if (!$request->isRead() && !($this->csrfValidator)($request->csrfToken)) {
            return self::error(403, \__('api.error.csrf'));
        }

        $user = ($this->userResolver)();
        if ($user === false) {
            return self::error(401, \__('api.error.unauthorized'));
        }

        if ($request->invalidJson) {
            return self::error(400, \__('api.error.invalid_json'));
        }

        try {
            $result = $handler($request, Actor::fromUser($user));
            if ($result instanceof Response) {
                return $result;
            }

            return Response::json(200, ['success' => true] + $result);
        } catch (ServiceException $e) {
            return new Response($e->httpStatus(), [
                'success' => false,
                'message' => $e->getMessage(),
                'errors'  => (object) $e->errors,
            ] + $e->details);
        } catch (Throwable $e) {
            error_log('[CertiSub API] ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

            return self::error(500, \__('api.error.server'));
        }
    }

    public static function error(int $status, string $message): Response
    {
        return Response::json($status, ['success' => false, 'message' => $message, 'errors' => (object) []]);
    }

    /**
     * Skrót dla plików api/*.php.
     *
     * @param callable(Request, Actor): (array<string, mixed>|Response) $handler
     * @param list<string>                                               $methods
     */
    public static function run(callable $handler, array $methods = ['GET', 'POST']): void
    {
        (new self())->handle(Request::fromGlobals(), $handler, $methods)->send();
    }
}
