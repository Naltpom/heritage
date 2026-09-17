<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Tree\Entity\Place;

/**
 * L'appariement d'une chaîne `PLAC` avec un référentiel géographique — **toute** la logique
 * de reconnaissance, et rien de la console ni de la base.
 *
 * Séparé de {@see \App\Module\Tree\Command\ResoudreLesLieuxCommand} pour une raison simple :
 * les cas qu'on veut éprouver — « ce triplet se résout », « cet homonyme ne se résout pas au
 * hasard » — n'ont besoin ni d'un noyau, ni d'un schéma, ni d'une entrée-sortie. Ils se
 * posent en trois lignes sur des fichiers de référence minuscules écrits pour le test.
 *
 * ── LE REPLIEMENT N'A PAS DE FORMULE ICI ─────────────────────────────────────────
 *
 * Toute comparaison de nom passe par {@see CleDeRecherche::replier()}, qui est **la**
 * définition du repliement d'accents du produit — celle qui écrit déjà
 * `tree_person.search_key`. En écrire une seconde ici, même juste, même testée, est
 * exactement le défaut que cette classe de `Core` existe pour supprimer :
 *
 * > le jour où elles auraient divergé d'un seul caractère, la recherche aurait cessé de
 * > trouver les noms qui le portent — sans erreur, sans test rouge.
 *
 * Sur un référentiel plein de *Saint-Étienne*, *Nîmes*, *Angoulême* et *Château-Thierry*, la
 * panne était garantie, et elle n'aurait pas ressemblé à une panne : juste des bulles qui
 * manquent.
 *
 * ── LES SIX PASSES, DANS L'ORDRE ─────────────────────────────────────────────────
 *
 * Une chaîne `PLAC` française s'écrit « commune, département, région, pays ». On la découpe
 * sur les virgules, on replie chaque morceau, et on essaie dans cet ordre — **la première
 * qui aboutit gagne**, parce que chaque passe est plus sûre que la suivante :
 *
 * | | ce qu'on reconnaît | confiance |
 * |---|---|---:|
 * | **A** | commune **et** un département cité | 100 |
 * | **B** | commune **et** une région citée | 90 |
 * | **C** | commune seule, **une seule** en France la porte | 70 |
 * | **D** | commune seule, **plusieurs** la portent | **0** |
 * | **E** | ville du monde **et** un pays cité | 60 |
 * | **F** | rien | 0 |
 *
 * ── LA PASSE D EST LA RAISON D'ÊTRE DE CE FICHIER ────────────────────────────────
 *
 * *Saint-Martin*, *Sainte-Marie*, *La Chapelle* : plusieurs dizaines de communes portent
 * chacun de ces noms. Prendre la plus peuplée, ou la première venue, donnerait une carte
 * **plausible et fausse** — une bulle bien placée dans le Pas-de-Calais pour un ancêtre du
 * Cantal, que rien ne signale et que personne ne vérifie. On ne résout donc **rien**, on
 * pose la confiance à 0, et on **imprime les candidats** : la seule information juste qu'on
 * ait est « il faut choisir, et voici entre quoi ».
 *
 * C'est le même arbitrage que `ReglePersonneVivante` tient pour la confidentialité — se
 * tromper dans le sens prudent est rattrapable, l'autre non — et que
 * `UncertainDate::format()` tient pour les dates : « montrer la provenance vaut mieux
 * qu'inventer une lecture ».
 *
 * ── LES ÉGALITÉS SE DÉPARTAGENT PAR `rang`, PUIS PAR POPULATION ──────────────────
 *
 * `communes-fr.csv` mêle deux natures : `rang = 0` pour les 34 742 communes (GeoNames
 * ADM4) et `rang = 1` pour les lieux habités — hameaux, lieux-dits, écarts. **À l'intérieur
 * d'une passe, une commune l'emporte toujours sur un hameau homonyme** : une chaîne `PLAC`
 * de GEDCOM nomme une commune dans l'immense majorité des cas, et un hameau qui porte le
 * nom de sa commune est une redite, pas une alternative.
 *
 * Les candidats se cherchent donc d'abord parmi les `rang = 0` ; les `rang = 1` ne sont
 * regardés que si aucun `rang = 0` ne convient. **L'ambiguïté se juge dans le rang
 * retenu** — deux hameaux homonymes n'empêchent pas une commune unique d'être reconnue.
 * À rang égal, c'est la population qui départage, et ce départage est écrit pour être
 * **déterministe** : sans lui, l'ordre des lignes du fichier déciderait, et une
 * régénération du référentiel changerait la carte sans que rien ne l'explique.
 *
 * ── UNE LIMITE DU RÉFÉRENTIEL MONDIAL, MESURÉE ET ASSUMÉE ───────────────────────
 *
 * `communes-fr.csv` porte les noms **français accentués** (`Saint-Étienne`), et le
 * repliement les rapproche de toutes leurs graphies. `villes-monde.csv`, lui, porte le nom
 * **principal de GeoNames**, qui est le plus souvent anglais ou translittéré : on y trouve
 * `Algiers`, jamais `Alger`. Une chaîne `PLAC` écrite en français sur une ville étrangère
 * tombe donc en passe F, où elle est **comptée et nommée** dans le rapport de la commande.
 *
 * Ce n'est pas réparé ici, et c'est délibéré : corriger demanderait une table d'exonymes
 * français — une troisième source à tracer, à licencier et à régénérer — pour une poignée
 * de lignes sur un arbre français. La bonne réponse est la correction manuelle, qui existe
 * déjà et que la commande protège. La table {@see self::PAYS} suit la même règle et le même
 * seuil.
 *
 * ── LE FORMAT DES FICHIERS, ET POURQUOI ON N'EMPLOIE PAS `fgetcsv()` ─────────────
 *
 * Séparateur `;`, pas de guillemets — un `;` présent dans un nom a été remplacé par `,` à
 * la capture (voir `ressources/gazetteer/LISEZMOI.md`). Les lignes commençant par `#` sont
 * l'en-tête de provenance et se sautent.
 *
 * `fgetcsv()` n'apporterait rien sur un format sans échappement, et **PHP 8.4 émet une
 * dépréciation** sur son paramètre `$escape`. Or `phpunit.dist.xml` pose
 * `failOnDeprecation="true"` : une dépréciation ferait tomber la suite entière. Un
 * `explode(';', $ligne)` est plus court, plus rapide sur 114 838 lignes, et ne dépend de
 * rien.
 */
