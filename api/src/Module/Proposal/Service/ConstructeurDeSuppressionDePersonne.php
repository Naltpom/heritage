<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `suppression_personne` — le dépôt de la demande.
 *
 * ## Le chemin qui n'existait pas, et ce qu'il coûtait
 *
 * Le circuit des demandes ne savait ni fusionner ni supprimer une fiche. Un doublon dans
 * un arbre généalogique se réparait donc **à la main en base** : un `DELETE` écrit dans un
 * client SQL, sans demande, sans motif, sans révision, et sans personne pour relire. C'est
 * le seul chemin d'écriture qui contournait le mécanisme, et l'en-tête de
 * {@see ChangeRequest} dit qu'il ne doit pas en exister.
 *
 * Deux doublons ont déjà été traités ainsi sur ce projet. L'un avait détaché six
 * générations.
 *
 * ## Les trois refus prononcés ici, et pourquoi tous les trois sont au dépôt
 *
 * **1. Pas de motif, pas de demande.** `message` est facultatif pour les huit autres
 * natures ; ici il ne l'est pas, et c'est la seule place possible. `POST
 * /api/demandes/{id}/valider` **ne lit aucun corps de requête** : seul un refus porte un
 * motif, une validation n'en laisse aucun. Un motif demandé à la validation n'existerait
 * donc nulle part, et l'administrateur de demain lirait « fiche 42 supprimée » sans
 * savoir pourquoi.
 *
 * **2. Pas de suppression qui détache des descendants.** Deux fiches du même homme, l'une
 * portant six générations et l'autre vide : supprimer la mauvaise est trivial, supprimer
 * la bonne serait une catastrophe silencieuse — la branche disparaît de l'arbre, et rien
 * ne le signale avant que quelqu'un ne l'ouvre. Le refus est prononcé **au dépôt** parce
 * qu'un contributeur doit apprendre tout de suite que ce n'est pas cette fiche-là qu'il
 * faut effacer, et parce que le message peut alors nommer les enfants concernés.
 *
 * **3. Pas de suppression qui emporte une preuve.** `med_media.subject_id` n'est protégé
 * par aucune clé étrangère. Une fiche effacée y laisse des lignes pendantes et des octets
 * sur le disque, sans plus aucun écran pour y mener : la « preuve orpheline » que ce
 * projet a déjà rencontrée. On ne supprime pas la preuve à sa place — effacer des fichiers
 * en effet de bord d'une autre opération est exactement ce que
 * `Media\Service\SuppressionMedia` refuse de laisser faire à trois autres chemins.
 *
 * ## Ce que l'op porte, et pourquoi elle porte un inventaire
 *
 * `old_value` reçoit **l'inventaire de ce que la suppression emporte** au moment du dépôt ;
 * `new_value` vaut `null`, une suppression n'ayant pas d'« après ». Le contrôle préalable
 * relit l'inventaire courant et compare : une note, une citation ou une preuve ajoutée
 * entre le dépôt et la validation rend la demande **caduque**, parce que l'administrateur
 * s'apprêterait sinon à effacer une ligne que personne ne lui a montrée. C'est la règle
 * « rien ne part en silence », tenue par la machinerie et non par la discipline.
 *
 * `base_version` **est** posée, à la différence d'une note ou d'une citation : supprimer
 * une fiche est une écriture **sur cette fiche**, et elle doit entrer en conflit avec les
 * autres écritures qui la visent.
 */
