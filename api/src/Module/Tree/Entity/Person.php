<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Repository\PersonRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une personne de l'arbre — 274 lignes après import (§ 3.1 du modèle de données).
 *
 * **Naissance et décès sont des colonnes, pas des `tree_fact`.** Ils sont lus à chaque
 * sérialisation, à chaque passage du moteur de placement (tri des fratries) et à chaque
 * barre de la vue chronologique : les faire passer par une table polymorphe coûterait
 * deux `LEFT JOIN` et un `DISTINCT ON` à chaque rendu, pour une pureté GEDCOM dont
 * personne ne tire rien.
 *
 * **Ce que cette entité ne porte pas, volontairement** : `vivant` (calculé à la
 * sérialisation, § 9.2 — aucune colonne ne le stocke, et ce n'est pas un oubli) ;
 * `parente` (relatif au lecteur, donc il y en aurait autant que de comptes) ; `unions`,
 * `parents`, `enfants`, `conjoints`, `fratrie` (projections de `tree_family` et
 * `tree_child`, les dupliquer garantirait la désynchronisation) ; `ligne_dates` (du
 * formatage, produit par {@see UncertainDate::format()}).
 */
#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Table(name: 'tree_person')]
#[ORM\UniqueConstraint(name: 'uniq_tree_person_xref', columns: ['gedcom_xref'])]
// `varchar_pattern_ops` est posé à la main dans la migration : sans cet opérateur, un
// index btree sur une base non-`C` — et la nôtre est en ICU fr-FR — ne sert pas les
// `LIKE 'BOURRACHE%'` préfixés.
//
// Il sert le `ORDER BY search_key` de la recherche de l'arbre (§ 5.5 du contrat) et les
// motifs préfixés. Il ne sert PAS le filtre de cette recherche, qui porte aussi une
// branche infixe `'% X%'` qu'aucun btree ne peut satisfaire — mesuré : `Seq Scan` même
// avec `enable_seqscan = off`. Et il ne sert pas non plus la recherche de RATTACHEMENT,
// qui ne lit jamais cette colonne (elle compare `surname`/`given_names` en PHP).
#[ORM\Index(name: 'idx_tree_person_search', columns: ['search_key'])]
#[ORM\Index(name: 'idx_tree_person_surname', columns: ['surname', 'given_names'])]
#[ORM\Index(name: 'idx_tree_person_birth', columns: ['birth_min'])]
// Le moteur de placement charge par branche puis par génération : c'est l'ordre dans
// lequel il pose les nœuds.
#[ORM\Index(name: 'idx_tree_person_placement', columns: ['branch', 'generation'])]
// Index partiel : le jeu de candidats du calcul « qui est vivant » (§ 9.2), 78 lignes sur
// 274. Il sert aussi l'audit de la règle de confidentialité, qui doit pouvoir énumérer
// les personnes à risque sans balayer la table.
#[ORM\Index(
    name: 'idx_tree_person_vivants',
    columns: ['id'],
    options: ['where' => '(deceased = false)'],
)]
#[ORM\Index(
    name: 'idx_tree_person_quay',
    columns: ['best_quay'],
    options: ['where' => '((best_quay = 0) OR (best_quay IS NULL))'],
)]
class Person
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * L'identifiant GEDCOM avec ses arobases : `@I500001@` pour une fiche venue du
     * fichier d'origine, `@I9xxxxx@` pour une fiche créée dans l'application (§ 9.8).
     * C'est l'identifiant **stable** d'une personne — unique, jamais réattribué, à la
     * différence de `id` qu'une restauration sur base neuve pourrait renuméroter.
     * `null` seulement entre la création applicative et le premier `flush` — le service
     * le remplit immédiatement. L'import GEDCOM qui s'appuyait dessus a été supprimé
     * (ADR 0017) ; la référence, elle, reste.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gedcomXref = null;

    /** Le `_UID` MyHeritage. Sans usage applicatif, mais c'est la seule clé qui survit à un aller-retour. */
    #[ORM\Column(length: 32, nullable: true, options: ['fixed' => true])]
    private ?string $gedcomUid = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $gedcomRin = null;

    /** Prénoms dans l'ordre d'état civil (`GIVN`). Vide pour les 7 personnes au prénom inconnu. */
    #[ORM\Column(length: 160, options: ['default' => ''])]
    private string $givenNames = '';

    /**
     * Nom de famille (`SURN`). Vide pour les 4 individus sans `NAME` : ce ne sont pas
     * des lignes corrompues, elles ouvrent chacune une branche de recherche.
     */
    #[ORM\Column(length: 120, options: ['default' => ''])]
    private string $surname = '';

    /** Nom d'épouse (`_MARNM`), 97 occurrences. `graphe.json` l'avait perdu : c'est de la vraie donnée. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $marriedName = null;

    /** Nom d'usage courant (`NICK`) — ce que `graphe.json` appelle `usage`. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $nickname = null;

    /**
     * Nom d'affichage composé, dénormalisé.
     *
     * **La seule colonne nominative que reçoit un compte hors cercle.** La liste
     * blanche complète pour une personne vivante hors cercle est `id`, `display_name`,
     * `sex`, `generation`, `branch` et la position du nœud avec ses arêtes (ADR 0003).
     */
    #[ORM\Column(length: 255)]
    private string $displayName;

    /**
     * `surname || ' ' || given_names || ' ' || nickname`, sans accents, en majuscules,
     * ponctuation retirée, **sans mot répété**.
     *
     * Sert la recherche de l'arbre (§ 5.5 du contrat) **sans extension PostgreSQL**
     * (§ 0.6) — et elle seule : la recherche de rattachement ne lit pas cette colonne.
     * Recalculée par {@see \App\Module\Core\Service\CleDeRecherche} à chaque écriture
     * des noms, aux trois sites d'écriture. Le surnom y entre parce qu'il pilote
     * `display_name` : sans lui, une fiche est affichée sous un nom qu'on ne peut pas
     * chercher. `married_name` y entre aussi, pour une raison voisine et non identique —
     * il n'est pas dans `display_name`, mais c'est très souvent sous lui qu'une famille
     * cherche une femme mariée, et 96 fiches sur 97 étaient introuvables ainsi.
     */
    #[ORM\Column(length: 200, options: ['default' => ''])]
    private string $searchKey = '';

    #[ORM\Column(length: 10, enumType: Sex::class, options: ['default' => 'inconnu'])]
    private Sex $sex = Sex::Inconnu;

    /**
     * Le `DEAT` du GEDCOM. 196 personnes le portent, mais seulement 171 ont une date :
     * `DEAT Y` sans `DATE` est un décès certain sans date. D'où ce booléen distinct du
     * bloc date — le décès est un fait, sa date est une information.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $deceased = false;

    /**
     * Verrou manuel de confidentialité : traiter la personne comme vivante quoi qu'en
     * dise la règle calculée.
     *
     * **Il n'existe pas de valeur inverse**, et c'est délibéré (§ 9.2) : un dérogateur
     * qui démasque est un dérogateur qui, un jour, démasquera par erreur ; un
     * dérogateur qui masque ne peut se tromper que dans le sens prudent.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $forcePrivacy = false;

    /**
     * Pourquoi `forcePrivacy` a été posé — obligatoire quand il l'est
     * (`ck_tree_person_privacy_motivee`). Le GEDCOM porte déjà des décisions de
     * caviardage « CONSIGNÉE POUR NE PAS ÊTRE REDÉFAITE » : on ne perd pas ce
     * raisonnement.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyNote = null;

    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'birth_')]
    private UncertainDate $birth;

    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'death_')]
    private UncertainDate $death;

    /**
     * **Colonne dérivée**, relative à `tree.personne_racine_id`, recalculée et persistée
     * par un **service de `Tree`** après toute écriture structurante.
     *
     * Ce n'est **pas** le moteur de placement qui l'écrit : l'ADR 0005 lui interdit tout
     * accès à la base, et c'est cette contrainte qui le rend testable contre
     * `graphe.json`. Le moteur la consomme en entrée. Aucune `ChangeRequest` ne la vise.
     */
    #[ORM\Column(length: 3, nullable: true, enumType: Branch::class)]
    private ?Branch $branch = null;

    /** Rang générationnel : 0 pour la racine, négatif en remontant (jusqu'à −11). Dérivée. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $generation = null;

    /**
     * Meilleur `QUAY` parmi les citations de la personne, 0 à 3, `null` si aucune.
     * Dérivée, recalculée par le service de citations. Sert le filtre « non vérifié »
     * sans agrégat à la volée.
     *
     * Typée `?int` et non `CitationQuality` : c'est le résultat d'un `MAX()`, et le
     * modèle ne l'adosse pas à l'énumération — voir le livrable.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $bestQuay = null;

    /** Nombre de citations. Dérivée. `0` = le « sans source » de `graphe.json`. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $citationCount = 0;

    /**
     * Verrou optimiste n° 1 : la concurrence **intra-transaction** (§ 9.6).
     *
     * Il ne voit rien passer entre une demande déposée lundi et validée vendredi —
     * l'entité est rechargée fraîche entre les deux. C'est `prp_change_op.base_version`
     * qui couvre ce cas-là, et les deux verrous sont nécessaires.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $displayName)
    {
        $this->displayName = $displayName;
        $this->birth = UncertainDate::inconnue();
        $this->death = UncertainDate::inconnue();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGedcomXref(): ?string
    {
        return $this->gedcomXref;
    }

    public function setGedcomXref(?string $gedcomXref): self
    {
        $this->gedcomXref = $gedcomXref;

        return $this;
    }

    public function getGedcomUid(): ?string
    {
        return $this->gedcomUid;
    }

    public function setGedcomUid(?string $gedcomUid): self
    {
        $this->gedcomUid = $gedcomUid;

        return $this;
    }

    public function getGedcomRin(): ?string
    {
        return $this->gedcomRin;
    }

    public function setGedcomRin(?string $gedcomRin): self
    {
        $this->gedcomRin = $gedcomRin;

        return $this;
    }

    public function getGivenNames(): string
    {
        return $this->givenNames;
    }

    public function setGivenNames(string $givenNames): self
    {
        $this->givenNames = $givenNames;

        return $this;
    }

    public function getSurname(): string
    {
        return $this->surname;
    }

    public function setSurname(string $surname): self
    {
        $this->surname = $surname;

        return $this;
    }

    public function getMarriedName(): ?string
    {
        return $this->marriedName;
    }

    public function setMarriedName(?string $marriedName): self
    {
        $this->marriedName = $marriedName;

        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): self
    {
        $this->nickname = $nickname;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getSearchKey(): string
    {
        return $this->searchKey;
    }

    public function setSearchKey(string $searchKey): self
    {
        $this->searchKey = $searchKey;

        return $this;
    }

    public function getSex(): Sex
    {
        return $this->sex;
    }

    public function setSex(Sex $sex): self
    {
        $this->sex = $sex;

        return $this;
    }

    public function isDeceased(): bool
    {
        return $this->deceased;
    }

    public function setDeceased(bool $deceased): self
    {
        $this->deceased = $deceased;

        return $this;
    }

    public function isForcePrivacy(): bool
    {
        return $this->forcePrivacy;
    }

    public function getPrivacyNote(): ?string
    {
        return $this->privacyNote;
    }

    /**
     * Le caviardage et son motif se posent ensemble : `ck_tree_person_privacy_motivee`
     * refuse en base un `force_privacy` sans note. On ne caviarde pas sans écrire
     * pourquoi.
     */
    public function forcerConfidentialite(string $motif): self
    {
        $this->forcePrivacy = true;
        $this->privacyNote = $motif;

        return $this;
    }

    public function leverConfidentialiteForcee(): self
    {
        $this->forcePrivacy = false;

        return $this;
    }

    public function getBirth(): UncertainDate
    {
        return $this->birth;
    }

    public function setBirth(UncertainDate $birth): self
    {
        $this->birth = $birth;

        return $this;
    }

    public function getDeath(): UncertainDate
    {
        return $this->death;
    }

    public function setDeath(UncertainDate $death): self
    {
        $this->death = $death;

        return $this;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function setBranch(?Branch $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    public function getGeneration(): ?int
    {
        return $this->generation;
    }

    public function setGeneration(?int $generation): self
    {
        $this->generation = $generation;

        return $this;
    }

    public function getBestQuay(): ?int
    {
        return $this->bestQuay;
    }

    public function setBestQuay(?int $bestQuay): self
    {
        $this->bestQuay = $bestQuay;

        return $this;
    }

    public function getCitationCount(): int
    {
        return $this->citationCount;
    }

    public function setCitationCount(int $citationCount): self
    {
        $this->citationCount = $citationCount;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toucher(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
