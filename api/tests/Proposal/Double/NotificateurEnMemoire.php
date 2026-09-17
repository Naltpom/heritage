<?php

declare(strict_types=1);

namespace App\Tests\Proposal\Double;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Service\Port\NotificateurDeDemandes;

/**
 * Enregistre les événements plutôt que de les notifier.
 *
 * Il sert deux assertions que le dossier de mission demande explicitement : que le
 * message de refus **parvienne à l'auteur**, et qu'une caducité notifie **avec le
 * diff de ce qui a bougé** — la notification et l'écran doivent dire la même chose.
 */
final class NotificateurEnMemoire implements NotificateurDeDemandes
{
    /** @var list<array{evenement: string, demande: int|null, message: string|null, rejouee: bool, champs: list<array<string, mixed>>}> */
    public static array $evenements = [];

    /**
     * Statique pour la même raison que l'arbre en mémoire : une notification déjà partie
     * ne revient pas parce qu'on a rebâti le noyau. Remise à zéro entre deux tests par
     * le socle.
     */
    public static function oublierTout(): void
    {
        self::$evenements = [];
    }

    public function demandeSoumise(ChangeRequest $demande): void
    {
        $this->enregistrer('demande_soumise', $demande);
    }

    public function demandeValidee(ChangeRequest $demande, bool $rejouee): void
    {
        $this->enregistrer('demande_validee', $demande, rejouee: $rejouee);
    }

    public function demandeRefusee(ChangeRequest $demande, string $message): void
    {
        $this->enregistrer('demande_refusee', $demande, message: $message);
    }

    public function demandeCaduque(ChangeRequest $demande, array $champsEnConflit): void
    {
        $this->enregistrer('demande_caduque', $demande, champs: $champsEnConflit);
    }

    /**
     * Enregistré sous **son propre nom** d'événement, et non sous `demande_caduque` :
     * un test qui vérifie qu'une caducité de dépendance notifie l'auteur ne doit pas
     * passer au vert parce qu'une caducité de champ est partie à sa place.
     */
    public function demandeCaduqueParDependance(ChangeRequest $demande, array $dependances): void
    {
        $this->enregistrer('demande_caduque_dependance', $demande, champs: $dependances);
    }

    /** @return list<array{evenement: string, demande: int|null, message: string|null, rejouee: bool, champs: list<array<string, mixed>>}> */
    public function de(string $evenement): array
    {
        return array_values(array_filter(
            self::$evenements,
            static fn (array $trace): bool => $trace['evenement'] === $evenement,
        ));
    }

    /** @param list<array<string, mixed>> $champs */
    private function enregistrer(
        string $evenement,
        ChangeRequest $demande,
        ?string $message = null,
        bool $rejouee = false,
        array $champs = [],
    ): void {
        self::$evenements[] = [
            'evenement' => $evenement,
            'demande' => $demande->getId(),
            'message' => $message,
            'rejouee' => $rejouee,
            'champs' => $champs,
        ];
    }
}
