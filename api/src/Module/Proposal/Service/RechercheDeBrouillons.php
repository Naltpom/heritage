<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Repository\DraftPersonRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * CHERCHER PARMI LES PERSONNES **PROPOSÉES** — le pendant du § 5.5, côté `Proposal`.
 *
 * `GET /api/arbre/recherche` cherche dans l'arbre **validé**, et c'est sa définition :
 * il applique le cercle de visibilité, et une personne qui n'a pas d'identifiant n'y
 * figure pas. Une fiche encore en attente n'y était donc trouvable par personne — pas
 * même par celui qui venait de la proposer :
 *
 * > « Si je propose une personne, elle apparaît dans mon arbre et celui de l'admin ;
 * > mais si je repousse une autre personne et que je cherche le père que j'ai proposé
 * > juste avant, non validé encore mais proposé par moi, il ne m'est pas proposé. »
 *
 * ── Pourquoi une seconde route et non une clé de plus dans la première ──────────
 *
 * Parce que les deux réponses ne se ressemblent pas et ne se filtrent pas pareil. Une
 * ligne de l'arbre porte un `personId` ; une fiche proposée n'en a pas et porte un
 * `demandeId` avec sa référence provisoire. Les glisser dans le même tableau
 * obligerait chaque client à tester quelle forme il tient — « et le premier qui
 * l'oublie enverra `personId: null` au serveur ». Surtout, **le filtre n'est pas le
 * même** : l'arbre se filtre par le cercle de visibilité (ADR 0003), les demandes par
 * la règle du § 13.3 — auteur et administrateurs, personne d'autre. Une route par
 * règle, c'est une règle par endroit.
 *
 * ── Qui voit quoi (§ 13.3, mot pour mot celle du calque) ────────────────────────
 *
 * | Lecteur                                     | Ce qu'il obtient                     |
 * |---------------------------------------------|--------------------------------------|
 * | Un membre                                   | les fiches de **ses** demandes        |
 * | Administrateur                              | celles de **toutes** les demandes     |
 * |                                             | soumises, et de ses propres brouillons|
 * | Administrateur sous « voir comme un membre »| ce que verrait le membre emprunté     |
 * | Compte `en_attente`                         | rien — il ne dépose pas de demande    |
 *
 * C'est {@see DraftPersonRepository::enAttenteVisibles()} qui la porte, en une seule
 * clause, pour qu'il n'existe pas un second endroit où elle pourrait être fausse.
 *
 * ── Le filtrage se fait en PHP, et c'est une décision de taille ─────────────────
 *
 * `tree_person` porte une colonne `search_key` indexée, parce qu'on y cherche parmi
 * 274 lignes et que la recherche est chaude. `prp_draft_person` ne porte **que les
 * fiches des demandes en attente** — quelques dizaines, sur une file qu'un
 * administrateur vide à la main. Ajouter une colonne dérivée, son déclencheur de
 * recalcul et sa commande de reprise pour ce volume-là coûterait plus à maintenir
 * qu'un balayage ne coûte à exécuter.
 *
 * Ce qui n'est **pas** négociable, en revanche, c'est le repliement : il passe par
 * {@see CleDeRecherche}, donc par la même table d'accents et la même casse que l'import
 * et que la recherche de l'arbre. Une seconde règle de repliement rendrait « Noël »
 * trouvable d'un côté et pas de l'autre — le genre d'écart qu'on ne constate que sur
 * les noms accentués, c'est-à-dire sur la moitié de cet arbre.
 */
