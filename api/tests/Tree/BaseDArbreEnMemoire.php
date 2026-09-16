<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Une vraie base, peuplée depuis `graphe.json`, pour éprouver
 * {@see \App\Module\Tree\Service\JeuDePlacementDoctrine}.
 *
 * **SQLite en mémoire, monté ici et nulle part ailleurs.** Ni noyau Symfony, ni fichier
 * partagé : chaque instance a sa propre base, qui disparaît avec elle. C'est délibéré —
 * les bases de test SQLite partagées entre processus produisent des erreurs fantômes
 * (tables déjà existantes ou absentes, tests qui changent d'avis sans modification de
 * code), et ce test-ci doit pouvoir accuser le fournisseur sans qu'on se demande si
 * c'est l'infrastructure.
 *
 * Seuls deux dossiers de mapping sont déclarés : `Tree\Entity` et `Core\ValueObject`
 * — ce dernier pour l'embeddable `UncertainDate`. Aucune entité de `Core` n'est
 * nécessaire, et c'est une **conséquence observable de la frontière du § 0.4** : si
 * `Tree` avait une relation Doctrine vers `Core`, ce test ne pourrait pas exister sous
 * cette forme.
 */
final class BaseDArbreEnMemoire
{
    private const array MOIS = [
        1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MAY', 6 => 'JUN',
        7 => 'JUL', 8 => 'AUG', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DEC',
    ];

    /**
     * Les deux tables de correspondance entre la référence GEDCOM (`@I500001@`) et
     * l'identifiant de nœud de l'application (`p1`).
     *
     * Le xref donné en exemple est **littéral à dessein**. Le brief d'anonymisation
     * demandait de renuméroter les xrefs ; cette consigne a été annulée (§ 9.1) : privé de
     * la date qu'on lui accole, un numéro opaque n'énonce rien. Ne pas « réparer ».
     *
     * On indexe par **identifiant de nœud** et non par identifiant de base : la personne 1
     * et la famille 1 existent toutes les deux, et une table indexée par le seul entier
     * en perdrait une. C'est le défaut que ce test a trouvé dans le fournisseur.
     *
     * @var array<string, string>
     */
    private array $noeudParXref = [];

    /** @var array<string, string> */
    private array $xrefParNoeud = [];

