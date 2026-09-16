<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Gedcom\Dto\CitationLue;
use App\Module\Gedcom\Dto\NoteLue;
use App\Module\Gedcom\Dto\ResultatAnalyse;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\ChildRelation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écrit dans `Tree` et `Core` ce que l'analyse a lu, puis **recompte depuis la base**.
 *
 * **Par l'ORM et non par des `INSERT` directs**, délibérément. Le volume ne le justifie
 * pas — 1 300 lignes environ — et les `INSERT` directs auraient coûté trois pièges :
 * `created_at` et `updated_at` sont `NOT NULL` **sans `DEFAULT now()`** (c'est voulu,
 * sinon `doctrine:schema:validate` serait rouge en permanence), les blocs date sont des
 * `#[ORM\Embedded]` qu'il faudrait aplatir à la main colonne par colonne, et le
 * `#[ORM\Version]` ne serait pas initialisé. Les constructeurs d'entité font les trois
 * sans qu'on ait à y penser.
 *
 * **Le ré-import est idempotent** (§ 9.8 du modèle) : les personnes, familles et sources
 * sont retrouvées par leur `gedcom_xref`. Les lignes qui n'ont pas d'identité propre —
 * filiations, faits, notes, citations — sont effacées puis réécrites, faute de quoi un
 * second import doublerait les 483 citations. Les notes **écrites dans l'application**
 * (`author_user_id IS NOT NULL`) sont épargnées : elles n'appartiennent pas au GEDCOM.
 */
final class EcrivainTree
{
    /** Le réglage que l'import est seul à savoir renseigner (§ 2.2 du modèle). */
    private const string CLE_RACINE = 'tree.personne_racine_id';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Écrit tout le modèle analysé. **N'ouvre pas de transaction** : c'est l'appelant qui
     * la tient, parce que c'est lui qui décide ce qui est dedans — le recomptage de
     * contrôle en fait partie, et un écart doit annuler l'écriture.
     *
     * @return array<string, int> les dénombrements **relus depuis la base**
     */
    public function ecrire(ResultatAnalyse $analyse): array
    {
        $sources = $this->ecrireSources($analyse);
        $personnes = $this->ecrirePersonnes($analyse);

        $this->em->flush();

        $familles = $this->ecrireFamilles($analyse, $personnes);

        $this->em->flush();

        // Le nettoyage vient **après** les deux `flush` : un `DELETE` porte sur des
        // identifiants, et ceux des lignes qu'on vient d'insérer ne sont connus qu'ici.
        // Il vient aussi **avant** tout `persist` de dépendance — un `DELETE` par DQL
        // part vers la base immédiatement, là où un `persist` attend le `flush`.
        $this->purgerDependances($personnes, $familles);

        $this->ecrireFiliations($analyse, $personnes, $familles);
        $this->ecrireFaits($analyse, $personnes, $sources);
        $this->ecrireNotesEtCitationsDeFamille($analyse, $familles, $sources);

        $this->em->flush();

        $this->poserPersonneRacine($analyse, $personnes);

        $this->em->flush();

        return $this->recompter($analyse);
    }

    /**
     * @return array<string, Source>
     */
    private function ecrireSources(ResultatAnalyse $analyse): array
    {
        /** @var array<string, Source> $existantes */
        $existantes = $this->parXref(Source::class);
        $ecrites = [];

        foreach ($analyse->sources as $xref => $lue) {
            $source = $existantes[$xref] ?? new Source($lue->titre, $lue->genre);
            $source
                ->setGedcomXref($xref)
                ->setTitle($lue->titre)
                ->setAuthor($lue->auteur)
                ->setPublication($lue->publication)
                ->setText($lue->texte)
                ->setNote($lue->note)
                ->setMediaType($lue->typeMedia)
                ->setSourceKind($lue->genre)
                ->toucher();

            $this->em->persist($source);
            $ecrites[$xref] = $source;
        }

        return $ecrites;
    }

