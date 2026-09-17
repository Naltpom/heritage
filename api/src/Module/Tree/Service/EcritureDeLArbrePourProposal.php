<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Contract\CitationAcreer;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Contract\NoteAcreer;
use App\Module\Proposal\Contract\SourceCitable;
use App\Module\Proposal\Contract\VocabulaireDAnnotation;
use App\Module\Proposal\Service\Port\EcritureAppliquee;
use App\Module\Proposal\Service\Port\EtatCible;
use App\Module\Proposal\Service\Port\LigneCreee;
use App\Module\Proposal\Service\Port\PersonneAcreer;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\NoteScope;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

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

    public function chargerSource(int $id): ?SourceCitable
    {
        $source = $this->em->find(Source::class, $id);

        // `null` et non une exception : l'appelant valide un formulaire, et un
        // identifiant de source faux est une faute de saisie, pas une panne. Même forme
        // que `chargerCible()`, pour la même raison.
        return $source instanceof Source ? new SourceCitable($id, $source->getTitle()) : null;
    }

    public function vocabulaireDAnnotation(): VocabulaireDAnnotation
    {
        // Construits depuis les deux énumérations, jamais recopiés : une valeur ajoutée
        // à `NoteScope` ou à `CitationQuality` devient proposable sans qu'aucun autre
        // fichier ne bouge, et une valeur retirée cesse de l'être. C'est le seul endroit
        // du projet qui a le droit de les nommer.
        return new VocabulaireDAnnotation(
            array_map(static fn (NoteScope $portee): string => $portee->value, NoteScope::cases()),
            array_map(static fn (CitationQuality $quay): int => $quay->value, CitationQuality::cases()),
        );
    }

    public function creerCitation(CitationAcreer $citation): LigneCreee
    {
        $personne = $this->em->find(Person::class, $citation->personneId);
        $source = $this->em->find(Source::class, $citation->sourceId);

        // **On lève, on ne rend pas une ligne vide.** Les deux absences ont déjà été
        // écartées en amont — la personne par `AnalyseDeDemande`, qui en fait un
        // `cible_disparue`, la source par le constructeur de la demande — et arriver ici
        // sur l'une d'elles signifie qu'une ligne a disparu **pendant** la validation.
        // Continuer en silence marquerait l'op appliquée et la demande validée sans
        // qu'aucune citation n'existe : c'est le défaut exact que l'en-tête de
        // `Proposal\Contract\ApplicateurDOperation` raconte, et on ne le rejoue pas.
        if (!$personne instanceof Person) {
            throw new \RuntimeException(\sprintf('La personne %d a disparu pendant la validation : aucune citation ne peut y être rattachée.', $citation->personneId));
        }

        if (!$source instanceof Source) {
            throw new \RuntimeException(\sprintf('La source %d a disparu pendant la validation : une citation sans source ne retrouve rien.', $citation->sourceId));
        }

        $ligne = new Citation($source, $this->portee($citation->scope));
        $ligne
            ->setPerson($personne)
            ->setPage($citation->page)
            ->setQuay($this->qualite($citation->quay))
            ->setDataText($citation->dataText);

        $this->em->persist($ligne);
        $this->em->flush();

        $id = $ligne->getId();
        \assert(null !== $id);

        // **Après le `flush`, jamais avant** — même raison qu'à `appliquerChamps()` : les
        // agrégats se relisent par une requête, et la citation doit déjà être en base
        // pour y être comptée. Avant, `citation_count` resterait à sa valeur d'hier et la
        // fiche afficherait « aucune source » alors qu'elle vient d'en recevoir une.
        $this->rafraichirLesAgregats($personne);
        $this->em->flush();

        return new LigneCreee($id, $source->getTitle());
    }

    public function creerNote(NoteAcreer $note): LigneCreee
    {
        $personne = $this->em->find(Person::class, $note->personneId);

        if (!$personne instanceof Person) {
            // Même refus que `creerCitation()` : une ligne disparue pendant la validation
            // fait échouer bruyamment plutôt que d'écrire à côté ou de ne rien écrire.
            throw new \RuntimeException(\sprintf('La personne %d a disparu pendant la validation : aucune note ne peut y être rattachée.', $note->personneId));
        }

        $ligne = new Note($note->corps, $this->portee($note->scope));
        $ligne
            ->setPerson($personne)
            // L'auteur de la **demande**, pas celui qui valide : c'est lui que la fiche
            // affichera sous la note, et `null` y voudrait dire « importée du GEDCOM ».
            ->setAuthor($note->auteurUserId, $note->auteurLabel);

        $this->em->persist($ligne);
        $this->em->flush();

        $id = $ligne->getId();
        \assert(null !== $id);

        // **Aucun agrégat, et aucun second `flush`.** `tree_note` n'alimente ni
        // `best_quay` ni `citation_count` — une note documente, elle n'étaye pas. Les
        // rafraîchir ici ferait passer une fiche pour sourcée parce qu'on a écrit une
        // phrase à son sujet, ce qui est l'inverse exact de ce que le § 9.7 cherche.
        return new LigneCreee($id, $this->libelleDeNote($ligne));
    }

    public function inventaireDeSuppression(int $personneId): ?InventaireDeSuppression
    {
        $personne = $this->em->find(Person::class, $personneId);

        if (!$personne instanceof Person) {
            // `null` et non une exception : une fiche déjà supprimée n'est pas une panne,
            // c'est le résultat recherché obtenu par quelqu'un d'autre. L'appelant en fait
            // un `cible_disparue`, qui est une caducité.
            return null;
        }

        return $this->mesurer($personne);
    }

    public function supprimerPersonne(int $personneId): InventaireDeSuppression
    {
        $personne = $this->em->find(Person::class, $personneId);

        if (!$personne instanceof Person) {
            // Même refus que `creerNote()` : une ligne disparue **pendant** la validation
            // fait échouer bruyamment. Ici, rendre un inventaire vide ferait croire à une
            // suppression réussie qui n'aurait rien supprimé.
            throw new \RuntimeException(\sprintf('La personne %d a disparu pendant la validation : il n’y a plus rien à supprimer.', $personneId));
        }

        $inventaire = $this->mesurer($personne);

        if ([] !== $inventaire->descendants) {
            // Le dernier filet. `AnalyseDeDemande` a déjà rendu la demande caduque dans ce
            // cas, et arriver ici signifie qu'un chemin l'a contourné. On lève plutôt que
            // d'effacer : détacher une branche est précisément ce que tout ce circuit
            // existe pour empêcher, et l'annulation de la transaction est le seul recours
            // qui ne laisse rien derrière.
            throw new \RuntimeException(\sprintf('La personne %d est le parent de %d fiche(s) : la supprimer les détacherait de l’arbre.', $personneId, \count($inventaire->descendants)));
        }

        $faits = $this->identifiantsDesFaits($personneId);
        $famillesEffacees = $this->famillesEffacees($inventaire);

        // ── L'ordre est celui des clés étrangères, des feuilles vers la racine ────────
        //
        // Les quatre `ON DELETE CASCADE` du schéma feraient ce travail en base. On ne s'y
        // fie pas, pour deux raisons : SQLite n'applique ses clés étrangères que si
        // `PRAGMA foreign_keys` est armé — ce que la suite de tests ne garantit pas —, et
        // surtout l'unité de travail de Doctrine ne saurait pas que ces lignes sont
        // parties. Une entité encore en mémoire ressortirait d'un `find()` ultérieur comme
        // si elle existait, et le compte journalisé serait faux.
        $this->effacerLesAnnexes(Note::class, $personneId, $faits, $famillesEffacees);
        $this->effacerLesAnnexes(Citation::class, $personneId, $faits, $famillesEffacees);
        $this->effacerLesFaits($personneId, $famillesEffacees);
        $this->effacerLesFiliations($personneId, $famillesEffacees);
        $this->reglerLesUnions($personne, $famillesEffacees);

        $this->em->remove($personne);
        $this->em->flush();

        // **Après le `flush`, et jamais avant.** `RecalculDesColonnesDerivees` relit
        // l'arbre par des requêtes : tant que la personne est là, il recalcule les
        // générations d'un graphe qui la contient encore. Le symptôme serait une branche
        // restée au rang qu'elle tenait par la fiche effacée — visible seulement à
        // l'écran, et seulement par quelqu'un qui connaît l'arbre. C'est le même piège,
        // dans le même ordre, que `rattacherEnfant()`.
        $this->derivees->recalculer();
        $this->em->flush();

        return $inventaire;
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

    /**
     * Le libellé d'une note dans l'historique : sa **première ligne**, tronquée.
     *
     * `prp_revision.subject_label` est du `VARCHAR(255)` et se relit longtemps, alors
     * qu'une note de recherche fait parfois plusieurs paragraphes. On prend la première
     * ligne parce que c'est là que la note dit de quoi elle parle, et on marque la coupe
     * plutôt que de laisser croire que la note s'arrête là.
     */
    private function libelleDeNote(Note $note): string
    {
        // Ni `strtok`, qui porte un état global d'un appel à l'autre, ni un découpage sur
        // « \n » seul : l'import recolle les `CONT` du GEDCOM, et un corps saisi au
        // navigateur arrive en « \r\n ». Les deux formes doivent donner la même première
        // ligne, sinon le même texte produirait deux libellés selon sa provenance.
        $lignes = preg_split('/\R/u', $note->getBody(), 2) ?: [];
        $premiere = trim((string) ($lignes[0] ?? ''));

        if ('' === $premiere) {
            return 'Note n° '.(int) $note->getId();
        }

        return mb_strlen($premiere) > 120 ? mb_substr($premiere, 0, 119).'…' : $premiere;
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

    // ------------------------------------------------- inventaire d'une suppression

    /**
     * Ce qu'une suppression emporterait, mesuré avant toute écriture.
     *
     * ## Pourquoi les unions se lisent **avant** les notes et les citations
     *
     * Parce qu'une union dont la personne est le seul époux **part avec elle** —
     * `ck_tree_family_au_moins_un_epoux` interdit une famille sans personne — et qu'elle
     * emporte à son tour ses propres notes, citations et faits, par les
     * `ON DELETE CASCADE` de `family_id`. Compter les seules notes de la personne
     * annoncerait donc un nombre inférieur à ce qui disparaîtra, et c'est exactement
     * l'inverse de ce que l'inventaire promet.
     *
     * ## Le second cas où une union part, et qui ne se devine pas
     *
     * `uniq_tree_family_couple` porte `NULLS NOT DISTINCT` : deux familles `(NULL, W)` ne
     * peuvent pas coexister. Retirer le mari d'une famille `(X, W)` alors qu'une famille
     * `(NULL, W)` existe déjà violerait l'index — au moment de la validation, dans la
     * transaction, sous la forme d'une `500` que personne n'aurait vue venir. On efface
     * donc la ligne plutôt que de la vider, et l'inventaire l'annonce.
     */
    private function mesurer(Person $personne): InventaireDeSuppression
    {
        $id = $personne->getId();
        \assert(null !== $id);

        $unions = [];
        $descendants = [];
        $famillesEffacees = [];

        foreach ($this->famillesDe($id) as $famille) {
            $familleId = $famille->getId();
            \assert(null !== $familleId);

            $enfants = $this->enfantsDeLaFamille($familleId);
            $effacee = $this->familleDisparaitraitSans($famille, $personne);

            $unions[] = [
                'familleId' => $familleId,
                'libelle' => $this->libelleDuCouple($famille),
                'enfants' => \count($enfants),
                'supprimee' => $effacee,
            ];

            if ($effacee) {
                $famillesEffacees[] = $familleId;
            }

            foreach ($enfants as $enfant) {
                $descendants[] = $enfant;
            }
        }

        $faits = $this->identifiantsDesFaits($id);

        return new InventaireDeSuppression(
            $id,
            $personne->getDisplayName(),
            $this->compterLesAnnexes(Note::class, $id, $faits, $famillesEffacees),
            $this->compterLesAnnexes(Citation::class, $id, $faits, $famillesEffacees),
            \count($faits) + $this->compterLesFaitsDesFamilles($famillesEffacees),
            $this->compterLesFiliations($id),
            $unions,
            $descendants,
            // Le parcours n'est lancé **que** s'il y a un enfant direct : sans descendance,
            // le total vaut zéro et trois requêtes par génération seraient dépensées pour
            // l'apprendre. C'est le cas courant — la fiche vide d'un doublon.
            [] === $descendants ? 0 : $this->compterLaDescendance($id),
        );
    }

    /**
     * Le nombre de fiches qui descendent de celle-ci, **toutes générations confondues**.
     *
     * ## Pourquoi ce nombre existe alors qu'il ne décide de rien
     *
     * Le blocage tient à `descendants` — la présence d'un seul enfant direct suffit, et
     * c'est volontairement le critère le plus simple possible : faire dépendre une règle de
     * sûreté d'un parcours de graphe, c'est la faire dépendre de sa terminaison.
     *
     * Ce total-ci ne sert qu'à **écrire la phrase qui arrête la main**. « Cette fiche est
     * le parent de Cobalt Sureau » est exact et ne pèse rien ; « 23 fiches descendent
     * d'elle » pèse. C'est la différence entre les deux moitiés du doublon réel : l'une
     * porte six générations, l'autre est vide, et c'est ce nombre-là qui les distingue d'un
     * coup d'œil.
     *
     * ## Le parcours
     *
     * En largeur, une génération par tour, et deux requêtes par tour plutôt qu'une requête
     * par personne. L'ensemble `$vus` garantit la terminaison : une généalogie n'a pas de
     * cycle, mais un import fautif peut en fabriquer un, et une boucle infinie dans une
     * lecture d'écran serait une panne plus coûteuse que le nombre qu'elle calcule.
     */
    private function compterLaDescendance(int $personneId): int
    {
        $vus = [$personneId => true];
        $generation = [$personneId];
        $total = 0;

        while ([] !== $generation) {
            $suivante = [];

            foreach ($this->enfantsDeCesParents($generation) as $enfant) {
                if (isset($vus[$enfant])) {
                    continue;
                }

                $vus[$enfant] = true;
                $suivante[] = $enfant;
                ++$total;
            }

            $generation = $suivante;
        }

        return $total;
    }

    /**
     * Les enfants de toute une génération, en deux requêtes.
     *
     * @param list<int> $parents
     *
     * @return list<int>
     */
    private function enfantsDeCesParents(array $parents): array
    {
        /** @var list<array{id: int}> $familles */
        $familles = $this->em->createQuery(\sprintf(
            'SELECT f.id FROM %s f WHERE IDENTITY(f.husband) IN (:parents) OR IDENTITY(f.wife) IN (:parents)',
            Family::class,
        ))->setParameter('parents', $parents)->getArrayResult();

        if ([] === $familles) {
            return [];
        }

        /** @var list<array{enfant: int}> $lignes */
        $lignes = $this->em->createQuery(\sprintf(
            'SELECT DISTINCT IDENTITY(c.person) AS enfant FROM %s c WHERE IDENTITY(c.family) IN (:familles)',
            Child::class,
        ))
            ->setParameter('familles', array_map(static fn (array $f): int => (int) $f['id'], $familles))
            ->getArrayResult();

        return array_map(static fn (array $ligne): int => (int) $ligne['enfant'], $lignes);
    }

    /**
     * Les unions que l'inventaire a marquées comme disparaissant.
     *
     * Relues depuis l'inventaire plutôt que recalculées : c'est ce qui garantit
     * qu'**exactement** ce qui a été annoncé est effacé. Deux calculs séparés
     * divergeraient un jour, et ce jour-là l'écran aurait annoncé une union et la
     * transaction en aurait emporté deux.
     *
     * @return list<int>
     */
    private function famillesEffacees(InventaireDeSuppression $inventaire): array
    {
        $identifiants = [];

        foreach ($inventaire->unions as $union) {
            if ($union['supprimee']) {
                $identifiants[] = $union['familleId'];
            }
        }

        return $identifiants;
    }

    /** @return list<Family> */
    private function famillesDe(int $personneId): array
    {
        /** @var list<Family> $familles */
        $familles = $this->em->createQuery(\sprintf(
            'SELECT f FROM %s f WHERE IDENTITY(f.husband) = :personne OR IDENTITY(f.wife) = :personne ORDER BY f.id ASC',
            Family::class,
        ))->setParameter('personne', $personneId)->getResult();

        return $familles;
    }

    /**
     * Les enfants rattachés à une union — **c'est le blocage de la règle 1**.
     *
     * Ils sont rendus nommés et non comptés : « cette fiche est le parent de Cobalt
     * Sureau » dit à l'auteur laquelle des deux fiches du doublon il s'apprête à effacer,
     * là où « 6 descendants » le laisse deviner.
     *
     * @return list<array{personneId: int, displayName: string}>
     */
    private function enfantsDeLaFamille(int $familleId): array
    {
        /** @var list<array{id: int, displayName: string}> $lignes */
        $lignes = $this->em->createQuery(\sprintf(
            'SELECT p.id, p.displayName FROM %s c JOIN c.person p WHERE IDENTITY(c.family) = :famille ORDER BY c.siblingOrder ASC, p.id ASC',
            Child::class,
        ))->setParameter('famille', $familleId)->getArrayResult();

        return array_map(
            static fn (array $ligne): array => ['personneId' => (int) $ligne['id'], 'displayName' => (string) $ligne['displayName']],
            $lignes,
        );
    }

    /**
     * L'union disparaîtrait-elle si cette personne en sortait ?
     *
     * Deux cas, et un seul se devine : le conjoint absent, et la collision avec une union
     * déjà existante — voir l'en-tête de {@see mesurer()}.
     */
    private function familleDisparaitraitSans(Family $famille, Person $personne): bool
    {
        $mari = $famille->getHusband() === $personne ? null : $famille->getHusband();
        $femme = $famille->getWife() === $personne ? null : $famille->getWife();

        if (null === $mari && null === $femme) {
            return true;
        }

        $jumelle = $this->familleDuCouple($mari, $femme);

        return null !== $jumelle && $jumelle !== $famille;
    }

    /** @return list<int> */
    private function identifiantsDesFaits(int $personneId): array
    {
        /** @var list<array{id: int}> $lignes */
        $lignes = $this->em->createQuery(\sprintf(
            'SELECT f.id FROM %s f WHERE IDENTITY(f.person) = :personne',
            Fact::class,
        ))->setParameter('personne', $personneId)->getArrayResult();

        return array_map(static fn (array $ligne): int => (int) $ligne['id'], $lignes);
    }

    /**
     * Les notes — ou les citations — qui partiraient : celles de la personne, celles de
     * ses faits, celles des unions qui disparaissent avec elle.
     *
     * @param class-string<Citation|Note> $entite
     * @param list<int>                   $faits
     * @param list<int>                   $famillesEffacees
     */
    private function compterLesAnnexes(string $entite, int $personneId, array $faits, array $famillesEffacees): int
    {
        return (int) $this->requeteDAnnexes('SELECT COUNT(x.id) FROM %s x WHERE %s', $entite, $personneId, $faits, $famillesEffacees)
            ->getSingleScalarResult();
    }

    /**
     * ── Pourquoi on charge les lignes pour les effacer, plutôt qu'un `DELETE` de masse ──
     *
     * Un `DELETE` en DQL contourne l'unité de travail : les lignes partent en base, et les
     * entités correspondantes **restent gérées en mémoire**. Au `flush` suivant — celui du
     * validateur, qui persiste les révisions —, Doctrine retrouve alors une `Note` gérée
     * pointant vers une `Person` qu'il vient de supprimer, la prend pour une entité neuve,
     * et refuse : « A new entity was found through the relationship ». La validation entière
     * échoue, après la suppression, dans une transaction qu'il faut annuler.
     *
     * Le volume autorise le chargement sans arrière-pensée : une fiche porte des notes et
     * des citations en unités, jamais en milliers. Le jour où ce ne serait plus vrai, c'est
     * le `flush` du validateur qu'il faudrait revoir, pas cette boucle.
     *
     * @param class-string<Citation|Note> $entite
     * @param list<int>                   $faits
     * @param list<int>                   $famillesEffacees
     */
    private function effacerLesAnnexes(string $entite, int $personneId, array $faits, array $famillesEffacees): void
    {
        /** @var list<Citation|Note> $lignes */
        $lignes = $this->requeteDAnnexes('SELECT x FROM %s x WHERE %s', $entite, $personneId, $faits, $famillesEffacees)
            ->getResult();

        foreach ($lignes as $ligne) {
            $this->em->remove($ligne);
        }
    }

    /**
     * La requête partagée par le compte et par l'effacement — **une seule condition**.
     *
     * Deux formulations qui divergeraient feraient annoncer un nombre et en effacer un
     * autre, ce qui est le mode de défaillance que l'inventaire entier existe pour
     * interdire. Une liste vide devient `[0]` : `IN ()` n'est pas du SQL valide, et
     * aucune ligne ne porte l'identifiant zéro.
     *
     * @param class-string<Citation|Note> $entite
     * @param list<int>                   $faits
     * @param list<int>                   $famillesEffacees
     */
    private function requeteDAnnexes(string $gabarit, string $entite, int $personneId, array $faits, array $famillesEffacees): Query
    {
        return $this->em
            ->createQuery(\sprintf(
                $gabarit,
                $entite,
                'IDENTITY(x.person) = :personne OR IDENTITY(x.fact) IN (:faits) OR IDENTITY(x.family) IN (:familles)',
            ))
            ->setParameter('personne', $personneId)
            ->setParameter('faits', [] === $faits ? [0] : $faits)
            ->setParameter('familles', [] === $famillesEffacees ? [0] : $famillesEffacees);
    }

    /** @param list<int> $famillesEffacees */
    private function compterLesFaitsDesFamilles(array $famillesEffacees): int
    {
        if ([] === $famillesEffacees) {
            return 0;
        }

        return (int) $this->em->createQuery(\sprintf(
            'SELECT COUNT(f.id) FROM %s f WHERE IDENTITY(f.family) IN (:familles)',
            Fact::class,
        ))->setParameter('familles', $famillesEffacees)->getSingleScalarResult();
    }

    /** Les `tree_child` où la personne est **l'enfant** : sa propre filiation. */
    private function compterLesFiliations(int $personneId): int
    {
        return (int) $this->em->createQuery(\sprintf(
            'SELECT COUNT(c.id) FROM %s c WHERE IDENTITY(c.person) = :personne',
            Child::class,
        ))->setParameter('personne', $personneId)->getSingleScalarResult();
    }

    // ------------------------------------------------- effacement d'une personne

    /** @param list<int> $famillesEffacees */
    private function effacerLesFaits(int $personneId, array $famillesEffacees): void
    {
        /** @var list<Fact> $faits */
        $faits = $this->em->createQuery(\sprintf(
            'SELECT f FROM %s f WHERE IDENTITY(f.person) = :personne OR IDENTITY(f.family) IN (:familles)',
            Fact::class,
        ))
            ->setParameter('personne', $personneId)
            ->setParameter('familles', [] === $famillesEffacees ? [0] : $famillesEffacees)
            ->getResult();

        foreach ($faits as $fait) {
            $this->em->remove($fait);
        }
    }

    /**
     * Les filiations qui partent : celle de la personne comme enfant, et celles portées
     * par les unions qui disparaissent.
     *
     * Les secondes sont **vides par construction** — une union avec enfants bloque la
     * suppression bien avant d'arriver ici. La clause est là quand même, parce qu'un
     * effacement qui dépend d'un invariant sans le nommer cassera le jour où l'invariant
     * changera, et `tree_child.family_id NOT NULL` le ferait échouer en base, tard et
     * sans explication.
     *
     * @param list<int> $famillesEffacees
     */
    private function effacerLesFiliations(int $personneId, array $famillesEffacees): void
    {
        /** @var list<Child> $filiations */
        $filiations = $this->em->createQuery(\sprintf(
            'SELECT c FROM %s c WHERE IDENTITY(c.person) = :personne OR IDENTITY(c.family) IN (:familles)',
            Child::class,
        ))
            ->setParameter('personne', $personneId)
            ->setParameter('familles', [] === $famillesEffacees ? [0] : $famillesEffacees)
            ->getResult();

        foreach ($filiations as $filiation) {
            $this->em->remove($filiation);
        }
    }

    /**
     * Les unions : effacées quand elles ne survivraient pas, vidées de leur époux sinon.
     *
     * `setHusband(null)` plutôt que d'attendre le `ON DELETE SET NULL` de la base : le
     * `SET NULL` ferait le travail en SQL, mais l'entité `Family` resterait en mémoire
     * avec son mari, et `#[ORM\Version]` ne verrait pas l'écriture. L'union garderait
     * alors le numéro de version d'avant, ce qui rendrait indétectable le conflit sur la
     * prochaine demande qui la vise.
     *
     * @param list<int> $famillesEffacees
     */
    private function reglerLesUnions(Person $personne, array $famillesEffacees): void
    {
        $id = $personne->getId();
        \assert(null !== $id);

        foreach ($this->famillesDe($id) as $famille) {
            if (\in_array($famille->getId(), $famillesEffacees, true)) {
                $this->em->remove($famille);

                continue;
            }

            if ($famille->getHusband() === $personne) {
                $famille->setHusband(null)->toucher();
            }

            if ($famille->getWife() === $personne) {
                $famille->setWife(null)->toucher();
            }
        }
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

    /**
     * La portée d'une citation — « cette source étaye la *naissance* » (§ 3.9).
     *
     * Elle traverse le port en chaîne, comme toute énumération : `NoteScope` appartient
     * à `Tree`, et c'est ici, au seul endroit qui a le droit de la nommer, que la chaîne
     * redevient un cas. Une valeur hors énumération lève — laisser retomber sur
     * `Generale` transformerait une faute de saisie en citation mal classée, que
     * personne ne retrouverait sous la portée qu'il a demandée.
     */
    private function portee(string $valeur): NoteScope
    {
        return NoteScope::tryFrom($valeur)
            ?? throw new \InvalidArgumentException(\sprintf('Portée de citation « %s » inconnue.', $valeur));
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
