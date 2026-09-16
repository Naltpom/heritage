<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Service\Port\EcritureAppliquee;
use App\Module\Proposal\Service\Port\EtatCible;
use App\Module\Proposal\Service\Port\LigneCreee;
use App\Module\Proposal\Service\Port\PersonneAcreer;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Enum\CitationQuality;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'écriture de `Tree` par une proposition validée — l'implémentation de
 * {@see PortArbre}, le seul chemin par lequel `Proposal` touche l'arbre.
 *
 * **Le sens de la dépendance.** `Tree` nomme `Proposal\Service\Port\…` ; l'inverse est
 * interdit (ADR 0002, règle 3). C'est voulu : l'interface appartient à celui qui en a
 * besoin, l'implémentation à celui qui sait faire. Ce qu'elle sait faire, et qu'on ne
 * réécrit nulle part ailleurs : composer `display_name` et `search_key`, normaliser un
 * couple selon le sexe, attribuer un `gedcom_xref` après le `flush`, recalculer
 * `best_quay`, `branch` et `generation`.
 *
 * ## Les trois règles de transaction, et ce qu'elles coûtent quand on les enfreint
 *
 * **1. On `flush()`, on n'ouvre jamais de transaction.** Le validateur en tient une, et
 * son tout-ou-rien en dépend : une seconde transaction imbriquée transformerait son
 * `rollback` en « une partie a été écrite quand même ». Le `flush()`, lui, est
 * obligatoire — sans lui `#[ORM\Version]` ne s'exerce pas et la version d'après reste
 * inconnue.
 *
 * **2. `OptimisticLockException` remonte telle quelle.** Elle devient le `409
 * verrou_concurrent` du § 6.8 du contrat d'API, celui qui dit « réessaye ». L'attraper
 * ici la transformerait en erreur générique, et le front perdrait la distinction avec
 * `demande_caduque`, qui dit exactement l'inverse — « cette demande est morte, ne la
 * rejoue pas ».
 *
 * **3. Une cible absente rend `null`, elle ne lève pas.** C'est le cas `cible_disparue`,
 * une caducité prévue et non une panne.
 *
 * ## Le piège des identifiants
 *
 * `tree_person.id` et `tree_family.id` sont **deux séquences indépendantes** : 103 entiers
 * désignent à la fois une personne et une famille. Rien ici ne mélange les deux — chaque
 * méthode sait de quelle table elle parle, et les identifiants qui circulent au-delà sont
 * préfixés par {@see IdentifiantDeNoeud}. C'est la même discipline que l'import, qui
 * indexe tout par le `xref` GEDCOM, lequel porte son type dans son premier caractère.
 */
