<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Exception\DomainException;
use App\Module\Proposal\Contract\LienARetirer;
use App\Module\Proposal\Contract\RecueilDErreurs;
use App\Module\Proposal\Contract\RetraitDeLien;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `retrait_lien` — le dépôt de la demande.
 *
 * ## Le chemin qui n'existait pas
 *
 * `OpKind::RetraitLien` était déclaré depuis la première migration, et l'en-tête de
 * {@see ApplicateurDOperationNote} disait encore « `retrait_lien` reste à brancher ». Un
 * lien faux — un enfant rattaché au mauvais couple, un conjoint saisi deux fois — ne se
 * défaisait donc qu'en base, à la main : le chemin d'écriture sans demande, sans révision
 * et sans relecture que l'ADR 0004 interdit.
 *
 * ## Le corps
 *
 * ```json
 * { "kind": "retrait_lien", "message": "…", "soumettre": true,
 *   "targetKind": "personne", "targetId": 17,
 *   "retraits": [
 *     { "kind": "enfant_de",   "personId": 17, "familyId": 4, "parentId": 30 },
 *     { "kind": "conjoint_de", "personId": 52, "familyId": 9 }
 *   ] }
 * ```
 *
 * **Le corps ne porte aucun `ops`**, pour la raison que donne déjà la suppression d'une
 * fiche : la demande dit ce qu'elle vise, et c'est le serveur qui mesure ce que ça fait.
 * Une mesure envoyée par le client serait une seconde source de vérité, et la seule qui
 * compte — l'union conservée ou supprimée — serait celle qu'il aurait calculée sans voir
 * `tree_child`, `tree_note` ni `med_media`.
 *
 * ## Un op par lien, sur la famille
 *
 * Chaque retrait devient un op `retrait_lien` dont le sujet est **la famille**
 * (`subject_kind = famille`, `base_version` = sa version), et dont `old_value` porte la
 * mesure ({@see RetraitDeLien}). La famille et non la personne : c'est la ligne que le
 * retrait écrit — une place d'époux vidée, une filiation retirée, l'union supprimée —, et
 * c'est donc avec les autres écritures sur cette famille qu'il doit entrer en conflit.
 *
 * ## Les refus prononcés ici
 *
 * - un lien qui n'existe pas — la personne n'est pas enfant de cette famille, pas époux de
 *   cette union — ou qui ne peut pas être défait : `422` sur `retraits[i]`, avec la phrase
 *   que `Tree` a écrite ;
 * - une famille ou une personne qui n'existe pas : `404`, comme toute cible absente ;
 * - le même lien deux fois : `422`. Deux ops sur la même arête feraient échouer le second
 *   à la validation, sur une ligne déjà partie.
 */
