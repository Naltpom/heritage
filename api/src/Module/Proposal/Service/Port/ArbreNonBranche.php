<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Implémentation de repli de {@see PortArbre}, tant que `Tree` n'a pas posé la sienne.
 *
 * **Elle lève sur les trois méthodes, et c'est délibéré.** Un repli qui rendrait
 * `null` en lecture ferait passer toute cible pour disparue, donc toute demande pour
 * caduque — et le premier essai réel invaliderait des demandes parfaitement valides
 * sans que rien ne dise pourquoi. Un repli qui ne ferait rien en écriture ferait
 * réussir une validation qui n'écrit pas, ce qui est pire encore.
 *
 * Elle existe parce que le conteneur doit se compiler avant que `Tree` ait livré : une
 * interface sans implémentation fait échouer l'autowiring, donc le démarrage, donc les
 * tests des six autres modules. Le repli rend cette dépendance visible au bon endroit —
 * à l'appel, avec un message qui nomme ce qu'il faut brancher.
 */
final readonly class ArbreNonBranche implements PortArbre
{
    private const string MESSAGE = 'Aucune implémentation de PortArbre n\'est branchée : '
        .'vérifier l\'alias App\\Module\\Proposal\\Service\\Port\\PortArbre avec bin/console debug:container, '
        .'puis le reposer dans config/services/proposal.yaml.';

    public function chargerCible(SubjectKind $kind, int $id): ?EtatCible
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function appliquerChamps(SubjectKind $kind, int $id, array $valeurs): EcritureAppliquee
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function creerPersonne(PersonneAcreer $fiche): LigneCreee
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function trouverOuCreerFamille(?int $personneA, ?int $personneB): LigneCreee
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function porterUnion(int $familleId, UncertainDate $mariage, bool $divorce, UncertainDate $rupture): void
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function rattacherEnfant(int $familleId, int $personneId, ?int $rangDansLaFratrie): void
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function empreinteDeLArbre(): string
    {
        throw new \LogicException(self::MESSAGE);
    }
}