final class AppariementDesLieux
{
    /** La passe A — commune et département. */
    public const string PASSE_COMMUNE_DEPARTEMENT = 'commune+département';

    /** La passe B — commune et région. */
    public const string PASSE_COMMUNE_REGION = 'commune+région';

    /** La passe C — une seule commune française porte ce nom. */
    public const string PASSE_COMMUNE_UNIQUE = 'commune unique';

    /** La passe D — plusieurs la portent : on ne choisit pas. */
    public const string PASSE_AMBIGU = 'homonymes';

    /** La passe E — ville du monde et pays cité. */
    public const string PASSE_VILLE_DU_MONDE = 'ville du monde';

    /** La passe F — aucun rapprochement. */
    public const string PASSE_INCONNU = 'inconnu';

    /**
     * Les noms de pays en français que ce produit sait reconnaître, vers leur code ISO
     * 3166-1 alpha-2.
     *
     * **Volontairement courte.** Le lot de référence ne comporte aucun fichier de noms de
     * pays : en ajouter un troisième pour une poignée de lignes coûterait une source de
     * plus à tracer, à licencier et à régénérer. Les entrées ci-dessous couvrent les pays
     * qu'un arbre familial français cite réellement ; un pays absent n'est pas une panne —
     * il tombe en passe F, est compté comme non résolu, et s'ajoute ici en une ligne le
     * jour où il apparaît.
     *
     * Un dernier morceau déjà écrit en ISO2 (`DZ`, `CH`) est reconnu sans passer par cette
     * table.
     *
     * @var array<string, string> clé repliée du nom → code ISO2
     */
    private const array PAYS = [
        'FRANCE' => 'FR', 'ALGERIE' => 'DZ', 'MAROC' => 'MA', 'TUNISIE' => 'TN',
        'BELGIQUE' => 'BE', 'SUISSE' => 'CH', 'LUXEMBOURG' => 'LU', 'ALLEMAGNE' => 'DE',
        'ITALIE' => 'IT', 'ESPAGNE' => 'ES', 'PORTUGAL' => 'PT', 'PAYS BAS' => 'NL',
        'ROYAUME UNI' => 'GB', 'ANGLETERRE' => 'GB', 'ECOSSE' => 'GB', 'IRLANDE' => 'IE',
        'AUTRICHE' => 'AT', 'POLOGNE' => 'PL', 'HONGRIE' => 'HU', 'ROUMANIE' => 'RO',
        'GRECE' => 'GR', 'TURQUIE' => 'TR', 'RUSSIE' => 'RU', 'UKRAINE' => 'UA',
        'SUEDE' => 'SE', 'NORVEGE' => 'NO', 'DANEMARK' => 'DK', 'FINLANDE' => 'FI',
        'TCHEQUIE' => 'CZ', 'SLOVAQUIE' => 'SK', 'CROATIE' => 'HR', 'SERBIE' => 'RS',
        'CANADA' => 'CA', 'ETATS UNIS' => 'US', 'MEXIQUE' => 'MX', 'BRESIL' => 'BR',
        'ARGENTINE' => 'AR', 'CHILI' => 'CL', 'SENEGAL' => 'SN', 'MALI' => 'ML',
        'COTE D IVOIRE' => 'CI', 'CAMEROUN' => 'CM', 'MADAGASCAR' => 'MG', 'CONGO' => 'CG',
        'LIBAN' => 'LB', 'SYRIE' => 'SY', 'ISRAEL' => 'IL', 'EGYPTE' => 'EG',
        'INDE' => 'IN', 'CHINE' => 'CN', 'JAPON' => 'JP', 'VIETNAM' => 'VN',
        'AUSTRALIE' => 'AU', 'NOUVELLE ZELANDE' => 'NZ',
    ];

