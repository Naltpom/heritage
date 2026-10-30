<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * CE QU'UNE SUPPRESSION DE FICHE EMPORTE — l'objet qui rend la règle « rien ne part
 * en silence » vérifiable plutôt que promise.
 *
 * ## Le défaut qu'il existe pour supprimer
 *
 * Supprimer une personne n'est pas supprimer une ligne. `tree_person` est référencée par
 * six clés étrangères — `tree_child.person_id`, `tree_citation.person_id`,
 * `tree_fact.person_id`, `tree_note.person_id` en `ON DELETE CASCADE`,
 * `tree_family.husband_id` et `wife_id` en `ON DELETE SET NULL` — et par une vingtaine
 * de colonnes sans contrainte, dans `med_media`, `prp_*`, `mbr_*`, `dmr_*`, `evt_*`
 * et `qst_*` (la carte complète : `heritage:verifier-integrite`). Un `DELETE` écrit à la
 * main dans un client SQL emporte donc les quatre premières **sans que personne ne sache
 * combien**, et laisse les autres pendantes.
 *
 * Cet objet est le décompte de tout cela, mesuré **avant**. Il sert trois fois, et c'est
 * pour cela qu'il est un objet plutôt qu'un tableau :
 *
 * 1. **au dépôt**, il est écrit dans `prp_change_op.old_value` — c'est l'état du monde que
 *    l'auteur avait sous les yeux en demandant ;
 * 2. **à la lecture d'administration**, il est recalculé et rendu en `valeurCourante`,
 *    exactement comme la valeur courante d'un champ modifié. L'administrateur lit donc ce
 *    qui partira, tel que c'est **aujourd'hui**, et non tel que c'était il y a trois jours ;
 * 3. **à la validation**, les deux sont comparés. S'ils diffèrent, la demande est
 *    **caduque** : quelqu'un a ajouté une note, une citation ou une preuve depuis le dépôt,
 *    et la valider effacerait une ligne que personne n'a montrée à celui qui valide.
 *
 * ## L'ordre des clés est un contrat, comme pour un bloc date
 *
 * La comparaison porte sur le tableau **entier**, avec `===`. Or `JSONB` ne préserve pas
 * l'ordre d'écriture — c'est PostgreSQL qui range —, et SQLite range dans l'ordre reçu.
 * Deux inventaires identiques au contenu pourraient donc ne pas l'être au test, et une
 * demande parfaitement valide deviendrait caduque sans que rien ne dise pourquoi.
 *
 * Les deux côtés repassent donc par {@see depuisJson()} puis {@see versJson()}, qui
 * reconstruisent l'ordre. C'est le même détour, pour la même raison, que
 * `NormaliseurDeValeur::normaliserStocke()` fait déjà sur les blocs date.
 *
 * ## Les deux champs qui **bloquent**, et ce ne sont pas des décomptes
 *
 * - **`descendants`** : les enfants rattachés à une union où la personne est un époux.
 *   Les perdre est le cas réel qui a motivé tout ceci — deux fiches du même homme, l'une
 *   portant six générations et l'autre vide. Supprimer la mauvaise est trivial ;
 *   supprimer la bonne détacherait la branche entière, et personne ne s'en apercevrait
 *   avant d'ouvrir l'arbre.
 *
 *   **Deux nombres, et il en faut deux.** `descendants` ne porte que les enfants
 *   **directs**, nommés ; `descendanceTotale` porte la branche entière, toutes générations
 *   confondues. Le premier seul dirait « parent de Cobalt Sureau », ce qui est vrai et ne
 *   pèse rien — un enfant, on l'efface sans y penser. Le second seul dirait « 23 fiches »,
 *   ce qui pèse mais ne dit pas **laquelle des deux fiches du doublon** on tient. Le
 *   message de blocage met donc le total en tête et les noms derrière.
 *
 *   Le total ne sert **qu'au message** : c'est la présence d'un seul enfant direct qui
 *   bloque, et rien d'autre. Un compte transitif qui servirait de garde-fou ferait
 *   dépendre la sûreté d'un parcours de graphe, là où une ligne de `tree_child` suffit.
 * - **`medias`** : aucune clé étrangère ne protège `med_media.subject_id`, et une preuve
 *   photographiée est le travail le plus coûteux d'une contribution. On ne l'efface pas en
 *   passant, et on ne la laisse pas non plus pointer vers une fiche disparue — c'est la
 *   « preuve orpheline » que ce projet a déjà payée une fois.
 *
 * Les deux sont **refusés au dépôt** et non à la validation : un contributeur doit
 * apprendre tout de suite que ce n'est pas cette fiche-là qu'il faut effacer.
 *
 * ## Ce que les autres modules y accrochent : `dependances`
 *
 * Trois modules tiennent un `person_id` **sans clé étrangère** que rien ne consultait —
 * une démarche d'acte, une invitation à un événement, une autorisation de partage de
 * naissance — et un quatrième des questions posées sur la fiche. Chacun mesure désormais les siennes ({@see DependancesDUneFiche}), et
 * {@see DependanceDeFiche} dit, nature par nature, si elle **bloque** ou si elle est
 * **nettoyée** dans la transaction. Elles entrent dans la comparaison comme le reste : une
 * démarche ouverte sur la fiche entre le dépôt et la validation rend la demande caduque.
 */
