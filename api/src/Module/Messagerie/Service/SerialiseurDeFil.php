<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Service;

use App\Module\Messagerie\Entity\Fil;
use App\Module\Messagerie\Entity\Message;

/**
 * La forme JSON d'un fil.
 *
 * `resume` sert les listes : le dernier message en extrait, et `nonLus` — les messages
 * **de l'autre côté** que le lecteur n'a pas encore ouverts. `detail` ajoute les messages
 * en entier, chacun avec son accusé : `vuLe` (et `vuPar` pour un message lu par un
 * administrateur). « Envoyé » se lit à l'absence de `vuLe`.
 *
 * `cote` dit de quel côté du fil se tient le lecteur : `membre` dans « Mes messages »,
 * `administration` dans l'écran d'administration. Un administrateur qui ouvre son propre
 * fil depuis « Mes messages » y est membre.
 */
final readonly class SerialiseurDeFil
{
    public const int EXTRAIT_MAX = 140;

    public function __construct(private ServiceDeLaMessagerie $service)
    {
    }

    /** @return array<string, mixed> */
    public function resume(Fil $fil, bool $commeAdministration): array
    {
        $dernier = $fil->getDernierMessageOuNull();

        return [
            'id' => $fil->getId(),
            'nature' => $fil->getNature()->value,
            'natureLibelle' => $fil->getNature()->libelle(),
            'sujet' => $fil->getSujet(),
            'statut' => $fil->getStatut()->value,
            'statutLibelle' => $fil->getStatut()->libelle(),
            'membre' => ['userId' => $fil->getMembreUserId(), 'libelle' => $fil->getMembreLibelle()],
            'cote' => $commeAdministration ? 'administration' : 'membre',
            'nombreDeMessages' => \count($fil->getMessages()),
            // Côté membre, on attend les messages de l'administration ; et inversement.
            'nonLus' => $this->service->nonLus($fil, deLAdministration: !$commeAdministration),
            'dernierMessage' => null === $dernier ? null : [
                'extrait' => self::extrait($dernier->getTexte()),
                'deLAdministration' => $dernier->estDeLAdministration(),
                'envoyeLe' => $dernier->getEnvoyeLe()->format(\DATE_ATOM),
                'vuLe' => $dernier->getVuLe()?->format(\DATE_ATOM),
            ],
            'creeLe' => $fil->getCreatedAt()->format(\DATE_ATOM),
            'modifieLe' => $fil->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Fil $fil, LecteurDeLaMessagerie $lecteur, bool $commeAdministration): array
    {
        return [
            ...$this->resume($fil, $commeAdministration),
            'messages' => array_map(
                static fn (Message $m): array => [
                    'id' => $m->getId(),
                    'texte' => $m->getTexte(),
                    'deLAdministration' => $m->estDeLAdministration(),
                    'auteur' => $m->getAuteurLibelle(),
                    'estMoi' => $m->getAuteurUserId() === $lecteur->userId,
                    'envoyeLe' => $m->getEnvoyeLe()->format(\DATE_ATOM),
                    'vuLe' => $m->getVuLe()?->format(\DATE_ATOM),
                    'vuPar' => $m->getVuParLibelle(),
                ],
                $fil->getMessages(),
            ),
        ];
    }

    private static function extrait(string $texte): string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));

        return mb_strlen($texte) > self::EXTRAIT_MAX ? rtrim(mb_substr($texte, 0, self::EXTRAIT_MAX - 1)).'…' : $texte;
    }
}