    /**
     * Les communes, indexées par leur nom replié. Chaque valeur est la **liste des lignes
     * brutes** du fichier, découpées à la demande.
     *
     * Garder la ligne plutôt que sept champs éclatés divise par trois l'empreinte mémoire
     * sur 114 838 entrées, et le découpage ne coûte que pour les quelques dizaines de noms
     * réellement cherchés.
     *
     * @var array<string, list<string>>
     */
    private array $communes = [];

    /** @var array<string, list<string>> même forme, pour `villes-monde.csv` */
    private array $villes = [];

    /** @var array<string, string> code INSEE → nom du département */
    private array $departements = [];

    /** @var array<string, string> nom replié du département → code INSEE */
    private array $codesDeDepartement = [];

    /** @var array<string, string> code INSEE → nom de la région */
    private array $regions = [];

    /** @var array<string, string> nom replié de la région → code INSEE */
    private array $codesDeRegion = [];

    private bool $charge = false;

    public function __construct(private readonly CleDeRecherche $cles = new CleDeRecherche())
    {
    }

    /**
     * Charge les quatre fichiers de référence.
     *
     * Les départements et les régions se lisent **à côté** du fichier des communes : ce
     * sont ses tables de codes, et les dissocier permettrait de charger des communes avec
     * les libellés d'un autre millésime — des codes INSEE qui ne désignent plus les mêmes
     * subdivisions, donc des rapprochements faux sans aucun message d'erreur.
     *
     * @throws \RuntimeException si un fichier manque, est illisible, ou ne contient aucune
     *                           ligne de données — ce dernier cas étant celui d'un mauvais
     *                           séparateur ou d'un fichier tronqué, qu'on veut voir rouge
     *                           plutôt que « 0 résolu »
     */
    public function charger(string $fichierCommunes, string $fichierVilles): void
    {
        $dossier = \dirname($fichierCommunes);

        $this->communes = $this->indexerParNom($fichierCommunes, 'communes', 7);
        $this->villes = $this->indexerParNom($fichierVilles, 'villes du monde', 5);

        // ── LE CANADA SE LIT À CÔTÉ, ET SE FOND DANS LE MÊME INDEX ──────────────────
        //
        // `villes-canada.csv` porte exactement les cinq mêmes colonnes, pays compris :
        // rien à changer à la passe E, qui filtre sur le code pays de la 2ᵉ colonne.
        //
        // Pourquoi un fichier pays entier alors qu'il existe un fichier mondial : le
        // fichier mondial s'arrête à 15 000 habitants. Cet arbre porte **12 naissances au
        // Canada**, dont huit à Castor (Alberta) et sept autour de Trochu (Alberta) —
        // moins de mille habitants chacun. Vérifié : ils sont absents de `cities15000`
        // **et** de `cities500`. Le fichier pays les a, et pèse six fois moins que
        // `cities500`.
        //
        // C'est la même logique que pour la France, dont on prend `FR.txt` entier et non
        // les seules grandes villes : une généalogie se passe dans des villages.
        foreach ($this->indexerParNom($dossier.'/villes-canada.csv', 'villes du Canada', 5) as $nom => $lignes) {
            $this->villes[$nom] = array_merge($this->villes[$nom] ?? [], $lignes);
        }

        [$this->departements, $this->codesDeDepartement] = $this->lireLesCodes($dossier.'/departements.csv', 'départements');
        [$this->regions, $this->codesDeRegion] = $this->lireLesCodes($dossier.'/regions.csv', 'régions');

        $this->charge = true;
    }

