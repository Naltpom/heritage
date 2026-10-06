<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\Sex;
use App\Module\Proposal\Contract\ArbrePourLesDoublons;
use App\Module\Proposal\Contract\PersonneCandidate;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * « Attention, cette personne existe déjà, ou est en cours de validation » — le
 * rapprochement **avant dépôt** d'une création (`POST /api/demandes/doublons-probables`,
 * § 6.3 ter du contrat d'API).
 *
 * Le besoin, dans les mots du commanditaire : *« dans le formulaire de création de
 * personne, vérifier si doublon soit dans l'arbre soit déjà en demande »*. Jusqu'ici, le
 * seul rapprochement était celui de l'administrateur, **après** coup, entre demandes en
 * attente ({@see CleDeDoublon}) — il ne voyait pas l'arbre, et il arrivait quand le
 * membre avait déjà tout saisi.
 *
 * ── Trois sources, trois règles de divulgation ───────────────────────────────────
 *
 * | Source | Ce qui est rendu | Pourquoi |
 * |---|---|---|
 * | **L'arbre** | la fiche, ses dates, ses proches | exactement ce que la recherche (§ 5.5) et la fiche (§ 5.3) montrent déjà à ce lecteur : le filtre est celui du cercle de `Tree`, réciprocité comprise ({@see ArbrePourLesDoublons}) |
 * | **Ses propres demandes en attente** | la fiche proposée en entier, la demande, ses liens | il en est l'auteur : c'est le calque « en attente » qu'il voit déjà (§ 13.3) |
 * | **Les demandes des autres** | `enCoursAilleurs: true`, **et rien d'autre** — pour une personne **décédée** seulement | voir ci-dessous |
 *
 * ── Les demandes des autres : un drapeau, et pour les défunts seulement ─────────
 *
 * Le contenu d'une demande en attente n'appartient qu'à son auteur et aux
 * administrateurs : « les autres membres voient les données validées, point »
 * (ADR 0004). On n'en rend donc **ni le contenu, ni l'auteur, ni l'identifiant, ni le
 * nombre** — un booléen, qui dit seulement qu'une proposition semblable attend déjà la
 * validation. C'est le même degré d'information que l'avertissement que l'ADR 0004
 * prévoit pour deux modifications concurrentes (« une modification est déjà en attente
 * sur cette fiche »), et il suffit au geste attendu : ne pas redéposer, ou déposer en le
 * sachant.
 *
 * Il n'est levé que pour une fiche proposée **décédée**. Une personne vivante proposée par
 * un autre ne déclenche rien, pour deux raisons qui tiennent chacune seule :
 *
 * 1. le rapprochement compare la **naissance** ; un drapeau qui s'allume quand on tape
 *    la bonne année serait un oracle sur la date de naissance d'un vivant, que la
 *    réciprocité (ADR 0016) refuse justement de servir ;
 * 2. une fiche proposée n'est dans aucun cercle (§ 13.4) : rien ne dit au serveur que ce
 *    lecteur aurait le droit de la voir une fois validée — une fiche confidentielle, par
 *    exemple.
 *
 * Le doublon de vivants entre deux membres reste rattrapé là où il l'était : par
 * l'administrateur, à la validation (`doublonsProbables`, § 6.7).
 *
 * ── Ce que la route n'écrit pas ─────────────────────────────────────────────────
 *
 * Rien. Elle lit le corps d'une création (§ 6.3), le valide comme le dépôt le ferait
 * ({@see ConstructeurDeCreation::lireLaFiche()}) et compare. Le plafond de débit
 * ({@see LimiteurDesDoublons}) borne l'usage de la route comme annuaire.
 */
