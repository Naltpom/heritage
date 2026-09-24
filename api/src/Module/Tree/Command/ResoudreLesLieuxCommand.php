<?php

declare(strict_types=1);

namespace App\Module\Tree\Command;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Tree\Entity\Place;
use App\Module\Tree\Service\AppariementDesLieux;
use App\Module\Tree\Service\GeocodeurEnLigne;
use App\Module\Tree\Service\RecoursEnLigne;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `heritage:resoudre-les-lieux` — remplit `tree_place` à partir des chaînes `PLAC` de
 * l'arbre.
 *
 * ## Pourquoi une commande, et non un service appelé à la sérialisation
 *
 * Parce que l'appariement lit 114 838 communes et 34 145 villes : c'est un travail de
 * secondes, fait **une fois**, dont le résultat tient en une centaine de lignes. Le faire à
 * la volée coûterait ce prix à chaque requête HTTP, et surtout il rendrait impossible la
 * seule chose qui compte vraiment ici — **corriger à la main** ce que l'automate n'a pas su
 * lire.
 *
 * ## Ce qu'elle n'écrase JAMAIS
 *
 * **Une ligne dont `source = 'manuel'` est intouchable, y compris avec `--forcer`.** Ce
 * n'est pas une politesse : la vraie base vit sur le VPS et le dépôt Git ne porte aucune
 * donnée familiale. Une correction faite à la main **n'a nulle part ailleurs où exister**,
 * et l'écraser c'est la perdre définitivement. `--forcer` rejoue l'appariement sur tout le
 * reste — c'est ce qu'on lance après avoir régénéré un fichier de référence.
 *
 * ## On n'écrit que ce qui change
 *
 * Même règle qu'à {@see RecalculerLesClesDeRechercheCommand} et à
 * `Tree\Service\RecalculDesColonnesDerivees` : on compare avant d'écrire, et `resolu_le` ne
 * bouge que si autre chose a bougé. C'est ce qui rend la commande **idempotente** —
 * rejouée, elle ne trouve plus rien à écrire — et donc sûre à lancer quand on doute de
 * l'état de la table.
 *
 * `tree_place` ne porte pas de `#[ORM\Version]` et n'est la cible d'aucune demande : la
 * réécrire ne périme rien. L'économie est ici de lisibilité — un « 0 ligne écrite » qui dit
 * la vérité vaut mieux qu'un « 104 lignes écrites » qui ne dit rien.
 *
 * ## `--en-ligne` : le recours à OpenStreetMap Nominatim
 *
 * Les référentiels locaux situent la France, le Canada et les villes du monde de plus de
 * 15 000 habitants **sous leur nom anglais**. Tout le reste — « Liège, Belgique »,
 * un village polonais — tombe en passe F. Avec `--en-ligne`, ces lieux-là, et **eux
 * seuls**, sont demandés à Nominatim ({@see GeocodeurEnLigne}). Les règles, qui sont
 * autant de refus :
 *
 * - **passe F seulement, jamais D.** Une passe D sait déjà qu'il faut choisir entre des
 *   homonymes ; un géocodeur choisirait à notre place, et c'est précisément ce qu'on ne
 *   fait pas ;
 * - **au moins deux morceaux non vides** (« Liège, Belgique ») : un mot seul est trop
 *   ambigu pour qu'une réponse unique veuille dire quelque chose ;
 * - **jamais un lieu qui avait des coordonnées** avant cette exécution, y compris sous
 *   `--forcer` : le recours vise les lieux jamais situés, pas une relecture générale —
 *   qui, sur un référentiel cassé, enverrait l'arbre entier au tiers ;
 * - **jamais une correction manuelle**, évidemment ;
 * - **pas deux fois en trente jours** pour un lieu introuvable : la ligne garde `source =
 *   'nominatim'` sans coordonnées, et `resolu_le` est la date de la demande. Une
 *   variation du nombre d'occurrences avance cette date — au pire, la prochaine demande
 *   est retardée, ce qui est le sens prudent ;
 * - **un résultat Nominatim n'est jamais redemandé** ; seule une passe locale qui
 *   aboutirait un jour (référentiel enrichi) le remplace, parce qu'elle vaut mieux ;
 * - **pas sous `--dry-run`** : une réponse qu'on jetterait est une requête gâchée chez un
 *   service bénévole, et la mémoire des échecs ne pourrait pas s'écrire ;
 * - **au plus {@see RecoursEnLigne::REQUETES_MAXIMUM} requêtes par exécution**, dans
 *   l'ordre des occurrences décroissantes : les lieux les plus cités d'abord, le reste à
 *   la nuit suivante ;
 * - **une panne arrête le recours pour l'exécution**, est rapportée, et ne change pas le
 *   code de sortie — ni un déploiement ni la nuit ne doivent tomber parce qu'un service
 *   tiers est indisponible.
 *
 * **Seule la chaîne du lieu sort de la machine** — ni personne, ni date, ni rien qui
 * rattache ce lieu à quelqu'un. Voir l'en-tête de
 * {@see \App\Module\Tree\Service\GeocodeurNominatim}.
 *
 * Le recours se coupe pour toute l'instance par `HERITAGE_GEOCODAGE_EN_LIGNE=0` : la
 * commande accepte alors l'option, le dit, et n'envoie rien. Vide ou absente, la variable
 * laisse l'option décider — c'est le défaut de production, où la résolution de nuit et
 * celle du déploiement passent `--en-ligne`.
 *
 * ## Le code de sortie, et le seul cas rouge
 *
 * **Un lieu non résolu n'est pas un échec** : c'est une bulle qui manque sur une carte, et
 * la commande l'annonce en chiffres et en liste. Elle sort donc `SUCCESS`.
 *
 * Elle sort `FAILURE` dans **un seul cas** : des lieux ont été trouvés dans l'arbre, et
 * **aucun** n'est résolu — ni par cette exécution, ni par une précédente. Ce cas-là n'est
 * pas une base pauvre en lieux, c'est un outil cassé : mauvais fichier, mauvais séparateur,
 * référentiel tronqué. Le distinguer importe, parce qu'un « 0 résolu » présenté proprement
 * dans un tableau vert est exactement le genre de panne qu'on déploie sans la voir.
 */
