<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\Service\ComparaisonDIdentites;
use App\Module\Core\ValueObject\IdentiteComparee;
use App\Module\Core\ValueObject\Ressemblance;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Repository\DraftPersonRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * « UNE PROPOSITION SEMBLABLE ATTEND DÉJÀ » — § 6.6 quater du contrat.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Le formulaire « Ajouter une personne » déposait une `creation_personne` sans rien
 * vérifier. Un doublon ne vient pas seulement d'une personne déjà dans l'arbre : il vient
 * aussi d'une personne **déjà proposée**, par soi-même un autre soir ou par un autre
 * membre. Personne ne pouvait le voir — un membre ne voit pas les propositions des autres
 * (§ 13.3), et seul l'administrateur découvrait le « doublon probable », trop tard.
 *
 * ── L'exception au § 13.3, et ce qui la borne ────────────────────────────────────
 *
 * Cette route parcourt les propositions **de tous les membres** — c'est l'exception écrite
 * au § 13.3, décidée par le commanditaire le 09/10/2026. Ce qui sort de chacune est décidé
 * **ici, par le serveur seul**, fiche par fiche :
 *
 * | La proposition | Forme | Ce qui est comparé | Ce qui sort |
 * |---|---|---|---|
 * | du lecteur (identité effective) | `complete` | tout | la ligne du § 6.6 bis, le lieu, l'auteur, le statut |
 * | de n'importe qui, lue par un administrateur hors bascule | `complete` | tout | idem |
 * | d'un **défunt**, par un autre membre | `complete` | tout | idem — un défunt est un fait historique, visible de tout compte |
 * | d'un **vivant**, par un autre membre | `discrete` | nom, prénoms, sexe | `{forme, draftPersonId, action, criteres, raisons}` — rien d'autre |
 *
 * La forme discrète ne compare **ni dates ni lieux** — ni ceux de la fiche, ni ceux de la
 * saisie. Sans quoi la route deviendrait un oracle de date de naissance : saisir 1934,
 * puis 1935, et regarder quand le signal s'éteint. Ses raisons sont génériques (« nom
 * semblable », « un prénom en commun ») et ne recopient aucune valeur de la fiche. Le
 * front ne reconstruit jamais ce qui est caché : il affiche la phrase, et propose de
 * déposer un complément.
 *
 * Les brouillons **non envoyés** ne sont parcourus que pour leur auteur — administrateur
 * compris — par {@see DraftPersonRepository::pourRessemblance()}.
 *
 * ── `action`, calculée ici et nulle part ailleurs ────────────────────────────────
 *
 * - `completer` : le lecteur peut compléter la proposition **sur place** — il en est
 *   l'auteur (brouillon ou en attente), ou il est administrateur et elle est en attente ;
 * - `complement` : dans tous les autres cas, il dépose un complément rattaché, que
 *   l'administrateur fusionnera.
 *
 * Sous la bascule « voir comme un membre », l'administrateur lit **comme le membre** :
 * `Lecteur::voitToutesLesDemandes()` est faux, l'identité effective est celle du membre —
 * les formes, les brouillons parcourus et les actions sont donc les siens.
 *
 * ── Ce que cette route ne change pas ─────────────────────────────────────────────
 *
 * Ni {@see RechercheDeBrouillons}, ni le graphe, ni le calque : un membre ne **voit**
 * toujours pas les propositions des autres dans l'arbre, il apprend seulement qu'une
 * proposition semblable à la sienne existe.
 */
