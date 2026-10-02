<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\NotificationService;
use PDO;

/**
 * api/notifications.php — powiadomienia wewnętrzne między kontami.
 *   GET   ?status=unread|open|all&type=&q=     odebrane (domyślnie)
 *   GET   ?view=sent                           wysłane
 *   GET   ?view=thread&id=N                    wątek wiadomości (oznacza odebrane jako przeczytane)
 *   GET   ?view=counters                       liczba nieprzeczytanych i otwartych próśb
 *   GET   ?view=recipients                     osoby i grupy, do których konto może pisać
 *   POST  {action: send|reply|mark_read|mark_unread|archive|resolve, id?, ids?, body?, data?}
 */
final class NotificationsController
{
    private readonly NotificationService $service;

    public function __construct(?PDO $db = null, ?NotificationService $service = null)
    {
        $this->service = $service ?? new NotificationService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            return match ($request->action()) {
                'sent'       => ['notifications' => $this->service->sent($actor)],
                'thread'     => ['thread' => $this->service->thread($actor, Params::requireId($request->queryInt('id')))],
                'counters'   => ['counters' => $this->service->counters($actor)],
                'recipients' => ['recipients' => $this->service->recipients($actor)],
                default      => [
                    'notifications' => $this->service->inbox($actor, [
                        'status' => $request->queryString('status'),
                        'type'   => $request->queryString('type'),
                        'q'      => $request->queryString('q'),
                    ]),
                    'counters'      => $this->service->counters($actor),
                ],
            };
        }

        $id = $request->bodyInt('id');

        switch ($request->action()) {
            case 'send':
                return ['result' => $this->service->send($actor, $request->data())];
            case 'reply':
                $body = $request->body['body'] ?? '';

                return ['result' => $this->service->reply($actor, Params::requireId($id), is_string($body) ? $body : '')];
            case 'mark_read':
                $ids = is_array($request->body['ids'] ?? null) ? array_map('intval', $request->body['ids']) : [];

                return ['marked' => $this->service->markRead($actor, $ids), 'counters' => $this->service->counters($actor)];
            case 'mark_unread':
                $this->service->markUnread($actor, Params::requireId($id));

                return ['counters' => $this->service->counters($actor)];
            case 'archive':
                $this->service->archive($actor, Params::requireId($id));

                return ['counters' => $this->service->counters($actor)];
            case 'resolve':
                $this->service->resolve($actor, Params::requireId($id));

                return ['counters' => $this->service->counters($actor)];
            default:
                throw Params::unknownAction();
        }
    }
}