    private function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?int $racineId,
    ) {
    }

    public static function depuis(ChargeurDeGrapheDeReference $reference): self
    {
        $em = self::entityManagerEnMemoire();

        $personnesParXref = [];

        // Les personnes d'abord, dans l'ordre du fichier : l'identifiant auto-incrémenté
        // reproduit ainsi l'ordre que l'import GEDCOM produirait, et c'est lui qui sert
        // de clé de tri au fournisseur.
        foreach ($reference->personnesBrutes() as $xref => $g) {
            $personne = self::enPersonne((string) $xref, $g);
            $em->persist($personne);
            $personnesParXref[(string) $xref] = $personne;
        }

        $em->flush();

        $noeudParXref = [];
        $xrefParNoeud = [];

        foreach ($personnesParXref as $xref => $personne) {
            $id = $personne->getId();
            \assert(null !== $id);
            $noeud = IdentifiantDeNoeud::personne($id);
            $noeudParXref[$xref] = $noeud;
            $xrefParNoeud[$noeud] = $xref;
        }

        // Puis les familles et les filiations, dans l'ordre du fichier également.
        $famillesParXref = [];

        foreach ($reference->famillesBrutes() as $xref => $f) {
            $epoux = self::identifiants($f, 'epoux');

            $famille = new Family(
                $personnesParXref[$epoux[0] ?? ''] ?? null,
                $personnesParXref[$epoux[1] ?? ''] ?? null,
            );
            $famille->setGedcomXref((string) $xref);
            $famille->setDivorced(true === ($f['a_divorce'] ?? false));
            $em->persist($famille);
            $famillesParXref[(string) $xref] = $famille;

            foreach (self::identifiants($f, 'enfants') as $rang => $enfantXref) {
                $enfant = $personnesParXref[$enfantXref] ?? null;

                if (null !== $enfant) {
                    // `sibling_order` porte l'ordre des lignes `CHIL`, pas celui des
                    // naissances : c'est ce que le fournisseur doit restituer.
                    $em->persist(new Child($famille, $enfant, $rang));
                }
            }
        }

        $em->flush();

        foreach ($famillesParXref as $xref => $famille) {
            $id = $famille->getId();
            \assert(null !== $id);
            $noeud = IdentifiantDeNoeud::famille($id);
            $noeudParXref[$xref] = $noeud;
            $xrefParNoeud[$noeud] = $xref;
        }

        $racine = $personnesParXref[$reference->ego()] ?? null;

        $em->clear();

        $base = new self($em, $racine?->getId());
        $base->noeudParXref = $noeudParXref;
        $base->xrefParNoeud = $xrefParNoeud;

        return $base;
    }

    public function entityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    public function racineId(): ?int
    {
        return $this->racineId;
    }

    /** @return list<Person> */
    public function personnes(): array
    {
        /** @var list<Person> $personnes */
        $personnes = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $personnes;
    }

    /** L'identifiant de nœud (`p1`, `f28`) correspondant à une référence GEDCOM. */
    public function idDe(string $xref): string
    {
        return $this->noeudParXref[$xref] ?? '';
    }

    public function xrefDe(Person $personne): string
    {
        $id = $personne->getId();

        return null === $id ? '' : ($this->xrefParNoeud[IdentifiantDeNoeud::personne($id)] ?? '');
    }

    /** L'inverse : d'un identifiant de nœud vers la référence GEDCOM. */
    public function xrefDeId(string $identifiantDeNoeud): string
    {
        return $this->xrefParNoeud[$identifiantDeNoeud] ?? '';
    }

    /**
     * Reconstitue la chaîne GEDCOM verbatim qu'un import aurait écrite dans
     * `birth_raw`, à partir de l'étiquette compacte de `graphe.json`.
     *
     * C'est l'inverse de `jolie()`, et il n'est fidèle que parce que les six formes
     * présentes dans le fichier sont réversibles : vide (50 naissances), `ABT` (19),
     * `BET` (11), année seule (45), date exacte (147), mois (2) — plus `BEF` sur six
     * décès. `testLaReconstitutionDesDatesEstFidele` le vérifie sur les 548 dates.
     */
    public static function chaineGedcom(string $etiquette): ?string
    {
        if ('' === $etiquette) {
            return null;
        }

        if (1 === preg_match('/^(\d{4})-(\d{4})$/', $etiquette, $m)) {
            return \sprintf('BET %s AND %s', $m[1], $m[2]);
        }

        if (1 === preg_match('/^~(\d{4})$/', $etiquette, $m)) {
            return 'ABT '.$m[1];
        }

        if (1 === preg_match('/^<(\d{4})$/', $etiquette, $m)) {
            return 'BEF '.$m[1];
        }

        if (1 === preg_match('/^>(\d{4})$/', $etiquette, $m)) {
            return 'AFT '.$m[1];
        }

        if (1 === preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $etiquette, $m)) {
            return \sprintf('%s %s %s', $m[1], self::MOIS[(int) $m[2]], $m[3]);
        }

        if (1 === preg_match('/^(\d{1,2})\/(\d{4})$/', $etiquette, $m)) {
            return self::MOIS[(int) $m[1]].' '.$m[2];
        }

        return $etiquette;
    }

    /** @param array<string, mixed> $g */
    private static function enPersonne(string $xref, array $g): Person
    {
        $prenom = \is_string($g['prenom'] ?? null) ? $g['prenom'] : '';
        $nom = \is_string($g['nom'] ?? null) ? $g['nom'] : '';
        $usage = \is_string($g['usage'] ?? null) ? $g['usage'] : '';

        $personne = new Person(trim($prenom.' '.$nom));
        $personne->setGedcomXref($xref);
        // `graphe.py` écrit `pre or "?"` : on remet la chaîne vide en base pour que ce
        // soit bien le fournisseur qui restitue le « ? ».
        $personne->setGivenNames('?' === $prenom ? '' : $prenom);
        $personne->setSurname($nom);
        $personne->setNickname('' !== $usage ? $usage : null);
        $personne->setSex(match ($g['sexe'] ?? '') {
            'M' => Sex::Masculin,
            'F' => Sex::Feminin,
            default => Sex::Inconnu,
        });
        $personne->setDeceased(true === ($g['mort'] ?? false));
        $personne->setGeneration(\is_int($g['gen'] ?? null) ? $g['gen'] : 0);

        // `force_privacy` reste faux partout : c'est la **règle** du § 9.2 qu'on veut
        // éprouver, pas le drapeau du GEDCOM recopié. Le recopier rendrait le test
        // circulaire et il passerait quoi qu'il arrive.
        $personne->setBirth(self::enDate(\is_string($g['nais'] ?? null) ? $g['nais'] : ''));
        $personne->setDeath(self::enDate(\is_string($g['deces'] ?? null) ? $g['deces'] : ''));

        return $personne;
    }

    /** Le bloc date complet : la chaîne verbatim, le qualificatif et les deux bornes. */
    private static function enDate(string $etiquette): UncertainDate
    {
        $brut = self::chaineGedcom($etiquette);

        if (null === $brut) {
            return UncertainDate::inconnue();
        }

        if (1 === preg_match('/^BET (\d{4}) AND (\d{4})$/', $brut, $m)) {
            return new UncertainDate($brut, DateQualifier::Entre, self::debut($m[1]), self::fin($m[2]));
        }

        if (1 === preg_match('/^ABT (\d{4})$/', $brut, $m)) {
            return new UncertainDate($brut, DateQualifier::Environ, self::debut($m[1]), self::fin($m[1]));
        }

        // `BEF 1920` : borne haute **exclue**, donc stockée au 31 décembre 1919 (§ 9.1).
        if (1 === preg_match('/^BEF (\d{4})$/', $brut, $m)) {
            return new UncertainDate($brut, DateQualifier::Avant, null, self::fin((string) ((int) $m[1] - 1)));
        }

        if (1 === preg_match('/^AFT (\d{4})$/', $brut, $m)) {
            return new UncertainDate($brut, DateQualifier::Apres, self::debut((string) ((int) $m[1] + 1)), null);
        }

        if (1 === preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/', $brut, $m)) {
            $mois = array_search($m[2], self::MOIS, true);
            \assert(\is_int($mois));
            $jour = new \DateTimeImmutable(\sprintf('%s-%02d-%02d', $m[3], $mois, (int) $m[1]));

            return new UncertainDate($brut, DateQualifier::Exacte, $jour, $jour);
        }

        if (1 === preg_match('/^([A-Z]{3}) (\d{4})$/', $brut, $m)) {
            $mois = array_search($m[1], self::MOIS, true);
            \assert(\is_int($mois));
            $premier = new \DateTimeImmutable(\sprintf('%s-%02d-01', $m[2], $mois));

            return new UncertainDate($brut, DateQualifier::Mois, $premier, $premier->modify('last day of this month'));
        }

        return new UncertainDate($brut, DateQualifier::Annee, self::debut($brut), self::fin($brut));
    }

    private static function debut(string $annee): \DateTimeImmutable
    {
        return new \DateTimeImmutable($annee.'-01-01');
    }

    private static function fin(string $annee): \DateTimeImmutable
    {
        return new \DateTimeImmutable($annee.'-12-31');
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<string>
     */
    private static function identifiants(array $source, string $cle): array
    {
        $valeurs = $source[$cle] ?? [];
        $sortie = [];

        if (\is_array($valeurs)) {
            foreach ($valeurs as $id) {
                if (\is_string($id)) {
                    $sortie[] = $id;
                }
            }
        }

        return $sortie;
    }

    private static function entityManagerEnMemoire(): EntityManagerInterface
    {
        $em = FabriqueDEntityManager::enMemoire();

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }
}