    public function nombreDeCommunes(): int
    {
        return \count($this->communes);
    }

    public function nombreDeVilles(): int
    {
        return \count($this->villes);
    }

    /**
     * Apparie une chaîne `PLAC` et rend ce qu'on a su en tirer.
     *
     * Le résultat est **toujours** exploitable : même en passe F il porte un libellé — la
     * première partie de la chaîne — parce qu'une ligne de `tree_place` sans libellé ne
     * dirait rien à qui vient la corriger à la main.
     *
     * @return array{
     *     libelle: string,
     *     commune: string|null,
     *     subdivision: string|null,
     *     pays: string|null,
     *     lat: float|null,
     *     lon: float|null,
     *     source: string,
     *     confiance: int,
     *     passe: string,
     *     candidats: list<string>,
     * }
     */
    public function apparier(string $brut): array
    {
        if (!$this->charge) {
            throw new \LogicException('Les fichiers de référence doivent être chargés avant tout appariement : appeler charger() d\'abord.');
        }

        $morceaux = $this->decouper($brut);

        if ([] === $morceaux) {
            return $this->echec('', self::PASSE_INCONNU);
        }

        // ── LA PASSE G : ON RECULE D'UN CRAN ────────────────────────────────────────
        //
        // Les six passes lisent le **premier** morceau comme le nom du lieu. C'est vrai de
        // « Bourges, Cher » et faux de tout ce qui nomme d'abord un lieu-dit :
        //
        //     Village du Colombier, Rampieux, Dordogne, France
        //     St. Mary's Cemetery, Calgary, Alberta, Canada
        //     Paris 7e Arrondissement, Paris, France
        //
        // La commune y est au deuxième morceau, et le premier n'est dans aucun
        // référentiel — ni ne le sera jamais : personne ne publie la liste des cimetières.
        // Mesuré sur l'arbre réel : **11 lieux, 20 occurrences** tombaient en passe F pour
        // cette seule raison, dont les deux cimetières canadiens et quatre arrondissements
        // de Paris.
        //
        // On réessaie donc depuis chaque morceau, dans l'ordre. **L'ordre est la garantie**
        // : un rapprochement sur le morceau 0 l'emporte toujours, et on ne recule que
        // faute de mieux. Ce qui est trouvé plus loin situe le **contenant** et non le lieu
        // nommé — le Colombier est *à* Rampieux —, donc la confiance baisse de dix points
        // par cran. C'est exact, et c'est ce que `confiance` sert à dire.
        //
        // Une ambiguïté rencontrée en chemin est **mise de côté et non rendue tout de
        // suite** : un morceau suivant peut trancher sans ambiguïté, et mieux vaut une
        // commune située qu'une liste d'homonymes. Si rien ne suit, on rend l'ambiguïté du
        // morceau le plus précis — celle qui nomme le vrai lieu cherché.
        $ambigu = null;

        foreach (array_keys($morceaux) as $depart) {
            $resultat = $this->apparierDepuis($morceaux, $depart);

            if (null === $resultat) {
                continue;
            }

            if (self::PASSE_AMBIGU === $resultat['passe']) {
                $ambigu ??= $resultat;

                continue;
            }

            if (0 === $depart) {
                return $resultat;
            }

            $resultat['confiance'] = max(30, $resultat['confiance'] - 10 * $depart);
            $resultat['libelle'] = $morceaux[0];

            return $resultat;
        }

        return $ambigu ?? $this->echec($morceaux[0], self::PASSE_INCONNU);
    }

