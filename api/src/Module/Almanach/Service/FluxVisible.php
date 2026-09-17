<?php

declare(strict_types=1);

namespace App\Module\Almanach\Service;

use App\Module\Almanach\Contract\EvenementDeLArbre;
use App\Module\Almanach\Contract\FluxDEvenements;
use App\Module\Almanach\Contract\PortEvenementsArbre;
use App\Module\Almanach\Contract\SujetDeLEvenement;
use App\Module\Core\Security\CercleDeVisibiliteInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE POINT UNIQUE OÙ LE CERCLE DE VISIBILITÉ S'APPLIQUE DANS CE MODULE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Les deux normaliseurs ne contiennent **aucune** logique de confidentialité, et les deux
 * contrôleurs non plus. Ils reçoivent un flux déjà filtré et le mettent en forme. C'est
 * l'amélioration structurelle de ce module sur `Timeline`, où le filtre vit à l'intérieur
 * du normaliseur : là-bas, ajouter une seconde vue obligerait à réécrire le filtre ou à
 * traverser un sérialiseur qui fait deux choses. Ici la carte et le calendrier partagent
 * le même filtre, par construction — et un troisième écran le partagerait aussi.
 *
 * Un test le garde : `testLeFiltreDuCercleNEstEcritQuAUnSeulEndroitDuModule` balaye
 * `src/Module/Almanach/**.php` et exige **exactement une** occurrence de
 * `CercleDeVisibiliteInterface::class`. Ce n'est pas du zèle : « deux endroits qui décident
 * de la confidentialité, et le second serait celui qu'on oublierait de corriger ».
 *
 * ── LA RÈGLE, ET POURQUOI ELLE EST UN « ET » ─────────────────────────────────────
 *
 * > **Un événement n'est rendu que si TOUS ses sujets sont visibles, et s'il a au moins un
 * > sujet.**
 *
 * Trois motifs, et ils ne disent pas la même chose :
 *
 * **1. Le § 16.2 du contrat ferme « tout lieu, y compris un lieu de fait ou d'union »**
 * pour une personne vivante hors cercle. Un événement d'union *est* un lieu et une date ;
 * il tombe sous la même fermeture que la fiche.
 *
 * **2. Une date de mariage est la date des DEUX époux.** Ne nommer que l'époux visible ne
 * protège pas l'autre : il reste l'autre extrémité d'une arête que le graphe publie
 * (ADR 0003 : une personne vivante hors cercle expose encore sa position et ses arêtes).
 * Publier « mariage, 14 mars 1959, Saule » avec un seul nom, c'est publier la date de
 * mariage de son conjoint à quiconque sait lire un arbre — c'est-à-dire tous les lecteurs.
 * La fuite se fait par jointure, pas par lecture directe, et c'est exactement le genre que
 * le § 16.2 vise.
 *
 * **3. Le « ou » ne peut pas se tromper du bon côté.** C'est l'argument décisif, et il est
 * dissymétrique :
 *
 * | règle | se trompe en | conséquence |
 * |---|---|---|
 * | **ET** | cachant trop | une bulle manque ; quelqu'un le signale |
 * | OU | montrant trop | une date de vivant est publiée ; personne ne le signale |
 *
 * **Un époux décédé ne doit jamais déverrouiller un époux vivant.** Avec le « ou », c'est
 * pourtant ce qui arriverait : le défunt est visible de tout compte connecté (règle 1 du
 * cercle), donc l'union sortirait toujours, donc la date de mariage de son conjoint vivant
 * hors cercle avec.
 *
 * ── `sujets === []` ⇒ ABSENT, et c'est le repli fermé ─────────────────────────────
 *
 * Un `array_diff` sur une liste vide rend une liste vide : écrite naïvement, la règle « pas
 * de sujet invisible » déclarerait visible un événement **sans aucun sujet**. Ce serait
 * l'ouverture par le cas vide — la forme de fuite la plus difficile à voir en relecture,
 * parce qu'elle n'apparaît dans aucun cas nominal. L'événement sans sujet ne peut pas avoir
 * été autorisé par un cercle : il ne sort pas.
 *
 * ── UN SEUL APPEL AU CERCLE, PAS UN PAR ÉVÉNEMENT ────────────────────────────────
 *
 * `filtrerPersonnes()` existe pour cette raison exacte, et son en-tête le dit : une boucle
 * sur `voitPersonne()` ferait autant de résolutions de cercle que d'événements — de l'ordre
 * du millier — là où **une seule** suffit. On collecte donc tous les identifiants de sujets
 * du flux, on appelle une fois, et on filtre sur l'ensemble obtenu.
 *
 * ── LE LIBELLÉ D'UNE UNION SE COMPOSE ICI, PAS DANS L'ADAPTATEUR ─────────────────
 *
 * `"Prune Aubépine & Cobalt Sureau"` est composé **après** le filtre, sur des sujets que le
 * cercle vient d'autoriser. Le composer dans `Tree` l'aurait figé avant que quiconque ait
 * décidé qui est visible : la chaîne aurait traversé toute seule, et le filtrage des
 * `sujets` n'aurait plus rien empêché. C'est la même leçon que `ligneDates` contre
 * `etiquetteDates` dans `Timeline` — une chaîne composée trop tôt emporte avec elle ce
 * qu'on croyait avoir filtré.
 *
 * Comme la règle est le « ET », composer après le filtre n'ampute jamais un libellé : un
 * événement qui sort porte **tous** ses sujets, donc tous ses noms.
 */
