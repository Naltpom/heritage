<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Membership\Service\Port\PortArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * **L'unique implémentation** de {@see CercleDeVisibiliteInterface} — la règle de
 * confidentialité du produit, au point où `Tree`, `Media`, `Timeline` et `Proposal` la
 * consultent.
 *
 * ## Les trois règles du contrat, et où elles sont écrites ici
 *
 * 1. **Une personne décédée est un fait historique**, visible de tout compte connecté :
 *    {@see self::voitPersonne()} rend `true` avant même de regarder le cercle. 256 fiches
 *    sur 274 ne franchissent jamais le reste de ce fichier.
 * 2. **Une personne vivante est visible de tout membre validé — sauf fiche
 *    confidentielle —, et jamais en régime `Aucun`** — y compris celle à laquelle ce
 *    lecteur prétend se rattacher. `mbr_invitation.person_id` et `mbr_member.person_id`
 *    ne sont **pas lus** tant que le membre n'est pas actif : c'est
 *    {@see FabriqueDuLecteurDuCercle} qui ferme, en amont, et ce fichier n'a aucun moyen
 *    de rouvrir. *Confirmer une date de naissance n'est pas la lire.* Une fiche à
 *    confidentialité **forcée** (`force_privacy`) reste, elle, réservée au cercle du
 *    lecteur — l'administrateur compris, par son cercle complet. Voir plus bas.
 * 3. **Un décès inconnu vaut vivant, donc masqué.** La question est posée à la vue de
 *    l'arbre, dont `estVivante()` rend `true` pour un identifiant qu'elle ne connaît pas.
 *
 * Et la quatrième, sur la forme des refus : ces méthodes ne distinguent pas « n'existe
 * pas » de « pas le droit ». L'appelant rend `404` dans les deux cas (§ 15.3).
 *
 * ## Révision du 2026-10-01 : le cercle ne garde plus que les fiches confidentielles
 *
 * Décision du commanditaire : *les membres sont de la famille*. Tout lecteur en régime
 * `Cercle` — un membre validé, l'administrateur, et l'administrateur sous la bascule —
 * voit désormais **toutes** les personnes vivantes, nom, dates, lieux, médias compris.
 * Une seule exception : la fiche dont l'administration a forcé la confidentialité
 * ({@see VueDeLArbre::estConfidentielle()}), qui reste servie réduite, sous
 * {@see CercleDeVisibiliteInterface::NOM_MASQUE}, à quiconque n'a pas la personne dans son
 * cercle **généalogique** — exactement comme avant la révision, puisque `force_privacy`
 * faisait déjà de la fiche une fiche « vivante » soumise au cercle (§ 9.2).
 *
 * Le changement tient en **une ligne** de {@see self::voitPersonne()} et de
 * {@see self::filtrerPersonnes()} — et non dans les douze appelants : tous posent leur
 * question ici, la réponse change ici. Ce qui n'a **pas** bougé, et c'est voulu :
 *
 * - la **formule** `C(u)`, son calcul et son cache. La valeur mise en cache est toujours
 *   le cercle généalogique, pas « tout sauf les confidentielles » : son sens n'a pas
 *   changé, les entrées écrites avant le déploiement restent donc justes et il n'y avait
 *   aucune version de clé à monter. Le drapeau est lu dans la vue de l'arbre, rechargée à
 *   chaque requête — et le poser change de toute façon l'empreinte, qui couvre toutes
 *   les colonnes de `tree_person` ;
 * - le régime `Aucun` : rien de vivant, et l'ordre du test l'assure — on ne regarde le
 *   drapeau qu'en régime `Cercle` ;
 * - l'absence de mode « sans filtre » : ce n'est pas un `if ($estMembre) return true`, la
 *   fiche confidentielle continue de passer par le cercle.
 *
 * ## L'administrateur passe par un cercle complet
 *
 * Corollaire non négociable de l'ADR 0003 : **il n'y a pas de mode « sans filtre »**. Un
 * administrateur obtient l'ensemble de toutes les personnes de l'arbre, calculé et mis en
 * cache comme n'importe quel autre cercle. La branche `if ($estAdmin) { return true; }`
 * n'existe pas ici, et c'est délibéré : c'est « exactement le bogue qu'on découvrira dans
 * deux ans, le jour où un rôle sera attribué par erreur ».
 *
 * ## Le cache, et pourquoi il n'y a rien à invalider
 *
 * ```
 * clé    : cercle.{empreinte}.{memberId}.{ancre}.{N}     (§ 9.3 du modèle, + l'ancre)
 * valeur : liste d'entiers — au plus 60 sur les données réelles
 * ttl    : 86400 s
 * ```
 *
 * **Un cercle vide n'est jamais mis en cache**, et `{ancre}` figure dans la clé. Ce sont
 * deux **précautions** contre des modes de défaillance identifiés — non la réparation
 * d'incidents datés : voir {@see self::identifiantsEnCache()} et {@see self::cleDeCache()},
 * où ce qui a été mesuré et ce qui ne l'a pas été est distingué.
 *
 * Le plus utile des deux est **l'avertissement au journal** quand l'ancre est absente de
 * la vue. Un `mbr_member.person_id` pendant est une panne, pas une situation normale, et
 * sans cette ligne il se lit exactement comme « ce membre n'a pas de parenté vivante ».
 * Distinguer « rien à montrer » de « je n'ai pas pu regarder » aurait tranché en trente
 * secondes une question qui a coûté deux heures.
 *
 * `{empreinte}` est celle du § 2.3, **recalculée depuis la base**, et **jamais un compteur
 * incrémenté à la main** : dès qu'une validation touche `tree_person`, `tree_family` ou
 * `tree_child`, l'empreinte change et toutes les entrées existantes deviennent
 * inatteignables d'un coup — sans balayage, sans énumération de clés, sans rien à « penser
 * à faire ». Le TTL ramasse les orphelines. *Un compteur qu'on oublie dans un des cinq
 * chemins d'écriture ne produit pas un ralentissement : il produit un cercle périmé, donc
 * une fuite.*
 *
 * `{N}` figure dans la clé **en plus** de l'empreinte parce qu'un changement de
 * `cercle.generations` ne touche pas l'arbre, donc ne change pas l'empreinte : c'est ce
 * segment-là qui rend les anciennes entrées inatteignables. C'est ce qui permet à
 * l'administrateur de changer `N` et de voir l'effet **à la requête suivante, sans
 * redéploiement** — l'exigence explicite du § 6.2 du dossier.
 *
 * ## La mémorisation par requête est séparée du cache
 *
 * Le graphe pose la question pour 274 personnes. Sans mémoire de requête, ce sont 274
 * lectures de pool ; avec, une seule. Elle est indexée par clé de cache et non par
 * lecteur : sous la bascule, un même processus peut légitimement résoudre deux cercles.
 */
