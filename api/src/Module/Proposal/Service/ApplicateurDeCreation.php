<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Contract\ApplicateurDeDemande;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\EcritureDeRevision;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\Port\PersonneAcreer;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Proposal\Service\Port\PortMedias;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * RÉSOUDRE UN IDENTIFIANT QUI N'EXISTE PAS ENCORE — § 9.5 du modèle de données.
 *
 * C'est le point dur de la création, et il tient en une phrase : **les identifiants
 * provisoires ne sont jamais convertis en base ; ils sont résolus une fois, en mémoire,
 * dans une transaction, et la base ne voit que des identifiants réels.**
 *
 * ── L'ordre des passes est ce qui rend le problème simple ───────────────────────
 *
 * | Passe | Ce qui se passe |
 * |---|---|
 * | 2 | chaque brouillon devient une `tree_person` ; `carte["#p1"] = 312` |
 * | 3 | chaque extrémité de lien `*_draft_ref` est remplacée par `carte[ref]` |
 * | 4 | chaque lien détermine son couple ; `Tree` trouve **ou** crée la famille |
 * | 5 | dates d'union portées, filiations rattachées |
 * | 6 | **les médias joints aux brouillons passent sur la personne créée** ({@see PortMedias::resoudreLesBrouillons()}) |
 *
 * La passe 6 a longtemps manqué, et son absence ne pouvait pas se voir : une
 * `creation_personne` porte **0 op**, donc les garde-fous qui attrapent ailleurs une
 * écriture perdue ne s'y déclenchent jamais. Le détail est au point d'appel.
 *
 * Après la passe 3, **plus aucun lien ne parle d'autre chose que d'identifiants
 * réels** : les passes 4 et 5 n'ont plus de cas particulier. C'est l'inverse — résoudre
 * au fil de l'eau — qui produirait des branches partout.
 *
 * La passe 1, le contrôle préalable, n'est pas ici : elle est faite **avant toute
 * écriture** par {@see AnalyseDeDemande}, avec le reste. C'est ce qui rend la validation
 * tout ou rien.
 *
 * ── Les familles se déduisent, elles ne se stockent pas ─────────────────────────
 *
 * Il n'existe aucun `prp_draft_family`. Le couple visé se lit du lien :
 *
 * - `conjoint_de` → couple (sujet, autre) ;
 * - `enfant_de` avec `family_id` → la famille est donnée, rien à créer ;
 * - `enfant_de` avec un ou deux parents → couple (autre, autre2) ;
 * - `parent_de` → couple (sujet, ∅), **sauf** si un autre lien de la même demande
 *   désigne un conjoint : on réutilise alors ce couple-là.
 *
 * Ce dernier cas est celui qui compte : Tourmaline proposée comme épouse de Jargon
 * **et** mère de deux enfants existants ne doit produire **qu'une** famille. C'est
 * `cartefam` qui le garantit — la clé de couple est normalisée et mémorisée, et le
 * second lien retrouve la famille du premier plutôt que d'en demander une seconde.
 */