final readonly class RechercheDeBrouillons
{
    /** Le minimum du § 5.5 : en deçà, `422`. Une lettre seule listerait toute la file. */
    public const int LONGUEUR_MINIMALE = 2;

    public const int LIMITE_PAR_DEFAUT = 10;
    public const int LIMITE_MAXIMALE = 25;

    /** La borne du § 5.5 sur le nombre de mots, pour la même raison qu'ailleurs. */
    private const int MOTS_MAXIMUM = 8;

    public function __construct(
        private DraftPersonRepository $brouillons,
        private CleDeRecherche $cles = new CleDeRecherche(),
    ) {
    }

    /**
     * Les fiches proposées qui répondent à ce terme.
     *
     * `tronque` se calcule sur le jeu **déjà filtré**, comme au § 5.5, et pour la même
     * raison : un compte avant filtrage dirait combien de lignes on ne montre pas.
     *
     * @return array{elements: list<DraftPerson>, tronque: bool}
     */
    public function resultats(string $terme, int $limite, Lecteur $lecteur): array
    {
        // Le garde-fou du § 13.3, en tête et en une ligne : un compte en attente de
        // rattachement ne dépose pas de demande, il n'a donc aucune proposition à
        // retrouver — pas même les siennes, il n'en a pas.
        if ($lecteur->regimeEnAttente) {
            return ['elements' => [], 'tronque' => false];
        }

        $mots = $this->mots($terme);

        if ([] === $mots) {
            return ['elements' => [], 'tronque' => false];
        }

        $auteur = $lecteur->voitToutesLesDemandes() ? null : $lecteur->identiteEffective();
        // Les brouillons non envoyés : ceux du lecteur effectif, et de lui seul — un
        // administrateur ne voit pas ce qu'un membre n'a pas encore envoyé.
        $brouillonsDe = $lecteur->identiteEffective();

        $elements = [];
        $tronque = false;

        foreach ($this->brouillons->enAttenteVisibles($auteur, $brouillonsDe) as $brouillon) {
            if (!$this->correspond($brouillon, $mots)) {
                continue;
            }

            if (\count($elements) >= $limite) {
                // On a trouvé une ligne de plus **après** filtrage : le plafond est
                // réellement atteint, et `tronque` le dit sans rien apprendre de plus.
                $tronque = true;
                break;
            }

            $elements[] = $brouillon;
        }

        return ['elements' => $elements, 'tronque' => $tronque];
    }

    /** Le plafond, **ramené** plutôt que refusé (§ 1.5). */
    public function limite(mixed $demandee): int
    {
        if (null === $demandee || '' === $demandee) {
            return self::LIMITE_PAR_DEFAUT;
        }

        $valeur = filter_var($demandee, \FILTER_VALIDATE_INT);

        if (false === $valeur || $valeur < 1) {
            return self::LIMITE_PAR_DEFAUT;
        }

        return min($valeur, self::LIMITE_MAXIMALE);
    }

    /**
     * **Chaque** mot du terme doit préfixer un mot du nom, dans n'importe quel ordre.
     *
     * C'est exactement la règle de `LectureDeLArbre::candidatsDeRecherche()`, dont le
     * `LIKE 'mot%' OR LIKE '% mot%'` dit la même chose en SQL : le nom s'écrit
     * « patronyme, prénoms », et un membre tape « Prénom Nom ». Exiger la clé entière
     * rendait **zéro** résultat sur ce terme-là — mesuré sur 272 fiches de l'arbre — et
     * il n'y a aucune raison que la même faute soit commise deux fois.
     *
     * @param list<string> $mots
     */
    private function correspond(DraftPerson $brouillon, array $mots): bool
    {
        $cle = $this->cles->cleDeNom(
            $brouillon->getGivenNames(),
            $brouillon->getSurname(),
            $brouillon->getNickname(),
            $brouillon->getMarriedName(),
        );

        if ('' === $cle) {
            return false;
        }

        $parties = explode(' ', $cle);

        foreach ($mots as $mot) {
            $trouve = false;

            foreach ($parties as $partie) {
                if (str_starts_with($partie, $mot)) {
                    $trouve = true;
                    break;
                }
            }

            if (!$trouve) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le terme découpé en mots repliés.
     *
     * Ils sont sûrs **par construction** : {@see CleDeRecherche::replier()} réduit tout
     * ce qui n'est pas `[A-Z0-9]` à un espace. Ils ne partent d'ailleurs dans aucun
     * `LIKE` ici — la comparaison est en PHP —, mais la propriété vaut d'être conservée
     * pour le jour où elle y partirait.
     *
     * @return list<string>
     */
    private function mots(string $terme): array
    {
        $cle = $this->cles->replier($terme);

        if ('' === $cle) {
            return [];
        }

        return \array_slice(explode(' ', $cle), 0, self::MOTS_MAXIMUM);
    }
}