final readonly class ConstructeurDeSuppressionDePersonne implements ConstructeurDeDemande
{
    /**
     * Le plancher du motif, en caractères.
     *
     * Arbitraire, et assumé comme tel : il n'existe pas de longueur qui distingue une
     * justification d'un mot jeté. Celle-ci écarte « ok », « doublon » et « à supprimer »
     * — trois motifs qui ne disent pas **de quelle autre fiche** il s'agit, ce qui est la
     * seule chose que l'administrateur a besoin de savoir pour trancher. « doublon de
     * 42 » passe, et c'est la limite qu'on cherchait.
     */
    private const int LONGUEUR_MINIMALE_DU_MOTIF = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private PortArbre $arbre,
        private InventaireDeLaSuppression $inventaire,
    ) {
    }

    public function natures(): array
    {
        return [RequestKind::SuppressionPersonne];
    }

    public function construire(ChangeRequest $demande, array $corps, Lecteur $lecteur): void
    {
        $this->exigerUnMotif($demande, $corps);

        $personneId = $this->personneVisee($corps);
        $inventaire = $this->inventaireOuIntrouvable($personneId);

        $this->refuserSiElleEmporteTrop($inventaire);

        $cible = $this->arbre->chargerCible(SubjectKind::Personne, $personneId);

        $op = new ChangeRequestOp(
            $demande,
            1,
            OpKind::SuppressionPersonne,
            SubjectKind::Personne,
            ValueKind::Suppression,
        );

        // `base_version` et non `null` : supprimer une fiche est une écriture sur cette
        // fiche. Une correction validée entre-temps doit faire rejouer la suppression sur
        // la version courante, et non passer inaperçue.
        $op->viserLigneExistante($personneId, $cible?->version);

        // `newValue` à `null` : après, il n'y a rien. L'« avant » n'est pas la valeur d'un
        // champ mais le décompte de ce qui disparaîtra — voir `ValueKind::Suppression`.
        $op->setValues($inventaire->versJson(), null);

        $this->em->persist($op);

        $demande->setTarget(SubjectKind::Personne, $personneId, $cible?->libelle);
    }

    /**
     * Une demande de suppression sans op ne vise rien.
     *
     * Contrôlé à la soumission comme partout ailleurs dans ce module : un brouillon
     * incomplet doit pouvoir s'enregistrer (§ 6.3). Le motif, lui, est exigé dès la
     * **construction** — il n'est pas une pièce du dossier, il est la demande elle-même,
     * et un brouillon de suppression sans raison n'a rien à enregistrer.
     */
    public function verifierAvantSoumission(ChangeRequest $demande): void
    {
        if ([] === $this->opsDe($demande)) {
            throw DonneesInvalides::pourChamp('targetId', 'Une demande de suppression vise une fiche.');
        }
    }

    /**
     * Le motif, obligatoire et substantiel.
     *
     * Il est écrit **sur la demande** et non dans l'op, pour trois raisons qui vont dans
     * le même sens : `prp_change_request.message` est déjà rendu par les deux vues du § 6,
     * il survit à la validation comme au refus, et il est ce que la file d'administration
     * affiche sans ouvrir la demande. Un motif rangé dans `old_value` ne serait lisible
     * qu'en dépliant l'op.
     *
     * @param array<string, mixed> $corps
     */
    private function exigerUnMotif(ChangeRequest $demande, array $corps): void
    {
        $motif = $corps['message'] ?? null;
        $motif = \is_string($motif) ? trim($motif) : '';

        if ('' === $motif) {
            throw DonneesInvalides::pourChamp('message', 'Une suppression se justifie : dites pourquoi cette fiche doit disparaître, pour que l\'administrateur puisse en juger.');
        }

        if (mb_strlen($motif) < self::LONGUEUR_MINIMALE_DU_MOTIF) {
            throw DonneesInvalides::pourChamp('message', \sprintf('Ce motif est trop court (%d caractères sur %d attendus) : « doublon » ne dit pas de quelle autre fiche, et c\'est précisément ce qu\'il faut savoir pour trancher.', mb_strlen($motif), self::LONGUEUR_MINIMALE_DU_MOTIF));
        }

        // `DepotDeDemande` le posera aussi, depuis le même corps. On le repose ici parce
        // que ce constructeur est appelé **après** et que l'ordre pourrait changer : une
        // demande dont le motif dépendrait de l'ordre d'appel serait une demande dont le
        // motif peut manquer.
        $demande->setMessage($motif);
    }

    /** @param array<string, mixed> $corps */
    private function personneVisee(array $corps): int
    {
        $annonce = $corps['targetKind'] ?? SubjectKind::Personne->value;

        if (SubjectKind::Personne->value !== $annonce) {
            // Supprimer une union ou une citation n'est pas la même opération et n'emporte
            // pas les mêmes lignes. Le dire plutôt que de laisser une demande se déposer
            // sur une cible que l'applicateur ne saurait pas traiter.
            throw DonneesInvalides::pourChamp('targetKind', 'Ce circuit ne supprime que des fiches de personne.');
        }

        $id = $corps['targetId'] ?? null;

        if (!\is_int($id) || $id < 1) {
            throw DonneesInvalides::pourChamp('targetId', 'La fiche à supprimer est désignée par son identifiant.');
        }

        return $id;
    }

    private function inventaireOuIntrouvable(int $personneId): InventaireDeSuppression
    {
        $inventaire = $this->inventaire->de($personneId);

        if (null === $inventaire) {
            // `404` sur la cible, comme pour une note sur une fiche inexistante : c'est
            // l'écran d'où vient la demande qui désigne une fiche qui n'est plus là.
            throw Introuvable::cible(SubjectKind::Personne->value, $personneId);
        }

        return $inventaire;
    }

    /**
     * Les deux refus de sûreté, prononcés ensemble et nommés séparément.
     *
     * Ils sont posés sur `targetId` et non sur un champ de formulaire : il n'y a rien à
     * corriger dans la saisie, c'est **la fiche choisie** qui n'est pas la bonne. Poser
     * l'erreur ailleurs ferait chercher une faute de frappe.
     */
    private function refuserSiElleEmporteTrop(InventaireDeSuppression $inventaire): void
    {
        if (!$inventaire->estBloquee()) {
            return;
        }

        throw new DonneesInvalides(['targetId' => implode(' ', $inventaire->raisonsDuBlocage())], 'Cette fiche ne peut pas être supprimée en l\'état.');
    }

    /**
     * Les ops de la demande, **qu'elle soit déjà en base ou non** — même motif que chez
     * ses voisins : `soumettre: true` construit et soumet avant le premier `flush`.
     *
     * @return list<ChangeRequestOp>
     */
    private function opsDe(ChangeRequest $demande): array
    {
        $ops = [];

        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof ChangeRequestOp && $entite->getRequest() === $demande) {
                $ops[] = $entite;
            }
        }

        if ([] === $ops && null !== $demande->getId()) {
            return $this->em->getRepository(ChangeRequestOp::class)->findBy(['request' => $demande], ['seq' => 'ASC']);
        }

        return $ops;
    }
}