final readonly class ConstructeurDeRetraitDeLien implements ConstructeurDeDemande
{
    public function __construct(
        private EntityManagerInterface $em,
        private PortArbre $arbre,
        private MesureDesRetraits $mesures,
    ) {
    }

    public function natures(): array
    {
        return [RequestKind::RetraitLien];
    }

    public function construire(ChangeRequest $demande, array $corps, Lecteur $lecteur, ?RecueilDErreurs $recueil = null): void
    {
        $retraits = $corps['retraits'] ?? [];

        if (!\is_array($retraits) || !array_is_list($retraits)) {
            throw DonneesInvalides::pourChamp('retraits', 'Les liens à retirer sont une liste.');
        }

        $vus = [];
        $touchees = [];
        $seq = 0;

        foreach ($retraits as $index => $brut) {
            try {
                $chemin = "retraits[$index]";
                $lien = $this->lienDepuis($brut, $chemin);
                $cle = implode(':', [$lien->nature->value, $lien->personneId, $lien->familleId, (string) $lien->parentId]);

                if (isset($vus[$cle])) {
                    throw DonneesInvalides::pourChamp($chemin, 'Ce lien est déjà retiré par cette demande.');
                }

                $vus[$cle] = true;
                $mesure = $this->mesurer($lien, $chemin);

                $cible = $this->arbre->chargerCible(SubjectKind::Famille, $lien->familleId);

                $op = new ChangeRequestOp($demande, ++$seq, OpKind::RetraitLien, SubjectKind::Famille, ValueKind::Lien);
                // `base_version` posée : défaire un lien est une écriture sur cette famille.
                // Une correction validée entre-temps la fait rejouer sur la version courante,
                // et la mesure — comparée, elle — dit si le retrait veut encore dire la même
                // chose.
                $op->viserLigneExistante($lien->familleId, $cible?->version);
                // `newValue` à `null` : après un retrait, le lien n'est plus. L'« avant » n'est
                // pas une valeur de champ mais la mesure de ce que le retrait fera.
                $op->setValues($mesure->versJson(), null);

                $this->em->persist($op);

                $touchees[] = $lien->personneId;

                if (null !== $lien->parentId) {
                    $touchees[] = $lien->parentId;
                }

                foreach ($this->arbre->epouxDeFamille($lien->familleId) as $epoux) {
                    $touchees[] = $epoux;
                }
            } catch (DomainException $e) {
                RecueilDErreurs::recueillir($recueil, $e);
            }
        }

        // Ce qui suit dépend de tous les éléments : on ne le juge que s'ils sont tous justes.
        $recueil?->leverSiNonVide();

        $this->poserLaCible($demande, $corps, array_values(array_unique($touchees)));
    }

    /**
     * Une demande de retrait sans lien ne retire rien — contrôlé à la soumission, comme
     * partout : un brouillon incomplet doit pouvoir s'enregistrer (§ 6.3).
     */
    public function verifierAvantSoumission(ChangeRequest $demande): void
    {
        if ([] === $this->opsDe($demande)) {
            throw DonneesInvalides::pourChamp('retraits', 'Une demande de retrait désigne au moins un lien.');
        }
    }

    private function lienDepuis(mixed $brut, string $chemin): LienARetirer
    {
        if (!\is_array($brut)) {
            throw DonneesInvalides::pourChamp($chemin, 'Un lien à retirer est un objet.');
        }

        $nature = LinkKind::tryFrom(\is_string($brut['kind'] ?? null) ? $brut['kind'] : '');

        if (LinkKind::ParentDe === $nature) {
            // Deux écritures pour la même arête feraient deux chemins à tenir cohérents :
            // « X n'est plus le parent de C » se lit du côté de l'enfant.
            throw DonneesInvalides::pourChamp("$chemin.kind", 'Un parent se retire depuis l’enfant : « enfant_de », avec « parentId » pour ne retirer que ce parent.');
        }

        if (null === $nature) {
            throw DonneesInvalides::pourChamp("$chemin.kind", 'Nature de lien inconnue : « enfant_de » ou « conjoint_de ».');
        }

        $personne = $brut['personId'] ?? null;
        $famille = $brut['familyId'] ?? null;
        $parent = $brut['parentId'] ?? null;

        if (!\is_int($personne) || $personne < 1) {
            throw DonneesInvalides::pourChamp("$chemin.personId", 'La personne du lien est désignée par son identifiant.');
        }

        if (!\is_int($famille) || $famille < 1) {
            throw DonneesInvalides::pourChamp("$chemin.familyId", 'La famille du lien est désignée par son identifiant.');
        }

        if (null !== $parent && (!\is_int($parent) || $parent < 1)) {
            throw DonneesInvalides::pourChamp("$chemin.parentId", 'Le parent est désigné par son identifiant.');
        }

        if (null !== $parent && LinkKind::EnfantDe !== $nature) {
            throw DonneesInvalides::pourChamp("$chemin.parentId", 'Seul un retrait « enfant_de » nomme un parent.');
        }

        return new LienARetirer($nature, $personne, $famille, $parent);
    }

    private function mesurer(LienARetirer $lien, string $chemin): RetraitDeLien
    {
        $mesure = $this->mesures->de($lien);

        if (null === $mesure) {
            // `404` sur celle des deux lignes qui manque : c'est l'écran d'où vient la
            // demande qui désigne une fiche ou une union qui n'est plus là.
            if (null === $this->arbre->chargerCible(SubjectKind::Famille, $lien->familleId)) {
                throw Introuvable::cible(SubjectKind::Famille->value, $lien->familleId);
            }

            throw Introuvable::cible(SubjectKind::Personne->value, $lien->personneId);
        }

        if ($mesure->estBloque()) {
            throw new DonneesInvalides([$chemin => (string) $mesure->blocage], 'Ce lien ne peut pas être retiré en l’état.');
        }

        return $mesure;
    }

    /**
     * La fiche sur laquelle la demande porte : celle qu'annonce le corps, **si les liens
     * la touchent** — l'enfant, le parent nommé, un époux d'une des familles —, sinon la
     * personne du premier retrait.
     *
     * Même contrôle, même raison que pour `ajout_lien` (§ 6.4 du contrat) : sans lui,
     * `targetId` serait un champ libre permettant de faire apparaître n'importe quelle
     * demande sur n'importe quelle fiche de la file. Et c'est la cible annoncée qui compte
     * pour l'écran : on retire un frère depuis la fiche de sa sœur, et l'administrateur doit
     * trouver la demande sur la fiche d'où elle est partie.
     *
     * @param array<string, mixed> $corps
     * @param list<int>            $touchees
     */
    private function poserLaCible(ChangeRequest $demande, array $corps, array $touchees): void
    {
        $kind = $corps['targetKind'] ?? SubjectKind::Personne->value;

        if (SubjectKind::Personne->value !== $kind) {
            throw DonneesInvalides::pourChamp('targetKind', 'Une demande de retrait de lien porte sur une fiche de personne.');
        }

        $annoncee = $corps['targetId'] ?? null;

        if (null !== $annoncee && !\is_int($annoncee)) {
            throw DonneesInvalides::pourChamp('targetId', 'L\'identifiant de la cible est un entier.');
        }

        if (null !== $annoncee && !\in_array($annoncee, $touchees, true)) {
            throw DonneesInvalides::pourChamp('targetId', 'La cible annoncée n\'est pas une des personnes que ces liens désignent.');
        }

        $cible = $annoncee ?? ($touchees[0] ?? null);

        if (null === $cible) {
            return;
        }

        $demande->setTarget(SubjectKind::Personne, $cible, $this->arbre->chargerCible(SubjectKind::Personne, $cible)?->libelle);
    }

    /**
     * Les ops de la demande, **qu'elle soit déjà en base ou non** — `soumettre: true`
     * construit et soumet avant le premier `flush`.
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
