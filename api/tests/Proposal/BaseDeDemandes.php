<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Service\AnalyseDeDemande;
use App\Module\Proposal\Service\CalqueEnAttente;
use App\Module\Proposal\Service\DepotDeDemande;
use App\Module\Proposal\Service\LectureDesPropositions;
use App\Module\Proposal\Service\NormaliseurDeValeur;
use App\Module\Proposal\Service\RefaireDemande;
use App\Module\Proposal\Service\RefusDeDemande;
use App\Module\Proposal\Service\SerialiseurDeDemande;
use App\Module\Proposal\Service\SurfaceEditable;
use App\Module\Proposal\Service\ValidationDeDemande;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use App\Tests\Proposal\Double\NotificateurEnMemoire;
use App\Tests\ReinitialisationDuSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le socle des tests fonctionnels de `Proposal`.
 *
 * ── Pourquoi des tests de service et non des tests HTTP ──────────────────────────
 *
 * Ils sont fonctionnels au sens fort : **une vraie base** (SQLite, comme le reste de la
 * suite), les vraies entités, les vrais dépôts, les vrais services. Ce qu'ils
 * n'exercent pas, c'est la couche HTTP — et pour une raison qui n'est pas un choix :
 * `config/packages/security.yaml` est encore « volontairement incomplet », sans
 * fournisseur d'utilisateurs sur `Core\User` ni pare-feu. `loginUser()` y échoue au
 * rafraîchissement de la session, et ce fichier n'est pas dans le périmètre de cette
 * tâche.
 *
 * Ce n'est pas une perte pour la règle qui compte : le calque « en attente » **se
 * calcule à la sérialisation** (ADR 0004), pas dans le contrôleur. Le tester au niveau
 * du service le teste là où il vit. Ce qui reste à couvrir en HTTP quand la sécurité
 * sera posée : les codes de statut et l'enveloppe d'erreur — signalé dans le livrable.
 *
 * ── Le schéma et l'isolation ─────────────────────────────────────────────────────
 *
 * Les deux sont réglés **hors de ce module** et on ne les refait pas ici :
 * `tests/bootstrap.php` donne à chaque processus son propre fichier SQLite et son
 * propre pool de cache, et {@see ReinitialisationDuSchema::reinitialiser()} porte la recette
 * commune de remise à zéro. Ce fichier a porté un temps ses propres versions des deux ;
 * elles sont retirées, parce que trois recettes pour un même piège finissent par
 * diverger sur le seul cas où l'une d'elles avait raison.
 */