#[AsCommand(
    name: 'heritage:resoudre-les-lieux',
    description: 'Apparie les chaînes PLAC de l’arbre avec le référentiel géographique et remplit tree_place.',
)]
final class ResoudreLesLieuxCommand extends Command
{
    /** Les valeurs de `HERITAGE_GEOCODAGE_EN_LIGNE` qui coupent le recours. */
    private const array VALEURS_QUI_COUPENT = ['0', 'false', 'non', 'no', 'off'];

    /**
     * Les chemins par défaut sont **injectés**, jamais écrits en dur : un chemin absolu
     * dans le code ne survit ni au conteneur Docker, ni à la machine du développeur
     * suivant. `%kernel.project_dir%` est la seule racine que les deux connaissent.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppariementDesLieux $appariement,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $racineDuProjet,
        private readonly CleDeRecherche $cles = new CleDeRecherche(),
        private readonly ?GeocodeurEnLigne $geocodeur = null,
        /**
         * L'interrupteur général : `0`, `false`, `non`, `no` ou `off` coupent le recours en
         * ligne pour toute l'instance, quelle que soit l'option. Vide ou absente, c'est
         * l'option `--en-ligne` qui décide.
         */
        #[Autowire(env: 'default::HERITAGE_GEOCODAGE_EN_LIGNE')]
        private readonly ?string $geocodageEnLigne = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Apparie, annonce les comptes, et n’écrit rien.')
            ->addOption('forcer', null, InputOption::VALUE_NONE, 'Rejoue l’appariement sur les lieux déjà résolus — sauf les corrections manuelles, jamais touchées.')
            ->addOption('en-ligne', null, InputOption::VALUE_NONE, 'Demande à OpenStreetMap Nominatim les lieux que les référentiels locaux n’ont pas situés (seule la chaîne du lieu sort ; coupé par HERITAGE_GEOCODAGE_EN_LIGNE=0).')
            ->addOption('fichier-communes', null, InputOption::VALUE_REQUIRED, 'Chemin du fichier des communes françaises.', $this->racineDuProjet.'/ressources/gazetteer/communes-fr.csv')
            ->addOption('fichier-villes', null, InputOption::VALUE_REQUIRED, 'Chemin du fichier des villes du monde.', $this->racineDuProjet.'/ressources/gazetteer/villes-monde.csv');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);
        $blanc = (bool) $entree->getOption('dry-run');
        $forcer = (bool) $entree->getOption('forcer');

        $lieux = $this->lieuxDeLArbre();

        if ([] === $lieux) {
            $style->warning('Aucune chaîne PLAC dans l’arbre : rien à résoudre.');

            return Command::SUCCESS;
        }

        $this->appariement->charger(
            (string) $entree->getOption('fichier-communes'),
            (string) $entree->getOption('fichier-villes'),
        );

        $style->definitionList(
            ['Lieux distincts dans l’arbre' => \count($lieux)],
            ['Communes de référence' => $this->appariement->nombreDeCommunes()],
            ['Villes du monde de référence' => $this->appariement->nombreDeVilles()],
        );

        $existantes = $this->lignesExistantes();
        $enLigne = $this->recoursEnLigne($style, (bool) $entree->getOption('en-ligne'), $blanc);

        $resolus = 0;
        $dejaResolus = 0;
        $manuels = 0;
        $ecrites = 0;
        /** @var list<array{lieu: string, occurrences: int, raison: string}> $enEchec */
        $enEchec = [];

        foreach ($lieux as $cle => $lieu) {
            $ligne = $existantes[$cle] ?? null;

            // La règle qui ne souffre aucune exception : une correction manuelle n'est
            // jamais réécrite, --forcer ou non. Elle n'existe nulle part ailleurs.
            if (null !== $ligne && $ligne->estManuel()) {
                ++$manuels;

                if (null !== $ligne->getLat()) {
                    ++$dejaResolus;
                }

                $ecrites += $this->majOccurrences($ligne, $lieu['occurrences']) ? 1 : 0;

                continue;
            }

            $dejaPose = null !== $ligne && null !== $ligne->getLat();

            if ($dejaPose && !$forcer) {
                ++$dejaResolus;
                $ecrites += $this->majOccurrences($ligne, $lieu['occurrences']) ? 1 : 0;

                continue;
            }

            $trouve = $this->appariement->apparier($lieu['brut']);
            $raison = 'aucun rapprochement';
            $demandeEnLigne = false;

            if (AppariementDesLieux::PASSE_INCONNU === $trouve['passe'] && null !== $ligne && Place::SOURCE_NOMINATIM === $ligne->getSource()) {
                // Un verdict de Nominatim existe déjà, et les passes locales ne font pas
                // mieux. Positif (atteignable sous --forcer seulement) : on le garde, on
                // ne le redemande pas. Négatif et récent : on ne le redemande pas non plus.
                if ($dejaPose) {
                    ++$dejaResolus;
                    $ecrites += $this->majOccurrences($ligne, $lieu['occurrences']) ? 1 : 0;

                    continue;
                }

                if (!$enLigne->peutDemander($ligne)) {
                    $enEchec[] = [
                        'lieu' => $lieu['brut'],
                        'occurrences' => $lieu['occurrences'],
                        'raison' => \sprintf('aucun rapprochement, ni en ligne (Nominatim, le %s)', $ligne->getResoluLe()->format('Y-m-d')),
                    ];
                    $ecrites += $this->majOccurrences($ligne, $lieu['occurrences']) ? 1 : 0;

                    continue;
                }
            }

            // Le recours en ligne : passe F seulement — jamais D —, sur un lieu qui n'avait
            // pas de coordonnées, et dont la chaîne a au moins deux morceaux.
            if (AppariementDesLieux::PASSE_INCONNU === $trouve['passe'] && !$dejaPose && self::assezPrecisPourLeReseau($lieu['brut'])) {
                if ($enLigne->peutDemander($ligne)) {
                    $demandeEnLigne = true;
                    $reponse = $enLigne->demander($lieu['brut']);

                    if (\is_array($reponse)) {
                        $trouve = $reponse;
                    } elseif (null === $reponse) {
                        // Rien d'acceptable : c'est un verdict, et on le mémorise.
                        $trouve['source'] = Place::SOURCE_NOMINATIM;
                        $raison = 'aucun rapprochement, ni en ligne (Nominatim)';
                    } else {
                        // Le tiers n'a pas répondu : pas un verdict, rien à mémoriser.
                        $demandeEnLigne = false;
                        $raison = 'aucun rapprochement (Nominatim injoignable, redemandé à la prochaine exécution)';
                    }
                } elseif ($enLigne->plafondAtteint()) {
                    $raison = 'aucun rapprochement (demande en ligne reportée : plafond de l’exécution atteint)';
                }
            }

            if (null === $trouve['lat']) {
                $enEchec[] = [
                    'lieu' => $lieu['brut'],
                    'occurrences' => $lieu['occurrences'],
                    'raison' => AppariementDesLieux::PASSE_AMBIGU === $trouve['passe']
                        ? \sprintf('homonymes : %s', implode(' · ', \array_slice($trouve['candidats'], 0, 6)))
                        : $raison,
                ];
            } else {
                ++$resolus;
            }

            $ligne ??= new Place($lieu['brut'], $cle, $trouve['libelle']);
            $ecrite = $this->appliquer($ligne, $lieu, $trouve);

            // Une demande en ligne est un fait daté, même quand sa réponse ne change rien
            // à la ligne : c'est `resolu_le` qui compte les trente jours. Sans ce geste, un
            // lieu redemandé en vain après trente jours ne serait plus jamais daté — et
            // serait redemandé chaque nuit.
            if ($demandeEnLigne && !$ecrite) {
                $ligne->toucher();
                $ecrite = true;
            }

            $ecrites += $ecrite ? 1 : 0;

            if (null === $ligne->getId()) {
                $this->em->persist($ligne);
            }
        }

        // **Rien n'est écrit quand rien n'est reconnu.** `charger()` refuse déjà un fichier
        // vide ou au mauvais séparateur ; il ne peut pas refuser un fichier bien formé mais
        // hors sujet — le référentiel d'un autre pays, des colonnes dans le mauvais ordre.
        // Dans ce cas `--forcer` effacerait les coordonnées de **toutes** les lignes avant
        // que quiconque ait pu lire le rapport, et la vraie base n'existe que sur le VPS.
        // On décide donc d'échouer avant de vider, pas après.
        $echecTotal = self::echecTotal(\count($lieux), $resolus, $dejaResolus);

        if (!$blanc && !$echecTotal) {
            $this->em->flush();
        }

        $enLigne->rendreCompte($style);

        return $this->rendreCompte($style, $blanc || $echecTotal, \count($lieux), $resolus, $dejaResolus, $manuels, $ecrites, $enEchec);
    }

    /**
     * Le recours en ligne de cette exécution — ou un recours inerte, qui le dit.
     *
     * Trois raisons de ne rien envoyer, et chacune est **annoncée** : un « 0 résolu en
     * ligne » sans cause lisible ferait chercher la panne au mauvais endroit.
     */
    private function recoursEnLigne(SymfonyStyle $style, bool $demande, bool $blanc): RecoursEnLigne
    {
        if (!$demande) {
            return new RecoursEnLigne(null);
        }

        $interrupteur = strtolower(trim((string) $this->geocodageEnLigne));

        if (\in_array($interrupteur, self::VALEURS_QUI_COUPENT, true)) {
            $style->note('--en-ligne demandé, mais HERITAGE_GEOCODAGE_EN_LIGNE='.$this->geocodageEnLigne.' le coupe pour cette instance : aucune requête ne part.');

            return new RecoursEnLigne(null);
        }

        if ($blanc) {
            $style->note('--en-ligne ignoré sous --dry-run : une réponse qu’on jetterait est une requête gâchée chez un service bénévole.');

            return new RecoursEnLigne(null);
        }

        if (null === $this->geocodeur) {
            $style->warning('--en-ligne demandé, mais aucun géocodeur n’est configuré : aucune requête ne part.');

            return new RecoursEnLigne(null);
        }

        return new RecoursEnLigne($this->geocodeur);
    }

    /**
     * Au moins **deux morceaux non vides** : « Liège, Belgique », pas « Liège ».
     *
     * Un mot seul est trop ambigu pour qu'une réponse unique veuille dire quelque chose —
     * Nominatim rendrait la Liège la plus « importante » à ses yeux, qui n'est pas
     * forcément celle de l'acte. Le découpage est celui d'{@see AppariementDesLieux} :
     * virgules, espaces parasites et morceaux vides ignorés.
     */
    private static function assezPrecisPourLeReseau(string $brut): bool
    {
        $morceaux = array_filter(
            array_map(trim(...), explode(',', $brut)),
            static fn (string $morceau): bool => '' !== $morceau,
        );

        return \count($morceaux) >= 2;
    }

    /**
     * Les chaînes `PLAC` distinctes de l'arbre, avec leur nombre d'occurrences, **en une
     * seule requête**.
     *
     * ## Pourquoi DBAL et non DQL
     *
     * **DQL ne sait pas faire d'`UNION`.** Les cinq colonnes vivent dans trois entités
     * différentes et ne sont pas des associations : il n'existe aucune façon de les réunir
     * en DQL, et les lire en cinq requêtes puis fusionner en PHP ferait charger trois
     * hydratations complètes pour cent chaînes distinctes.
     *
     * ## Pourquoi `UNION ALL` et non `UNION`
     *
     * `UNION` dédoublonne **avant** le comptage : chaque lieu ressortirait à une occurrence,
     * et la colonne `occurrences` — qui donne l'ordre dans lequel on relit les lieux à
     * corriger — ne voudrait plus rien dire. Le dédoublonnage se fait donc au `GROUP BY`
     * extérieur, qui compte au passage. C'est une seule instruction, et elle rend les deux
     * informations d'un coup.
     *
     * ## `trim()`, jamais `btrim()`
     *
     * `btrim()` est **propre à PostgreSQL**. Le développement et toute la suite de tests
     * tournent sur SQLite : une commande écrite en `btrim()` échouerait avec « no such
     * function » sur la machine de qui l'écrit, et ne serait donc jamais éprouvée avant la
     * production. `trim()` existe des deux côtés et dit la même chose.
     *
     * ## Le regroupement final se fait sur la clé repliée
     *
     * `uniq_tree_place_cle` porte sur `raw_key` : deux graphies qui se replient sur la même
     * clé — `Saule` et `SAULE ` — sont **une seule ligne**. Les regrouper ici évite une
     * violation de contrainte d'unicité au `flush`, et additionne correctement leurs
     * occurrences.
     *
     * @return array<string, array{brut: string, occurrences: int}> indexé par clé repliée
     */
    private function lieuxDeLArbre(): array
    {
        $sql = <<<'SQL'
            SELECT lieu, COUNT(*) AS occurrences
            FROM (
                SELECT birth_place AS lieu FROM tree_person WHERE birth_place IS NOT NULL AND trim(birth_place) <> ''
                UNION ALL
                SELECT death_place FROM tree_person WHERE death_place IS NOT NULL AND trim(death_place) <> ''
                UNION ALL
                SELECT marriage_place FROM tree_family WHERE marriage_place IS NOT NULL AND trim(marriage_place) <> ''
                UNION ALL
                SELECT divorce_place FROM tree_family WHERE divorce_place IS NOT NULL AND trim(divorce_place) <> ''
                UNION ALL
                SELECT happened_place FROM tree_fact WHERE happened_place IS NOT NULL AND trim(happened_place) <> ''
            ) AS lieux
            GROUP BY lieu
            ORDER BY COUNT(*) DESC, lieu ASC
            SQL;

        /** @var list<array{lieu: string, occurrences: int|string}> $lignes */
        $lignes = $this->em->getConnection()->executeQuery($sql)->fetchAllAssociative();

        $lieux = [];

        foreach ($lignes as $ligne) {
            $brut = trim($ligne['lieu']);
            $cle = $this->cles->replier($brut);

            if ('' === $cle) {
                continue;
            }

            if (isset($lieux[$cle])) {
                $lieux[$cle]['occurrences'] += (int) $ligne['occurrences'];

                continue;
            }

            $lieux[$cle] = ['brut' => $brut, 'occurrences' => (int) $ligne['occurrences']];
        }

        return $lieux;
    }

    /** @return array<string, Place> indexé par `raw_key` */
    private function lignesExistantes(): array
    {
        $existantes = [];

        foreach ($this->em->getRepository(Place::class)->findAll() as $ligne) {
            $existantes[$ligne->getRawKey()] = $ligne;
        }

        return $existantes;
    }

    /**
     * Pose le résultat de l'appariement sur la ligne, **et rend `true` si quelque chose a
     * bougé**.
     *
     * Le `resolu_le` ne se touche que dans ce cas : une date qui avance à chaque exécution
     * transformerait « rejouée, elle ne trouve rien à écrire » en « rejouée, elle réécrit
     * tout », et le compte publié cesserait de vouloir dire quelque chose.
     *
     * @param array{brut: string, occurrences: int}                                                                                                                                                               $lieu
     * @param array{libelle: string, commune: string|null, subdivision: string|null, pays: string|null, lat: float|null, lon: float|null, source: string, confiance: int, passe: string, candidats: list<string>} $trouve
     */
    private function appliquer(Place $ligne, array $lieu, array $trouve): bool
    {
        $avant = [
            $ligne->getRaw(), $ligne->getLibelle(), $ligne->getCommune(), $ligne->getSubdivision(),
            $ligne->getPays(), $ligne->getLat(), $ligne->getLon(), $ligne->getSource(),
            $ligne->getConfiance(), $ligne->getOccurrences(),
        ];

        $ligne
            ->setRaw($lieu['brut'])
            ->setLibelle($trouve['libelle'])
            ->setCommune($trouve['commune'])
            ->setSubdivision($trouve['subdivision'])
            ->setPays($trouve['pays'])
            ->poserLesCoordonnees($trouve['lat'], $trouve['lon'])
            ->setSource($trouve['source'])
            ->setConfiance($trouve['confiance'])
            ->setOccurrences($lieu['occurrences']);

        $apres = [
            $ligne->getRaw(), $ligne->getLibelle(), $ligne->getCommune(), $ligne->getSubdivision(),
            $ligne->getPays(), $ligne->getLat(), $ligne->getLon(), $ligne->getSource(),
            $ligne->getConfiance(), $ligne->getOccurrences(),
        ];

        if ($avant === $apres && null !== $ligne->getId()) {
            return false;
        }

        $ligne->toucher();

        return true;
    }

    /** Le seul champ qu'on met à jour sur une ligne qu'on ne réapparie pas. */
    private function majOccurrences(Place $ligne, int $occurrences): bool
    {
        if ($ligne->getOccurrences() === $occurrences) {
            return false;
        }

        $ligne->setOccurrences($occurrences)->toucher();

        return true;
    }

    /**
     * **Deux nombres, toujours : ce qui est résolu et ce qui ne l'est pas**.
     *
     * Un rapport qui n'annoncerait que les succès laisserait croire qu'une carte est
     * complète alors qu'il lui manque un tiers de ses bulles. Les lieux en échec sont donc
     * listés, **par nombre d'occurrences décroissant** — c'est l'ordre dans lequel les
     * corriger rapporte le plus — et chacun dit **pourquoi** il a échoué : entre des
     * homonymes qu'on nomme, ou faute de tout rapprochement.
     *
     * @param bool                                                        $sansEcriture `--dry-run`, ou l'échec
     *                                                                                  total — qui n'écrit rien non plus
     * @param list<array{lieu: string, occurrences: int, raison: string}> $enEchec
     */
    private function rendreCompte(
        SymfonyStyle $style,
        bool $sansEcriture,
        int $trouves,
        int $resolus,
        int $dejaResolus,
        int $manuels,
        int $ecrites,
        array $enEchec,
    ): int {
        usort($enEchec, static fn (array $a, array $b): int => [$b['occurrences'], $a['lieu']] <=> [$a['occurrences'], $b['lieu']]);

        $style->definitionList(
            ['Résolus par cette exécution' => $resolus],
            ['Déjà résolus, laissés tels quels' => $dejaResolus],
            ['Corrections manuelles préservées' => $manuels],
            ['NON RÉSOLUS' => \count($enEchec)],
            ['Lignes écrites' => $sansEcriture ? 0 : $ecrites],
        );

        if ([] !== $enEchec) {
            $style->section('Lieux non résolus, du plus fréquent au plus rare');
            $style->table(
                ['Occurrences', 'Lieu', 'Raison'],
                array_map(
                    static fn (array $echec): array => [$echec['occurrences'], $echec['lieu'], $echec['raison']],
                    $enEchec,
                ),
            );
            $style->note(
                'Un lieu non résolu est une bulle qui manque, pas une panne. Pour en corriger un : '
                .'poser à la main lat/lon sur sa ligne de tree_place et mettre source = \''.Place::SOURCE_MANUEL.'\' — '
                .'aucune exécution ultérieure ne la réécrira, même avec --forcer.'
            );
        }

        // Le seul cas rouge : des lieux existent, et pas un seul n'est résolu — ni
        // maintenant, ni avant. Ce n'est pas un arbre pauvre en lieux, c'est un outil
        // cassé : mauvais fichier, mauvais séparateur, référentiel tronqué. Un « 0 résolu »
        // présenté proprement en vert est exactement la panne qu'on déploie sans la voir.
        if (self::echecTotal($trouves, $resolus, $dejaResolus)) {
            $style->error(\sprintf(
                '%d lieu(x) trouvé(s) dans l’arbre et AUCUN résolu — RIEN N’A ÉTÉ ÉCRIT. Ce n’est pas un arbre '
                .'sans lieux : c’est le symptôme d’un fichier de référence absent, tronqué, hors sujet, ou lu '
                .'avec le mauvais séparateur. Vérifier --fichier-communes et --fichier-villes avant de conclure.',
                $trouves,
            ));

            return Command::FAILURE;
        }

        if ($sansEcriture) {
            $style->note('--dry-run : rien n’a été écrit. Relancer sans l’option pour appliquer.');
        }

        $style->success(\sprintf(
            '%d lieu(x) résolu(s), %d non résolu(s) sur %d.',
            $resolus + $dejaResolus,
            \count($enEchec),
            $trouves,
        ));

        return Command::SUCCESS;
    }

    /**
     * La condition d'échec, **écrite une fois** : elle décide à la fois de ne rien écrire
     * et de sortir en rouge, et deux rédactions divergeraient un jour — on écrirait alors
     * en base un état qu'on s'apprête à déclarer faux.
     */
    private static function echecTotal(int $trouves, int $resolus, int $dejaResolus): bool
    {
        return $trouves > 0 && 0 === $resolus && 0 === $dejaResolus;
    }
}