final class CercleDeVisibilite implements CercleDeVisibiliteInterface
{
    /** 24 h, § 9.3 du modèle. Il ne ramasse que les entrées déjà inatteignables. */
    private const int TTL = 86400;

    /** @var array<string, array<int, true>> les cercles déjà résolus dans cette requête */
    private array $memoire = [];

    private ?LecteurDuCercle $lecteur = null;

    /**
     * L'arbre, chargé **au plus une fois par requête**. Le port peut mémoriser de son
     * côté ; on ne le suppose pas, parce que `voitPersonne()` est posée 274 fois par
     * graphe et qu'une lecture de base par nœud ne se voit pas en test.
     */
    private ?VueDeLArbre $vue = null;

    public function __construct(
        private readonly SourceDuLecteur $lecteurs,
        private readonly PortArbre $arbre,
        private readonly CalculDuCercle $calcul,
        private readonly ReglagesDeMembership $reglages,
        // `cache.app` et non un pool dédié `cache.cercle` : les pools se déclarent sous
        // `framework.cache.pools`, dans `config/packages/cache.yaml`, hors du périmètre de
        // ce module. Le préfixe de clé suffit à la lisibilité, et le comportement est le
        // même — système de fichiers, pas de Redis (§ 3 du dossier). Signalé au livrable.
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        // Un cercle vide est une panne, pas une situation normale : il faut qu'elle se
        // lise dans les journaux au lieu de passer pour « ce membre n'a pas de parenté ».
        private readonly LoggerInterface $journal = new NullLogger(),
    ) {
    }

    public function regime(): Regime
    {
        return $this->lecteur()->regime;
    }