final class EcritureDeLArbrePourProposal implements PortArbre
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NormalisateurNom $noms,
        private readonly RecalculDesColonnesDerivees $derivees,
        private readonly TreeFingerprint $empreinte,
    ) {
    }

    public function chargerCible(SubjectKind $kind, int $id): ?EtatCible
    {
        $ligne = $this->ligne($kind, $id);

        if (null === $ligne) {
            return null;
        }

        return new EtatCible($kind, $id, $this->libelle($ligne), $this->version($ligne), $this->valeurs($ligne));
    }

    public function appliquerChamps(SubjectKind $kind, int $id, array $valeurs): EcritureAppliquee
    {
        $ligne = $this->ligne($kind, $id);

        if (null === $ligne) {
            // On n'arrive ici que si la ligne a disparu **entre** le chargement du plan et
            // son application, dans la même transaction. C'est une course, pas une
            // caducité : le validateur a déjà décidé sur un état qui n'existe plus.
            throw new \RuntimeException(\sprintf('La ligne %s:%d a disparu pendant la validation.', $kind->value, $id));
        }

        $versionAvant = $this->version($ligne);

        match (true) {
            $ligne instanceof Person => $this->ecrirePersonne($ligne, $valeurs),
            $ligne instanceof Family => $this->ecrireFamille($ligne, $valeurs),
            default => $this->ecrireCitation($ligne, $valeurs),
        };

        // `flush()` et non `flush($ligne)` : le second n'écrit que l'entité nommée et
        // laisserait en attente tout ce qu'on touche autour.
        $this->em->flush();

        if ($ligne instanceof Citation) {
            // **Après le `flush`, jamais avant.** Les agrégats se relisent par une requête,
            // et le nouveau `QUAY` doit déjà être en base — sinon on recalcule `best_quay`
            // sur la valeur d'avant et la fiche reste du mauvais côté du filtre « appuyée
            // sur un acte », sans que rien ne le signale.
            $this->rafraichirLesAgregats($ligne->getPerson());
            $this->em->flush();
        }

        return new EcritureAppliquee($versionAvant, $this->version($ligne), $this->libelle($ligne));
    }

    public function creerPersonne(PersonneAcreer $fiche): LigneCreee
    {
        $affichage = $this->noms->composerAffichage($fiche->givenNames, $fiche->surname, $fiche->nickname);

        $personne = new Person($affichage);
        $personne
            ->setGivenNames($fiche->givenNames)
            ->setSurname($fiche->surname)
            ->setMarriedName($fiche->marriedName)
            ->setNickname($fiche->nickname)
            // Le surnom entre dans la clé comme il entre dans `display_name` : sans lui,
            // une fiche créée sous « Cobalt Sureau » resterait introuvable sous ce nom-là.
            // Le nom d'usage y entre pour la même raison, à ceci près qu'il n'est pas dans
            // `display_name` : c'est souvent sous lui que la famille cherche.
            ->setSearchKey($this->noms->cleDeRecherche(
                $fiche->givenNames,
                $fiche->surname,
                $fiche->nickname,
                $fiche->marriedName,
            ))
            ->setSex($this->sexe($fiche->sex))
            ->setDeceased($fiche->deceased)
            ->setBirth($fiche->birth)
            ->setDeath($fiche->death);

        $this->em->persist($personne);

        if (null !== $fiche->note && '' !== trim($fiche->note)) {
            // La note du demandeur devient une note de recherche de portée générale, sans
            // auteur applicatif — c'est la demande qui porte l'auteur, et `prp_revision`
            // qui porte le lien.
            $this->em->persist((new Note(trim($fiche->note)))->setPerson($personne));
        }

        // Premier `flush` : la clé primaire n'existe pas avant, et le `gedcom_xref` s'en
        // dérive (§ 9.8). D'où les deux temps — un `postPersist` qui referait un `flush`
        // serait le chemin habituel, et le piège habituel.
        $this->em->flush();

        $id = $personne->getId();
        \assert(null !== $id);

        $personne->setGedcomXref($this->xref('I', $id));
        $this->derivees->recalculer();
        $this->em->flush();

        return new LigneCreee($id, $personne->getDisplayName());
    }

    public function trouverOuCreerFamille(?int $personneA, ?int $personneB): LigneCreee
    {
        [$mari, $femme] = $this->rangerLeCouple($personneA, $personneB);

        if (null === $mari && null === $femme) {
            throw new \InvalidArgumentException('Une famille sans aucun époux n’est pas une famille.');
        }

        $existante = $this->familleDuCouple($mari, $femme);

        if (null !== $existante) {
            $id = $existante->getId();
            \assert(null !== $id);

            // Retrouvée, pas créée : elle ne produira pas de révision. Écrire « famille
            // 104 créée » alors qu'elle existe depuis l'import mettrait dans l'historique
            // d'une fiche un événement qui n'a pas eu lieu.
            return new LigneCreee($id, $this->libelleDuCouple($existante), creee: false);
        }

        $famille = new Family($mari, $femme);
        $this->em->persist($famille);
        $this->em->flush();

        $id = $famille->getId();
        \assert(null !== $id);

        $famille->setGedcomXref($this->xref('F', $id));
        $this->derivees->recalculer();
        $this->em->flush();

        return new LigneCreee($id, $this->libelleDuCouple($famille));
    }

    public function porterUnion(int $familleId, UncertainDate $mariage, bool $divorce, UncertainDate $rupture): void
    {
        $famille = $this->em->find(Family::class, $familleId);

        if (!$famille instanceof Family) {
            throw new \RuntimeException(\sprintf('Famille %d introuvable.', $familleId));
        }

        // Une union sans date reste une union : on pose ce qu'on reçoit, `inconnue`
        // comprise, parce que le demandeur peut vouloir effacer une date qu'il sait fausse.
        $famille->setMarriage($mariage)->setDivorced($divorce)->setDivorce($rupture)->toucher();

        $this->em->flush();
    }

    public function rattacherEnfant(int $familleId, int $personneId, ?int $rangDansLaFratrie): void
    {
        $famille = $this->em->find(Family::class, $familleId);
        $personne = $this->em->find(Person::class, $personneId);

        if (!$famille instanceof Family || !$personne instanceof Person) {
            throw new \RuntimeException(\sprintf('Famille %d ou personne %d introuvable.', $familleId, $personneId));
        }

        if ($this->dejaRattache($familleId, $personneId)) {
            // Rejouer un lien que l'arbre porte déjà n'est pas une faute : refuser la
            // demande ici, ce serait la refuser pour avoir eu raison.
            return;
        }

        $this->em->persist(new Child($famille, $personne, $rangDansLaFratrie ?? $this->prochainRang($familleId)));

        // **Le `flush` d'abord, le recalcul ensuite.** `RecalculDesColonnesDerivees` relit
        // l'arbre par des requêtes, et une entité seulement `persist()`ée n'est pas encore
        // dans la base : le recalcul ne verrait pas la filiation qu'on vient de créer et
        // rendrait exactement les générations d'avant. Le symptôme serait un enfant
        // rattaché mais resté au rang de son parent — visible seulement à l'écran, et
        // seulement par quelqu'un qui connaît l'arbre.
        $this->em->flush();

        $this->derivees->recalculer();
        $this->em->flush();
    }

    public function empreinteDeLArbre(): string
    {
        // `oublie()` avant de lire : l'empreinte est mémoïsée pour la durée de la requête,
        // et on vient précisément de changer ce dont elle dérive. Sans cet oubli, la
        // révision porterait l'empreinte d'**avant** l'écriture — donc une clé de cache
        // déjà servie, donc un graphe périmé rendu comme s'il était neuf.
        $this->empreinte->oublie();

        return $this->empreinte->empreinte();
    }

    // ------------------------------------------------------------------ lecture

    private function ligne(SubjectKind $kind, int $id): Person|Family|Citation|null
    {
        $entite = match ($kind) {
            SubjectKind::Personne => $this->em->find(Person::class, $id),
            SubjectKind::Famille => $this->em->find(Family::class, $id),
            SubjectKind::Citation => $this->em->find(Citation::class, $id),
            SubjectKind::Media, SubjectKind::Note, SubjectKind::Fait => throw new \InvalidArgumentException(\sprintf('Le port de l’arbre ne porte pas les cibles de type « %s ».', $kind->value)),
        };

        return $entite instanceof Person || $entite instanceof Family || $entite instanceof Citation ? $entite : null;
    }

    private function version(Person|Family|Citation $ligne): int
    {
        return $ligne->getVersion();
    }

    private function libelle(Person|Family|Citation $ligne): string
    {
        return match (true) {
            $ligne instanceof Person => $ligne->getDisplayName(),
            $ligne instanceof Family => $this->libelleDuCouple($ligne),
            default => $ligne->getSource()->getTitle(),
        };
    }

    private function libelleDuCouple(Family $famille): string
    {
        $noms = array_filter([
            $famille->getHusband()?->getDisplayName(),
            $famille->getWife()?->getDisplayName(),
        ]);

        return [] === $noms ? 'Union' : implode(' × ', $noms);
    }

    /**
     * Tous les champs éditables de la ligne, jamais un sous-ensemble.
     *
     * Une ligne qui en rendrait moins rendrait **indétectable** une caducité sur les
     * champs manquants : le verrou n° 2 compare ces valeurs aux `old_value` des ops, et
     * ce qu'il ne voit pas, il ne peut pas refuser.
     *
     * @return array<string, mixed>
     */
    private function valeurs(Person|Family|Citation $ligne): array
    {
        if ($ligne instanceof Person) {
            return [
                'givenNames' => $ligne->getGivenNames(),
                'surname' => $ligne->getSurname(),
                'marriedName' => $ligne->getMarriedName(),
                'nickname' => $ligne->getNickname(),
                // Les énumérations traversent le port en **chaîne** : `Proposal` ne peut
                // pas nommer `Tree\Enum\…` sans enfreindre la règle 3.
                'sex' => $ligne->getSex()->value,
                'deceased' => $ligne->isDeceased(),
                'birth' => $ligne->getBirth(),
                'death' => $ligne->getDeath(),
                'forcePrivacy' => $ligne->isForcePrivacy(),
                'privacyNote' => $ligne->getPrivacyNote(),
            ];
        }

        if ($ligne instanceof Family) {
            return [
                'marriage' => $ligne->getMarriage(),
                'divorced' => $ligne->isDivorced(),
                'divorce' => $ligne->getDivorce(),
            ];
        }

        return [
            'page' => $ligne->getPage(),
            'quay' => $ligne->getQuay()?->value,
            'dataText' => $ligne->getDataText(),
        ];
    }

    // ------------------------------------------------------------------ écriture

    /**
     * @param array<string, mixed> $valeurs
     */
    private function ecrirePersonne(Person $personne, array $valeurs): void
    {
        foreach ($valeurs as $champ => $valeur) {
            match ($champ) {
                'givenNames' => $personne->setGivenNames($this->texte($valeur) ?? ''),
                'surname' => $personne->setSurname($this->texte($valeur) ?? ''),
                'marriedName' => $personne->setMarriedName($this->texte($valeur)),
                'nickname' => $personne->setNickname($this->texte($valeur)),
                'sex' => $personne->setSex($this->sexe($this->texte($valeur) ?? Sex::Inconnu->value)),
                'deceased' => $personne->setDeceased((bool) $valeur),
                'birth' => $personne->setBirth($this->date($valeur, 'birth')),
                'death' => $personne->setDeath($this->date($valeur, 'death')),
                // Traités ensemble plus bas : ils vont par paire.
                'forcePrivacy', 'privacyNote' => null,
                default => throw new \InvalidArgumentException(\sprintf('Champ « %s » hors de la surface éditable d’une personne.', $champ)),
            };
        }

        $this->reglerLaConfidentialite($personne, $valeurs);

        // Les deux colonnes dénormalisées suivent les noms, toujours : c'est ici qu'elles
        // se recomposent, pour les 274 fiches importées comme pour celles créées ensuite.
        //
        // **Les deux reçoivent les MÊMES quatre champs**, et c'est le correctif : la clé
        // ne recevait que les prénoms et le patronyme là où l'affichage recevait aussi le
        // surnom. Valider une op `nickname` renommait donc la fiche sans la rendre
        // trouvable sous son nouveau nom — pas « la clé n'est pas recalculée », mais
        // « elle est recalculée à partir d'une entrée plus étroite que l'affichage ».
        $personne
            ->setDisplayName($this->noms->composerAffichage(
                $personne->getGivenNames(),
                $personne->getSurname(),
                $personne->getNickname(),
            ))
            ->setSearchKey($this->noms->cleDeRecherche(
                $personne->getGivenNames(),
                $personne->getSurname(),
                $personne->getNickname(),
                // Le nom d'usage est relu sur l'entité, donc **après** l'op qui vient
                // peut-être de le changer : valider une op `marriedName` doit rendre la
                // fiche trouvable sous le nouveau nom, pas sous l'ancien.
                $personne->getMarriedName(),
            ))
            ->toucher();
    }

    /**
     * `force_privacy` et `privacy_note` se posent ensemble, et dans cet ordre.
     *
     * `ck_tree_person_privacy_motivee` refuse en base un caviardage sans motif : appliquer
     * les deux champs indépendamment, dans l'ordre où ils arrivent, produirait une ligne
     * refusée une fois sur deux selon l'ordre des ops. On ne caviarde pas sans écrire
     * pourquoi, et il n'existe pas de dérogateur dans l'autre sens (§ 9.2).
     *
     * @param array<string, mixed> $valeurs
     */
    private function reglerLaConfidentialite(Person $personne, array $valeurs): void
    {
        $forcee = \array_key_exists('forcePrivacy', $valeurs)
            ? (bool) $valeurs['forcePrivacy']
            : $personne->isForcePrivacy();

        $motif = \array_key_exists('privacyNote', $valeurs)
            ? $this->texte($valeurs['privacyNote'])
            : $personne->getPrivacyNote();

        if (!$forcee) {
            $personne->leverConfidentialiteForcee();

            return;
        }

        if (null === $motif || '' === trim($motif)) {
            throw new \InvalidArgumentException('Forcer la confidentialité exige un motif : on ne caviarde pas sans écrire pourquoi.');
        }

        $personne->forcerConfidentialite(trim($motif));
    }

    /**
     * @param array<string, mixed> $valeurs
     */
    private function ecrireFamille(Family $famille, array $valeurs): void
    {
        foreach ($valeurs as $champ => $valeur) {
            match ($champ) {
                'marriage' => $famille->setMarriage($this->date($valeur, 'marriage')),
                'divorced' => $famille->setDivorced((bool) $valeur),
                'divorce' => $famille->setDivorce($this->date($valeur, 'divorce')),
                default => throw new \InvalidArgumentException(\sprintf('Champ « %s » hors de la surface éditable d’une famille.', $champ)),
            };
        }

        $famille->toucher();
    }

    /**
     * @param array<string, mixed> $valeurs
     */
    private function ecrireCitation(Citation $citation, array $valeurs): void
    {
        foreach ($valeurs as $champ => $valeur) {
            match ($champ) {
                'page' => $citation->setPage($this->texte($valeur)),
                'quay' => $citation->setQuay($this->qualite($valeur)),
                'dataText' => $citation->setDataText($this->texte($valeur)),
                default => throw new \InvalidArgumentException(\sprintf('Champ « %s » hors de la surface éditable d’une citation.', $champ)),
            };
        }

        // `best_quay` et `citation_count` sont des agrégats de la **personne**, pas de la
        // citation : changer un `QUAY` déplace la fiche entre « appuyée sur un acte » et
        // « recopie non vérifiée ». Ils sont rafraîchis par {@see appliquerChamps()},
        // après le `flush` — voir le commentaire qui s'y trouve.
        $citation->toucher();
    }

    private function rafraichirLesAgregats(?Person $personne): void
    {
        $id = $personne?->getId();

        if (null === $personne || null === $id) {
            return;
        }

        /** @var array<int, array{meilleur: int|null, nombre: int|string}> $lignes */
        $lignes = $this->em->createQuery(\sprintf(
            'SELECT MAX(c.quay) AS meilleur, COUNT(c.id) AS nombre FROM %s c WHERE IDENTITY(c.person) = :personne',
            Citation::class,
        ))->setParameter('personne', $id)->getArrayResult();

        $agregat = $lignes[0] ?? ['meilleur' => null, 'nombre' => 0];

        $personne
            ->setBestQuay(null === $agregat['meilleur'] ? null : (int) $agregat['meilleur'])
            ->setCitationCount((int) $agregat['nombre'])
            ->toucher();
    }

    // ------------------------------------------------------------------ familles

    /**
     * Range un couple en `(husband_id, wife_id)` **selon le sexe**, pas selon la position.
     *
     * Une personne de sexe inconnu prend l'emplacement libre : c'est ce qui permet à deux
     * personnes dont on ignore le sexe de former quand même une famille, et c'est
     * `uniq_tree_family_couple` — posée en `NULLS NOT DISTINCT` — qui garantit qu'on ne
     * pourra pas en créer deux pour le même couple.
     *
     * @return array{0: ?Person, 1: ?Person}
     */
    private function rangerLeCouple(?int $personneA, ?int $personneB): array
    {
        $personnes = [];

        foreach ([$personneA, $personneB] as $id) {
            if (null === $id) {
                continue;
            }

            $personne = $this->em->find(Person::class, $id);

            if (!$personne instanceof Person) {
                throw new \RuntimeException(\sprintf('Personne %d introuvable.', $id));
            }

            $personnes[] = $personne;
        }

        $mari = null;
        $femme = null;
        $indetermines = [];

        foreach ($personnes as $personne) {
            match ($personne->getSex()) {
                Sex::Masculin => $mari ??= $personne,
                Sex::Feminin => $femme ??= $personne,
                Sex::Inconnu => $indetermines[] = $personne,
            };
        }

        foreach ($indetermines as $personne) {
            if (null === $mari) {
                $mari = $personne;
            } elseif (null === $femme) {
                $femme = $personne;
            } else {
                throw new \InvalidArgumentException('Un couple ne peut pas compter trois personnes.');
            }
        }

        return [$mari, $femme];
    }

    private function familleDuCouple(?Person $mari, ?Person $femme): ?Family
    {
        // `IS NULL` explicite plutôt qu'un paramètre nul : en SQL, `x = NULL` n'est jamais
        // vrai, et une famille de parent seul ne serait donc jamais retrouvée — on en
        // créerait une seconde à chaque validation, jusqu'à ce que
        // `uniq_tree_family_couple` refuse.
        $dql = \sprintf(
            'SELECT f FROM %s f WHERE %s AND %s',
            Family::class,
            null === $mari ? 'f.husband IS NULL' : 'IDENTITY(f.husband) = :mari',
            null === $femme ? 'f.wife IS NULL' : 'IDENTITY(f.wife) = :femme',
        );

        $requete = $this->em->createQuery($dql)->setMaxResults(1);

        if (null !== $mari) {
            $requete->setParameter('mari', $mari->getId());
        }

        if (null !== $femme) {
            $requete->setParameter('femme', $femme->getId());
        }

        $famille = $requete->getOneOrNullResult();

        return $famille instanceof Family ? $famille : null;
    }

    private function dejaRattache(int $familleId, int $personneId): bool
    {
        $nombre = $this->em->createQuery(\sprintf(
            'SELECT COUNT(e.id) FROM %s e WHERE IDENTITY(e.family) = :famille AND IDENTITY(e.person) = :personne',
            Child::class,
        ))->setParameter('famille', $familleId)->setParameter('personne', $personneId)->getSingleScalarResult();

        return \is_numeric($nombre) && (int) $nombre > 0;
    }

    private function prochainRang(int $familleId): int
    {
        $maximum = $this->em->createQuery(\sprintf(
            'SELECT MAX(e.siblingOrder) FROM %s e WHERE IDENTITY(e.family) = :famille',
            Child::class,
        ))->setParameter('famille', $familleId)->getSingleScalarResult();

        return \is_numeric($maximum) ? (int) $maximum + 1 : 0;
    }

    // ------------------------------------------------------------------ conversions

    /**
     * L'identifiant GEDCOM d'une ligne créée dans l'application (§ 9.8).
     *
     * Le préfixe `9` la distingue au premier coup d'œil de la plage `@I5xxxxx@` de
     * MyHeritage, dans un fichier exporté : on sait ce qui vient de l'import d'origine et
     * ce qui a été ajouté depuis. Déterministe, sans séquence supplémentaire, et
     * reconstructible si la colonne est perdue.
     */
    private function xref(string $lettre, int $id): string
    {
        return \sprintf('@%s9%05d@', $lettre, $id);
    }

    private function texte(mixed $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur)) {
            throw new \InvalidArgumentException(\sprintf('Valeur texte attendue, %s reçu.', get_debug_type($valeur)));
        }

        return $valeur;
    }

    private function date(mixed $valeur, string $champ): UncertainDate
    {
        if (!$valeur instanceof UncertainDate) {
            // Jamais `null` : une date inconnue s'écrit `UncertainDate::inconnue()`, pas
            // par l'absence de valeur. C'est ce qui permet d'**effacer** une date qu'on
            // sait fausse, au lieu de ne pas pouvoir y toucher.
            throw new \InvalidArgumentException(\sprintf('Le champ « %s » attend un UncertainDate, %s reçu.', $champ, get_debug_type($valeur)));
        }

        return $valeur;
    }

    private function sexe(string $valeur): Sex
    {
        return Sex::tryFrom($valeur)
            ?? throw new \InvalidArgumentException(\sprintf('Sexe « %s » inconnu.', $valeur));
    }

    private function qualite(mixed $valeur): ?CitationQuality
    {
        if (null === $valeur) {
            return null;
        }

        // `QUAY` traverse en chaîne comme toute énumération au bord du port, mais il est
        // adossé à `int` — c'est l'exception assumée du § 2.7 du modèle, parce que la
        // colonne est un `SMALLINT` qui porte les valeurs 0 à 3 de la norme GEDCOM.
        if (!\is_string($valeur) && !\is_int($valeur)) {
            throw new \InvalidArgumentException(\sprintf('QUAY attendu en chaîne ou en entier, %s reçu.', get_debug_type($valeur)));
        }

        if (!is_numeric($valeur)) {
            throw new \InvalidArgumentException(\sprintf('QUAY « %s » n’est pas un entier.', (string) $valeur));
        }

        return CitationQuality::tryFrom((int) $valeur)
            ?? throw new \InvalidArgumentException(\sprintf('QUAY %d hors de la plage 0-3 de la norme.', (int) $valeur));
    }
}