    /**
     * Les six passes, appliquées en prenant le morceau `$depart` pour nom du lieu.
     *
     * Rend `null` quand aucune ne dit rien de ce morceau-là — c'est ce qui autorise
     * {@see apparier()} à reculer d'un cran sans distinguer « rien trouvé » de « trouvé
     * puis écarté ».
     *
     * @param list<string> $morceaux
     *
     * @return array<string, mixed>|null
     */
    private function apparierDepuis(array $morceaux, int $depart): ?array
    {
        $morceau = $morceaux[$depart] ?? null;

        if (null === $morceau) {
            return null;
        }

        $nom = $this->cles->replier($morceau);
        $autres = \array_slice($morceaux, $depart + 1);

        // Les candidats français, rang 0 d'abord : une commune l'emporte sur un hameau.
        $candidats = $this->candidatsFrancais($nom);

        if ([] !== $candidats) {
            $resultat = $this->passeAvecSubdivision($candidats, $autres);

            if (null !== $resultat) {
                return $resultat;
            }

            // Passes C et D : aucune subdivision citée ne tranche. Une seule commune la
            // porte -> on résout ; plusieurs -> on ne choisit pas, et on dit entre quoi.
            if (1 === \count($candidats)) {
                return $this->succesFrancais($candidats[0], 70, self::PASSE_COMMUNE_UNIQUE);
            }

            return $this->echec(
                $morceau,
                self::PASSE_AMBIGU,
                array_map($this->decrireUneCommune(...), $candidats),
            );
        }

        return $this->passeVilleDuMonde($nom, \array_slice($morceaux, $depart));
    }

