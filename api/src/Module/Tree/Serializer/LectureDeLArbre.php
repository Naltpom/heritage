<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\ResearchClosure;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Repository\CitationRepository;
use App\Module\Tree\Repository\FactRepository;
use App\Module\Tree\Repository\FamilyRepository;
use App\Module\Tree\Repository\NoteRepository;
use App\Module\Tree\Repository\PersonRepository;
use App\Module\Tree\Repository\ResearchClosureRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les lectures Doctrine qui alimentent les normaliseurs.
 *
 * Elle est séparée d'eux pour une raison précise : **un normaliseur qui interroge la
 * base ne se teste pas**. Ici vivent les requêtes ; là-bas, les décisions de
 * confidentialité. La conséquence pratique est que le test le plus important du module —
 * « une personne vivante hors cercle n'expose que sept clés » — s'écrit sans base, sans
 * conteneur et sans pare-feu.
 *
 * **Aucun filtre de confidentialité ici, et ce n'est pas un oubli.** Le cercle masque des
 * champs, il ne retire pas de lignes : c'est le normaliseur qui décide, champ par champ,
 * ce qu'il ajoute (§ 1.3). La **seule** exception est la recherche, qui doit retirer des
 * lignes entières — et elle le fait dans {@see NormaliseurDeRecherche}, après filtrage,
 * pas dans le `WHERE` : le SQL ne connaît pas le cercle, et un `LIMIT` posé avant le
 * filtre rendrait moins de résultats que le plafond sans que rien ne soit tronqué,
 * c'est-à-dire un compte qui trahit ce qu'on cache.
 */