    public function voitPersonne(int $personId): bool
    {
        // **Règle 1 du contrat, et elle passe avant tout le reste.** Une personne décédée
        // est visible de tout compte connecté, y compris en régime `Aucun` — qui n'atteint
        // plus l'arbre depuis le 2026-10-01 (`ReserveAuxMembres`), mais la règle reste la
        // sienne. Ce n'est pas une exception, c'est sa conséquence mécanique.
        if (!$this->vue()->estVivante($personId)) {
            return true;
        }

        // **Révision du 2026-10-01** : un vivant est visible de tout membre validé, sauf
        // fiche confidentielle. Voir l'en-tête.
        if ($this->membreVoitLesVivants() && !$this->vue()->estConfidentielle($personId)) {
            return true;
        }

        return isset($this->personnesDuCercle()[$personId]);
    }

    public function filtrerPersonnes(array $personIds): array
    {
        $vue = $this->vue();
        $membre = $this->membreVoitLesVivants();
        $cercle = $this->personnesDuCercle();

        $visibles = [];

        foreach ($personIds as $id) {
            if (isset($visibles[$id])) {
                continue;
            }

            if (!$vue->estVivante($id)
                || ($membre && !$vue->estConfidentielle($id))
                || isset($cercle[$id])) {
                $visibles[$id] = true;
            }
        }

        return array_keys($visibles);
    }

    /**
     * Le lecteur relève-t-il du régime où les vivants **non confidentiels** sont ouverts ?
     *
     * Le régime `Cercle`, et lui seul : un membre actif rattaché, l'administrateur, et
     * l'administrateur sous la bascule quand le membre emprunté est actif. Le régime `Aucun`
     * — compte en attente, compte actif sans rattachement valide, bascule sur un compte
     * inactif — n'y entre pas : le défaut fermé reste fermé.
     */
    private function membreVoitLesVivants(): bool
    {
        return Regime::Cercle === $this->regime();
    }

    /**
     * La taille annoncée dans la charge de session (§ 2.2 du contrat) — celle du cercle
     * **généalogique** `C(u)`, qui ne décide plus, depuis le 2026-10-01, que des fiches
     * confidentielles ; elle n'est pas le nombre de vivants que le lecteur voit. **Absente** du
     * régime `Aucun`, d'où le `null` plutôt que `0` : « le front branche sur `regime`,
     * jamais sur `taille === 0` », et il ne peut le faire que si lire `taille` rend
     * `undefined`.
     */
    public function taille(): ?int
    {
        return Regime::Cercle === $this->regime() ? \count($this->personnesDuCercle()) : null;
    }

    /** Le lecteur effectif, pour la charge de session et la bascule (§ 10.4). */
    public function lecteur(): LecteurDuCercle
    {
        return $this->lecteur ??= $this->lecteurs->courant();
    }

    /**
     * L'ensemble du cercle, indexé par identifiant : la question posée est « appartient-il
     * au cercle ? », 274 fois par graphe — `isset()` plutôt que `in_array()`.
     *
     * @return array<int, true>
     */
    private function personnesDuCercle(): array
    {
        $lecteur = $this->lecteur();

        if (Regime::Cercle !== $lecteur->regime) {
            return [];
        }

        $cle = $this->cleDeCache($lecteur);

        if (isset($this->memoire[$cle])) {
            return $this->memoire[$cle];
        }

        return $this->memoire[$cle] = $this->ensemble($this->identifiantsEnCache($cle, $lecteur));
    }