abstract class BaseDeDemandes extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected ArbreEnMemoire $arbre;
    protected NotificateurEnMemoire $notificateur;

    protected NormaliseurDeValeur $normaliseur;
    protected SurfaceEditable $surface;
    protected AnalyseDeDemande $analyse;
    protected DepotDeDemande $depot;
    protected ValidationDeDemande $validation;
    protected RefusDeDemande $refus;
    protected RefaireDemande $reprise;
    protected CalqueEnAttente $calque;
    protected SerialiseurDeDemande $serialiseur;
    protected LectureDesPropositions $propositions;
    protected ChangeRequestRepository $demandes;

    /** Les identifiants de comptes employés par tous les tests, pour qu'ils se lisent d'un coup d'œil. */
    protected const int OPALE = 11;
    protected const int TUF = 12;
    protected const int ADMIN = 1;

    /** La personne servant de cible commune : Marne Dolomite Sureau, `id = 17`, celle du § 9.6. */
    protected const int PERSONNE = 17;

    /** Le schéma complet n'est dressé qu'une fois par processus — voir {@see partirDUneBaseVide()}. */
    private static bool $schemaDresse = false;

    protected function setUp(): void
    {
        // Les doubles portent un état statique — ils tiennent le rôle des tables, qui
        // survivent à une requête. On le remet à zéro ici, et nulle part ailleurs.
        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();

        $this->demarrer(dresserLeSchema: true);
        $this->poserLaFicheDeReference();
    }

    /**
     * **Une seconde requête HTTP**, sur le même arbre et la même base.
     *
     * Ce n'est pas une commodité de test : c'est la seule façon fidèle d'éprouver le
     * conseil que donne `verrou_concurrent`, « réessayez ». Doctrine **ferme**
     * l'`EntityManager` quand une transaction échoue — `UnitOfWork::commit()` appelle
     * `close()` avant de relancer —, si bien qu'un réessai n'est possible que dans une
     * requête neuve. C'est ce qui se passe en production, où le front reposte ; c'est
     * donc ce qu'on simule, plutôt que de s'accommoder d'un gestionnaire moribond.
     *
     * L'arbre et les notifications, eux, sont **conservés** : ils tiennent le rôle des
     * tables `tree_*` et de `core_notification`, qui survivent évidemment à une requête.
     */
    protected function nouvelleRequete(): void
    {
        $this->demarrer(dresserLeSchema: false);
    }

    /**
     * Tout vient du **conteneur**, et c'est le correctif d'un défaut réel.
     *
     * Ce socle construisait autrefois ses services à la main, avec un tableau littéral
     * de constructeurs et d'applicateurs. Il ne testait donc pas le **mécanisme de
     * greffe** : il testait un câblage qui lui ressemblait. Deux conséquences, toutes
     * deux constatées :
     *
     * - un module qui se greffait pour de bon restait **invisible** des tests, si bien
     *   qu'un test annonçant « cette nature n'est pas branchée » restait vert des heures
     *   après qu'elle l'était ;
     * - un point de greffe que le validateur **n'appelait jamais** ne pouvait pas être
     *   détecté, puisque le harnais ne passait pas par lui.
     *
     * En lisant le conteneur, ces tests exercent les vrais tags, les vrais itérateurs et
     * les vrais alias. Une liste écrite à la main vieillit ; un conteneur, non.
     */
    private function demarrer(bool $dresserLeSchema): void
    {
        // `bootKernel()` éteint le noyau précédent : un second appel rend un conteneur
        // neuf, donc un `EntityManager` neuf, donc des dépôts qui ne pointent plus sur
        // celui qui a été fermé.
        self::bootKernel();

        $conteneur = self::getContainer();
        $this->em = $conteneur->get(EntityManagerInterface::class);

        if ($dresserLeSchema) {
            $this->partirDUneBaseVide();
        }

        // Les deux doubles sont les services que le conteneur injecte partout ailleurs,
        // déclarés en `when@test` dans `config/services/proposal.yaml` : ce que le test
        // inspecte est exactement ce que les services ont écrit.
        $this->arbre = $conteneur->get(ArbreEnMemoire::class);
        $this->notificateur = $conteneur->get(NotificateurEnMemoire::class);

        $this->normaliseur = $conteneur->get(NormaliseurDeValeur::class);
        $this->surface = $conteneur->get(SurfaceEditable::class);
        $this->analyse = $conteneur->get(AnalyseDeDemande::class);
        $this->depot = $conteneur->get(DepotDeDemande::class);
        $this->validation = $conteneur->get(ValidationDeDemande::class);
        $this->refus = $conteneur->get(RefusDeDemande::class);
        $this->reprise = $conteneur->get(RefaireDemande::class);
        $this->calque = $conteneur->get(CalqueEnAttente::class);
        $this->serialiseur = $conteneur->get(SerialiseurDeDemande::class);
        $this->propositions = $conteneur->get(LectureDesPropositions::class);
        $this->demandes = $conteneur->get(ChangeRequestRepository::class);
    }

    /**
     * Marne Dolomite Sureau telle que le § 9.6 du modèle la met en scène : `version = 7`,
     * naissance `1987` — celle qui la faisait naître après ses propres enfants, et que
     * les deux demandes du scénario viennent corriger.
     */
    protected function poserLaFicheDeReference(): void
    {
        $this->arbre->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', 7, [
            'givenNames' => 'Marne Dolomite',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => true,
            'birth' => $this->annee(1987),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);
    }

    protected function annee(int $annee): UncertainDate
    {
        return new UncertainDate(
            (string) $annee,
            DateQualifier::Annee,
            new \DateTimeImmutable($annee.'-01-01'),
            new \DateTimeImmutable($annee.'-12-31'),
        );
    }

    /**
     * Le bloc date **à cinq clés** d'une valeur d'op — jamais six : `texte` est en
     * lecture seule et sa présence en entrée est une erreur (§ 14.1).
     *
     * @return array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null}
     */
    protected function blocDate(UncertainDate $date): array
    {
        /** @var array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null} $bloc */
        $bloc = $this->normaliseur->versJson(\App\Module\Proposal\Enum\ValueKind::DateIncertaine, $date);

        return $bloc;
    }

    protected function lecteur(int $userId, bool $administrateur = false, bool $enAttente = false): Lecteur
    {
        return new Lecteur($userId, 'Compte '.$userId, $administrateur, $enAttente);
    }

    /**
     * Un op de champ texte, prêt à être posté.
     *
     * @return array<string, mixed>
     */
    protected function opTexte(string $field, ?string $ancienne, ?string $nouvelle, int $baseVersion = 7, int $seq = 1): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'champ',
            'subjectKind' => 'personne',
            'subjectId' => self::PERSONNE,
            'field' => $field,
            'valueKind' => 'texte',
            'oldValue' => $ancienne,
            'newValue' => $nouvelle,
            'baseVersion' => $baseVersion,
        ];
    }

    /**
     * Le corps d'une `modification_personne`.
     *
     * @param list<array<string, mixed>> $ops
     *
     * @return array<string, mixed>
     */
    protected function corpsDeModification(array $ops, ?string $message = null): array
    {
        return [
            'kind' => 'modification_personne',
            'message' => $message,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'ops' => $ops,
        ];
    }

    /**
     * Une base vide avant chaque cas — sans reconstruire les 23 tables 51 fois.
     *
     * {@see ReinitialisationDuSchema::reinitialiser()} reste la seule recette de construction, et
     * c'est elle qu'on appelle : elle supprime et recrée **tout** le schéma, ce qui est
     * juste mais coûte cher. Mesuré sur ce lot, l'appeler à chaque cas fait passer la
     * classe de 17 secondes à près de cinq minutes — et une suite qu'on n'a plus envie
     * de lancer est une suite qui ne tourne plus.
     *
     * On la paie donc **une fois par processus**, puis on vide les cinq tables `prp_*`.
     * Le drapeau statique reste juste même si un autre module reconstruit le schéma
     * entre-temps : `reinitialiser()` recrée toujours ce qu'elle supprime, si bien que
     * les tables sont là dans tous les cas — simplement vides, ce qui est exactement ce
     * qu'on veut.
     */
    private function partirDUneBaseVide(): void
    {
        if (!self::$schemaDresse) {
            ReinitialisationDuSchema::reinitialiser($this->em);
            self::$schemaDresse = true;
        }

        $connexion = $this->em->getConnection();

        // Filles d'abord : `prp_revision` et les tables de brouillon référencent
        // `prp_change_request`.
        foreach (['prp_revision', 'prp_draft_link', 'prp_draft_person', 'prp_change_op', 'prp_change_request'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
    }

    /** Recharge une demande depuis la base, pour ne jamais affirmer sur un objet resté en mémoire. */
    protected function recharger(ChangeRequest $demande): ChangeRequest
    {
        $id = $demande->getId();
        self::assertNotNull($id);

        $this->em->clear();
        $rechargee = $this->demandes->find($id);
        self::assertInstanceOf(ChangeRequest::class, $rechargee);

        return $rechargee;
    }
}