final readonly class RessemblanceDesPropositions
{
    public const string FORME_COMPLETE = 'complete';
    public const string FORME_DISCRETE = 'discrete';

    public const string ACTION_COMPLETER = 'completer';
    public const string ACTION_COMPLEMENT = 'complement';

    public function __construct(
        private DraftPersonRepository $brouillons,
        private ComparaisonDIdentites $comparaison,
        private SerialiseurDeDemande $serialiseur,
        private CleDeRecherche $cles = new CleDeRecherche(),
    ) {
    }

    /**
     * Les propositions qui ressemblent à cette saisie, triées puis coupées à `$limite`.
     *
     * `tronque` se calcule sur le jeu **déjà filtré**, comme au § 5.5 ; pas de `total`.
     *
     * @param int|null $sauf une demande à ne pas parcourir — celle qu'on complète
     *
     * @return array{elements: list<array<string, mixed>>, tronque: bool}
     */
    public function resultats(IdentiteComparee $saisie, int $limite, Lecteur $lecteur, ?int $sauf = null): array
    {
        // Le garde-fou du § 13.3, en tête : un compte en attente de rattachement ne dépose
        // pas de demande — il n'a rien à compléter, et aucune proposition d'autrui à
        // apprendre. Même réponse que la recherche des brouillons (§ 6.6 bis).
        if ($lecteur->regimeEnAttente) {
            return ['elements' => [], 'tronque' => false];
        }

        /** @var list<array{brouillon: DraftPerson, ressemblance: Ressemblance, complete: bool, cle: string}> $retenues */
        $retenues = [];

        foreach ($this->brouillons->pourRessemblance($lecteur->identiteEffective(), $sauf) as $brouillon) {
            $complete = $this->enClair($brouillon, $lecteur);
            // Forme discrète : ni dates ni lieux, **des deux côtés**, avant même la
            // comparaison — qui les retire elle aussi. Deux verrous pour la seule règle de
            // cette route dont la rupture ne se verrait pas.
            $ressemblance = $complete
                ? $this->comparaison->comparer($saisie, self::identite($brouillon))
                : $this->comparaison->comparer($saisie->sansDatesNiLieux(), self::identite($brouillon)->sansDatesNiLieux(), true);

            if (null === $ressemblance) {
                continue;
            }

            $retenues[] = [
                'brouillon' => $brouillon,
                'ressemblance' => $ressemblance,
                'complete' => $complete,
                // La clé de tri d'une proposition discrète est **la saisie** : celle de la
                // fiche ferait dire à l'ordre des réponses où son nom se range dans
                // l'alphabet. À score égal, elles se départagent par leur identifiant.
                'cle' => $complete ? $this->cleDeNom($brouillon) : $this->cles->cleDeNom($saisie->prenoms, $saisie->nom),
            ];
        }

        usort($retenues, static fn (array $a, array $b): int => [
            $b['ressemblance']->score,
            $b['ressemblance']->nomExact,
            $a['cle'],
            $a['brouillon']->getId(),
        ] <=> [
            $a['ressemblance']->score,
            $a['ressemblance']->nomExact,
            $b['cle'],
            $b['brouillon']->getId(),
        ]);

        $elements = [];

        foreach (\array_slice($retenues, 0, $limite) as $retenue) {
            $elements[] = $this->ligne($retenue['brouillon'], $retenue['ressemblance'], $retenue['complete'], $lecteur);
        }

        return ['elements' => $elements, 'tronque' => \count($retenues) > $limite];
    }

    /**
     * La proposition se montre-t-elle **en clair** à ce lecteur ? Ses propres propositions,
     * toutes pour un administrateur hors bascule, et celle d'un défunt pour quiconque.
     */
    public function enClair(DraftPerson $brouillon, Lecteur $lecteur): bool
    {
        return $lecteur->estAuteurDe($brouillon->getRequest()->getAuthorUserId())
            || $lecteur->voitToutesLesDemandes()
            || $brouillon->isDeceased();
    }

    /**
     * `completer` ou `complement` — ce que le lecteur peut faire de cette proposition.
     *
     * L'auteur complète sa proposition sur place, envoyée ou non ; l'administrateur
     * complète sur place une proposition **en attente** (jamais le brouillon d'un autre,
     * qu'il ne voit d'ailleurs pas). Tout autre lecteur dépose un complément.
     */
    public function action(DraftPerson $brouillon, Lecteur $lecteur): string
    {
        $demande = $brouillon->getRequest();

        if ($lecteur->estAuteurDe($demande->getAuthorUserId())) {
            return self::ACTION_COMPLETER;
        }

        if ($lecteur->voitToutesLesDemandes() && RequestStatus::EnAttente === $demande->getStatus()) {
            return self::ACTION_COMPLETER;
        }

        return self::ACTION_COMPLEMENT;
    }

    /**
     * Le nœud de cette fiche **dans le graphe du lecteur**, ou `null`.
     *
     * Le graphe ne porte une fiche proposée que si sa demande est **en attente** et que le
     * lecteur en reçoit la projection — l'auteur, ou un administrateur hors bascule
     * (`LectureDesPropositions::projection()`, § 13.4). Un brouillon non envoyé n'y est pas,
     * ni la proposition d'un défunt faite par un autre membre, même montrée en clair ici.
     *
     * La projection peut encore **écarter** un nœud qu'elle ne sait pas placer
     * (`ecartes`, § 5.2) : le front vérifie qu'il est dessiné avant de le sélectionner.
     */
    public function noeudId(DraftPerson $brouillon, Lecteur $lecteur): ?string
    {
        $demande = $brouillon->getRequest();
        $demandeId = $demande->getId();

        if (null === $demandeId || RequestStatus::EnAttente !== $demande->getStatus()) {
            return null;
        }

        if (!$lecteur->voitToutesLesDemandes() && !$lecteur->estAuteurDe($demande->getAuthorUserId())) {
            return null;
        }

        return $this->serialiseur->noeudId($demandeId, $brouillon->getLocalRef());
    }

    /** @return array<string, mixed> */
    private function ligne(DraftPerson $brouillon, Ressemblance $ressemblance, bool $complete, Lecteur $lecteur): array
    {
        $action = $this->action($brouillon, $lecteur);

        if (!$complete) {
            // Par construction : discrète veut dire « ni l'auteur, ni un administrateur hors
            // bascule », et l'un ou l'autre est la condition de `completer`.
            \assert(self::ACTION_COMPLEMENT === $action);

            return $this->serialiseur->ressemblanceDiscrete($brouillon, $action, $ressemblance->criteres, $ressemblance->raisons);
        }

        return $this->serialiseur->ressemblanceComplete(
            $brouillon,
            $this->noeudId($brouillon, $lecteur),
            $action,
            $ressemblance->criteres,
            $ressemblance->raisons,
            $ressemblance->score,
        );
    }

    /**
     * La fiche proposée, **entière** — la forme discrète la réduit par
     * {@see IdentiteComparee::sansDatesNiLieux()} avant de la comparer.
     */
    private static function identite(DraftPerson $brouillon): IdentiteComparee
    {
        return new IdentiteComparee(
            $brouillon->getGivenNames(),
            $brouillon->getSurname(),
            $brouillon->getMarriedName(),
            $brouillon->getNickname(),
            $brouillon->getSex(),
            $brouillon->getBirth(),
            $brouillon->getDeath(),
        );
    }

    private function cleDeNom(DraftPerson $brouillon): string
    {
        return $this->cles->cleDeNom(
            $brouillon->getGivenNames(),
            $brouillon->getSurname(),
            $brouillon->getNickname(),
            $brouillon->getMarriedName(),
        );
    }
}