    /**
     * @return array<string, Person>
     */
    private function ecrirePersonnes(ResultatAnalyse $analyse): array
    {
        /** @var array<string, Person> $existantes */
        $existantes = $this->parXref(Person::class);
        $ecrites = [];

        foreach ($analyse->personnes as $xref => $lue) {
            $personne = $existantes[$xref] ?? new Person($lue->affichage);
            $personne
                ->setGedcomXref($xref)
                ->setGedcomUid($lue->uid)
                ->setGedcomRin($lue->rin)
                ->setGivenNames($lue->prenoms)
                ->setSurname($lue->nom)
                ->setMarriedName($lue->nomEpouse)
                ->setNickname($lue->surnom)
                ->setDisplayName($lue->affichage)
                ->setSearchKey($lue->cleRecherche)
                ->setSex($lue->sexe)
                ->setDeceased($lue->decede)
                ->setBirth($lue->naissance)
                ->setDeath($lue->deces)
                ->setBranch($analyse->placement->branches[$xref] ?? null)
                ->setGeneration($analyse->placement->generations[$xref] ?? null)
                ->setBestQuay($lue->meilleurQuay())
                ->setCitationCount(\count($lue->citations))
                ->toucher();

            $this->em->persist($personne);
            $ecrites[$xref] = $personne;
        }

        return $ecrites;
    }

    /**
     * @param array<string, Person> $personnes
     *
     * @return array<string, Family>
     */
    private function ecrireFamilles(ResultatAnalyse $analyse, array $personnes): array
    {
        /** @var array<string, Family> $existantes */
        $existantes = $this->parXref(Family::class);
        $ecrites = [];

        foreach ($analyse->familles as $xref => $lue) {
            $mari = null === $lue->mari ? null : ($personnes[$lue->mari] ?? null);
            $femme = null === $lue->femme ? null : ($personnes[$lue->femme] ?? null);

            $famille = $existantes[$xref] ?? new Family($mari, $femme);
            $famille
                ->setGedcomXref($xref)
                ->setGedcomUid($lue->uid)
                ->setGedcomRin($lue->rin)
                ->setHusband($mari)
                ->setWife($femme)
                ->setMarriage($lue->mariage)
                ->setDivorced($lue->divorce)
                ->setDivorce($lue->dateDivorce)
                ->setGeneration($analyse->placement->generationsFamille[$xref] ?? null)
                ->setRank($analyse->placement->rangs[$xref] ?? null)
                ->toucher();

            $this->em->persist($famille);
            $ecrites[$xref] = $famille;
        }

        return $ecrites;
    }

    /**
     * Efface les lignes sans identité propre avant de les réécrire.
     *
     * L'ordre suit les clés étrangères : citations et notes d'abord — elles pointent vers
     * les faits —, puis les faits, puis les filiations. `ON DELETE CASCADE` ferait le
     * travail, mais un `DELETE` explicite dit ce qu'on efface et ne dépend pas du schéma.
     *
     * @param array<string, Person> $personnes
     * @param array<string, Family> $familles
     */
    private function purgerDependances(array $personnes, array $familles): void
    {
        $idsPersonnes = $this->identifiants($personnes);
        $idsFamilles = $this->identifiants($familles);

        if ([] === $idsPersonnes && [] === $idsFamilles) {
            return;
        }

        // **Les faits d'abord, parce que des notes et des citations pendent dessous.**
        // Une note de `1 CHR / 2 NOTE` est rattachée au fait, donc ni à une personne ni à
        // une famille : la chercher par son sujet ne la trouve pas. On ne s'en remet pas
        // au `ON DELETE CASCADE` de la clé étrangère — il n'agit que là où les clés
        // étrangères sont actives, et SQLite les laisse inertes par défaut. Un import qui
        // ne nettoie pas pareil selon la base est un import qu'on ne peut pas tester.
        $idsFaits = $this->faitsDe($idsPersonnes, $idsFamilles);

        $this->supprimer(Citation::class, 'c', $idsPersonnes, $idsFamilles, $idsFaits);
        // `author_user_id IS NULL` : une note écrite dans l'application n'appartient pas
        // au GEDCOM et n'a aucune raison de disparaître au ré-import.
        $this->supprimer(Note::class, 'n', $idsPersonnes, $idsFamilles, $idsFaits, 'n.authorUserId IS NULL');
        $this->supprimer(Fact::class, 'f', $idsPersonnes, $idsFamilles, []);

        if ([] !== $idsFamilles) {
            $this->em->createQuery(
                \sprintf('DELETE FROM %s e WHERE IDENTITY(e.family) IN (:familles)', Child::class),
            )->setParameter('familles', $idsFamilles)->execute();
        }
    }

