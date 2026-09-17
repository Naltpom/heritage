<?php

declare(strict_types=1);

namespace App\Module\Almanach\Service;

use App\Module\Almanach\Contract\GenreDEvenement;
use App\Module\Almanach\Contract\LieuResolu;
use App\Module\Almanach\Contract\SujetDeLEvenement;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * La charge de `GET /api/carte` : `lieux`, `evenements`, `resume`.
 *
 * **Ce fichier ne contient aucune logique de confidentialité, et il ne doit jamais en
 * contenir.** Le cercle est appliqué une seule fois, dans {@see FluxVisible}, qui rend un
 * flux déjà réduit à ce que le lecteur a le droit de voir. Ce normaliseur met en forme ; le
 * jour où il écrirait un `if ($estVisible)`, il existerait deux endroits qui décident de la
 * confidentialité, et le second serait celui qu'on oublierait de corriger.
 *
 * ── LE RAPPROCHEMENT ÉVÉNEMENT → LIEU ────────────────────────────────────────────
 *
 * Un événement porte une chaîne `PLAC` verbatim dans sa date ; un {@see LieuResolu} porte
 * sa clé repliée. On replie la chaîne de l'événement avec **le même appel** —
 * {@see CleDeRecherche::replier()}, la définition unique du produit — et on lit une table
 * de hachage d'une centaine d'entrées. Aucune clé étrangère, aucune jointure SQL : le
 * motif complet est dans l'en-tête de `src/Module/Tree/Entity/Place.php`.
 *
 * ── CE QUI EST RENDU, ET CE QUI EST SEULEMENT COMPTÉ ─────────────────────────────
 *
 * Une bulle a besoin d'une position **et** d'une année : la carte les répartit dans
 * l'espace et les filtre dans le temps. Un événement qui manque de l'un des deux ne peut
 * pas être dessiné — et **le taire serait pire que l'omettre**. Il est donc compté, et les
 * trois compteurs disent lequel des trois manques l'a écarté :
 *
 * | compteur | ce qu'il compte |
 * |---|---|
 * | `sansLieu` | aucun `PLAC` du tout sur l'événement |
 * | `lieuNonResolu` | un `PLAC` écrit, qu'aucune ligne de `tree_place` ne résout |
 * | `sansDate` | aucune borne exploitable, donc aucune année |
 *
 * Les trois sont **indépendants** : un événement sans lieu **et** sans date compte dans
 * deux d'entre eux. C'est voulu — ce sont trois questions différentes (« combien de lieux
 * me manque-t-il ? », « combien de lieux dois-je corriger ? », « combien de dates
 * dois-je chercher ? »), et un total qui les additionnerait ne répondrait à aucune.
 *
 * `annee` n'est donc **jamais `null`** dans cette charge : un événement sans année n'y
 * figure pas.
 *
 * ── `lieux` NE PORTE QUE LES LIEUX EFFECTIVEMENT EMPLOYÉS ────────────────────────
 *
 * On ne publie pas la table `tree_place` entière. Ce n'est pas une économie d'octets,
 * c'est la seconde moitié du cercle : un lieu que **seule** une personne vivante hors
 * cercle porte apprendrait à un lecteur qu'il existe, dans l'arbre, quelqu'un né à cet
 * endroit — exactement la fuite par déduction que le § 16.2 ferme, et celle que le
 * `bornes` de `Timeline` ferme pour les années. La liste est donc construite **à partir
 * des événements retenus**, jamais à partir du flux de lieux.
 *
 * ── `bornes` N'EST JAMAIS `null` ─────────────────────────────────────────────────
 *
 * Même contrat que `Timeline` : `bornes` est typée non nullable côté front, et c'est
 * `evenements: []` qui porte la vacuité. Quand rien n'est rendu — compte en attente sur un
 * arbre sans défunt, port non branché —, `bornes` vaut `{année courante, année courante}`.
 * La rendre nullable ferait mentir son type **uniquement** pour un compte en attente,
 * c'est-à-dire pas avant la production : le pire moment pour découvrir qu'une clé non
 * nullable était nullable.
 *
 * ── AUCUN PARAMÈTRE DE REQUÊTE ───────────────────────────────────────────────────
 *
 * Pas de filtre par genre, pas de bornes d'années, pas de pagination. Les pastilles et le
 * curseur de temps sont des **réglages de rendu** : le client a déjà toute la charge, et
 * refiltrer côté serveur lui coûterait un aller-retour par clic pour un jeu qui tient en
 * mémoire. Corollaire assumé et inscrit dans le contrat : `resume.parGenre` compte **avant
 * tout filtre client et après le cercle** — c'est le compte que les pastilles affichent au
 * premier affichage.
 */
final class NormaliseurDeLaCarte
{
    public function __construct(
        private readonly FluxVisible $flux,
        private readonly CleDeRecherche $cles = new CleDeRecherche(),
    ) {
    }

