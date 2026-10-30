<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Contract\CitationAcreer;
use App\Module\Proposal\Contract\FiliationEtablie;
use App\Module\Proposal\Contract\FiliationPrevue;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Contract\LienARetirer;
use App\Module\Proposal\Contract\LienRetire;
use App\Module\Proposal\Contract\NoteAcreer;
use App\Module\Proposal\Contract\RetraitDeLien;
use App\Module\Proposal\Contract\SignatureDeDemande;
use App\Module\Proposal\Contract\SourceCitable;
use App\Module\Proposal\Contract\VocabulaireDAnnotation;

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

    public function appliquerChamps(SubjectKind $kind, int $id, array $valeurs, ?SignatureDeDemande $signature = null): EcritureAppliquee
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function cotesConnus(int $personneId): array
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function creerPersonne(PersonneAcreer $fiche): LigneCreee
    {
        throw new \LogicException(self::MESSAGE);
    }

    /**
     * Elle lève, alors que le contrat autorise `null` pour « source inexistante ».
     *
     * Rendre `null` ferait refuser en `422` toute source réellement présente — « cette
     * source n'existe pas » —, et l'auteur corrigerait un identifiant juste jusqu'à
     * renoncer. Le repli doit nommer ce qui manque, pas accuser la saisie.
     */
    public function chargerSource(int $id): ?SourceCitable
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function vocabulaireDAnnotation(): VocabulaireDAnnotation
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function creerCitation(CitationAcreer $citation): LigneCreee
    {
        throw new \LogicException(self::MESSAGE);
    }

    /**
     * Elle lève, alors que le contrat autorise `null` pour « la personne n'existe plus ».
     *
     * Même raison que {@see chargerSource()} : rendre `null` ferait passer toute fiche pour
     * déjà supprimée, donc toute demande de suppression pour caduque, et l'auteur
     * conclurait que quelqu'un l'a devancé.
     */
    public function inventaireDeSuppression(int $personneId): ?InventaireDeSuppression
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function supprimerPersonne(int $personneId): InventaireDeSuppression
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function creerNote(NoteAcreer $note): LigneCreee
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function trouverOuCreerFamille(?int $personneA, ?int $personneB): LigneCreee
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function epouxDeFamille(int $familleId): array
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function porterUnion(int $familleId, UncertainDate $mariage, ?bool $marie, bool $divorce, UncertainDate $rupture): void
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function rattacherEnfant(int $familleId, int $personneId, ?int $rangDansLaFratrie): void
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function prevoirFiliation(?int $enfantId, ?int $parentA, ?int $parentB, bool $unionUniqueAdmise): FiliationPrevue
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function etablirFiliation(int $enfantId, ?int $parentA, ?int $parentB, bool $unionUniqueAdmise, ?int $rangDansLaFratrie): FiliationEtablie
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function supprimerFamilleVide(int $familleId): ?string
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function prevoirRetraitDeLien(LienARetirer $lien, bool $familleProtegee): ?RetraitDeLien
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function retirerLien(LienARetirer $lien, bool $familleProtegee): LienRetire
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function empreinteDeLArbre(): string
    {
        throw new \LogicException(self::MESSAGE);
    }

    public function enUnLot(\Closure $ecritures): mixed
    {
        throw new \LogicException(self::MESSAGE);
    }
}