final class FluxVisible
{
    public function __construct(
        private readonly PortEvenementsArbre $port,
        private readonly CercleDeVisibiliteInterface $cercle,
    ) {
    }

    /**
     * Le flux de l'arbre, réduit à ce que le lecteur courant a le droit de voir.
     *
     * Les lieux traversent **tels quels** : `tree_place` ne porte aucune donnée
     * personnelle — une chaîne `PLAC`, un nom de commune, deux coordonnées — et un lieu
     * sans événement visible est simplement un lieu qu'aucune bulle n'emploie. Les
     * normaliseurs ne publient d'ailleurs que les lieux effectivement portés par un
     * événement retenu : voir {@see NormaliseurDeLaCarte}.
     */
    public function pourLeLecteur(): FluxDEvenements
    {
        $flux = $this->port->flux();

        $visibles = array_flip($this->cercle->filtrerPersonnes($this->identifiantsDesSujets($flux)));

        $retenus = [];

        foreach ($flux->evenements as $evenement) {
            if (!$this->estVisible($evenement, $visibles)) {
                continue;
            }

            $retenus[] = $evenement;
        }

        return new FluxDEvenements($retenus, $flux->lieux);
    }

    /**
     * Le libellé d'un événement, **composé après le filtre**.
     *
     * Pour une union sans valeur textuelle, c'est la liste des noms de ses sujets :
     * `"Prune Aubépine & Cobalt Sureau"`. Pour un fait qui porte une valeur (« Tisserand »),
     * c'est elle. Pour le reste, `null` — et le front met alors le libellé du type, qu'il a
     * déjà.
     *
     * Rangée ici et non dans un normaliseur parce que c'est **la même règle pour les deux
     * écrans**, et parce que sa correction dépend du filtre qui la précède immédiatement.
     */
    public function libelle(EvenementDeLArbre $evenement): ?string
    {
        if (null !== $evenement->libelle && '' !== trim($evenement->libelle)) {
            return trim($evenement->libelle);
        }

        if (\count($evenement->sujets) < 2) {
            return null;
        }

        return implode(' & ', array_map(
            static fn (SujetDeLEvenement $sujet): string => $sujet->displayName,
            $evenement->sujets,
        ));
    }

    /**
     * Tous les identifiants de sujets du flux, doublons compris — `filtrerPersonnes()` les
     * retire lui-même, et son contrat le dit.
     *
     * @return list<int>
     */
    private function identifiantsDesSujets(FluxDEvenements $flux): array
    {
        $identifiants = [];

        foreach ($flux->evenements as $evenement) {
            foreach ($evenement->identifiantsDesSujets() as $personId) {
                $identifiants[] = $personId;
            }
        }

        return $identifiants;
    }

    /**
     * La règle du « ET », écrite en clair — lire l'en-tête avant de la transformer en
     * « au moins un sujet visible ».
     *
     * @param array<int, int> $visibles les identifiants autorisés, **en clés**
     */
    private function estVisible(EvenementDeLArbre $evenement, array $visibles): bool
    {
        // Le repli fermé : sans sujet, aucun cercle n'a rien autorisé.
        if ([] === $evenement->sujets) {
            return false;
        }

        foreach ($evenement->sujets as $sujet) {
            if (!isset($visibles[$sujet->personId])) {
                return false;
            }
        }

        return true;
    }
}
