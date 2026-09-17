<?php

declare(strict_types=1);

namespace App\Module\Almanach\Service;

use App\Module\Almanach\Contract\EvenementDeLArbre;
use App\Module\Almanach\Contract\GenreDEvenement;
use App\Module\Almanach\Contract\SujetDeLEvenement;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * La charge de `GET /api/anniversaires` : `jours`, `resume`.
 *
 * **Ce fichier ne contient aucune logique de confidentialité, et il ne doit jamais en
 * contenir.** Le cercle est appliqué une seule fois, dans {@see FluxVisible} ; ce
 * normaliseur met en forme. Deux endroits qui décideraient de la confidentialité, c'est un
 * endroit de trop, et ce serait le second qu'on oublierait de corriger.
 *
 * ── L'ANNÉE ENTIÈRE, EN JOURS CREUX ──────────────────────────────────────────────
 *
 * On rend **tous** les jours qui portent quelque chose, et **eux seuls** : pas les 365
 * cases, pas un mois. Deux raisons :
 *
 * - **la navigation de mois en mois est côté client.** Un `?mois=3` ferait un
 *   aller-retour par flèche, pour un jeu qui tient dans une réponse ; et il ouvrirait une
 *   surface de paramètres à valider sur une route de lecture qui n'en a aucun besoin ;
 * - **un jour vide n'a rien à transporter.** Sur l'arbre de travail, environ 300 jours de
 *   l'année portent au moins un événement ; les 65 autres ne méritent pas une entrée dont
 *   la seule information est `evenements: []`. Le client sait quels jours existent : c'est
 *   un calendrier.
 *
 * Les jours sortent **triés par mois puis par jour**, ce qui est l'ordre d'affichage et
 * évite au front de retrier ce que le serveur sait ordonner.
 *
 * ── LE 29 FÉVRIER SORT TEL QUEL ──────────────────────────────────────────────────
 *
 * `{mois: 2, jour: 29}`, et le serveur **ne le déplace pas**. La règle et son motif sont
 * dans {@see JourAnniversaire} ; ici il suffit de savoir qu'aucune ligne de ce fichier ne
 * corrige un jour. Le client décide ce qu'il en fait les trois années sur quatre où il
 * n'existe pas — c'est lui qui sait s'il affiche un mois, une année, ou la journée en
 * cours.
 *
 * ── `sansJour`, VENTILÉ PAR GENRE ────────────────────────────────────────────────
 *
 * La majorité des dates de l'arbre ne portent pas de jour — elles sont des années. Les
 * taire donnerait un calendrier qui semble couvrir toute la famille alors qu'il n'en
 * montre qu'une fraction. On les compte, **par genre**, parce que la répartition n'est pas
 * uniforme : les naissances anciennes sont souvent réduites à une année, les décès récents
 * portent un jour. Un total unique cacherait cette différence, qui est précisément ce
 * qu'un lecteur veut savoir avant de conclure que « son » ancêtre manque.
 *
 * ── LES DEUX COMPTEURS SONT CALCULÉS APRÈS LE CERCLE ─────────────────────────────
 *
 * `parGenre` compte les événements **placés**, `sansJour` ceux qui ne le sont pas — les
 * deux sur le flux déjà filtré. **Aucun total global n'est publié** : un « sur N » dirait
 * par soustraction combien de personnes le lecteur n'a pas le droit de voir, ce qui est
 * exactement la fuite par déduction que le § 16.2 ferme. La somme des deux compteurs est
 * le nombre d'événements **visibles**, et rien de plus.
 */
final class NormaliseurDesAnniversaires
{
    public function __construct(private readonly FluxVisible $flux)
    {
    }

    /** @return array<string, mixed> la charge complète de `GET /api/anniversaires` */
    public function vue(): array
    {
        $flux = $this->flux->pourLeLecteur();

        /** @var array<string, array{mois: int, jour: int, evenements: list<array<string, mixed>>}> $jours */
        $jours = [];
        $parGenre = GenreDEvenement::compteurVide();
        $sansJour = GenreDEvenement::compteurVide();

        foreach ($flux->evenements as $evenement) {
            $genre = $evenement->genre()->value;
            $jour = JourAnniversaire::depuis($evenement->date);

            if (null === $jour) {
                ++$sansJour[$genre];

                continue;
            }

            ++$parGenre[$genre];

            // La clé de regroupement est zéro-préfixée pour que le tri final soit une
            // comparaison de chaînes, et l'entier ressort tel quel dans la charge : la
            // clé technique ne fuit pas dans le JSON.
            $cle = \sprintf('%02d-%02d', $jour['mois'], $jour['jour']);

            $jours[$cle] ??= ['mois' => $jour['mois'], 'jour' => $jour['jour'], 'evenements' => []];
            $jours[$cle]['evenements'][] = $this->enJson($evenement, $genre);
        }

        ksort($jours);

        return [
            'jours' => array_values($jours),
            'resume' => [
                'parGenre' => $parGenre,
                'sansJour' => $sansJour,
            ],
        ];
    }

    /**
     * Un événement placé, dans la forme d'une ligne de jour.
     *
     * `annee` n'y est jamais `null` : un événement n'arrive ici qu'après avoir passé
     * {@see JourAnniversaire::depuis()}, qui exige une date exacte non intervalle — donc
     * une date qui porte une borne, donc une année. Le `?? 0` de la formule ci-dessous
     * n'est pas un repli métier, c'est ce qu'exige l'analyse statique là où le type
     * n'encode pas cette implication.
     *
     * @return array<string, mixed>
     */
    private function enJson(EvenementDeLArbre $evenement, string $genre): array
    {
        return [
            'cle' => $evenement->cle,
            'genre' => $genre,
            'type' => $evenement->type->value,
            'annee' => self::anneeDeLEvenement($evenement->date) ?? 0,
            'texte' => $evenement->date->format(),
            // Le `PLAC` **verbatim**, et non un libellé résolu : le calendrier n'a pas
            // besoin de coordonnées, et montrer ce que la donnée dit vaut mieux que
            // montrer ce que l'appariement a cru comprendre. La résolution en coordonnées
            // est l'affaire de la carte.
            'lieu' => $evenement->date->getPlace(),
            'sujets' => array_map(
                static fn (SujetDeLEvenement $sujet): array => $sujet->enJson(),
                $evenement->sujets,
            ),
            'familyId' => $evenement->familyId,
            'libelle' => $this->flux->libelle($evenement),
        ];
    }

    /**
     * **Le seul endroit de ce fichier où une date devient un point**, écrit en clair —
     * même formule et même consigne que dans {@see NormaliseurDeLaCarte::vue()}, et
     * **recopiée à dessein**.
     *
     * Un `Core\Service\AnneeDUneDate::depuis()` serait le `getYear()` que l'en-tête
     * d'`UncertainDate` refuse, sous un autre nom : « si la méthode existait, quelqu'un
     * l'appellerait et une fourchette deviendrait une date ferme ». Deux recopies de trois
     * lignes, chacune visible en relecture, coûtent moins qu'un accesseur partagé qu'on
     * appelle sans y penser.
     */
    private static function anneeDeLEvenement(UncertainDate $date): ?int
    {
        $borne = $date->getMin() ?? $date->getMax();

        return null === $borne ? null : (int) $borne->format('Y');
    }
}