final readonly class ApplicateurDeCreation implements ApplicateurDeDemande
{
    public function __construct(
        private EntityManagerInterface $em,
        private PortArbre $arbre,
        private ChangeRequestRepository $demandes,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private PortMedias $medias,
    ) {
    }

    public function natures(): array
    {
        return [RequestKind::CreationPersonne, RequestKind::AjoutLien];
    }

    public function appliquer(DemandeEnCours $vue, array $ops, Lecteur $lecteur): array
    {
        // Le contrat ne transporte plus l'entité — il n'a pas à la transporter. Cet
        // applicateur-ci est **interne** à `Proposal` : il a le droit de la relire, et
        // c'est le seul endroit du module où la conversion se fait en sens inverse.
        $demandeId = $vue->id;
        $demande = null === $demandeId ? null : $this->demandes->find($demandeId);

        if (null === $demande || null === $demandeId) {
            return [];
        }

        $ecritures = [];

        // ── Passe 2 : les personnes ─────────────────────────────────────────────
        // `carte` porte la résolution `#p1 → 312`. Rien dans la demande ne contenait
        // 312 : il apparaît ici, et la carte le propage.
        $carte = [];

        // La même résolution, enrichie du libellé : la passe 6 en a besoin pour reprendre
        // `med_media.subject_label` sur le `display_name` de la personne réellement créée.
        $creees = [];

        foreach ($this->brouillons->pourDemande($demande) as $brouillon) {
            $creee = $this->arbre->creerPersonne($this->fiche($brouillon));

            $carte[$brouillon->getLocalRef()] = $creee->id;
            $creees[$brouillon->getLocalRef()] = ['personId' => $creee->id, 'libelle' => $creee->libelle];

            // C'est cette colonne qui permet, six mois plus tard, d'ouvrir la demande et
            // d'arriver sur la fiche réellement créée. Sans elle, une demande validée
            // perd le lien vers son résultat.
            $brouillon->setResolvedPersonId($creee->id);

            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Personne,
                $creee->id,
                $creee->libelle,
                RevisionOperation::Creation,
                $this->diffDeCreation($brouillon),
            );
        }

        // ── Passes 3 à 5 : les liens ────────────────────────────────────────────
        $liens = $this->liens->pourDemande($demande);

        // Les conjoints déclarés, résolus **avant** la boucle. C'est ce qui fait que
        // « conjoint de Jargon » puis « parent de ces deux enfants » ne produit qu'une
        // famille, quel que soit l'ordre des liens — un `parent_de` lu en premier ne
        // peut pas deviner le conjoint qu'un lien suivant désignera.
        $conjoints = $this->conjointsDeclares($liens, $carte);

        $famillesParCouple = [];

        foreach ($liens as $lien) {
            $famille = $this->resoudreLaFamille($lien, $carte, $conjoints, $famillesParCouple, $ecritures);

            if (null === $famille) {
                continue;
            }

            $lien->setResolvedFamilyId($famille);

            $ecritures[] = $this->porterLeLien($lien, $carte, $famille);
        }

        // ── Passe 6 : les médias ────────────────────────────────────────────────
        //
        // **Cette passe manquait**, et son absence était totalement silencieuse. Une
        // `creation_personne` porte **0 op** : `appliquerLesAutresOps()` ne boucle sur
        // rien, et les deux garde-fous qui attrapent ailleurs une écriture perdue
        // (`ValidationDeDemande::exigerUneEcriture()` et `applicateurPour()`) ne se
        // déclenchent jamais ici — il n'y a pas d'op à ne pas appliquer. La preuve jointe
        // au brouillon restait donc pointée sur `#<demande>/<ref>` après validation,
        // c'est-à-dire sur une référence que plus rien ne désigne.
        //
        // Elle n'était pas perdue — la ligne et les octets survivaient — mais elle
        // n'était accessible d'aucun écran : le nœud en attente ayant disparu du graphe,
        // la seule route qui la servait (`GET /api/demandes/{id}/brouillons/{ref}`)
        // n'était plus atteignable. Le membre voyait sa création validée sans sa preuve.
        //
        // Ici, et pas ailleurs : la carte de résolution n'existe qu'à cet instant, en
        // mémoire, dans la transaction — « les identifiants provisoires ne sont jamais
        // convertis en base » (en-tête). Après le `flush`, il faudrait la reconstruire
        // par une jointure sur `resolved_person_id`.
        if ([] !== $creees) {
            $this->medias->resoudreLesBrouillons($demandeId, $creees);
        }

        $this->em->flush();

        return $ecritures;
    }

    /**
     * Passe 4 : le couple visé, trouvé ou créé — **par un service de `Tree`**.
     *
     * C'est `Tree` qui normalise `(husband_id, wife_id)` selon le sexe et qui connaît
     * `uniq_tree_family_couple` ; le faire ici serait le mécanisme exact par lequel une
     * frontière se dissout (ADR 0002, règle 5).
     *
     * `cartefam` évite la seconde famille du cas « conjoint **et** parent dans la même
     * demande » : la clé est le couple normalisé, et deux liens qui visent le même
     * couple retrouvent la même famille.
     *
     * @param array<string, int>       $carte
     * @param array<int, int>          $conjoints
     * @param array<string, int>       $famillesParCouple
     * @param list<EcritureDeRevision> $ecritures
     */
    private function resoudreLaFamille(
        DraftLink $lien,
        array $carte,
        array $conjoints,
        array &$famillesParCouple,
        array &$ecritures,
    ): ?int {
        // Une filiation qui désigne un couple existant n'a rien à déduire : la famille
        // est donnée, et en créer une seconde serait le doublon que le § 9.5 écarte.
        if (null !== $lien->getFamilyId()) {
            return $lien->getFamilyId();
        }

        [$a, $b] = $this->couple($lien, $carte, $conjoints);

        if (null === $a && null === $b) {
            return null;
        }

        $cle = $this->cleDeCouple($a, $b);

        if (isset($famillesParCouple[$cle])) {
            return $famillesParCouple[$cle];
        }

        $famille = $this->arbre->trouverOuCreerFamille($a, $b);
        $famillesParCouple[$cle] = $famille->id;

        // **Une famille retrouvée ne produit pas de révision.** Écrire « famille créée »
        // sur une famille qui existait depuis l'import mettrait dans l'historique un
        // événement qui n'a pas eu lieu.
        if ($famille->creee) {
            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Famille,
                $famille->id,
                $famille->libelle,
                RevisionOperation::Creation,
                ['couple' => ['avant' => null, 'apres' => $famille->libelle]],
            );
        }

        return $famille->id;
    }

    /**
     * Passe 5 : ce que le lien fait à la famille — une union, ou une filiation.
     *
     * @param array<string, int> $carte
     */
    private function porterLeLien(DraftLink $lien, array $carte, int $famille): EcritureDeRevision
    {
        if (LinkKind::ConjointDe === $lien->getKind()) {
            $this->arbre->porterUnion($famille, $lien->getMarriage(), $lien->isDivorced(), $lien->getDivorce());

            return new EcritureDeRevision(
                SubjectKind::Famille,
                $famille,
                $this->libelleDuLien($lien),
                RevisionOperation::LienAjoute,
                [
                    'marriage' => ['avant' => null, 'apres' => $lien->getMarriage()->format()],
                    'divorced' => ['avant' => null, 'apres' => $lien->isDivorced()],
                ],
            );
        }

        // `enfant_de` rattache le **sujet** à la famille ; `parent_de` rattache
        // l'**autre**. Les deux disent la même arête, dans les deux sens — et le
        // demandeur a le droit de la décrire dans celui qui lui vient.
        $enfant = LinkKind::EnfantDe === $lien->getKind()
            ? $this->extremite($lien->getSubjectPersonId(), $lien->getSubjectDraftRef(), $carte)
            : $this->extremite($lien->getOtherPersonId(), $lien->getOtherDraftRef(), $carte);

        if (null !== $enfant) {
            $this->arbre->rattacherEnfant($famille, $enfant, $lien->getSiblingOrder());
        }

        return new EcritureDeRevision(
            SubjectKind::Famille,
            $famille,
            $this->libelleDuLien($lien),
            RevisionOperation::LienAjoute,
            ['filiation' => ['avant' => null, 'apres' => $lien->getKind()->label()]],
        );
    }

    /**
     * Les conjoints que la demande déclare, résolus en identifiants réels.
     *
     * La carte va dans les **deux sens** : « X conjoint de Y » se lit aussi « Y conjoint
     * de X », et le lien `parent_de` suivant peut porter sur l'un ou sur l'autre.
     *
     * @param list<DraftLink>    $liens
     * @param array<string, int> $carte
     *
     * @return array<int, int>
     */
    private function conjointsDeclares(array $liens, array $carte): array
    {
        $conjoints = [];

        foreach ($liens as $lien) {
            if (LinkKind::ConjointDe !== $lien->getKind()) {
                continue;
            }

            $sujet = $this->extremite($lien->getSubjectPersonId(), $lien->getSubjectDraftRef(), $carte);
            $autre = $this->extremite($lien->getOtherPersonId(), $lien->getOtherDraftRef(), $carte);

            if (null !== $sujet && null !== $autre) {
                $conjoints[$sujet] = $autre;
                $conjoints[$autre] = $sujet;
            }
        }

        return $conjoints;
    }

    /**
     * Le couple que ce lien vise, en identifiants réels.
     *
     * Le cas `parent_de` est le seul qui regarde ailleurs que dans son propre lien :
     * « couple (sujet, ∅) **sauf si un conjoint est désigné par un autre lien de la même
     * demande, auquel cas on réutilise ce couple** » (§ 9.5, passe 4). C'est ce qui fait
     * que Tourmaline, proposée épouse de Jargon et mère de deux enfants, produit **une**
     * famille et non deux.
     *
     * @param array<string, int> $carte
     * @param array<int, int>    $conjoints
     *
     * @return array{int|null, int|null}
     */
    private function couple(DraftLink $lien, array $carte, array $conjoints): array
    {
        $sujet = $this->extremite($lien->getSubjectPersonId(), $lien->getSubjectDraftRef(), $carte);
        $autre = $this->extremite($lien->getOtherPersonId(), $lien->getOtherDraftRef(), $carte);
        $second = $this->extremite($lien->getOther2PersonId(), $lien->getOther2DraftRef(), $carte);

        return match ($lien->getKind()) {
            LinkKind::ConjointDe => [$sujet, $autre],
            // Les parents de l'enfant : `autre` et `autre2`, jamais le sujet — c'est lui
            // l'enfant.
            LinkKind::EnfantDe => [$autre, $second],
            LinkKind::ParentDe => [$sujet, null === $sujet ? null : ($conjoints[$sujet] ?? null)],
        };
    }

    /**
     * La clé de couple, **indépendante de l'ordre** : `parent_de` puis `conjoint_de`
     * doivent retrouver le même couple que `conjoint_de` puis `parent_de`. Trier les
     * deux identifiants est ce qui le garantit sans supposer un ordre de saisie.
     */
    private function cleDeCouple(?int $a, ?int $b): string
    {
        $identifiants = array_values(array_filter([$a, $b], static fn (?int $id): bool => null !== $id));
        sort($identifiants);

        return implode(':', $identifiants);
    }

    /** @param array<string, int> $carte */
    private function extremite(?int $personneId, ?string $reference, array $carte): ?int
    {
        if (null !== $personneId) {
            return $personneId;
        }

        return null === $reference ? null : ($carte[$reference] ?? null);
    }

    private function fiche(DraftPerson $brouillon): PersonneAcreer
    {
        return new PersonneAcreer(
            $brouillon->getGivenNames(),
            $brouillon->getSurname(),
            $brouillon->getMarriedName(),
            $brouillon->getNickname(),
            $brouillon->getSex()->value,
            $brouillon->isDeceased(),
            $brouillon->getBirth(),
            $brouillon->getDeath(),
            $brouillon->getNote(),
        );
    }

    /**
     * Pour une création, « `avant` vaut `null` partout » (§ 5.5) — et `apres` est rendu
     * **formaté**, parce que l'historique d'une fiche est fait pour être lu.
     *
     * @return array<string, mixed>
     */
    private function diffDeCreation(DraftPerson $brouillon): array
    {
        $champs = [
            'givenNames' => $brouillon->getGivenNames(),
            'surname' => $brouillon->getSurname(),
            'marriedName' => $brouillon->getMarriedName(),
            'nickname' => $brouillon->getNickname(),
            'sex' => $brouillon->getSex()->value,
            'deceased' => $brouillon->isDeceased(),
            'birth' => $this->texteDeDate($brouillon->getBirth()),
            'death' => $this->texteDeDate($brouillon->getDeath()),
        ];

        $diff = [];

        foreach ($champs as $nom => $valeur) {
            $diff[$nom] = ['avant' => null, 'apres' => $valeur];
        }

        return $diff;
    }

    private function texteDeDate(UncertainDate $date): ?string
    {
        return '' === ($texte = $date->format()) ? null : $texte;
    }

    private function libelleDuLien(DraftLink $lien): string
    {
        $autre = $lien->getOtherLabel() ?? $lien->getFamilyLabel() ?? '';

        return mb_substr(trim($lien->getSubjectLabel().' — '.$autre, ' —'), 0, 255);
    }
}