    /**
     * Les passes A et B : un des morceaux suivants nomme-t-il un département, puis une
     * région, qui retienne un seul de nos candidats ?
     *
     * L'ordre compte : un département est plus précis qu'une région, et une chaîne `PLAC`
     * complète cite les deux. Chercher la région d'abord rendrait 90 là où 100 était
     * disponible, et perdrait la distinction entre deux communes homonymes d'une même
     * région.
     *
     * Le code INSEE est accepté autant que le nom (`18` comme `Cher`) : les deux formes
     * circulent dans les fichiers GEDCOM, et la table les porte toutes les deux.
     *
     * @param list<string> $candidats lignes brutes du fichier des communes
     * @param list<string> $autres    les morceaux de la chaîne après le premier
     *
     * @return array{libelle: string, commune: string|null, subdivision: string|null, pays: string|null, lat: float|null, lon: float|null, source: string, confiance: int, passe: string, candidats: list<string>}|null
     */
    private function passeAvecSubdivision(array $candidats, array $autres): ?array
    {
        foreach ([[1, $this->codesDeDepartement, 100, self::PASSE_COMMUNE_DEPARTEMENT], [2, $this->codesDeRegion, 90, self::PASSE_COMMUNE_REGION]] as [$colonne, $index, $confiance, $passe]) {
            $codes = [];

            foreach ($autres as $morceau) {
                $replie = $this->cles->replier($morceau);
                $code = $index[$replie] ?? null;

                if (null !== $code) {
                    $codes[$code] = true;

                    continue;
                }

                // Le code INSEE écrit tel quel — `18`, `01`. Il est numérique, donc le
                // repliement le laisse intact, et on le teste contre les clés de la table.
                if (isset($this->departements[$morceau]) || isset($this->regions[$morceau])) {
                    $codes[$morceau] = true;
                }
            }

            if ([] === $codes) {
                continue;
            }

            $retenus = array_values(array_filter(
                $candidats,
                fn (string $ligne): bool => isset($codes[explode(';', $ligne)[$colonne]]),
            ));

            if ([] === $retenus) {
                continue;
            }

            return $this->succesFrancais($this->departager($retenus), $confiance, $passe);
        }

        return null;
    }

    /**
     * La passe E : le premier morceau est une ville du monde, et un des morceaux nomme son
     * pays.
     *
     * **Le pays doit être cité.** Sans lui, « Springfield » désignerait n'importe laquelle
     * des dizaines de villes de ce nom : c'est la passe D transposée hors de France, avec
     * la même réponse — on ne choisit pas.
     *
     * @param list<string> $morceaux
     *
     * @return array{libelle: string, commune: string|null, subdivision: string|null, pays: string|null, lat: float|null, lon: float|null, source: string, confiance: int, passe: string, candidats: list<string>}|null
     */
    private function passeVilleDuMonde(string $nom, array $morceaux): ?array
    {
        $villes = $this->villes[$nom] ?? [];

        if ([] === $villes) {
            return null;
        }

        $pays = $this->paysCite(\array_slice($morceaux, 1));

        if (null === $pays) {
            return null;
        }

        $retenues = array_values(array_filter(
            $villes,
            static fn (string $ligne): bool => explode(';', $ligne)[1] === $pays,
        ));

        if ([] === $retenues) {
            return null;
        }

        // Les villes du monde n'ont pas de colonne `rang` : c'est la population qui
        // départage, et le fichier ne retient que les agglomérations de plus de 15 000
        // habitants — une homonymie y reste rare, et jamais silencieuse puisque le pays
        // est exigé.
        usort($retenues, static fn (string $a, string $b): int => (int) explode(';', $b)[4] <=> (int) explode(';', $a)[4]);

        $champs = explode(';', $retenues[0]);

        return [
            'libelle' => \sprintf('%s (%s)', $champs[0], $champs[1]),
            'commune' => null,
            'subdivision' => null,
            'pays' => $champs[1],
            'lat' => (float) $champs[2],
            'lon' => (float) $champs[3],
            'source' => Place::SOURCE_VILLES,
            'confiance' => 60,
            'passe' => self::PASSE_VILLE_DU_MONDE,
            'candidats' => [],
        ];
    }

