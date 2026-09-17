<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\ManagerSubscriptionManager;
use App\Service\Actor;
use App\Service\Validator;

/**
 * api/add_subscription.php — zapis do menedżera osobistego (moduł zamrożony, D1).
 * Logika przeniesiona z pliku endpointu, żeby dało się ją przetestować (§5 pkt 10 i 21).
 */
final class PersonalManagerController
{
    public function __construct(private readonly ?ManagerSubscriptionManager $manager = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        $v = new Validator($request->body);
        $name = (string) $v->string('nazwa_uslugi', true, 255);
        $email = $v->email('mail_subskrypcji', false);
        $username = $v->string('username_konta', false, 255);
        $cost = $v->decimal('koszt_pln', true, 0, 99999999.99);
        $paymentDate = (string) $v->date('data_nastepnej_platnosci', true);
        $v->throwIfFailed();

        $manager = $this->manager ?? new ManagerSubscriptionManager();

        return ['subscription' => $manager->create($actor->id, [
            'nazwa_uslugi'             => $name,
            'mail_subskrypcji'         => $email ?? '',
            'username_konta'           => $username ?? '',
            'koszt_pln'                => (float) $cost,
            'data_nastepnej_platnosci' => $paymentDate,
        ])];
    }
}