    /**
     * @return list<int>
     */
    private function identifiantsEnCache(string $cle, LecteurDuCercle $lecteur): array
    {
        $entree = $this->cache->getItem($cle);

        if ($entree->isHit()) {
            $valeur = $entree->get();

            if (\is_array($valeur)) {
                /* @var list<int> $valeur */
                return $valeur;
            }
        }

        $identifiants = $this->calculer($lecteur);

        // ─────────────────────────────────────────────────────────────────────────────
        //  ON NE MET JAMAIS UN CERCLE VIDE EN CACHE.
        //
        //  **La valeur d'échec et la valeur légitime sont la même : `[]`.**
        //  `CalculDuCercle::cercle()` rend `[]` dès que l'ancre est absente de la vue —
        //  vue non chargée, port non branché, `mbr_member.person_id` pendant. Mémoriser
        //  ce `[]` mémorise la panne, et pour 24 heures.
        //
        //  **C'est une précaution contre un mode de défaillance identifié, pas la
        //  réparation d'un incident daté** — et la nuance est écrite ici exprès, parce
        //  qu'un commentaire qui invente une histoire égare plus qu'il n'aide.
        //
        //  L'hypothèse d'un cercle vide mémorisé pendant la fenêtre où le conteneur
        //  servait un cache compilé périmé a bien été formulée pour expliquer un
        //  `cercle.taille: 0` observé. **La mesure ne l'a pas confirmée** : un
        //  `cache:pool:clear cache.app` sur la pile n'a rien changé, le cercle valait 49
        //  avant comme après. La cause réelle était ailleurs — un `TRUNCATE` avait laissé
        //  les `mbr_member.person_id` désigner des lignes disparues.
        //
        //  Le mécanisme reste néanmoins réel : rien n'empêche aujourd'hui qu'un `[]`
        //  calculé pendant une indisponibilité de la vue soit mémorisé 24 h sous une clé
        //  que ni l'activation du membre ni la réparation du câblage ne changent —
        //  l'empreinte ne bouge qu'avec `tree_person`, `tree_family`, `tree_child`.
        //
        //  Le coût de cette garde est nul : le seul cas non mémorisé est celui où il n'y
        //  a rien à mémoriser. Et un cercle réellement vide ne se produit pas — un membre
        //  rattaché est au moins dans son propre cercle.
        // ─────────────────────────────────────────────────────────────────────────────
        if ([] === $identifiants) {
            $this->journal->warning(
                'Cercle vide : l’ancre du lecteur est introuvable dans l’arbre. Résultat non mis en cache.',
                ['memberId' => $lecteur->memberId, 'personneAncre' => $lecteur->personneAncre],
            );

            return $identifiants;
        }

        $entree->set($identifiants)->expiresAfter(self::TTL);
        $this->cache->save($entree);

        return $identifiants;
    }

    /** @return list<int> */
    private function calculer(LecteurDuCercle $lecteur): array
    {
        $vue = $this->vue();

        if ($lecteur->cercleComplet) {
            // « L'administrateur voit tout, via un cercle complet » — un ensemble, pas un
            // court-circuit. Il se journalise, se teste et se remplace par la bascule
            // exactement comme les autres.
            $identifiants = array_keys($vue->personnes);
            sort($identifiants);

            return $identifiants;
        }

        if (null === $lecteur->personneAncre) {
            return [];
        }

        return $this->calcul->cercle($vue, $lecteur->personneAncre, $this->reglages->generationsDuCercle());
    }

    /**
     * `cercle.{empreinte}.{memberId}.{N}` — § 9.3 du modèle.
     *
     * Deux écarts au gabarit, tous deux nécessaires :
     *
     * - le cercle **complet** d'un administrateur ne dépend pas de `N` et n'a pas d'ancre.
     *   Il porte donc `complet` aux deux places, ce qui le rend partageable entre
     *   administrateurs — ils ont tous le même — et impossible à confondre avec le cercle
     *   d'un membre, `memberId` étant toujours un entier ;
     * - un lecteur sans `mbr_member` (compte d'amorçage) porte `sans-membre`. Le segment
     *   reste non vide : deux segments consécutifs vides produiraient `cercle.abc..3`, que
     *   la prochaine relecture du gabarit lirait de travers.
     */
    private function cleDeCache(LecteurDuCercle $lecteur): string
    {
        if ($lecteur->cercleComplet) {
            return \sprintf('cercle.%s.complet.complet', $this->arbre->empreinte());
        }

        // `personneAncre` entre dans la clé **en plus** du membre. Les deux ne varient pas
        // ensemble : le rattachement d'un membre peut être recalé sur une autre personne
        // — c'est arrivé après un `TRUNCATE` qui avait laissé les `person_id` pointer dans
        // le vide — sans que ni l'empreinte de l'arbre ni l'identifiant du membre ne
        // changent. Sans ce segment, le cercle d'avant le recalage serait servi comme s'il
        // était à jour.
        return \sprintf(
            'cercle.%s.%s.%s.%d',
            $this->arbre->empreinte(),
            null !== $lecteur->memberId ? (string) $lecteur->memberId : 'sans-membre',
            null !== $lecteur->personneAncre ? (string) $lecteur->personneAncre : 'sans-ancre',
            $this->reglages->generationsDuCercle(),
        );
    }

    private function vue(): VueDeLArbre
    {
        return $this->vue ??= $this->arbre->vue();
    }

    /**
     * @param list<int> $identifiants
     *
     * @return array<int, true>
     */
    private function ensemble(array $identifiants): array
    {
        $ensemble = [];

        foreach ($identifiants as $id) {
            $ensemble[$id] = true;
        }

        return $ensemble;
    }
}