final readonly class DoublonsAvantDepot
{
    /** La meilleure correspondance d'abord ; au-delà de cinq, le membre ne lit plus. */
    public const int PLAFOND = 5;

    /** Candidats demandés à l'arbre **par terme**, avant comparaison. */
    private const int CANDIDATS_PAR_TERME = 20;

    public function __construct(
        private ConstructeurDeCreation $creation,
        private ArbrePourLesDoublons $arbre,
        private PortArbre $arbreDesDemandes,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private RapprochementDeDoublons $rapprochement,
        private SerialiseurDeDemande $serialiseur,
        private NormaliseurDeValeur $normaliseur,
    ) {
    }

    /**
     * @param array<string, mixed> $corps le corps d'une création (§ 6.3)
     *
     * @return array{personnes: list<array<string, mixed>>, mesDemandes: list<array<string, mixed>>, enCoursAilleurs: bool}
     */
    public function pour(array $corps, Lecteur $lecteur): array
    {
        // Le régime `ATTENTE` ne lit pas l'arbre et ne dépose pas : la route le refuse
        // déjà avant le contrôleur, et ce service ne compte pas sur elle.
        if ($lecteur->regimeEnAttente) {
            throw AccesRefuse::regimeEnAttente();
        }

        $fiche = $this->creation->lireLaFiche($corps, $lecteur);
        $saisie = $this->identiteDeLaSaisie($fiche, $corps);

        return [
            'personnes' => $this->dansLArbre($fiche, $saisie),
            ...$this->enAttente($saisie, $lecteur),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dansLArbre(DraftPerson $fiche, IdentiteComparee $saisie): array
    {
        $trouves = [];

        foreach ($this->termes($fiche) as $terme) {
            foreach ($this->arbre->candidats($terme, self::CANDIDATS_PAR_TERME) as $candidate) {
                if (isset($trouves[$candidate->personId])) {
                    continue;
                }

                $rapprochement = $this->rapprochement->comparer($saisie, $this->identiteDeLArbre($candidate));

                if (null !== $rapprochement) {
                    $trouves[$candidate->personId] = [$rapprochement, $candidate];
                }
            }
        }

        uasort($trouves, static fn (array $a, array $b): int => [$b[0]->score, $a[1]->personId] <=> [$a[0]->score, $b[1]->personId]);

        return array_values(array_map(
            fn (array $paire): array => $this->ligneDeLArbre($paire[1], $paire[0]),
            \array_slice($trouves, 0, self::PLAFOND, true),
        ));
    }

    /**
     * @return array{mesDemandes: list<array<string, mixed>>, enCoursAilleurs: bool}
     */
    private function enAttente(IdentiteComparee $saisie, Lecteur $lecteur): array
    {
        $auteur = $lecteur->identiteEffective();
        $miens = [];
        $ailleurs = false;

        // **Toutes** les fiches en attente, filtrées ici : une requête pour les siennes et
        // une pour celles des autres liraient deux fois la même table, à quelques
        // dizaines de lignes. Ce qui sort des autres est un booléen, rien de plus.
        foreach ($this->brouillons->enAttenteVisibles(null) as $brouillon) {
            $estAMoi = $brouillon->getRequest()->getAuthorUserId() === $auteur;

            if (!$estAMoi && ($ailleurs || !$brouillon->isDeceased())) {
                continue;
            }

            $liens = $this->liensDuSujet($brouillon);
            $rapprochement = $this->rapprochement->comparer($saisie, $this->identiteDuBrouillon($brouillon, $liens));

            if (null === $rapprochement) {
                continue;
            }

            if ($estAMoi) {
                $miens[] = [$rapprochement, $brouillon];
            } else {
                $ailleurs = true;
            }
        }

        usort($miens, static fn (array $a, array $b): int => $b[0]->score <=> $a[0]->score);

        return [
            'mesDemandes' => array_map(
                fn (array $paire): array => $this->ligneDeMaDemande($paire[1], $paire[0]),
                \array_slice($miens, 0, self::PLAFOND),
            ),
            'enCoursAilleurs' => $ailleurs,
        ];
    }

    /**
     * Les termes cherchés dans l'arbre : « patronyme prénom », et « nom d'usage prénom »
     * s'il y en a un. La recherche exige **chaque** mot : un terme par nom, sinon une
     * épouse saisie sous ses deux noms ne serait trouvée que si l'arbre porte les deux.
     *
     * @return list<string>
     */
    private function termes(DraftPerson $fiche): array
    {
        $morceaux = preg_split('/\s+/u', trim($fiche->getGivenNames()), 2);
        $prenom = \is_array($morceaux) ? ($morceaux[0] ?? '') : '';
        $noms = array_filter(
            [trim($fiche->getSurname()), trim($fiche->getMarriedName() ?? '')],
            static fn (string $nom): bool => '' !== $nom,
        );

        if ([] === $noms) {
            return '' === $prenom ? [] : [$prenom];
        }

        return array_values(array_unique(array_map(
            static fn (string $nom): string => trim($nom.' '.$prenom),
            $noms,
        )));
    }

    /**
     * La saisie, et ses proches **de l'arbre** lus dans les liens du corps. Lecture
     * indulgente : les liens n'ont pas à être valides pour comparer — seuls les
     * identifiants entiers comptent, et ils ne sont confrontés qu'à des proches que le
     * lecteur voit déjà.
     *
     * @param array<string, mixed> $corps
     */
    private function identiteDeLaSaisie(DraftPerson $fiche, array $corps): IdentiteComparee
    {
        $parents = [];
        $conjoints = [];
        $enfants = [];
        $liens = $corps['liens'] ?? [];

        foreach (\is_array($liens) ? $liens : [] as $lien) {
            if (!\is_array($lien)) {
                continue;
            }

            $autres = array_values(array_filter(
                [$lien['otherPersonId'] ?? null, $lien['other2PersonId'] ?? null],
                static fn (mixed $id): bool => \is_int($id),
            ));

            match ($lien['kind'] ?? null) {
                LinkKind::EnfantDe->value => array_push($parents, ...$autres, ...$this->epoux($lien['familyId'] ?? null)),
                LinkKind::ConjointDe->value => array_push($conjoints, ...$autres),
                LinkKind::ParentDe->value => array_push($enfants, ...$autres),
                default => null,
            };
        }

        return new IdentiteComparee(
            $fiche->getGivenNames(),
            $fiche->getSurname(),
            $fiche->getMarriedName(),
            $fiche->getSex(),
            $fiche->getBirth(),
            $fiche->getDeath(),
            $parents,
            $conjoints,
            $enfants,
        );
    }

    private function identiteDeLArbre(PersonneCandidate $candidate): IdentiteComparee
    {
        return new IdentiteComparee(
            $candidate->givenNames,
            $candidate->surname,
            $candidate->marriedName,
            Sex::tryFrom($candidate->sex) ?? Sex::Inconnu,
            $candidate->naissance,
            $candidate->deces,
            array_column($candidate->parents, 'personId'),
            array_column($candidate->conjoints, 'personId'),
            array_column($candidate->enfants, 'personId'),
        );
    }

    /** @param list<DraftLink> $liens les liens dont la fiche est le sujet */
    private function identiteDuBrouillon(DraftPerson $brouillon, array $liens): IdentiteComparee
    {
        $parents = [];
        $conjoints = [];
        $enfants = [];

        foreach ($liens as $lien) {
            $autres = array_values(array_filter([$lien->getOtherPersonId(), $lien->getOther2PersonId()], static fn (?int $id): bool => null !== $id));

            match ($lien->getKind()) {
                LinkKind::EnfantDe => array_push($parents, ...$autres, ...$this->epoux($lien->getFamilyId())),
                LinkKind::ConjointDe => array_push($conjoints, ...$autres),
                LinkKind::ParentDe => array_push($enfants, ...$autres),
            };
        }

        return new IdentiteComparee(
            $brouillon->getGivenNames(),
            $brouillon->getSurname(),
            $brouillon->getMarriedName(),
            $brouillon->getSex(),
            $brouillon->getBirth(),
            $brouillon->getDeath(),
            $parents,
            $conjoints,
            $enfants,
        );
    }

    /** @return list<int> les époux d'un couple désigné par `familyId` */
    private function epoux(mixed $familleId): array
    {
        return \is_int($familleId) ? $this->arbreDesDemandes->epouxDeFamille($familleId) : [];
    }

    /** @return list<DraftLink> */
    private function liensDuSujet(DraftPerson $brouillon): array
    {
        return array_values(array_filter(
            $this->liens->pourDemande($brouillon->getRequest()),
            static fn (DraftLink $lien): bool => $lien->getSubjectDraftRef() === $brouillon->getLocalRef(),
        ));
    }

    /**
     * Une personne de l'arbre, sous la forme de sa fiche (§ 5.3) — limitée à ce que le
     * rapprochement compare : identité, dates, proches.
     *
     * @return array<string, mixed>
     */
    private function ligneDeLArbre(PersonneCandidate $candidate, Rapprochement $rapprochement): array
    {
        return [
            'personId' => $candidate->personId,
            'displayName' => $candidate->displayName,
            'givenNames' => $candidate->givenNames,
            'surname' => $candidate->surname,
            'marriedName' => $candidate->marriedName,
            'nickname' => $candidate->nickname,
            'sex' => $candidate->sex,
            'deceased' => $candidate->deceased,
            'vivant' => $candidate->vivant,
            'birth' => $candidate->blocNaissance,
            'death' => $candidate->blocDeces,
            'ligneDates' => $candidate->ligneDates,
            'parents' => $candidate->parents,
            'conjoints' => $candidate->conjoints,
            'enfants' => $candidate->enfants,
            'criteres' => $rapprochement->criteres,
        ];
    }

    /**
     * Une fiche proposée **par le lecteur**, avec de quoi la compléter : la demande, la
     * référence de la fiche, ses liens sous la forme du § 6.5.
     *
     * @return array<string, mixed>
     */
    private function ligneDeMaDemande(DraftPerson $brouillon, Rapprochement $rapprochement): array
    {
        $demande = $brouillon->getRequest();
        $demandeId = $demande->getId() ?? 0;

        return [
            'demandeId' => $demandeId,
            'draftPersonId' => $brouillon->getId(),
            'localRef' => $brouillon->getLocalRef(),
            'noeudId' => $this->serialiseur->noeudId($demandeId, $brouillon->getLocalRef()),
            'status' => $demande->getStatus()->value,
            'submittedAt' => $demande->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
            'displayName' => $brouillon->nomPropose(),
            'givenNames' => $brouillon->getGivenNames(),
            'surname' => $brouillon->getSurname(),
            'marriedName' => $brouillon->getMarriedName(),
            'nickname' => $brouillon->getNickname(),
            'sex' => $brouillon->getSex()->value,
            'deceased' => $brouillon->isDeceased(),
            'birth' => $this->normaliseur->dateAvecTexte($brouillon->getBirth()),
            'death' => $this->normaliseur->dateAvecTexte($brouillon->getDeath()),
            'ligneDates' => $this->normaliseur->ligneDates($brouillon->getBirth(), $brouillon->getDeath()),
            'note' => $brouillon->getNote(),
            'liens' => $this->serialiseur->liensDuSujet($brouillon),
            'criteres' => $rapprochement->criteres,
        ];
    }
}