    /**
     * Le code ISO2 du pays cité par un des morceaux, ou `null`.
     *
     * Deux formes acceptées : le code à deux lettres écrit tel quel, et le nom français
     * présent dans {@see self::PAYS}.
     *
     * @param list<string> $morceaux
     */
    private function paysCite(array $morceaux): ?string
    {
        foreach (array_reverse($morceaux) as $morceau) {
            $replie = $this->cles->replier($morceau);

            if (isset(self::PAYS[$replie])) {
                return self::PAYS[$replie];
            }

            if (1 === preg_match('/^[A-Z]{2}$/', $replie)) {
                return $replie;
            }
        }

        return null;
    }

    /**
     * Les communes françaises portant ce nom, **rang 0 d'abord**.
     *
     * L'ambiguïté se juge dans le rang retenu : deux hameaux homonymes n'empêchent pas une
     * commune unique d'être reconnue, et c'est ce qui évite de rendre ambigus des noms
     * parfaitement clairs.
     *
     * @return list<string> lignes brutes du fichier
     */
    private function candidatsFrancais(string $nom): array
    {
        $lignes = $this->communes[$nom] ?? [];

        if ([] === $lignes) {
            return [];
        }

        $communes = array_values(array_filter(
            $lignes,
            static fn (string $ligne): bool => '0' === trim(explode(';', $ligne)[6]),
        ));

        return [] !== $communes ? $communes : $lignes;
    }

    /**
     * À rang égal, la plus peuplée l'emporte — et ce choix est **déterministe**.
     *
     * Sans ce tri, c'est l'ordre des lignes du fichier qui déciderait : une régénération du
     * référentiel changerait alors des bulles de place sans qu'aucune ligne de code n'ait
     * bougé, et personne ne saurait dire pourquoi.
     *
     * @param list<string> $lignes
     */
    private function departager(array $lignes): string
    {
        usort($lignes, static function (string $a, string $b): int {
            $ca = explode(';', $a);
            $cb = explode(';', $b);

            return [(int) trim($ca[6]), -(int) $ca[5], $ca[0]] <=> [(int) trim($cb[6]), -(int) $cb[5], $cb[0]];
        });

        return $lignes[0];
    }

    /**
     * @return array{libelle: string, commune: string|null, subdivision: string|null, pays: string|null, lat: float|null, lon: float|null, source: string, confiance: int, passe: string, candidats: list<string>}
     */
    private function succesFrancais(string $ligne, int $confiance, string $passe): array
    {
        $champs = explode(';', $ligne);
        $departement = $this->departements[$champs[1]] ?? null;

        return [
            'libelle' => $this->decrireUneCommune($ligne),
            'commune' => $champs[0],
            // On garde le **nom** et non le code : c'est ce qu'un lecteur lit, et la table
            // ne sert pas à faire de la géographie administrative. Le repli sur la région
            // couvre les quelques lignes dont le code de département est absent de la
            // table — un territoire d'outre-mer réorganisé, par exemple.
            'subdivision' => $departement ?? ($this->regions[$champs[2]] ?? null),
            'pays' => 'FR',
            'lat' => (float) $champs[3],
            'lon' => (float) $champs[4],
            'source' => Place::SOURCE_COMMUNES,
            'confiance' => $confiance,
            'passe' => $passe,
            'candidats' => [],
        ];
    }

    /** `Saule (18)` — le nom et son département, qui est ce qui distingue deux homonymes. */
    private function decrireUneCommune(string $ligne): string
    {
        $champs = explode(';', $ligne);

        return \sprintf('%s (%s)', $champs[0], $champs[1]);
    }

    /**
     * @param list<string> $candidats
     *
     * @return array{libelle: string, commune: string|null, subdivision: string|null, pays: string|null, lat: float|null, lon: float|null, source: string, confiance: int, passe: string, candidats: list<string>}
     */
    private function echec(string $libelle, string $passe, array $candidats = []): array
    {
        return [
            // Mieux vaut afficher ce que la donnée dit qu'un vide : c'est sous ce nom-là
            // qu'on retrouvera la ligne pour la corriger.
            'libelle' => '' === $libelle ? '(lieu vide)' : mb_substr($libelle, 0, 160),
            'commune' => null,
            'subdivision' => null,
            'pays' => null,
            'lat' => null,
            'lon' => null,
            'source' => Place::SOURCE_AUCUNE,
            'confiance' => 0,
            'passe' => $passe,
            'candidats' => $candidats,
        ];
    }

