<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\CalqueEnAttenteInterface;
use App\Module\Core\Contract\ProjectionEnAttente;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Enum\ParenteDansLArbre;
use App\Module\Tree\Service\ArbreVuDepuis;
use App\Module\Tree\Service\EtiquetteDeDateGedcom;
use App\Module\Tree\Service\MesureDeFiche;
use App\Module\Tree\Service\PlacementPartage;
use App\Module\Tree\Service\ProjecteurDePlacement;
use App\Module\Tree\Service\TreeFingerprint;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\PersonnePlacable;
use App\Module\Tree\ValueObject\Placement;
use Doctrine\DBAL\Connection;

/**
 * Le graphe — § 5.2 du contrat. C'est **l'étage 2** de l'ADR 0005 : le placement
 * partagé, projeté pour ce lecteur, puis habillé des champs que son cercle autorise.
 *
 * ## Ce que ce fichier ne fait surtout pas
 *
 * **Il n'ajoute aucun cache.** L'étage 1 ({@see PlacementPartage}) est partagé par tous
 * les lecteurs et calculé **sans filtre de confidentialité** — il ne contient que des
 * identifiants et des coordonnées, donc aucune donnée personnelle, et c'est ce qui le
 * rend partageable. L'étage 2 n'est **jamais** mis en cache, et ce n'est pas un oubli
 * d'optimisation : une clé par lecteur ne changerait qu'à une modification *validée* de
 * l'arbre, or déposer un brouillon n'en est pas une — l'auteur ne verrait jamais son
 * propre nœud en attente, c'est-à-dire la seule chose que ce calque existe pour
 * produire (arbitrage **B**).
 *
 * **Il ne recalcule aucune largeur.** `w` et `h` viennent du placement partagé et sont
 * **identiques pour tous les lecteurs** — c'est ce qui rend ce placement partageable ; une
 * largeur qui dépendrait du lecteur imposerait un graphe en cache par compte, l'architecture
 * que le projet a écartée.
 *
 * ⚠ **Révision du 2026-10-01 : la largeur d'une fiche vivante ne dépend plus de rien.**
 * L'ancien raisonnement disait « `w` n'encode pas la ligne de dates masquée, parce que
 * le moteur mesure la constante « vivant·e » ». C'était vrai du **moteur**, et devenu faux
 * du **graphe servi** depuis que l'affinage remesure la fiche affichée — naissance des
 * vivants comprise — avec {@see MesureDeFiche::largeurAffichee()}. Et depuis que le nom
 * d'un vivant hors cercle ne sort plus, la longueur de ce nom en était une seconde fuite,
 * par `w` et par l'écart des `x`. La largeur affichée d'un vivant est donc une constante,
 * pour tout le monde ({@see MesureDeFiche::LARGEUR_AFFICHEE_VIVANT}) ; le moteur, lui, n'a
 * pas changé, et sa preuve des 377 positions non plus.
 *
 * **Il ne reconstruit jamais `ligneDates`.** Il appelle `MesureDeFiche::ligneDates()`,
 * sur le **même** `PersonnePlacable` que celui qui a servi à mesurer `w`. C'est ce qui
 * garantit que la largeur envoyée et le texte envoyé ne peuvent pas diverger.
 *
 * > **`ligneDates` désigne deux objets différents, et les confondre se voit à l'écran.**
 * > Sur un **nœud**, c'est l'étiquette du moteur : la forme **compacte** que produit
 * > `EtiquetteDeDateGedcom` — « 1901-1909 – 1987 », « vivant·e », « — » —, et c'est la
 * > chaîne sur laquelle `w` a été mesurée. Sur une **fiche**, c'est la prose française de
 * > `UncertainDate::format()` — « entre 1901 et 1909 – 1987 » —, deux fois plus longue.
 * > Émettre la seconde ici ferait déborder l'étiquette de sa boîte.
 * >
 * > Le § 5.2 du contrat d'API donne la prose en exemple sur un nœud. **C'est une erreur
 * > du contrat**, signalée au livrable : elle contredit le § 11.2 du même document, qui
 * > décrit `ligneDates` comme « la chaîne sur laquelle `w` a été mesurée », et elle
 * > contredit `graphe.json`, qui est la référence de non-régression du moteur.
 *
 * ## Deux conventions d'identifiant que ce fichier impose
 *
 * 1. **Les identifiants de nœuds sont ceux de {@see IdentifiantDeNoeud}** : `p17` pour
 *    `tree_person.id = 17`, `f28` pour `tree_family.id = 28`. Le moteur range personnes
 *    et familles dans **une seule** table indexée par identifiant : sans préfixe, la
 *    personne 28 et la famille 28 se recouvriraient, et le graphe sortirait avec 274
 *    nœuds au lieu de 377 comme si aucune famille n'avait été calculée. C'est donc une
 *    contrainte du moteur autant qu'une exigence du contrat, et on relit ces
 *    identifiants par le même objet valeur qui les a écrits.
 * 2. **La famille provisoire d'un nœud en attente est renumérotée à l'émission.** Le
 *    projecteur la nomme `#12/p1#union`, forme dérivée du brouillon donc stable d'un
 *    affichage à l'autre ; le contrat expose `#12/f1`, numérotée dans l'ordre des liens
 *    de la demande. La correspondance est faite ici, sur les nœuds **et** sur les arêtes
 *    — c'est une convention de sérialisation, elle n'a pas à remonter dans le moteur.
 */