    /**
     * Les identifiants des faits portés par ces personnes ou ces familles.
     *
     * @param list<int> $idsPersonnes
     * @param list<int> $idsFamilles
     *
     * @return list<int>
     */
    private function faitsDe(array $idsPersonnes, array $idsFamilles): array
    {
        $portees = [];
        $parametres = [];

        if ([] !== $idsPersonnes) {
            $portees[] = 'IDENTITY(f.person) IN (:personnes)';
            $parametres['personnes'] = $idsPersonnes;
        }

        if ([] !== $idsFamilles) {
            $portees[] = 'IDENTITY(f.family) IN (:familles)';
            $parametres['familles'] = $idsFamilles;
        }

        if ([] === $portees) {
            return [];
        }

        $requete = $this->em->createQuery(
            \sprintf('SELECT f.id FROM %s f WHERE %s', Fact::class, implode(' OR ', $portees)),
        );

        foreach ($parametres as $nom => $valeur) {
            $requete->setParameter($nom, $valeur);
        }

        $ids = [];

        foreach ($requete->getArrayResult() as $ligne) {
            if (\is_array($ligne) && \is_numeric($ligne['id'] ?? null)) {
                $ids[] = (int) $ligne['id'];
            }
        }

        return $ids;
    }

    /**
     * @param class-string $entite
     * @param list<int>    $idsPersonnes
     * @param list<int>    $idsFamilles
     * @param list<int>    $idsFaits
     */
    private function supprimer(string $entite, string $alias, array $idsPersonnes, array $idsFamilles, array $idsFaits, ?string $condition = null): void
    {
        $portees = [];
        $parametres = [];

        if ([] !== $idsPersonnes) {
            $portees[] = \sprintf('IDENTITY(%s.person) IN (:personnes)', $alias);
            $parametres['personnes'] = $idsPersonnes;
        }

        if ([] !== $idsFamilles) {
            $portees[] = \sprintf('IDENTITY(%s.family) IN (:familles)', $alias);
            $parametres['familles'] = $idsFamilles;
        }

        if ([] !== $idsFaits) {
            $portees[] = \sprintf('IDENTITY(%s.fact) IN (:faits)', $alias);
            $parametres['faits'] = $idsFaits;
        }

        if ([] === $portees) {
            return;
        }

        $dql = \sprintf('DELETE FROM %s %s WHERE (%s)', $entite, $alias, implode(' OR ', $portees));

        if (null !== $condition) {
            $dql .= ' AND '.$condition;
        }

        $requete = $this->em->createQuery($dql);

        foreach ($parametres as $nom => $valeur) {
            $requete->setParameter($nom, $valeur);
        }

        $requete->execute();
    }

    /**
     * Les lignes déjà en base qui portent un `gedcom_xref`, indexées par cet `xref`.
     *
     * **C'est le pivot de l'idempotence du ré-import** (§ 9.8) : `@I500017@` retrouve sa
     * ligne, et le second import met à jour au lieu de créer un 275ᵉ Romarin.
     *
     * `INDEX BY` plutôt qu'une boucle de réindexation : Doctrine construit la table
     * associative pendant l'hydratation, et le `WHERE` évite de ramener les lignes créées
     * dans l'application, qui reçoivent leur `xref` après leur premier `flush`.
     *
     * @param class-string $entite
     *
     * @return array<string, object>
     */
    private function parXref(string $entite): array
    {
        /** @var array<string, object> $lignes */
        $lignes = $this->em->createQuery(
            \sprintf('SELECT e FROM %s e INDEX BY e.gedcomXref WHERE e.gedcomXref IS NOT NULL', $entite),
        )->getResult();

        return $lignes;
    }

