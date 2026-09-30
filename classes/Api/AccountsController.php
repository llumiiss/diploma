<?php

declare(strict_types=1);

namespace App\Api;

use App\AuthManager;
use App\Database;
use App\Http\Request;
use App\Service\AccountService;
use App\Service\Actor;
use PDO;

/**
 * api/accounts.php (tylko ADMIN)
 *   GET  lista kont personelu
 *   POST {action: create|update|deactivate|reactivate, id?, data?}
 *   POST {action: transfer, from_user_id, to_user_id}
 *   POST {action: send_password_link, id}
 *
 * Nowe konto nie ma hasła (decyzja D3: zakłada je administrator). Zaraz po utworzeniu
 * wysyłamy na podany adres wiadomość z jednorazowym linkiem „ustaw hasło” — to samo
 * działanie powtarza akcja send_password_link, gdy wiadomość przepadnie albo link wygaśnie.
 */
final class AccountsController
{
    private readonly AccountService $service;
    private readonly AuthManager $auth;

    public function __construct(?PDO $db = null, ?AccountService $service = null, ?AuthManager $auth = null)
    {
        $connection = $db ?? Database::getInstance()->getConnection();
        $this->service = $service ?? new AccountService($connection);
        $this->auth = $auth ?? new AuthManager($connection);
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            return ['accounts' => $this->service->list($actor)];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'create'     => $this->create($actor, $request),
            'update'     => ['account' => $this->service->update($actor, Params::requireId($id), $request->data())],
            'deactivate' => ['account' => $this->service->deactivate($actor, Params::requireId($id))],
            'reactivate' => ['account' => $this->service->reactivate($actor, Params::requireId($id))],
            'transfer'   => ['transferred' => $this->service->transferCertificates(
                $actor,
                Params::requireId($request->bodyInt('from_user_id')),
                Params::requireId($request->bodyInt('to_user_id'))
            )],
            'send_password_link' => $this->sendPasswordLink($actor, Params::requireId($id)),
            default      => throw Params::unknownAction(),
        };
    }

    /**
     * @return array{account: array<string, mixed>, password_link_sent: bool}
     */
    private function create(Actor $actor, Request $request): array
    {
        $account = $this->service->create($actor, $request->data());

        return [
            'account'            => $account,
            'password_link_sent' => $this->issuePasswordLink((string) ($account['email'] ?? '')),
        ];
    }

    /**
     * @return array{account: array<string, mixed>, password_link_sent: bool}
     */
    private function sendPasswordLink(Actor $actor, int $id): array
    {
        $actor->authorize('accounts.manage');
        $account = $this->service->get($id);

        return [
            'account'            => $account,
            'password_link_sent' => $this->issuePasswordLink((string) ($account['email'] ?? '')),
        ];
    }

    private function issuePasswordLink(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return $this->auth->requestPasswordSetLink($email, is_string($ip) ? $ip : null)['ok'];
    }
}