final readonly class InventaireDeSuppression
{
    /**
     * @param string                                                                      $displayName        le nom de la fiche, recopié pour que l'inventaire se
     *                                                                                                        relise seul dans `prp_revision.changes`
     * @param int                                                                         $notes              `tree_note` rattachées à la personne
     * @param int                                                                         $citations          `tree_citation` rattachées à la personne
     * @param int                                                                         $faits              `tree_fact` rattachés à la personne
     * @param int                                                                         $filiations         `tree_child` où la personne est **l'enfant** — sa propre
     *                                                                                                        filiation, qui disparaît avec elle
     * @param list<array{familleId: int, libelle: string, enfants: int, supprimee: bool}> $unions             les unions où elle est un époux. `supprimee` vaut `true`
     *                                                                                                        quand elle en est le **seul** époux :
     *                                                                                                        `ck_tree_family_au_moins_un_epoux` interdit alors le
     *                                                                                                        `SET NULL`, et la ligne part avec elle
     * @param list<array{personneId: int, displayName: string}>                           $descendants        les **enfants directs** qui seraient détachés, nommés.
     *                                                                                                        **Non vide interdit.**
     * @param int                                                                         $descendanceTotale  le nombre de fiches qui descendent de celle-ci,
     *                                                                                                        **toutes générations confondues**. Voir l'en-tête.
     * @param int                                                                         $medias             `med_media` visant la personne, ou une union ou une citation qui
     *                                                                                                        partirait avec elle. **Non nul interdit.**
     * @param int|null                                                                    $membreId           le rattachement `mbr_member` dont cette fiche est la
     *                                                                                                        racine. **Non nul interdit.**
     * @param int                                                                         $invitationsActives invitations en attente désignant cette fiche.
     *                                                                                                        **Non nul interdit.**
     * @param list<DependanceMesuree>                                                     $dependances        ce que les autres modules y accrochent, dans l'ordre de
     *                                                                                                        {@see DependanceDeFiche} — voir l'en-tête
     * @param list<int>                                                                   $citationsEmportees les `tree_citation` qui partiraient avec la fiche — les siennes,
     *                                                                                                        celles de ses faits et des unions effacées. **Pas dans
     *                                                                                                        `versJson()`** : c'est l'outil qui permet à `Proposal` de
     *                                                                                                        compter les preuves qui les visent, et `medias` porte déjà
     *                                                                                                        ce compte. Un inventaire relu depuis la base n'en a plus
     *                                                                                                        besoin.
     * @param int                                                                         $finsDeRecherche    `tree_research_closure` rattachées à la personne (ADR 0023).
     *                                                                                                        Une fin de recherche part avec la fiche, et **ne bloque pas** la suppression.
     */
    public function __construct(
        public int $personneId,
        public string $displayName,
        public int $notes,
        public int $citations,
        public int $faits,
        public int $filiations,
        public array $unions,
        public array $descendants,
        public int $descendanceTotale = 0,
        public int $medias = 0,
        public ?int $membreId = null,
        public int $invitationsActives = 0,
        public array $dependances = [],
        public array $citationsEmportees = [],
        public int $finsDeRecherche = 0,
    ) {
    }

    /**
     * Les unions qui partent avec la fiche — celles dont `supprimee` vaut `true`.
     *
     * @return list<int>
     */
    public function famillesEmportees(): array
    {
        $familles = [];

        foreach ($this->unions as $union) {
            if ($union['supprimee']) {
                $familles[] = $union['familleId'];
            }
        }

        return $familles;
    }

    /**
     * Le même inventaire, avec le compte de médias que `Proposal` est allé chercher.
     *
     * `PortArbre` ne peut pas le rendre : `med_media` appartient à `Media`, et l'arbre ne
     * la lit pas. C'est donc `Proposal` qui recolle les deux moitiés, par
     * {@see \App\Module\Proposal\Service\Port\PortMedias::compterLesMediasDe()}. Le port de
     * l'arbre rend un inventaire à `medias = 0`, et cette méthode le complète — plutôt
     * qu'un second objet, qui obligerait chaque lecteur à savoir lequel des deux il tient.
     */
    public function avecMedias(int $medias): self
    {
        return new self(
            $this->personneId,
            $this->displayName,
            $this->notes,
            $this->citations,
            $this->faits,
            $this->filiations,
            $this->unions,
            $this->descendants,
            $this->descendanceTotale,
            $medias,
            $this->membreId,
            $this->invitationsActives,
            $this->dependances,
            $this->citationsEmportees,
            $this->finsDeRecherche,
        );
    }

    /**
     * Le même inventaire, avec ce que `Membership` a répondu.
     *
     * Troisième et dernière moitière recollée par `Proposal` : l'arbre ne lit pas
     * `mbr_member`, et pour cause — aucune clé étrangère ne les relie, le § 2.6 du modèle
     * interdisant la jointure qui la poserait. La question passe par
     * `Core\Contract\AnnuaireDesMembresInterface`, que `Membership` implémente.
     */
    public function avecComptes(?int $membreId, int $invitationsActives): self
    {
        return new self(
            $this->personneId,
            $this->displayName,
            $this->notes,
            $this->citations,
            $this->faits,
            $this->filiations,
            $this->unions,
            $this->descendants,
            $this->descendanceTotale,
            $this->medias,
            $membreId,
            $invitationsActives,
            $this->dependances,
            $this->citationsEmportees,
            $this->finsDeRecherche,
        );
    }

    /**
     * Le même inventaire, avec ce que les autres modules ont répondu
     * ({@see DependancesDUneFiche::mesurer()}).
     *
     * Les mesures sont remises dans l'ordre de {@see DependanceDeFiche}, une nature
     * répondue deux fois est additionnée, une nature à zéro est retirée : c'est ce qui rend
     * l'inventaire comparable quel que soit l'ordre dans lequel le conteneur a rangé les
     * modules.
     *
     * @param list<DependanceMesuree> $dependances
     */
    public function avecDependances(array $dependances): self
    {
        return new self(
            $this->personneId,
            $this->displayName,
            $this->notes,
            $this->citations,
            $this->faits,
            $this->filiations,
            $this->unions,
            $this->descendants,
            $this->descendanceTotale,
            $this->medias,
            $this->membreId,
            $this->invitationsActives,
            self::ordonner($dependances),
            $this->citationsEmportees,
            $this->finsDeRecherche,
        );
    }

    /**
     * La suppression est-elle interdite en l'état ?
     *
     * Les deux raisons ne se confondent pas et {@see raisonsDuBlocage()} les nomme
     * séparément : l'une se corrige en supprimant l'**autre** fiche du doublon, l'autre en
     * déplaçant une preuve. Un message unique ferait chercher au mauvais endroit.
     */
    public function estBloquee(): bool
    {
        return [] !== $this->descendants
            || $this->medias > 0
            || null !== $this->membreId
            || $this->invitationsActives > 0
            || [] !== $this->dependancesBloquantes();
    }

    /** @return list<DependanceMesuree> */
    public function dependancesBloquantes(): array
    {
        return array_values(array_filter($this->dependances, static fn (DependanceMesuree $d): bool => $d->nature->bloque()));
    }

    /**
     * Pourquoi elle est bloquée, en français, prêt à poser sur un champ de formulaire.
     *
     * @return list<string>
     */
    public function raisonsDuBlocage(): array
    {
        $raisons = [];

        if ([] !== $this->descendants) {
            // **Le chiffre d'abord, et c'est le total de la branche.** « parent de Cobalt
            // Sureau » est vrai et ne dit rien : c'est « 23 fiches descendent d'elle » qui
            // arrête la main. Les noms suivent, parce qu'eux seuls disent **laquelle des
            // deux fiches du doublon** on s'apprêtait à effacer.
            $raisons[] = \sprintf(
                '%d fiche%s descend%s de celle-ci, toutes générations confondues : la supprimer détacherait %s de l\'arbre. '
                .'Elle est le parent de %s. '
                .'S\'il s\'agit d\'un doublon, c\'est l\'autre fiche — celle qui ne porte pas la descendance — qu\'il faut supprimer.',
                $this->descendanceTotale,
                $this->descendanceTotale > 1 ? 's' : '',
                $this->descendanceTotale > 1 ? 'ent' : '',
                \count($this->descendants) > 1 ? 'ces branches' : 'cette branche',
                $this->nommerLesDescendants(),
            );
        }

        if ($this->medias > 0) {
            $raisons[] = \sprintf(
                '%d preuve%s %s rattachée%s à cette fiche. Une image ne se supprime pas en passant : '
                .'déplacez-la ou supprimez-la depuis la galerie avant de demander la suppression de la fiche.',
                $this->medias,
                $this->medias > 1 ? 's' : '',
                $this->medias > 1 ? 'sont' : 'est',
                $this->medias > 1 ? 's' : '',
            );
        }

        if (null !== $this->membreId) {
            // Le rattachement est **nommé par son identifiant**, pas par l'adresse du
            // compte : ce message part sur une route ouverte à tout membre, et le libellé
            // d'un compte est la huitième porte de sortie de l'ADR 0003. Le numéro suffit
            // à le retrouver dans la file d'administration, qui est le seul endroit où on
            // peut le rattacher ailleurs.
            $raisons[] = \sprintf(
                'Le compte membre n° %d a cette fiche pour racine : la supprimer le laisserait sans point de départ dans l\'arbre — '
                .'son cercle de visibilité et sa parenté s\'en déduisent, et un calcul sur une racine absente ne lève pas, il rend n\'importe quoi. '
                .'Rattachez ce compte à une autre fiche avant de supprimer celle-ci.',
                $this->membreId,
            );
        }

        if ($this->invitationsActives > 0) {
            // Le même défaut, **en différé** : rien ne casse aujourd'hui, un compte sans
            // racine apparaît le jour où quelqu'un accepte. C'est la variante la plus
            // difficile à relier à son geste, et c'est pour cela qu'elle bloque aussi.
            $raisons[] = 'Une invitation en attente désigne cette fiche : elle créerait, à son acceptation, '
                .'un compte sans racine dans l\'arbre. Révoquez-la ou redirigez-la vers une autre fiche d\'abord.';
        }

        // Ce que les autres modules y accrochent, chacun avec son geste — la phrase est
        // celle de l'énumération, pour qu'une nature n'ait qu'une seule façon d'être dite.
        foreach ($this->dependancesBloquantes() as $dependance) {
            $raisons[] = $dependance->nature->raison($dependance->nombre);
        }

        return $raisons;
    }

    /**
     * Ce que la suppression emporte, en une ligne de français.
     *
     * C'est le `libelleDiff` de l'op — « Suppression de la fiche : emporte 3 notes,
     * 2 citations, 1 union ». Il est fabriqué ici et non dans le serialiseur pour que
     * l'écran d'administration, la notification de caducité et `prp_revision` disent tous
     * la même phrase.
     */
    public function enUneLigne(): string
    {
        $morceaux = [];

        foreach ([
            [$this->notes, 'note', 'notes'],
            [$this->citations, 'citation', 'citations'],
            [$this->faits, 'fait', 'faits'],
            [$this->finsDeRecherche, 'fin de recherche', 'fins de recherche'],
            [\count($this->unions), 'union', 'unions'],
            [$this->filiations, 'rattachement à ses parents', 'rattachements à ses parents'],
        ] as [$nombre, $singulier, $pluriel]) {
            if ($nombre > 0) {
                $morceaux[] = \sprintf('%d %s', $nombre, 1 === $nombre ? $singulier : $pluriel);
            }
        }

        // Ce qui part avec elle dans les autres modules. Une nature qui bloque n'y figure
        // pas : elle n'emporte rien, elle empêche — `raisonsDuBlocage()` la dit.
        foreach ($this->dependances as $dependance) {
            if (!$dependance->nature->bloque()) {
                $morceaux[] = $dependance->nature->enMorceau($dependance->nombre);
            }
        }

        if ([] === $morceaux) {
            // Le cas du doublon vide, et c'est le cas qu'on veut voir écrit noir sur blanc :
            // « cette fiche ne porte rien » est précisément ce qui autorise à l'effacer sans
            // hésiter.
            return \sprintf('Suppression de la fiche « %s » : elle ne porte rien d\'autre.', $this->displayName);
        }

        return \sprintf('Suppression de la fiche « %s » : emporte %s.', $this->displayName, $this->joindre($morceaux));
    }

    /**
     * La forme stockée dans `old_value` et rendue en `valeurCourante`.
     *
     * **L'ordre des clés est fixé ici, et nulle part ailleurs** : c'est lui qui rend la
     * comparaison indépendante de la base.
     *
     * @return array<string, mixed>
     */
    public function versJson(): array
    {
        return [
            'personneId' => $this->personneId,
            'displayName' => $this->displayName,
            'notes' => $this->notes,
            'citations' => $this->citations,
            'faits' => $this->faits,
            'filiations' => $this->filiations,
            'unions' => array_map(
                static fn (array $union): array => [
                    'familleId' => $union['familleId'],
                    'libelle' => $union['libelle'],
                    'enfants' => $union['enfants'],
                    'supprimee' => $union['supprimee'],
                ],
                $this->unions,
            ),
            'descendants' => array_map(
                static fn (array $enfant): array => [
                    'personneId' => $enfant['personneId'],
                    'displayName' => $enfant['displayName'],
                ],
                $this->descendants,
            ),
            'descendanceTotale' => $this->descendanceTotale,
            'medias' => $this->medias,
            'finsDeRecherche' => $this->finsDeRecherche,
            'membreId' => $this->membreId,
            'invitationsActives' => $this->invitationsActives,
            // `bloquante` est recopiée de l'énumération : elle sert le front, qui l'affiche
            // sans redériver la règle.
            'dependances' => array_map(
                static fn (DependanceMesuree $d): array => [
                    'nature' => $d->nature->value,
                    'nombre' => $d->nombre,
                    'bloquante' => $d->nature->bloque(),
                ],
                $this->dependances,
            ),
        ];
    }

    /**
     * L'inventaire relu depuis `JSONB`, **tolérant** — comme
     * `NormaliseurDeValeur::reconstruire()` l'est pour un bloc date, et pour la même
     * raison : une valeur en base a déjà été validée à l'entrée, et lever ici
     * transformerait un affichage en panne.
     *
     * Une clé absente vaut zéro. C'est sans danger dans le sens qui compte : un inventaire
     * relu **plus pauvre** que le courant déclenche une caducité, donc un refus d'écrire.
     *
     * @param array<string, mixed> $json
     */
    public static function depuisJson(array $json): self
    {
        return new self(
            self::entier($json['personneId'] ?? null),
            \is_string($json['displayName'] ?? null) ? $json['displayName'] : '',
            self::entier($json['notes'] ?? null),
            self::entier($json['citations'] ?? null),
            self::entier($json['faits'] ?? null),
            self::entier($json['filiations'] ?? null),
            self::unions($json['unions'] ?? null),
            self::descendants($json['descendants'] ?? null),
            self::entier($json['descendanceTotale'] ?? null),
            self::entier($json['medias'] ?? null),
            \is_numeric($json['membreId'] ?? null) ? (int) $json['membreId'] : null,
            self::entier($json['invitationsActives'] ?? null),
            // Absente d'un inventaire déposé avant elle : aucune dépendance. C'est le sens
            // sûr — une dépendance apparue depuis fera diverger la comparaison.
            self::ordonner(self::dependances($json['dependances'] ?? null)),
            [],
            self::entier($json['finsDeRecherche'] ?? null),
        );
    }

    /**
     * Une nature inconnue — retirée de l'énumération depuis le dépôt — est ignorée : elle
     * ne peut plus rien bloquer, et la relire ferait lever un affichage.
     *
     * @return list<DependanceMesuree>
     */
    private static function dependances(mixed $brut): array
    {
        $dependances = [];

        foreach (\is_array($brut) ? $brut : [] as $ligne) {
            $nature = \is_array($ligne) && \is_string($ligne['nature'] ?? null) ? DependanceDeFiche::tryFrom($ligne['nature']) : null;

            if (null !== $nature) {
                $dependances[] = new DependanceMesuree($nature, self::entier($ligne['nombre'] ?? null));
            }
        }

        return $dependances;
    }

    /**
     * L'ordre canonique : celui des cas de {@see DependanceDeFiche}, une ligne par nature,
     * aucune à zéro.
     *
     * @param list<DependanceMesuree> $dependances
     *
     * @return list<DependanceMesuree>
     */
    private static function ordonner(array $dependances): array
    {
        $parNature = [];

        foreach ($dependances as $dependance) {
            $parNature[$dependance->nature->value] = ($parNature[$dependance->nature->value] ?? 0) + $dependance->nombre;
        }

        $ordonnees = [];

        foreach (DependanceDeFiche::cases() as $nature) {
            if (($parNature[$nature->value] ?? 0) > 0) {
                $ordonnees[] = new DependanceMesuree($nature, $parNature[$nature->value]);
            }
        }

        return $ordonnees;
    }

    /** @return list<array{familleId: int, libelle: string, enfants: int, supprimee: bool}> */
    private static function unions(mixed $brut): array
    {
        $unions = [];

        foreach (\is_array($brut) ? $brut : [] as $union) {
            if (!\is_array($union)) {
                continue;
            }

            $unions[] = [
                'familleId' => self::entier($union['familleId'] ?? null),
                'libelle' => \is_string($union['libelle'] ?? null) ? $union['libelle'] : '',
                'enfants' => self::entier($union['enfants'] ?? null),
                'supprimee' => (bool) ($union['supprimee'] ?? false),
            ];
        }

        return $unions;
    }

    /** @return list<array{personneId: int, displayName: string}> */
    private static function descendants(mixed $brut): array
    {
        $enfants = [];

        foreach (\is_array($brut) ? $brut : [] as $enfant) {
            if (!\is_array($enfant)) {
                continue;
            }

            $enfants[] = [
                'personneId' => self::entier($enfant['personneId'] ?? null),
                'displayName' => \is_string($enfant['displayName'] ?? null) ? $enfant['displayName'] : '',
            ];
        }

        return $enfants;
    }

    private static function entier(mixed $valeur): int
    {
        return \is_int($valeur) ? $valeur : (\is_numeric($valeur) ? (int) $valeur : 0);
    }

    private function nommerLesDescendants(): string
    {
        $noms = array_map(
            static fn (array $enfant): string => $enfant['displayName'],
            \array_slice($this->descendants, 0, 3),
        );

        $reste = \count($this->descendants) - \count($noms);

        if ($reste > 0) {
            $noms[] = \sprintf('%d autre%s', $reste, $reste > 1 ? 's' : '');
        }

        return $this->joindre($noms);
    }

    /** @param list<string> $morceaux */
    private function joindre(array $morceaux): string
    {
        if (\count($morceaux) < 2) {
            return implode('', $morceaux);
        }

        $dernier = array_pop($morceaux);

        return implode(', ', $morceaux).' et '.$dernier;
    }
}
