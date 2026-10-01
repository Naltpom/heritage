<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\FiliationPrevue;
use App\Module\Proposal\Contract\MotifDeRattachement;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Proposal\Service\Port\PortMedias;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * OÙ UNE FILIATION ATTERRIT — ce que `Proposal` sait et que `Tree` ne voit pas.
 *
 * ## Le défaut du 01/10/2026
 *
 * Quatre demandes validées, toutes justes une à une, ont donné à Véronique (469) trois
 * familles de parents :
 *
 * | Demande | Lien | Ce que la validation a fait |
 * |---|---|---|
 * | 714 | Hélène (468) `conjoint_de` Jean-Marie (450) | famille 183 = 450 × 468 — juste |
 * | 715 | Véronique (469) `enfant_de` 450, sans second parent | **famille 184 = 450 seul**, créée |
 * | 725 | 469 `enfant_de` 468, sans second parent | **famille 189 = 468 seule**, créée |
 * | 727 | 469 `enfant_de` 468 et 450 | rattachée à 183 — mais 184 et 189 restent |
 *
 * La cause tenait en une ligne de `ApplicateurDeCreation::couple()` : un `enfant_de` à un
 * parent visait le couple `(P, ∅)`, que `trouverOuCreerFamille()` ne trouvait pas et
 * créait. Ni la famille que l'enfant avait déjà, ni l'union du parent n'étaient
 * regardées. Les règles qui la remplacent sont en tête de
 * {@see PortArbre::etablirFiliation()} ; elles vivent dans `Tree`, qui seul sait ranger
 * un couple et lire `tree_child`.
 *
 * ## Ce qui reste ici
 *
 * Trois choses que `Tree` ne peut pas faire sans enfreindre la règle 3 de l'ADR 0002 :
 *
 * 1. **La règle 3 (union unique) n'est admise que pour un parent qui existait avant la
 *    demande, et que la demande ne marie pas.** Sinon l'annonce faite à l'administrateur
 *    — calculée sur l'arbre courant — et l'écriture — faite après les autres liens de la
 *    même demande — pourraient diverger. Un administrateur qui lit « sera rattachée à un
 *    parent seul » et trouve l'enfant chez un couple serait surpris, et c'est
 *    précisément ce qu'on corrige.
 * 2. **Une famille vidée n'est supprimée que si rien ne la désigne** : ni média (qui
 *    appartient à `Media`), ni demande en attente — une cible, une op ou un lien
 *    `family_id`. Supprimer une famille qu'une demande désigne rendrait cette demande
 *    caduque par un effet de bord que personne n'aurait vu passer.
 * 3. **L'annonce**, en mots, pour l'écran de décision comme pour la révision.
 *
 * ## Le choix de la règle 3, et pourquoi
 *
 * « Enfant de Jean-Marie », quand Jean-Marie n'a qu'une union connue, avec Hélène :
 * l'enfant est rattaché au couple. Garder la famille à un parent, c'était fabriquer la
 * fiche « fantôme » que le commanditaire voit mal, et presque toujours à tort — dans cet
 * arbre, un parent à une seule union a ses enfants de cette union. Le cas contraire (un
 * enfant d'une union antérieure inconnue) existe, et c'est pour lui que la décision est
 * **annoncée avant la validation** : « sera rattaché·e au couple Jean-Marie × Hélène,
 * seule union connue de Jean-Marie ». L'administrateur qui sait mieux refuse, et la
 * demande se refait avec le second parent. Une famille à un parent créée en silence, à
 * l'inverse, ne se voyait qu'après, dans l'arbre.
 */
final readonly class RattachementDesFiliations
{
    public function __construct(
        private PortArbre $arbre,
        private PortMedias $medias,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Le lien passe-t-il par {@see PortArbre::etablirFiliation()} ?
     *
     * `enfant_de` et `parent_de` disent la même arête dans les deux sens : les traiter
     * différemment serait rouvrir, par `parent_de`, le défaut qu'on ferme par `enfant_de`.
     * Une filiation qui désigne une famille par `family_id` n'y passe que si cette
     * famille a deux époux — c'est alors exactement « deux parents », règle 2 comprise ;
     * une famille à un époux désignée explicitement est respectée telle quelle.
     */
    public function estUneFiliation(DraftLink $lien): bool
    {
        return match ($lien->getKind()) {
            LinkKind::ConjointDe => false,
            LinkKind::ParentDe => true,
            LinkKind::EnfantDe => null === $lien->getFamilyId() || 2 === \count($this->arbre->epouxDeFamille($lien->getFamilyId())),
        };
    }

    /**
     * Les annonces de toutes les filiations d'une demande, par `seq` — pour l'écran
     * d'administration.
     *
     * Un lien dont un parent n'existe pas encore (fiche de la même demande, ou d'une
     * autre encore en attente) n'a pas d'annonce : rien ne se prévoit sur une personne qui
     * n'existe pas, et deviner serait pire que se taire.
     *
     * @return array<int, array<string, mixed>>
     */
    public function annonces(ChangeRequest $demande): array
    {
        if (RequestStatus::EnAttente !== $demande->getStatus() && RequestStatus::Brouillon !== $demande->getStatus()) {
            return [];
        }

        $liens = $this->liens->pourDemande($demande);
        $conjoints = $this->conjointsDeclares($liens);
        $annonces = [];

        foreach ($liens as $lien) {
            if (!$this->estUneFiliation($lien)) {
                continue;
            }

            $prevue = $this->prevoir($lien, $conjoints);

            if (null !== $prevue) {
                $parent = LinkKind::ParentDe === $lien->getKind() ? $lien->getSubjectLabel() : $lien->getOtherLabel();
                $annonces[$lien->getSeq()] = $this->annonce($prevue, $demande->getId(), $parent);
            }
        }

        return $annonces;
    }

    /**
     * Une famille vidée peut-elle partir ? Non si un média la porte, ou si une **autre**
     * demande en attente la désigne.
     */
    public function familleProtegee(int $familleId, ?int $demandeCourante): bool
    {
        if ($this->medias->compterLesMediasDe(SubjectKind::Famille->value, $familleId) > 0) {
            return true;
        }

        $enAttente = static fn (string $dql): string => $dql.' AND d.status = :statut AND d.id <> :courante';
        $parametres = ['famille' => $familleId, 'statut' => RequestStatus::EnAttente, 'courante' => $demandeCourante ?? 0];

        foreach ([
            $enAttente(\sprintf('SELECT COUNT(d.id) FROM %s d WHERE d.targetKind = :kind AND d.targetId = :famille', ChangeRequest::class)),
            $enAttente(\sprintf('SELECT COUNT(o.id) FROM %s o JOIN o.request d WHERE o.subjectKind = :kind AND o.subjectId = :famille', ChangeRequestOp::class)),
        ] as $dql) {
            if ((int) $this->em->createQuery($dql)->setParameters($parametres + ['kind' => SubjectKind::Famille])->getSingleScalarResult() > 0) {
                return true;
            }
        }

        $liens = $enAttente(\sprintf('SELECT COUNT(l.id) FROM %s l JOIN l.request d WHERE l.familyId = :famille', DraftLink::class));

        return (int) $this->em->createQuery($liens)->setParameters($parametres)->getSingleScalarResult() > 0;
    }

    /**
     * Ce que dira l'annonce : la destination, et pourquoi quand elle ne se devine pas.
     *
     * Partagée avec la révision ({@see ApplicateurDeCreation}) : ce que l'administrateur a
     * lu avant de valider est, au mot près, ce que l'historique garde.
     */
    public static function phrase(MotifDeRattachement $motif, string $libelle, ?string $parent = null): string
    {
        $parent ??= 'ce parent';

        return match ($motif) {
            MotifDeRattachement::DejaRattache => \sprintf('Déjà enfant de « %s » : rien à créer.', $libelle),
            MotifDeRattachement::Couple => \sprintf('Rattaché·e au couple « %s ».', $libelle),
            MotifDeRattachement::ParentSeul => \sprintf('Rattaché·e à « %s » seul·e, sans second parent.', $libelle),
            MotifDeRattachement::UnionUnique => \sprintf('Sera rattaché·e au couple « %s », seule union connue de %s.', $libelle, $parent),
            MotifDeRattachement::FamilleCompletee => \sprintf('Complète sa famille de parents, qui devient « %s ».', $libelle),
            MotifDeRattachement::CoupleExistant => \sprintf('Sera rattaché·e au couple existant « %s » plutôt qu’à une famille à un seul parent.', $libelle),
            MotifDeRattachement::CoupleForme => \sprintf('Forme le couple « %s » : sa famille à un seul parent compte d’autres enfants, qui n’y sont pas déplacés.', $libelle),
        };
    }

    /**
     * @param array<int, int|null> $conjoints personne existante → son conjoint déclaré par la demande,
     *                                        `null` quand ce conjoint n'existe pas encore
     */
    private function prevoir(DraftLink $lien, array $conjoints): ?FiliationPrevue
    {
        [$enfant, $parents, $nouveau] = $this->extremites($lien, $conjoints);

        if ($nouveau || [] === $parents) {
            return null;
        }

        // Même règle que l'applicateur : l'union unique ne joue que pour un parent que
        // la demande ne marie pas — sinon « sa seule union » d'aujourd'hui n'est peut-être
        // plus la seule une fois la demande écrite.
        $unionUnique = 1 === \count($parents) && !\array_key_exists($parents[0], $conjoints);

        return $this->arbre->prevoirFiliation($enfant, $parents[0], $parents[1] ?? null, $unionUnique);
    }

    /**
     * @return array<string, mixed>
     */
    private function annonce(FiliationPrevue $prevue, ?int $demandeId, ?string $parent): array
    {
        return [
            'motif' => $prevue->motif->value,
            'familleId' => $prevue->familleId,
            'libelle' => $prevue->libelle,
            'phrase' => self::phrase($prevue->motif, $prevue->libelle, $parent),
            // Ce qui mérite d'être lu avant le clic : une destination qui ne se devine pas
            // du lien, une famille quittée, ou un refus annoncé.
            'aSignaler' => $prevue->motif->seDevineMal() || [] !== $prevue->quittees || null !== $prevue->incompatibilite,
            'quittees' => array_map(
                fn (array $quittee): array => [
                    'familleId' => $quittee['familleId'],
                    'libelle' => $quittee['libelle'],
                    'supprimee' => $quittee['seraVide'] && !$this->familleProtegee($quittee['familleId'], $demandeId),
                ],
                $prevue->quittees,
            ),
            'incompatibilite' => $prevue->incompatibilite,
        ];
    }

    /**
     * L'enfant (ou `null` s'il est créé par une demande), les parents en identifiants
     * réels, et `true` si l'un des parents n'existe pas encore.
     *
     * @param array<int, int|null> $conjoints
     *
     * @return array{0: ?int, 1: list<int>, 2: bool}
     */
    private function extremites(DraftLink $lien, array $conjoints): array
    {
        $sujet = $this->reel($lien->getSubjectPersonId(), $lien->getSubjectDraftRef(), $lien->getSubjectDraftPersonId());
        $autre = $this->reel($lien->getOtherPersonId(), $lien->getOtherDraftRef(), $lien->getOtherDraftPersonId());

        if (LinkKind::ParentDe === $lien->getKind()) {
            // `parent_de` + un conjoint déclaré dans la même demande = le couple, comme
            // dans `ApplicateurDeCreation::couple()`.
            $parent = $sujet['id'];
            $conjoint = null === $parent ? null : ($conjoints[$parent] ?? null);
            $conjointNouveau = null !== $parent && \array_key_exists($parent, $conjoints) && null === $conjoint;

            return [
                $autre['id'],
                array_values(array_filter([$parent, $conjoint], static fn (?int $id): bool => null !== $id)),
                $sujet['nouveau'] || $conjointNouveau,
            ];
        }

        if (null !== $lien->getFamilyId()) {
            return [$sujet['id'], $this->arbre->epouxDeFamille($lien->getFamilyId()), false];
        }

        $second = $this->reel($lien->getOther2PersonId(), $lien->getOther2DraftRef(), $lien->getOther2DraftPersonId());
        $parents = array_values(array_filter([$autre['id'], $second['id']], static fn (?int $id): bool => null !== $id));

        return [$sujet['id'], $parents, $autre['nouveau'] || $second['nouveau']];
    }

    /**
     * Une extrémité en identifiant réel. `nouveau` dit qu'elle est désignée mais n'existe
     * pas encore dans l'arbre — une fiche de la demande, ou d'une autre non validée.
     *
     * @return array{id: ?int, nouveau: bool}
     */
    private function reel(?int $personneId, ?string $reference, ?int $brouillonId): array
    {
        if (null !== $personneId) {
            return ['id' => $personneId, 'nouveau' => false];
        }

        if (null !== $reference) {
            return ['id' => null, 'nouveau' => true];
        }

        if (null === $brouillonId) {
            return ['id' => null, 'nouveau' => false];
        }

        $resolue = $this->brouillons->find($brouillonId)?->getResolvedPersonId();

        return ['id' => $resolue, 'nouveau' => null === $resolue];
    }

    /**
     * Les conjoints que la demande déclare, pour les personnes **existantes** : la
     * personne → son conjoint, ou `null` quand ce conjoint est une fiche encore à créer.
     *
     * Pour ces personnes, la règle 3 ne joue pas (leur « seule union » d'aujourd'hui n'est
     * peut-être plus la seule après la validation), et un `parent_de` vise le couple.
     *
     * @param list<DraftLink> $liens
     *
     * @return array<int, int|null>
     */
    private function conjointsDeclares(array $liens): array
    {
        $conjoints = [];

        foreach ($liens as $lien) {
            if (LinkKind::ConjointDe !== $lien->getKind()) {
                continue;
            }

            $sujet = $this->reel($lien->getSubjectPersonId(), $lien->getSubjectDraftRef(), $lien->getSubjectDraftPersonId())['id'];
            $autre = $this->reel($lien->getOtherPersonId(), $lien->getOtherDraftRef(), $lien->getOtherDraftPersonId())['id'];

            if (null !== $sujet) {
                $conjoints[$sujet] = $autre;
            }

            if (null !== $autre) {
                $conjoints[$autre] = $sujet;
            }
        }

        return $conjoints;
    }
}