    /**
     * Découpe une chaîne `PLAC` sur ses virgules, sans morceau vide.
     *
     * `« Saule,, Cher , France »` donne trois morceaux : les virgules doublées et les
     * espaces parasites sont courants dans un GEDCOM exporté, et un morceau vide ferait
     * échouer la reconnaissance du pays pour une raison purement typographique.
     *
     * @return list<string>
     */
    private function decouper(string $brut): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $brut)),
            static fn (string $morceau): bool => '' !== $morceau,
        ));
    }

    /**
     * Indexe un fichier de référence par le nom replié de sa première colonne.
     *
     * @param int $colonnes le nombre de champs attendu — une ligne plus courte est le
     *                      symptôme d'un mauvais séparateur, et on préfère l'ignorer
     *                      ligne à ligne puis échouer sur le total vide
     *
     * @return array<string, list<string>>
     */
    private function indexerParNom(string $fichier, string $quoi, int $colonnes): array
    {
        $flux = @fopen($fichier, 'r');

        if (false === $flux) {
            throw new \RuntimeException(\sprintf('Fichier de référence des %s illisible : %s', $quoi, $fichier));
        }

        $index = [];
        $lues = 0;

        try {
            while (false !== ($ligne = fgets($flux))) {
                $ligne = rtrim($ligne, "\r\n");

                // Les lignes `#` portent la provenance, la licence et l'empreinte du
                // fichier : elles se sautent, elles ne se suppriment pas.
                if ('' === $ligne || str_starts_with($ligne, '#')) {
                    continue;
                }

                $champs = explode(';', $ligne);

                if (\count($champs) < $colonnes) {
                    continue;
                }

                ++$lues;
                $index[$this->cles->replier($champs[0])][] = $ligne;
            }
        } finally {
            fclose($flux);
        }

        if (0 === $lues) {
            throw new \RuntimeException(\sprintf('Le fichier des %s ne contient aucune ligne exploitable : %s. Séparateur attendu « ; » et %d colonnes — vérifier le fichier avant de conclure que l\'arbre n\'a pas de lieu.', $quoi, $fichier, $colonnes));
        }

        return $index;
    }

    /**
     * Lit une table `code;nom` et rend les deux sens de lecture.
     *
     * @return array{array<string, string>, array<string, string>} code → nom, puis nom replié → code
     */
    private function lireLesCodes(string $fichier, string $quoi): array
    {
        $flux = @fopen($fichier, 'r');

        if (false === $flux) {
            throw new \RuntimeException(\sprintf('Table des %s illisible : %s. Elle doit se trouver à côté du fichier des communes — des codes INSEE lus dans un millésime et des libellés dans un autre produiraient des rapprochements faux, sans aucun message d\'erreur.', $quoi, $fichier));
        }

        $parCode = [];
        $parNom = [];

        try {
            while (false !== ($ligne = fgets($flux))) {
                $ligne = rtrim($ligne, "\r\n");

                if ('' === $ligne || str_starts_with($ligne, '#')) {
                    continue;
                }

                $champs = explode(';', $ligne);

                if (\count($champs) < 2) {
                    continue;
                }

                $parCode[$champs[0]] = $champs[1];
                $parNom[$this->cles->replier($champs[1])] = $champs[0];
            }
        } finally {
            fclose($flux);
        }

        if ([] === $parCode) {
            throw new \RuntimeException(\sprintf('La table des %s est vide : %s', $quoi, $fichier));
        }

        return [$parCode, $parNom];
    }
}