    /**
     * @param array<string, Person|Family> $entites
     *
     * @return list<int>
     */
    private function identifiants(array $entites): array
    {
        $ids = [];

        foreach ($entites as $entite) {
            $id = $entite->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, Person> $personnes
     * @param array<string, Family> $familles
     */
    private function ecrireFiliations(ResultatAnalyse $analyse, array $personnes, array $familles): void
    {
        foreach ($analyse->familles as $xref => $lue) {
            $famille = $familles[$xref] ?? null;

            if (null === $famille) {
                continue;
            }

            $rang = 0;

            foreach ($lue->enfants as $enfantXref) {
                $enfant = $personnes[$enfantXref] ?? null;

                if (null === $enfant) {
                    continue;
                }

                // Le rang est celui des lignes `CHIL`, **pas** l'ordre des naissances :
                // 50 personnes n'ont aucune date, et le § 3.3 du modèle impose que
                // l'ordre du fichier survive jusqu'au moteur de placement.
                $this->em->persist(new Child($famille, $enfant, $rang++, ChildRelation::Naissance));
            }
        }
    }

    /**
     * @param array<string, Person> $personnes
     * @param array<string, Source> $sources
     */
    private function ecrireFaits(ResultatAnalyse $analyse, array $personnes, array $sources): void
    {
        foreach ($analyse->personnes as $xref => $lue) {
            $personne = $personnes[$xref] ?? null;

            if (null === $personne) {
                continue;
            }

            foreach ($lue->notes as $note) {
                $this->em->persist($this->note($note)->setPerson($personne));
            }

            foreach ($lue->citations as $citation) {
                $entite = $this->citation($citation, $sources);

                if (null !== $entite) {
                    $this->em->persist($entite->setPerson($personne));
                }
            }

            foreach ($lue->faits as $lu) {
                $fait = new Fact($lu->type, $personne);
                $fait
                    ->setLabel($lu->libelle)
                    ->setHappened($lu->quand)
                    ->setAddress($lu->adresse)
                    ->setAge($lu->age)
                    ->setCause($lu->cause)
                    ->setSortOrder($lu->ordre)
                    ->toucher();

                $this->em->persist($fait);

                foreach ($lu->notes as $note) {
                    $this->em->persist($this->note($note)->setFact($fait));
                }

                foreach ($lu->citations as $citation) {
                    $entite = $this->citation($citation, $sources);

                    if (null !== $entite) {
                        $this->em->persist($entite->setFact($fait));
                    }
                }
            }
        }
    }

    /**
     * @param array<string, Family> $familles
     * @param array<string, Source> $sources
     */
    private function ecrireNotesEtCitationsDeFamille(ResultatAnalyse $analyse, array $familles, array $sources): void
    {
        foreach ($analyse->familles as $xref => $lue) {
            $famille = $familles[$xref] ?? null;

            if (null === $famille) {
                continue;
            }

            foreach ($lue->notes as $note) {
                $this->em->persist($this->note($note)->setFamily($famille));
            }

            foreach ($lue->citations as $citation) {
                $entite = $this->citation($citation, $sources);

                if (null !== $entite) {
                    $this->em->persist($entite->setFamily($famille));
                }
            }
        }
    }

    private function note(NoteLue $lue): Note
    {
        // `setAuthor(null, null)` est explicite et non redondant : c'est la marque qui
        // distingue une note importée d'une note écrite dans l'application, et c'est elle
        // que la purge du ré-import interroge.
        return (new Note($lue->corps, $lue->portee))->setAuthor(null, null);
    }

    /**
     * @param array<string, Source> $sources
     */
    private function citation(CitationLue $lue, array $sources): ?Citation
    {
        $source = $sources[$lue->sourceXref] ?? null;

        if (null === $source) {
            return null;
        }

        return (new Citation($source))
            ->setPage($lue->page)
            ->setQuay($lue->quay)
            ->setEvenContext($lue->contexte)
            ->setRole($lue->role)
            ->setDataDateRaw($lue->dateDonnees)
            ->setDataText($lue->texteDonnees);
    }

    /**
     * Pose `tree.personne_racine_id`, que la migration laisse à 0 — « non défini ».
     *
     * C'est la seule écriture de l'import hors de `Tree` : sans elle, `generation` et
     * `branch` sont en base sans que rien ne dise par rapport à qui.
     *
     * @param array<string, Person> $personnes
     */
    private function poserPersonneRacine(ResultatAnalyse $analyse, array $personnes): void
    {
        $racine = $personnes[$analyse->ego] ?? null;
        $id = $racine?->getId();

        if (null === $id) {
            return;
        }

        $reglage = $this->em->createQuery(
            \sprintf('SELECT r FROM %s r WHERE r.settingKey = :cle', Setting::class),
        )->setParameter('cle', self::CLE_RACINE)->getOneOrNullResult();

        if (!$reglage instanceof Setting) {
            // Le jeu de réglages est posé par la migration initiale : son absence veut
            // dire que la base n'est pas migrée, ce que l'écriture aurait déjà signalé.
            return;
        }

        $reglage->setValue((string) $id)->setUpdatedBy(null, 'console');
    }

    /**
     * Les dénombrements **relus depuis la base**.
     *
     * C'est là toute la différence entre « j'ai lu 274 personnes » et « il y a 274
     * personnes en base ». Un import qui écrit 273 lignes sans lever d'exception — une
     * contrainte d'unicité avalée, une entité jamais `persist()`ée — ne se voit que par
     * là.
     *
     * @return array<string, int>
     */
    private function recompter(ResultatAnalyse $analyse): array
    {
        $analyses = $analyse->totaux();

        $bas = $this->agreger('MIN', Person::class, 'p', 'generation');
        $haut = $this->agreger('MAX', Person::class, 'p', 'generation');
        $etendue = null === $bas || null === $haut ? 0 : $haut - $bas + 1;

        // Cinq requêtes plutôt qu'un `GROUP BY` : sur 274 lignes la différence n'existe
        // pas, et une colonne d'énumération relue par `getArrayResult()` rend tantôt le
        // cas, tantôt sa valeur selon la version de Doctrine. On ne veut pas d'un
        // dénombrement de contrôle qui dépende de ça.
        $parBranche = [];

        foreach (Branch::cases() as $branche) {
            $parBranche[$branche->value] = $this->compter(
                Person::class,
                'p',
                'p.branch = :branche',
                ['branche' => $branche->value],
            );
        }

        return [
            'personnes' => $this->compter(Person::class, 'p'),
            'familles' => $this->compter(Family::class, 'f'),
            'filiations' => $this->compter(Child::class, 'e'),
            'sources' => $this->compter(Source::class, 's'),
            'citations' => $this->compter(Citation::class, 'c'),
            'citations_individus' => $this->compter(Citation::class, 'c', 'c.person IS NOT NULL'),
            'citations_familles' => $this->compter(Citation::class, 'c', 'c.family IS NOT NULL'),
            'notes' => $this->compter(Note::class, 'n'),
            'notes_de_source' => $this->compter(Source::class, 's', 's.note IS NOT NULL'),
            'faits' => $this->compter(Fact::class, 'a'),
            // Non persistés : les 31 `OBJE` sont comptés et signalés, jamais téléchargés,
            // et `RESN privacy` est reproduit par la règle calculée du § 9.2 plutôt que
            // stocké. Le rapport dit d'où vient chaque nombre.
            'medias' => $analyses['medias'],
            'resn_privacy' => $analyses['resn_privacy'],
            'generations' => $etendue,
            'sans_deces' => $this->compter(Person::class, 'p', 'p.deceased = false'),
            'deces_sans_date' => $this->compter(
                Person::class,
                'p',
                'p.deceased = true AND p.death.qualifier = :inconnue',
                ['inconnue' => DateQualifier::Inconnue->value],
            ),
            'fiches_actees' => $this->compter(Person::class, 'p', 'p.bestQuay >= 2'),
            'branche_pp' => $parBranche['pp'],
            'branche_pm' => $parBranche['pm'],
            'branche_mp' => $parBranche['mp'],
            'branche_mm' => $parBranche['mm'],
            'branche_tr' => $parBranche['tr'],
        ];
    }

    /**
     * @param class-string         $entite
     * @param array<string, mixed> $parametres
     */
    private function compter(string $entite, string $alias, ?string $condition = null, array $parametres = []): int
    {
        $dql = \sprintf('SELECT COUNT(%s.id) FROM %s %s', $alias, $entite, $alias);

        if (null !== $condition) {
            $dql .= ' WHERE '.$condition;
        }

        $requete = $this->em->createQuery($dql);

        foreach ($parametres as $nom => $valeur) {
            $requete->setParameter($nom, $valeur);
        }

        $resultat = $requete->getSingleScalarResult();

        return \is_numeric($resultat) ? (int) $resultat : 0;
    }

    /**
     * @param class-string $entite
     */
    private function agreger(string $fonction, string $entite, string $alias, string $champ): ?int
    {
        $resultat = $this->em->createQuery(
            \sprintf('SELECT %s(%s.%s) FROM %s %s', $fonction, $alias, $champ, $entite, $alias),
        )->getSingleScalarResult();

        return \is_numeric($resultat) ? (int) $resultat : null;
    }
}