    /** @return array<string, mixed> la charge complète de `GET /api/carte` */
    public function vue(\DateTimeImmutable $maintenant = new \DateTimeImmutable()): array
    {
        $flux = $this->flux->pourLeLecteur();

        /** @var array<string, LieuResolu> $parCle */
        $parCle = [];
        foreach ($flux->lieux as $lieu) {
            $parCle[$lieu->cle] = $lieu;
        }

        $evenements = [];
        $annees = [];
        $parGenre = GenreDEvenement::compteurVide();
        $employes = [];

        $sansLieu = 0;
        $lieuNonResolu = 0;
        $sansDate = 0;

        foreach ($flux->evenements as $evenement) {
            $annee = self::anneeDeLEvenement($evenement->date);
            $brut = $evenement->date->getPlace();
            $lieu = null;

            if (null === $brut || '' === trim($brut)) {
                ++$sansLieu;
            } else {
                // Le même repliement des deux côtés — c'est toute la raison d'être de
                // `CleDeRecherche`. En écrire un second ici ferait disparaître des bulles
                // sans qu'aucune erreur ne se produise.
                $lieu = $parCle[$this->cles->replier($brut)] ?? null;

                if (null === $lieu) {
                    ++$lieuNonResolu;
                }
            }

            if (null === $annee) {
                ++$sansDate;
            }

            if (null === $annee || null === $lieu) {
                continue;
            }

            $annees[] = $annee;
            ++$parGenre[$evenement->genre()->value];
            $employes[$lieu->cle] = $lieu;

            $evenements[] = [
                'cle' => $evenement->cle,
                'genre' => $evenement->genre()->value,
                'type' => $evenement->type->value,
                'placeId' => $lieu->placeId,
                // La chaîne `PLAC` **verbatim**, toujours présente à côté de l'identifiant :
                // une bulle montre ce que la donnée dit, et non le libellé que
                // l'appariement a retenu. C'est ce qui rend une résolution douteuse visible
                // au lecteur plutôt qu'invisible sous un beau nom.
                'place' => $brut,
                'annee' => $annee,
                'texte' => $evenement->date->format(),
                'qualifier' => $evenement->date->getQualifier()->value,
                // « Certaine » veut dire « désigne un jour unique », pas « fiable ». C'est
                // l'inverse de `isRange()`, et c'est ce que le front hachure.
                'certain' => !$evenement->date->isRange(),
                'sujets' => array_map(
                    static fn (SujetDeLEvenement $sujet): array => $sujet->enJson(),
                    $evenement->sujets,
                ),
                'familyId' => $evenement->familyId,
                'libelle' => $this->flux->libelle($evenement),
            ];
        }

        return [
            'lieux' => array_map(
                static fn (LieuResolu $lieu): array => $lieu->enJson(),
                array_values($employes),
            ),
            'evenements' => $evenements,
            'resume' => [
                'parGenre' => $parGenre,
                'sansLieu' => $sansLieu,
                'lieuNonResolu' => $lieuNonResolu,
                'sansDate' => $sansDate,
                'bornes' => [] === $annees
                    ? ['min' => (int) $maintenant->format('Y'), 'max' => (int) $maintenant->format('Y')]
                    : ['min' => min($annees), 'max' => max($annees)],
            ],
        ];
    }

    /**
     * **Le seul endroit de ce fichier où une date devient un point**, écrit en clair et non
     * caché dans un accesseur.
     *
     * `getMin() ?? getMax()` — la borne basse d'abord, parce qu'une date bornée à droite
     * seulement (`BEF 1920`) ne porte son information que dans `max`. C'est exactement la
     * formule de `Timeline\Service\NormaliseurDeChronologie::anneeDuJalon()`, et elle est
     * **recopiée à dessein**.
     *
     * **Ne pas factoriser ces trois lignes dans `Core`.** Un `AnneeDUneDate::depuis()`
     * serait le `getYear()` que l'en-tête d'`UncertainDate` refuse, sous un autre nom :
     *
     * > Si la méthode existait, quelqu'un l'appellerait et une fourchette deviendrait une
     * > date ferme — c'est exactement le bogue qui faisait naître Marne Dolomite Sureau en
     * > 1987, après ses propres enfants.
     *
     * La duplication est ici **le dispositif** : chaque appelant qui veut un point l'écrit,
     * donc chaque appelant est vu en relecture. Trois lignes recopiées à deux endroits
     * coûtent moins qu'un accesseur qu'on appelle sans y penser à vingt.
     *
     * Une bulle sur une carte est légitimement un point : elle dit « quelque chose s'est
     * passé ici, cette année-là ». Ce n'est pas l'aplatissement interdit — la fourchette
     * reste entière dans `texte`, `qualifier` et `certain`, que le front reçoit et affiche.
     */
    private static function anneeDeLEvenement(UncertainDate $date): ?int
    {
        $borne = $date->getMin() ?? $date->getMax();

        return null === $borne ? null : (int) $borne->format('Y');
    }
}