final readonly class LectureDeLArbre
{
    /**
     * Le facteur de sur-échantillonnage de la recherche : on demande davantage de lignes
     * que le plafond, parce qu'une partie sera retirée par le cercle. Quatre fois suffit
     * largement — 18 personnes sur 274 sont vivantes.
     */
    private const int SUR_ECHANTILLON = 4;

    public function __construct(
        private EntityManagerInterface $em,
        private PersonRepository $personnes,
        private FamilyRepository $familles,
        private FactRepository $faits,
        private CitationRepository $citations,
        private NoteRepository $notes,
        private ResearchClosureRepository $finsDeRecherche,
    ) {
    }

    public function personne(int $id): ?Person
    {
        return $this->personnes->find($id);
    }

    public function famille(int $id): ?Family
    {
        return $this->familles->find($id);
    }

    /**
     * Les lignes `tree_child` qui font de cette personne **un enfant** — donc ses
     * familles d'origine.
     *
     * Une personne peut en avoir plusieurs : adoption, reconnaissance, et les implexes
     * de consanguinité que cet arbre contient réellement.
     *
     * @return list<Child>
     */
    public function filiationsDe(Person $personne): array
    {
        /** @var list<Child> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT c, f, h, w FROM '.Child::class.' c
             JOIN c.family f
             LEFT JOIN f.husband h
             LEFT JOIN f.wife w
             WHERE c.person = :personne
             ORDER BY c.id ASC',
        )->setParameter('personne', $personne)->getResult();

        return $lignes;
    }

    /**
     * La fratrie : les autres enfants des familles d'origine, dans l'ordre des lignes
     * `CHIL` du GEDCOM — **pas** dans l'ordre des naissances, 50 personnes n'ayant
     * aucune date.
     *
     * @param list<Family> $famillesDOrigine
     *
     * @return list<Child>
     */
    public function fratrieDe(Person $personne, array $famillesDOrigine): array
    {
        if ([] === $famillesDOrigine) {
            return [];
        }

        /** @var list<Child> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT c, p FROM '.Child::class.' c
             JOIN c.person p
             WHERE c.family IN (:familles) AND c.person <> :personne
             ORDER BY c.siblingOrder ASC, c.id ASC',
        )
            ->setParameter('familles', $famillesDOrigine)
            ->setParameter('personne', $personne)
            ->getResult();

        return $lignes;
    }

    /**
     * Les unions de cette personne, époux et épouse confondus.
     *
     * Le tri est sur `rank` puis `id` : `rank` porte l'ordre des unions successives,
     * qu'un remariage rend significatif (4 cas dans les données réelles).
     *
     * @return list<Family>
     */
    public function unionsDe(Person $personne): array
    {
        /** @var list<Family> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT f, h, w FROM '.Family::class.' f
             LEFT JOIN f.husband h
             LEFT JOIN f.wife w
             WHERE f.husband = :personne OR f.wife = :personne
             ORDER BY f.rank ASC, f.id ASC',
        )->setParameter('personne', $personne)->getResult();

        return $lignes;
    }

    /**
     * Les enfants de familles données, indexés par identifiant de famille.
     *
     * Une requête pour toutes les unions plutôt qu'une par union : c'est le même index
     * `idx_tree_child_fratrie`, et une fiche de patriarche en compte huit.
     *
     * @param list<Family> $famillesVisees
     *
     * @return array<int, list<Child>>
     */
    public function enfantsDe(array $famillesVisees): array
    {
        if ([] === $famillesVisees) {
            return [];
        }

        /** @var list<Child> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT c, p, f FROM '.Child::class.' c
             JOIN c.person p
             JOIN c.family f
             WHERE c.family IN (:familles)
             ORDER BY c.siblingOrder ASC, c.id ASC',
        )->setParameter('familles', $famillesVisees)->getResult();

        $parFamille = [];

        foreach ($lignes as $ligne) {
            $familleId = $ligne->getFamily()->getId();

            if (null !== $familleId) {
                $parFamille[$familleId][] = $ligne;
            }
        }

        return $parFamille;
    }

    /**
     * Les filiations de **plusieurs** personnes d'un coup, familles et époux joints.
     *
     * L'ascendance d'une affiche se remonte génération par génération : une requête par
     * génération (une douzaine sur l'arbre réel) plutôt qu'une par ancêtre (plusieurs
     * centaines). Ordre des lignes : celui des identifiants, que l'appelant ne lit pas —
     * c'est lui qui choisit la filiation retenue, sur une règle qui ne dépend pas de
     * l'ordre ({@see NormaliseurDAffiche}).
     *
     * @param list<Person> $personnes
     *
     * @return list<Child>
     */
    public function filiationsDesPersonnes(array $personnes): array
    {
        if ([] === $personnes) {
            return [];
        }

        /** @var list<Child> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT c, f, h, w FROM '.Child::class.' c
             JOIN c.family f
             LEFT JOIN f.husband h
             LEFT JOIN f.wife w
             WHERE c.person IN (:personnes)
             ORDER BY c.id ASC',
        )->setParameter('personnes', $personnes)->getResult();

        return $lignes;
    }

    /**
     * Les unions de **plusieurs** personnes d'un coup — le pendant de
     * {@see self::unionsDe()} pour une génération entière de descendants.
     *
     * @param list<Person> $personnes
     *
     * @return list<Family>
     */
    public function unionsDesPersonnes(array $personnes): array
    {
        if ([] === $personnes) {
            return [];
        }

        /** @var list<Family> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT f, h, w FROM '.Family::class.' f
             LEFT JOIN f.husband h
             LEFT JOIN f.wife w
             WHERE f.husband IN (:personnes) OR f.wife IN (:personnes)
             ORDER BY f.id ASC',
        )->setParameter('personnes', $personnes)->getResult();

        return $lignes;
    }

    /** @return list<Fact> */
    public function faitsDe(Person $personne): array
    {
        /** @var list<Fact> $lignes */
        $lignes = $this->faits->createQueryBuilder('f')
            ->where('f.person = :personne')->setParameter('personne', $personne)
            ->orderBy('f.sortOrder', 'ASC')->addOrderBy('f.id', 'ASC')
            ->getQuery()->getResult();

        return $lignes;
    }

    /**
     * Les citations d'une personne **et de ses faits** : une citation rattachée à un
     * `tree_fact` étaye bien la fiche, et la perdre ferait passer pour non sourcée une
     * profession dont on a l'acte.
     *
     * @return list<Citation>
     */
    public function citationsDePersonne(Person $personne): array
    {
        /** @var list<Citation> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT c, s FROM '.Citation::class.' c
             JOIN c.source s
             LEFT JOIN c.fact ft
             WHERE c.person = :personne OR ft.person = :personne
             ORDER BY c.id ASC',
        )->setParameter('personne', $personne)->getResult();

        return $lignes;
    }

    /** @return list<Citation> */
    public function citationsDeFamille(Family $famille): array
    {
        /** @var list<Citation> $lignes */
        $lignes = $this->citations->createQueryBuilder('c')
            ->addSelect('s')->join('c.source', 's')
            ->where('c.family = :famille')->setParameter('famille', $famille)
            ->orderBy('c.id', 'ASC')
            ->getQuery()->getResult();

        return $lignes;
    }

    /**
     * Les fins de recherche d'une personne, par côté (ADR 0023) — servies avec la fiche
     * complète, comme ses notes.
     *
     * @return array{pere?: ResearchClosure, mere?: ResearchClosure}
     */
    public function finsDeRechercheDe(Person $personne): array
    {
        $parCote = [];

        foreach ($this->finsDeRecherche->dePersonne($personne) as $ligne) {
            $parCote[$ligne->getSide()->value] = $ligne;
        }

        return $parCote;
    }

    /**
     * Les fins de recherche de plusieurs personnes, en **une** requête — l'affiche.
     *
     * @param list<int> $personneIds
     *
     * @return array<int, array{pere?: ResearchClosure, mere?: ResearchClosure}>
     */
    public function finsDeRechercheDes(array $personneIds): array
    {
        return $this->finsDeRecherche->desPersonnes($personneIds);
    }

    /** @return list<Note> */
    public function notesDePersonne(Person $personne): array
    {
        /** @var list<Note> $lignes */
        $lignes = $this->notes->createQueryBuilder('n')
            ->where('n.person = :personne')->setParameter('personne', $personne)
            ->orderBy('n.id', 'ASC')
            ->getQuery()->getResult();

        return $lignes;
    }

    /** @return list<Note> */
    public function notesDeFamille(Family $famille): array
    {
        /** @var list<Note> $lignes */
        $lignes = $this->notes->createQueryBuilder('n')
            ->where('n.family = :famille')->setParameter('famille', $famille)
            ->orderBy('n.id', 'ASC')
            ->getQuery()->getResult();

        return $lignes;
    }

    /**
     * Les candidats de la recherche : **chaque mot du terme** doit apparaître dans
     * `search_key`, en tête ou en début d'un autre mot (§ 0.6 du modèle — aucune
     * extension PostgreSQL), puis affinage en PHP.
     *
     * ── Ce que la forme précédente ne pouvait pas trouver ───────────────────────────
     *
     * Elle cherchait le terme **entier** comme un seul motif :
     * `searchKey LIKE :terme || '%' OR searchKey LIKE '% ' || :terme || '%'`. Or la clé
     * s'écrit « patronyme, prénoms » et un membre tape « Prénom Nom » : aucun des deux
     * motifs ne correspondait. Mesuré sur la base de travail — **0 fiche retrouvée sur
     * les 272** qui portent un prénom et un patronyme, interrogées chacune avec son
     * propre « Prénom Nom ». « Bourrache » trouvait, « Marne » trouvait, « Marne
     * Bourrache » ne trouvait rien.
     *
     * ── La forme retenue, et pourquoi elle n'élargit pas plus qu'il ne faut ─────────
     *
     * Une **conjonction** : `AND` sur les mots, chacun en `(préfixe OR infixe)`.
     *
     * - **Sur un terme d'un seul mot, elle est exactement l'ancienne forme.** Ce n'est
     *   pas une intention, c'est une mesure : 749 termes d'un mot confrontés aux 279
     *   clés de la base donnent **0 divergence** ligne à ligne. Tout ce que les tests de
     *   cercle existants éprouvent (`q=tilleul` → `[]`, `q=sureau` → 1) porte donc sur un
     *   comportement rigoureusement inchangé.
     * - **Chaque mot supplémentaire RESTREINT.** Un `AND` ne peut pas rendre plus de
     *   lignes que le `OR` d'un seul de ses termes. La seule chose que ce correctif rend
     *   possible, c'est l'indifférence à l'**ordre** des mots — précisément le défaut, et
     *   rien de plus. Bruit mesuré sur les 272 termes « Prénom Nom » : **1,5 ligne rendue
     *   en moyenne**, cible comprise.
     * - **Le cercle décide toujours seul.** Cette requête ne connaît ni le lecteur ni la
     *   confidentialité ; elle élargit un **jeu de candidats** que
     *   {@see NormaliseurDeRecherche} filtre ensuite personne par personne. Élargir ici
     *   ne peut donc pas exposer quelqu'un : une personne vivante hors cercle reste
     *   **absente**, qu'elle ait été candidate ou non (ADR 0003).
     *
     * ── L'index, dit sans le flatter ────────────────────────────────────────────────
     *
     * `idx_tree_person_search` est un index de **préfixe** (`varchar_pattern_ops`) : il
     * sert la branche `LIKE 'X%'`, jamais la branche `LIKE '% X%'`. Une disjonction dont
     * un membre est un infixe n'est donc pas indexable, et `EXPLAIN` rend un `Seq Scan`
     * même avec `enable_seqscan = off`. **C'était déjà le cas avant ce changement** — la
     * branche infixe existait — et le coût est le même : à 279 lignes, le planificateur
     * choisit de toute façon le balayage. On ne perd pas un index ici, on constate qu'il
     * n'était plus employé par cette requête depuis que l'infixe y est entré. Il sert
     * toujours `ORDER BY p.searchKey`.
     *
     * ── Le sur-échantillonnage ──────────────────────────────────────────────────────
     *
     * **On demande plus de lignes que le plafond** parce que le cercle en retirera : le
     * `LIMIT` ne peut pas être le plafond de la réponse, sinon le nombre de résultats
     * renseigne sur ce qui a été caché (§ 9.2 du modèle : « tout champ dérivé d'une
     * donnée masquée est lui-même une donnée masquée », et un compte en est un).
     *
     * @param list<string> $mots les mots du terme, déjà repliés par
     *                           {@see NormaliseurDeRequete::mots()} — donc `[A-Z0-9]+`,
     *                           sans `%` ni `_` possibles
     *
     * @return list<Person>
     */
    public function candidatsDeRecherche(array $mots, int $limite, bool $sansDecesEnregistre = false): array
    {
        if ([] === $mots) {
            return [];
        }

        $constructeur = $this->personnes->createQueryBuilder('p')
            ->orderBy('p.searchKey', 'ASC')->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limite * self::SUR_ECHANTILLON);

        // La recherche des vivants seulement (`?vivants=1`) écarte dès le SQL les décès
        // **enregistrés** : sans cela, un patronyme riche en ancêtres remplirait le
        // sur-échantillon de défunts, et les vivants passeraient sous le plafond. Ce n'est
        // qu'un pré-filtre — la règle complète (repli par génération, `force_privacy`)
        // reste appliquée ensuite, personne par personne, par l'appelant.
        // `force_privacy` fait une personne « vivante » même décédée (la seule échappatoire
        // de la règle, dans le sens prudent) : on la garde, la règle tranchera.
        if ($sansDecesEnregistre) {
            $constructeur->andWhere('p.deceased = false OR p.forcePrivacy = true');
        }

        foreach (array_values($mots) as $rang => $mot) {
            // Le préfixe d'abord, parce que c'est ce que servirait l'index ; l'infixe
            // ensuite, parce qu'on cherche aussi bien une personne par son prénom. Les
            // paramètres sont numérotés : Doctrine refuse deux valeurs sur un même nom.
            $constructeur
                ->andWhere($constructeur->expr()->orX(
                    $constructeur->expr()->like('p.searchKey', ':prefixe'.$rang),
                    $constructeur->expr()->like('p.searchKey', ':infixe'.$rang),
                ))
                ->setParameter('prefixe'.$rang, $mot.'%')
                ->setParameter('infixe'.$rang, '% '.$mot.'%');
        }

        /** @var list<Person> $lignes */
        $lignes = $constructeur->getQuery()->getResult();

        return $lignes;
    }

    /**
     * Les candidats de la **ressemblance** (§ 5.5 bis) : toute personne dont la clé porte,
     * en tête ou en début d'un autre mot, **l'un** des préfixes donnés — puis tout le reste
     * se décide en PHP ({@see NormaliseurDeRessemblance}).
     *
     * ── Pourquoi un `OR`, quand la recherche fait un `AND` ──────────────────────────
     *
     * La recherche exige chaque mot parce qu'elle restreint : on sait ce qu'on cherche. La
     * ressemblance, elle, doit **retrouver une faute de frappe** — « Tilleule » pour
     * « Tilleul », « Pyroxenne » pour « Pyroxène ». Exiger chaque mot écarterait justement
     * la fiche que la faute rend différente. On prend donc les **trois premières lettres**
     * de chaque mot saisi (nom, nom d'usage, prénoms, surnom), en `OR` : une faute au-delà
     * de la troisième lettre ne fait plus perdre la fiche, et une faute dans les trois
     * premières lettres du nom est rattrapée par le prénom, qui est de toute façon exigé
     * ensuite.
     *
     * ── Ce qui borne l'élargissement ────────────────────────────────────────────────
     *
     * - **Le filtre en PHP**, qui exige un nom proche **et** un prénom en commun : le
     *   jeu de candidats peut être large, la réponse ne l'est pas.
     * - **Le cercle**, qui décide seul, personne par personne, après cette requête. Elle ne
     *   connaît ni le lecteur ni la confidentialité ; élargir ici ne peut exposer personne
     *   (ADR 0003) — exactement l'argument de {@see self::candidatsDeRecherche()}.
     * - **Le plafond**, `$plafond` lignes au plus, dans l'ordre des identifiants : un ordre
     *   **stable**, qui ne dépend de rien de ce qu'on compare. Il ne mord pas aujourd'hui
     *   (l'arbre compte moins de 300 fiches) ; s'il mordait, une ressemblance pourrait
     *   manquer, mais aucune ne serait faussée et rien ne dirait combien on a coupé.
     *
     * Pas d'extension PostgreSQL (`pg_trgm`, `fuzzystrmatch`) : c'est une décision écrite
     * du modèle (§ 0.6, « Ce que le modèle ne contient délibérément pas »), et elle tient à
     * cette échelle — un balayage de préfixes, puis Levenshtein en PHP sur des clés
     * repliées.
     *
     * @param list<string> $prefixes repliés par {@see \App\Module\Core\Service\CleDeRecherche} —
     *                               donc `[A-Z0-9]+`, sans `%` ni `_` possibles
     *
     * @return list<Person>
     */
    public function candidatsDeRessemblance(array $prefixes, int $plafond): array
    {
        if ([] === $prefixes) {
            return [];
        }

        $constructeur = $this->personnes->createQueryBuilder('p')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($plafond);

        $ou = $constructeur->expr()->orX();

        foreach (array_values($prefixes) as $rang => $prefixe) {
            $ou->add($constructeur->expr()->like('p.searchKey', ':prefixe'.$rang));
            $ou->add($constructeur->expr()->like('p.searchKey', ':infixe'.$rang));
            $constructeur
                ->setParameter('prefixe'.$rang, $prefixe.'%')
                ->setParameter('infixe'.$rang, '% '.$prefixe.'%');
        }

        /** @var list<Person> $lignes */
        $lignes = $constructeur->andWhere($ou)->getQuery()->getResult();

        return $lignes;
    }

    /**
     * Toutes les fiches de l'arbre, pour le tableau (§ 5.5 ter) — triées par la clé de
     * recherche, c'est-à-dire « patronyme, prénoms », l'ordre d'un registre.
     *
     * `EtatVivant` a déjà chargé la même population pour ses médianes : dans la même
     * requête HTTP, Doctrine rend les entités de sa carte d'identité, et seule la lecture
     * SQL se répète.
     *
     * @return list<Person>
     */
    public function personnesDuTableau(): array
    {
        /** @var list<Person> $lignes */
        $lignes = $this->personnes->createQueryBuilder('p')
            ->orderBy('p.searchKey', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();

        return $lignes;
    }

    /**
     * Les liens de toutes les fiches d'un coup, **en identifiants seulement** : parents,
     * conjoints, enfants et professions. Trois lectures SQL, quel que soit le nombre de
     * fiches — une par personne en ferait six mille.
     *
     * Aucun filtre ici non plus : c'est {@see NormaliseurDuTableau} qui décide, avec le
     * cercle, du nom qu'il sert pour chaque identifiant et des enfants qu'il compte.
     *
     * @return array{
     *     parents: array<int, list<int>>,
     *     conjoints: array<int, list<int>>,
     *     enfants: array<int, list<int>>,
     *     professions: array<int, list<string>>,
     * }
     */
    public function liensDuTableau(): array
    {
        $connexion = $this->em->getConnection();
        $table = fn (string $entite): string => $this->em->getClassMetadata($entite)->getTableName();

        $familles = [];
        foreach ($connexion->fetchAllAssociative(\sprintf('SELECT id, husband_id, wife_id FROM %s ORDER BY id', $table(Family::class))) as $ligne) {
            $familles[(int) $ligne['id']] = array_values(array_filter(
                [$ligne['husband_id'], $ligne['wife_id']],
                static fn (mixed $id): bool => is_numeric($id),
            ));
        }

        $conjoints = [];
        foreach ($familles as $epoux) {
            foreach ($epoux as $un) {
                foreach ($epoux as $autre) {
                    if ($un !== $autre) {
                        $conjoints[(int) $un][] = (int) $autre;
                    }
                }
            }
        }

        $parents = [];
        $enfants = [];
        foreach ($connexion->fetchAllAssociative(\sprintf('SELECT family_id, person_id FROM %s ORDER BY id', $table(Child::class))) as $ligne) {
            foreach ($familles[(int) $ligne['family_id']] ?? [] as $parent) {
                $parents[(int) $ligne['person_id']][] = (int) $parent;
                $enfants[(int) $parent][] = (int) $ligne['person_id'];
            }
        }

        $professions = [];
        $requete = \sprintf(
            'SELECT person_id, label FROM %s WHERE type = ? AND person_id IS NOT NULL ORDER BY sort_order, id',
            $table(Fact::class),
        );
        foreach ($connexion->fetchAllAssociative($requete, [FactType::Profession->value]) as $ligne) {
            $libelle = trim((string) $ligne['label']);
            if ('' !== $libelle) {
                $professions[(int) $ligne['person_id']][] = $libelle;
            }
        }

        return [
            'parents' => array_map(static fn (array $ids): array => array_values(array_unique($ids)), $parents),
            'conjoints' => array_map(static fn (array $ids): array => array_values(array_unique($ids)), $conjoints),
            'enfants' => array_map(static fn (array $ids): array => array_values(array_unique($ids)), $enfants),
            'professions' => $professions,
        ];
    }
}