final readonly class NormaliseurDeGraphe
{
    public function __construct(
        private PlacementPartage $partage,
        private ProjecteurDePlacement $projecteur,
        private TreeFingerprint $empreinte,
        private CalqueEnAttenteInterface $calque,
        private PropositionsProjetees $traduction,
        private PortDesMedias $medias,
        private PersonneDuLecteur $pointDeVue,
        private EtiquetteDuNom $noms,
        private Connection $connexion,
        private ArbreVuDepuis $arbre,
        private MesureDeFiche $mesure = new MesureDeFiche(),
        private EtiquetteDeDateGedcom $etiquettes = new EtiquetteDeDateGedcom(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function graphe(LecteurDeLArbre $lecteur, CercleDuLecteur $cercle): array
    {
        // Le jeu complet est relu ici **en plus** du placement en cache, et ce n'est pas
        // un oubli d'optimisation : c'est lui qui porte les `PersonnePlacable` sur
        // lesquels `w` a été mesurée. En émettre l'étiquette depuis une autre source
        // rouvrirait l'écart entre la largeur envoyée et le texte envoyé. 274 lignes,
        // une fois par affichage du graphe : à cette échelle, la cohérence vaut la
        // requête (§ 3 du dossier de mission).
        // **Le même point de vue que le placement**, sans quoi les étiquettes seraient
        // mesurées sur un arbre et posées sur un autre.
        $pointDeVue = $this->pointDeVue->pour($lecteur);

        $jeu = $this->arbre->jeu($pointDeVue);

        /** @var array<string, PersonnePlacable> $placables indexés par identifiant de nœud */
        $placables = [];
        foreach ($jeu->personnes as $placable) {
            $placables[$placable->id] = $placable;
        }

        // La mise en page est **celle du lecteur** : `génération 0` est sa rangée, et les
        // quatre couleurs de branche disent son quart d'ascendance à lui. Servir celle de
        // la racine à tout le monde serait juste pour un compte et faux pour tous les
        // autres — voir {@see ArbreVuDepuis} et {@see PersonneDuLecteur}.
        $partage = $this->partage->placement($pointDeVue);
        $libelles = $this->libelles($this->arbre->branches($pointDeVue));
        // Famille, conjoint ou alliance, vu de ce lecteur — et le pont de chaque famille
        // par alliance. Calculés par le même parcours que les branches.
        $parentes = $this->arbre->parentes($pointDeVue);

        // Le calque et le cercle sont deux filtres **composés** (ADR 0004) : celui-ci
        // décide *quelles* propositions ce lecteur a le droit de voir — les siennes, ou
        // toutes s'il est administrateur hors bascule —, celui-là décide ce qu'on dit de
        // chaque personne validée. Aucun des deux ne remplace l'autre.
        $projection = $this->calque->projection($lecteur->pourLeCalque());

        ['noeuds' => $proposes, 'renommage' => $renommage, 'ecartes' => $ecartes] = $this->traduction->preparer(
            $projection,
            $partage,
            $libelles['personnes'],
        );

        $placement = $this->projecteur->projette(
            $partage,
            array_map(static fn (NoeudProposePlacable $n) => $n->pourLaProjection(), $proposes),
        );

        /** @var array<string, NoeudProposePlacable> $proposesParId */
        $proposesParId = [];
        foreach ($proposes as $propose) {
            $proposesParId[$propose->id()] = $propose;
            $placables[$propose->id()] = $propose->mesure;
        }

        // Une seule question pour tout l'arbre : le filtre « Avec image » de la barre
        // d'outils a besoin d'un booléen par nœud, et 274 appels unitaires coûteraient
        // 274 allers-retours pour l'obtenir.
        $avecImage = $this->medias->personnesAvecMedia(array_keys($libelles['personnes']), $lecteur);

        // Même geste pour la vignette du portrait, dessinée à gauche de chaque fiche :
        // une question pour tout l'arbre, et une personne masquée en est absente.
        $vignettes = $this->medias->vignettesDePortraits(array_keys($libelles['personnes']), $lecteur);

        $demandes = $projection->demandes;
        $nonPlaces = $this->nonPlaces($projection, $placement, $ecartes);

        return [
            'empreinte' => $this->empreinte->empreinte(),
            'ego' => $placement->egoId,
            'boite' => [
                // Jamais arrondies : le test de non-régression compare au centième, et un
                // arrondi de sérialisation le rendrait ininterprétable (§ 1.4).
                'x' => $placement->boite->x,
                'y' => $placement->boite->y,
                'w' => $placement->boite->w,
                'h' => $placement->boite->h,
            ],
            'calque' => [
                // On regarde la **projection**, pas la seule liste de nœuds : une demande
                // `ajout_lien` ne propose aucune personne — « ils étaient frère et sœur »,
                // « elle s'est remariée avec lui » — et elle a pourtant des arêtes à
                // dessiner, donc une légende à allumer.
                'actif' => !$projection->estVide(),
                'demandes' => $demandes,
                // **`actif` ne dit pas « complet ».** Ces trois clés le disent, et elles
                // ont été ajoutées après un cas mesuré où le calque s'annonçait actif sur
                // cinq demandes en ayant écarté dix-sept nœuds sur vingt-deux — voir
                // {@see NoeudProposeEcarte}.
                'proposes' => \count($projection->noeuds),
                'places' => \count($projection->noeuds) - \count($nonPlaces),
                'nonPlaces' => $nonPlaces,
            ],
        ] + GrapheDuPerimetre::retirer(
            $this->noeuds($placement, $placables, $proposesParId, $libelles, $parentes, $renommage, $cercle, $avecImage, $vignettes, $this->naissancesReduites($placables, $cercle)),
            $this->aretes($placement, $renommage),
            $cercle,
        );
    }

    /**
     * Les nœuds proposés que le rendu **n'a pas placés**, chacun avec sa raison.
     *
     * ── Mesurée sur la sortie, jamais sur l'intention ───────────────────────────────
     *
     * La liste se bâtit en confrontant `$projection->noeuds` — tout ce que `Proposal`
     * publie pour ce lecteur — au `Placement` **final**, celui dont `noeuds` sort. C'est
     * délibérément la seule source : additionner les écarts que chaque étage déclare
     * laisserait passer ceux qu'aucun ne déclare, et il y en a deux —
     * {@see PropositionsProjetees} écarte à la traduction,
     * {@see ProjecteurDePlacement::pose()} écarte à la pose. Un
     * compte tenu à part dériverait du jour où un troisième apparaîtrait.
     *
     * De là découle l'invariant que le contrat publie et qu'un test tient :
     * `proposes === places + count(nonPlaces)`, par construction et non par accord.
     *
     * Les raisons, elles, viennent bien des étages, parce qu'eux seuls les connaissent ;
     * un nœud tombé à la pose n'en a aucune et prend `placement_refuse`.
     *
     * ── Ce que la liste peut nommer, et pourquoi ce n'est pas une fuite ─────────────
     *
     * `displayName` y figure. La projection est déjà bornée aux demandes que ce lecteur a
     * le droit de voir — les siennes, ou toutes s'il est administrateur (§ 13.3) —, et ce
     * nom serait sur le nœud si le nœud avait été placé. Dire « Morille Chanterelle n'a pas
     * pu être placé » ne divulgue rien de plus que l'aurait fait son affichage.
     *
     * @param list<NoeudProposeEcarte> $ecartes
     *
     * @return list<array<string, mixed>>
     */
    private function nonPlaces(ProjectionEnAttente $projection, Placement $placement, array $ecartes): array
    {
        $raisons = [];
        foreach ($ecartes as $ecarte) {
            $raisons[$ecarte->source->noeudId] = $ecarte->raison;
        }

        $nonPlaces = [];

        foreach ($projection->noeuds as $source) {
            if (isset($placement->noeuds[$source->noeudId])) {
                continue;
            }

            $nonPlaces[] = [
                'noeudId' => $source->noeudId,
                'demandeId' => $source->demandeId,
                // Les deux clés que le § 5.2 impose pour ouvrir le détail d'un brouillon
                // sans concaténer : `#` est un séparateur de fragment, tout ce qui le
                // suit ne quitterait jamais le navigateur.
                'brouillonRef' => $source->brouillonRef,
                'displayName' => $source->displayName,
                'raison' => $raisons[$source->noeudId] ?? NoeudProposeEcarte::PLACEMENT_REFUSE,
            ];
        }

        return $nonPlaces;
    }

    /**
     * @param array<string, PersonnePlacable>                                                                                                                   $placables
     * @param array<string, NoeudProposePlacable>                                                                                                               $proposes
     * @param array{personnes: array<int, array{displayName: string, branch: string|null, bestQuay: int|null, citationCount: int}>, familles: array<int, bool>} $libelles
     * @param array<int, array{parente: ParenteDansLArbre, pont: string|null}>                                                                                  $parentes
     * @param array<string, string>                                                                                                                             $renommage
     * @param array<int, true>                                                                                                                                  $avecImage
     * @param array<int, string>                                                                                                                                $vignettes          personne visible → URL de la vignette de son portrait
     * @param array<int, string>                                                                                                                                $naissancesReduites vivant vu partiellement → sa naissance compacte réduite (ADR 0016)
     *
     * @return list<array<string, mixed>>
     */
    private function noeuds(
        Placement $placement,
        array $placables,
        array $proposes,
        array $libelles,
        array $parentes,
        array $renommage,
        CercleDuLecteur $cercle,
        array $avecImage,
        array $vignettes,
        array $naissancesReduites = [],
    ): array {
        $sortie = [];

        foreach ($placement->noeuds as $noeud) {
            $propose = $proposes[$noeud->id] ?? null;

            if (null !== $propose) {
                $sortie[] = $this->noeudPropose($noeud, $propose);

                continue;
            }

            if (isset($renommage[$noeud->id])) {
                $sortie[] = $this->familleProvisoire($noeud, $renommage[$noeud->id]);

                continue;
            }

            $base = IdentifiantDeNoeud::identifiantBase($noeud->id);

            if (IdentifiantDeNoeud::estPersonne($noeud->id) && null !== $base) {
                $sortie[] = $this->noeudDePersonne($noeud, $base, $placables[$noeud->id] ?? null, $libelles['personnes'][$base] ?? null, $parentes[$base] ?? null, $cercle, $avecImage, $vignettes, $naissancesReduites[$base] ?? null);

                continue;
            }

            $familleId = IdentifiantDeNoeud::estFamille($noeud->id) ? $base : null;

            if (null !== $familleId) {
                $sortie[] = [
                    'id' => $noeud->id,
                    'type' => 'famille',
                    'familyId' => $familleId,
                    'x' => $noeud->x,
                    'y' => $noeud->y,
                    'w' => $noeud->w,
                    'h' => $noeud->h,
                    // `.ar.divorce` est **conservée** au rendu (§ 7.3 du dossier) : un
                    // divorce est une information, pas une distance.
                    'divorced' => $libelles['familles'][$familleId] ?? false,
                ];
            }
        }

        return $sortie;
    }

    /**
     * @param array{displayName: string, branch: string|null, bestQuay: int|null, citationCount: int}|null $libelle
     * @param array{parente: ParenteDansLArbre, pont: string|null}|null                                    $parente   `null` sans point de vue calculé : la personne est alors servie `famille`
     * @param array<int, true>                                                                             $avecImage
     * @param array<int, string>                                                                           $vignettes personne visible → URL de la vignette de son portrait
     *
     * @return array<string, mixed>
     */
    private function noeudDePersonne(
        NoeudPlace $noeud,
        int $personneId,
        ?PersonnePlacable $placable,
        ?array $libelle,
        ?array $parente,
        CercleDuLecteur $cercle,
        array $avecImage,
        array $vignettes,
        ?string $naissanceReduite = null,
    ): array {
        // **L'état vivant est celui du jeu de placement**, pas un second calcul. C'est
        // celui sur lequel `w` a été mesurée ; en recalculer un ici ouvrirait la porte à
        // une étiquette « vivant·e » dans une boîte dimensionnée pour une date, ou
        // l'inverse.
        //
        // Le nœud sans `PersonnePlacable` ne devrait pas exister — le placement vient du
        // jeu. S'il existe malgré tout (une ligne disparue entre le calcul du placement
        // en cache et cette requête), on le tient pour **vivant** : c'est le côté qui
        // ferme, et une fiche muette de trop coûte moins qu'une date de trop.
        $vivante = null === $placable || $placable->vivant;
        $visible = $cercle->voit($personneId, $vivante);

        $charge = [
            'id' => $noeud->id,
            'type' => 'personne',
            'personId' => $personneId,
            'x' => $noeud->x,
            'y' => $noeud->y,
            // Recopiés tels quels : le moteur les produit déjà entiers, par troncature.
            // La sérialisation n'arrondit ni ne tronque, elle recopie (§ 1.4).
            //
            // ⚠ **Pour une personne vivante, `w` est une constante** — révision du
            // 2026-10-01 : l'affinage mesure toute fiche vivante à
            // `MesureDeFiche::LARGEUR_AFFICHEE_VIVANT`, pour tout le monde. Avant, elle
            // était mesurée sur le vrai nom et sur la naissance : une fiche restreinte,
            // dont on n'envoie plus le nom, en aurait encore livré la longueur — et, par
            // l'écart avec sa voisine, `x` aussi.
            'w' => $noeud->w,
            'h' => $noeud->h,
            // ── LE NOM NE SORT QUE SI LE CERCLE LE PERMET ──────────────────────────
            //
            // Révision du 2026-10-01 de l'ADR 0003 : une personne vivante hors cercle
            // n'expose plus son nom. Le masque est posé **ici, à la construction**, et
            // jamais par un remplacement après coup : une charge remplie avec le vrai nom
            // puis corrigée laisse passer le premier champ nominatif ajouté demain.
            'displayName' => $visible ? ($libelle['displayName'] ?? '?') : CercleDeVisibiliteInterface::NOM_MASQUE,
            // `etiquetteNom` est le nom que le canevas dessine. Hors cercle, c'est le même
            // masque : une troncature du vrai nom en dirait autant que le nom.
            'etiquetteNom' => match (true) {
                !$visible => CercleDeVisibiliteInterface::NOM_MASQUE,
                null !== $placable => $this->noms->pour($placable),
                default => $libelle['displayName'] ?? '?',
            },
            'sex' => $placable?->sexe->value ?? 'inconnu',
            'generation' => $placable?->generation,
            'branch' => $libelle['branch'] ?? null,
            'vivant' => $vivante,
            // **Famille, conjoint ou alliance, vu de ce lecteur** (décision du
            // 2026-10-01, {@see ParenteDansLArbre}). Se déduit des arêtes, que tout
            // lecteur reçoit : sur un nœud restreint, il n'apprend rien de plus que
            // `branch`. Sans point de vue calculé, tout le monde est `famille` — rien
            // n'est masqué au hasard.
            'parente' => ($parente['parente'] ?? ParenteDansLArbre::Famille)->value,
        ];

        // Le pont n'existe que sur une famille par alliance : le conjoint « famille » par
        // lequel ce groupe se rattache au lecteur — la clé de son bloc côté canevas.
        // Toujours un identifiant : l'îlot qu'aucun chemin ne relie est `famille`, pas
        // `alliance` (CalculAscendance::parentes()). Absent ailleurs (§ 1.3).
        if (null !== $parente && ParenteDansLArbre::Alliance === $parente['parente']) {
            $charge['pont'] = $parente['pont'];
        }

        if (!$visible) {
            // Les clés du § 11.4, et rien de plus. Quatre champs tombent ici, pour deux
            // raisons distinctes :
            //
            // - `etiquetteDates` est du formatage **de dates** — la laisser reviendrait à
            //   envoyer la naissance sous une autre orthographe. Le canevas ne dessine
            //   alors pas de ligne sous le nom, et il ne la **reconstitue jamais** : la
            //   boîte reste dimensionnée par le moteur ;
            // - `bestQuay`, `citationCount` et `avecImage` sont nommément interdits par le
            //   § 16.2 — le premier et le deuxième explicitement, le troisième parce qu'il
            //   révélerait qu'une personne masquée porte des médias.
            //
            // **Ils sont omis, jamais mis à zéro ni à `null`.** Le client teste la présence
            // de la clé, pas sa valeur : `citationCount: 0` est une donnée, `citationCount`
            // absent est un silence, et les confondre ferait passer « la donnée n'est pas
            // arrivée » pour « aucune fiche ne correspond » (§ 1.3).
            $charge['visibilite'] = NiveauDeVisibilite::Restreinte->value;

            return $charge;
        }

        $charge['visibilite'] = NiveauDeVisibilite::Complete->value;
        // `MesureDeFiche::ligneDates()`, jamais une recomposition depuis les blocs date :
        // c'est la chaîne **sur laquelle `w` a été mesurée**, et la seule qui rende la
        // constante « vivant·e ».
        // La ligne AFFICHÉE — naissance des vivants comprise —, celle sur laquelle
        // l'affinage a remesuré `w`. `ligneDates()` reste ce que le moteur mesure.
        //
        // Réciprocité des vivants (ADR 0016) : la naissance d'un vivant dont le lecteur ne
        // voit pas tout est rendue depuis la date **dégradée** — jamais depuis
        // `PersonnePlacable::$nais`, qui vient de `raw` et porte toutes les composantes. La
        // largeur ne bouge pas : celle d'un vivant est une constante.
        $charge['etiquetteDates'] = match (true) {
            null !== $naissanceReduite => '' !== $naissanceReduite ? 'n. '.$naissanceReduite.' · vivant·e' : 'vivant·e',
            null !== $placable => $this->mesure->ligneDatesAffichee($placable),
            default => '—',
        };
        $charge['bestQuay'] = $libelle['bestQuay'] ?? null;
        $charge['citationCount'] = $libelle['citationCount'] ?? 0;
        $charge['avecImage'] = isset($avecImage[$personneId]);

        // La vignette du portrait actif, pour l'emplacement photo de la fiche. Absente
        // et non `null` quand il n'y en a pas : le canevas dessine alors la silhouette.
        if (isset($vignettes[$personneId])) {
            $charge['portrait'] = $vignettes[$personneId];
        }

        return $charge;
    }

    /**
     * La naissance **réduite** des vivants dont le lecteur ne voit pas toutes les
     * composantes (ADR 0016), sous la forme compacte : « 1985 », « 3/1985 », « 12.3 », ou
     * la chaîne vide. Les vivants vus en entier, et les défunts, n'y figurent pas : leur
     * étiquette reste celle du moteur.
     *
     * Une requête pour tous les vivants concernés, sur les **bornes** — `raw` n'est pas lu.
     *
     * @param array<string, PersonnePlacable> $placables
     *
     * @return array<int, string>
     */
    private function naissancesReduites(array $placables, CercleDuLecteur $cercle): array
    {
        $permises = [];

        foreach ($placables as $id => $placable) {
            $base = IdentifiantDeNoeud::estPersonne($id) ? IdentifiantDeNoeud::identifiantBase($id) : null;

            if (null === $base || !$placable->vivant) {
                continue;
            }

            $partage = $cercle->naissance($base, true);

            if (!$partage->estComplete()) {
                $permises[$base] = $partage;
            }
        }

        if ([] === $permises) {
            return [];
        }

        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT id, birth_qualifier, birth_min, birth_max FROM tree_person WHERE id IN (?)',
            [array_keys($permises)],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        $reduites = array_fill_keys(array_keys($permises), '');

        foreach ($lignes as $ligne) {
            $id = (int) $ligne['id'];
            $date = new UncertainDate(
                null,
                DateQualifier::tryFrom((string) $ligne['birth_qualifier']) ?? DateQualifier::Inconnue,
                null !== $ligne['birth_min'] ? new \DateTimeImmutable((string) $ligne['birth_min']) : null,
                null !== $ligne['birth_max'] ? new \DateTimeImmutable((string) $ligne['birth_max']) : null,
            );

            $reduites[$id] = $this->etiquettes->etiquettePartielle($date, $permises[$id]);
        }

        return $reduites;
    }

    /**
     * @return array<string, mixed>
     */
    private function noeudPropose(NoeudPlace $noeud, NoeudProposePlacable $propose): array
    {
        return [
            'id' => $noeud->id,
            'type' => 'personne',
            // La personne n'a pas d'identifiant d'arbre tant qu'elle n'est pas validée.
            'personId' => null,
            // Émis **séparément** de `id` : le front ne fabrique jamais l'URL de détail
            // en concaténant l'identifiant de nœud, où le `#` serait un séparateur de
            // fragment jamais envoyé au serveur (§ 5.2, divergence 8).
            'demandeId' => $propose->source->demandeId,
            'brouillonRef' => $propose->source->brouillonRef,
            'enAttente' => true,
            'x' => $noeud->x,
            'y' => $noeud->y,
            'w' => $noeud->w,
            'h' => $noeud->h,
            'displayName' => $propose->source->displayName,
            'etiquetteNom' => $this->noms->pour($propose->mesure),
            'sex' => $propose->mesure->sexe->value,
            'generation' => $propose->mesure->generation,
            'branch' => $propose->branche?->value,
            'vivant' => $propose->mesure->vivant,
            // Toujours `famille` : le nœud n'est servi qu'à son auteur et aux
            // administrateurs, et un brouillon masqué par défaut serait une contribution
            // qu'on ne voit pas — « une création qu'on ne voit pas dans l'arbre n'est pas
            // une contribution ».
            'parente' => ParenteDansLArbre::Famille->value,
            // Le nœud n'est servi qu'à son auteur et aux administrateurs : le cercle n'a
            // rien à trancher ici, il porte sur des personnes **validées** (§ 9.3).
            'visibilite' => NiveauDeVisibilite::Complete->value,
            'etiquetteDates' => $this->mesure->ligneDates($propose->mesure),
            // Les quatre champs de filtre ne sont **pas** émis sur un nœud proposé : la
            // personne n'existe pas dans `tree_person`, elle n'a donc ni agrégat de
            // citations ni média. Les omettre plutôt que de les mettre à zéro garde la
            // distinction entre « la donnée dit non » et « la donnée n'existe pas ».
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function familleProvisoire(NoeudPlace $noeud, string $idPublic): array
    {
        return [
            'id' => $idPublic,
            'type' => 'famille',
            'familyId' => null,
            'demandeId' => self::demandeDe($idPublic),
            'enAttente' => true,
            'x' => $noeud->x,
            'y' => $noeud->y,
            'w' => $noeud->w,
            'h' => $noeud->h,
            'divorced' => false,
        ];
    }

    /**
     * @param array<string, string> $renommage
     *
     * @return list<array<string, mixed>>
     */
    private function aretes(Placement $placement, array $renommage): array
    {
        $sortie = [];

        foreach ($placement->aretes as $arete) {
            $de = $renommage[$arete->de] ?? $arete->de;
            $vers = $renommage[$arete->vers] ?? $arete->vers;

            $charge = [
                'de' => $de,
                'vers' => $vers,
                'genre' => $arete->genre,
            ];

            if (AreteDePlacement::GENRE_UNION === $arete->genre) {
                $charge['divorce'] = $arete->divorce;
            }

            // Une arête touchant un identifiant provisoire appartient au calque. Le test
            // est exact et non heuristique : le projecteur n'ajoute d'arête que pour les
            // nœuds en attente, et seul un identifiant provisoire commence par `#`.
            if (str_starts_with($de, '#') || str_starts_with($vers, '#')) {
                $charge['enAttente'] = true;
            }

            // **Aucune arête ne porte de marqueur « longue ».** `.ar.long` est supprimée
            // (§ 7.3 du dossier, ADR 0007) : l'API n'émet ni `long`, ni `distance`, ni
            // `portee`. Les calculer donnerait au front de quoi réintroduire les
            // pointillés que le commanditaire a demandé de retirer.
            $sortie[] = $charge;
        }

        return $sortie;
    }

    /**
     * `display_name` et `branch` des 274 personnes, `divorced` des 103 familles — en
     * deux requêtes scalaires, pas 377 hydratations d'entité.
     *
     * Ce sont les **seules** colonnes que le jeu de placement ne porte pas déjà :
     * `sex`, `generation` et l'état vivant viennent de {@see PersonnePlacable}, ce qui
     * garantit qu'ils ne divergent pas de ce que le moteur a mesuré.
     *
     * @param array<int, Branch> $branchesVues les branches recalculées pour le point de vue
     *                                         du lecteur, vides quand il regarde depuis la
     *                                         racine — la colonne suffit alors
     *
     * @return array{personnes: array<int, array{displayName: string, branch: string|null, bestQuay: int|null, citationCount: int}>, familles: array<int, bool>}
     */
    private function libelles(array $branchesVues = []): array
    {
        $personnes = [];

        $requete = 'SELECT id, display_name, branch, best_quay, citation_count FROM tree_person';

        foreach ($this->connexion->fetchAllAssociative($requete) as $ligne) {
            if (!is_numeric($ligne['id'])) {
                continue;
            }

            // La colonne `branch` est la vue de **la racine** : elle reste juste pour qui
            // n'est rattaché à personne, et `$branchesVues` est alors vide. Pour un lecteur
            // rattaché, la branche a été recalculée depuis lui — « paternelle » doit
            // vouloir dire *son* côté paternel.
            //
            // ⚠ **Quand la vue est calculée, elle seule fait foi — absente veut dire
            // `null`.** Le repli sur la colonne servait jusqu'ici aussi aux personnes que
            // la vue laissait sans branche, et leur rendait le quart de **la racine** :
            // faux sans en avoir l'air. Depuis le 2026-10-01, les familles par alliance
            // sortent exprès sans branche ; le repli leur aurait rendu la branche de la
            // racine — celle-là même qui les rangeait dans la bande d'une tante.
            $branche = [] !== $branchesVues
                ? ($branchesVues[(int) $ligne['id']] ?? null)
                : (\is_string($ligne['branch']) ? Branch::tryFrom($ligne['branch']) : null);

            $personnes[(int) $ligne['id']] = [
                'displayName' => \is_string($ligne['display_name']) ? $ligne['display_name'] : '?',
                'branch' => $branche?->value,
                // Colonnes **dérivées**, recalculées par le service d'écriture de `Tree` :
                // `best_quay` est le `MAX(quay)` des citations, `citation_count` leur
                // nombre. On les recopie, on ne les recalcule pas — un agrégat à la volée
                // sur 274 nœuds à chaque affichage, et deux définitions du « sourcé ».
                'bestQuay' => is_numeric($ligne['best_quay']) ? (int) $ligne['best_quay'] : null,
                'citationCount' => is_numeric($ligne['citation_count']) ? (int) $ligne['citation_count'] : 0,
            ];
        }

        $familles = [];

        foreach ($this->connexion->fetchAllAssociative('SELECT id, divorced FROM tree_family') as $ligne) {
            if (is_numeric($ligne['id'])) {
                $familles[(int) $ligne['id']] = (bool) $ligne['divorced'];
            }
        }

        return ['personnes' => $personnes, 'familles' => $familles];
    }

    /** `#12/f1` → 12. */
    private static function demandeDe(string $idPublic): ?int
    {
        return 1 === preg_match('#^\#(\d+)/#', $idPublic, $trouve) ? (int) $trouve[1] : null;
    }
}
