<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'empreinte de l'arbre — § 2.3 du modèle de données, ADR 0005.
 *
 * **Il n'existe ni table, ni colonne, ni compteur d'invalidation de cache.** Les clés de
 * cache du placement (§ 9.9) et du cercle de visibilité (§ 9.3) portent cette empreinte,
 * **recalculée depuis la base**. Le motif tient en une phrase : *une empreinte ne peut
 * pas se désynchroniser ; un compteur qu'on oublie d'incrémenter dans un chemin
 * d'écriture donne un graphe faux qu'on met des semaines à comprendre.*
 *
 * Ce projet a **cinq** chemins d'écriture, et c'est ce qui condamne le compteur : la
 * validation d'une `prp_change_request`, l'édition directe d'un administrateur (une
 * demande auto-validée, § 9.4), l'import GEDCOM, le changement de
 * `tree.personne_racine_id` — qui recalcule `branch` et `generation` — et toute
 * correction faite directement en base. Un compteur exigerait que les cinq pensent à
 * l'incrémenter, et que le sixième, le jour où il sera ajouté, y pense aussi. **Aucun
 * d'eux n'a besoin de penser à quoi que ce soit ici** : l'empreinte est dérivée de la
 * donnée, donc elle a déjà changé quand ils ont écrit.
 *
 * Le service vit dans `Tree` bien que la section soit rangée dans `Core`, parce que les
 * tables `tree_*` ne se lisent que par leur propriétaire (§ 0.4). `Membership` l'appelle
 * pour composer sa clé de cercle ; il ne lit pas `tree_person` lui-même.
 *
 * ## Le périmètre est étroit, et c'est délibéré
 *
 * **`tree_person`, `tree_family`, `tree_child`. Ces trois tables, et aucune autre.** Ni
 * `med_media`, ni `tree_citation`, ni `tree_source`, ni `tree_fact`, ni `tree_note` :
 * rien de tout cela ne déplace un nœud ni ne modifie une parenté. Les inclure serait le
 * défaut symétrique de celui du compteur — non plus invalider trop peu, mais **trop** :
 * téléverser une photo de famille recalculerait le placement des 377 nœuds **et** tous
 * les cercles de visibilité, pour un graphe rigoureusement identique.
 *
 * « Élargir l'empreinte par prudence » est le réflexe naturel de qui l'implémente ;
 * c'est ici une erreur, et l'ADR 0005 la nomme pour qu'elle ne se prenne pas pour de la
 * rigueur. Si vous vous apprêtez à ajouter une entité à {@see ENTITES_STRUCTURANTES}, la
 * question à trancher est : *cette écriture peut-elle déplacer un nœud ou changer une
 * parenté ?* Si non, elle n'a rien à y faire.
 *
 * ## Pourquoi le haché est calculé en PHP et non par la base
 *
 * La première version portait la requête du § 2.3 telle quelle —
 * `sha256(convert_to(string_agg(p::text, …)))`. **C'est du PostgreSQL pur**, et toute la
 * suite de tests tourne sur SQLite : la route du graphe levait une `DBALException` avant
 * même d'atteindre le placement.
 *
 * On aurait pu brancher sur le dialecte. On ne l'a pas fait, pour la raison qui a déjà
 * fait écarter le compteur : **deux implémentations qui doivent produire le même haché
 * finiront par diverger**, et la divergence ne se verrait qu'en service — un cache qui
 * ne s'invalide jamais, ou qui s'invalide toujours. Une seule implémentation ne peut pas
 * se désynchroniser d'elle-même.
 *
 * Le volume rend le choix gratuit : 274 + 103 + 174 = 551 lignes courtes.
 *
 * ## Ce qui rend le haché indépendant du moteur
 *
 * On ne hache pas les valeurs telles que le pilote les rend — elles diffèrent : un
 * booléen sort `'t'` de PostgreSQL et `1` de SQLite, un horodatage sort
 * `2026-09-15 18:24:05+00` d'un côté et `2026-09-15 18:24:05` de l'autre. On les
 * **canonicalise** d'abord, type par type ({@see canonique()}), et c'est cette forme
 * canonique qui est hachée.
 *
 * Les colonnes, elles, sont **découvertes dans les métadonnées Doctrine**, jamais
 * énumérées à la main : une colonne ajoutée demain entre dans l'empreinte sans que
 * personne y pense. C'est ce que garantissait `p::text`, et c'est ce qu'une liste écrite
 * à la main aurait perdu à la première migration.
 *
 * **Une condition à connaître pour l'égalité entre moteurs** : SQLite ne sait pas
 * stocker le décalage horaire (`getDateTimeTzFormatString()` y vaut `Y-m-d H:i:s`, contre
 * `Y-m-d H:i:sO` sur PostgreSQL). Les horodatages sans décalage sont donc lus **comme de
 * l'UTC**, ce qui est exact tant que l'application écrit en UTC — c'est le cas ici. Si
 * un jour elle écrivait en heure locale, les deux moteurs cesseraient de s'accorder sur
 * ces colonnes-là ; ce serait à corriger à l'écriture, pas ici.
 *
 * ## L'atomicité, qui était l'argument du compteur
 *
 * Elle est acquise autrement : l'empreinte est **dérivée de la donnée**. Tant que la
 * transaction n'est pas validée, les autres connexions lisent l'ancien état, donc
 * l'ancienne empreinte, donc l'ancien cache — qui est juste. Au `commit`, elles lisent le
 * nouvel état, donc une nouvelle clé. Il n'y a pas de fenêtre intermédiaire parce qu'il
 * n'y a rien à synchroniser. Si la transaction est annulée, il n'y a rien à annuler côté
 * cache.
 */
final class TreeFingerprint
{
    /**
     * Les trois tables structurantes. **Cette liste est volontairement écrite à la
     * main** : c'est la décision de périmètre de l'ADR 0005, et elle doit rester un acte
     * délibéré. Les *colonnes*, elles, sont découvertes — le contraire serait une dette.
     *
     * @var list<class-string>
     */
    private const array ENTITES_STRUCTURANTES = [Person::class, Family::class, Child::class];

    /** Sépare deux valeurs d'une même ligne. Hors de tout contenu textuel possible. */
    private const string SEPARATEUR_VALEUR = "\x1f";

    /** Sépare deux lignes. */
    private const string SEPARATEUR_LIGNE = "\x1e";

    /** Distingue « colonne nulle » de « chaîne vide ». */
    private const string MARQUE_NULL = "\x00";

    /**
     * Calculée **une fois par requête HTTP** et gardée pour sa durée : le placement, les
     * cercles de visibilité et l'`ETag` la demandent tous les trois.
     */
    private ?string $memoire = null;

    /**
     * Seul l'`EntityManager` est demandé, et la connexion en est tirée : les prendre
     * tous les deux laisserait passer un couple mal assorti — des métadonnées décrivant
     * une base, une connexion pointant sur une autre — qui rendrait une empreinte
     * plausible et fausse.
     */
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    private function connexion(): Connection
    {
        return $this->em->getConnection();
    }

    /** 64 caractères hexadécimaux, utilisables tels quels en segment de clé de cache. */
    public function empreinte(): string
    {
        return $this->memoire ??= hash('sha256', $this->projectionCanonique());
    }

    /**
     * La forme canonique de l'arbre structurant, avant hachage.
     *
     * Exposée pour que les tests puissent comparer deux moteurs sur la **projection**
     * plutôt que sur le haché : quand les deux diffèrent, un haché ne dit pas où.
     */
    public function projectionCanonique(): string
    {
        $morceaux = [];

        foreach (self::ENTITES_STRUCTURANTES as $entite) {
            $morceaux[] = $this->projectionDe($entite);
        }

        return implode(self::SEPARATEUR_LIGNE, $morceaux);
    }

    /**
     * L'`ETag` servi au client — **les trois composantes du § 5.2 du contrat d'API** :
     *
     * ```
     * ETag = sha256( empreinte_de_l_arbre
     *        || ':' || empreinte_du_cercle_effectif_du_lecteur
     *        || ':' || empreinte_des_demandes_du_lecteur
     *        || ':' || point_de_vue_du_lecteur
     *        || ':' || empreinte_de_la_mise_en_page )
     * ```
     *
     * **Les deux paramètres sont obligatoires, et c'est la correction elle-même.** La
     * signature précédente portait une seule composante, avec une valeur par défaut :
     * un appelant qui n'y pensait pas obtenait un `ETag` recevable, identique pour tous
     * les lecteurs, et rien ne tombait. C'est ainsi que les trois comptes réels ont reçu
     * des charges de 124 412, 124 092 et 122 954 octets sous **un seul** `ETag` — et que
     * le compte en attente, présentant celui de l'administrateur, recevait `304`.
     *
     * Sans défaut, un appelant qui oublie le cercle ne compile plus. *Une fuite de
     * confidentialité qu'un paramètre par défaut rend silencieuse doit devenir une erreur
     * bruyante ; c'est la seule forme de garde qui survive à une relecture distraite.*
     *
     * **Les cinq sont donc obligatoires**, et les deux dernières le sont devenues pour la
     * même raison qu'un cas réel a rendue concrète : le point de vue et la mise en page
     * portaient un défaut, et le défaut rendait l'`ETag` recevable et faux. La leçon vaut
     * pour la sixième composante que quelqu'un ajoutera un jour.
     *
     * @param string $empreinteDeLaMiseEnPage {@see EmpreinteDeLaMiseEnPage}
     * @param string $pointDeVue              `''` quand le lecteur n'est rattaché à aucune personne
     *
     * Ce qui entre dans chaque composante, et pourquoi, est écrit là où elle se calcule :
     * {@see \App\Module\Tree\Serializer\EtagDuGraphe}
     * @param string $empreinteDuCercleEffectif {@see \App\Module\Tree\Serializer\CercleDuLecteur::empreinte()}
     * @param string $empreinteDesDemandes      `''` quand le lecteur n'a aucune demande en cours
     */
    public function etag(
        string $empreinteDuCercleEffectif,
        string $empreinteDesDemandes,
        string $pointDeVue,
        string $empreinteDeLaMiseEnPage,
    ): string {
        return hash('sha256', implode(':', [
            $this->empreinte(),
            $empreinteDuCercleEffectif,
            $empreinteDesDemandes,
            $pointDeVue,
            $empreinteDeLaMiseEnPage,
        ]));
    }

    /**
     * À n'appeler qu'après une écriture structurante **dans le même processus** — une
     * commande d'import, typiquement, qui recalcule et resert dans la foulée.
     *
     * Ce n'est pas une invalidation de cache : il n'y en a pas à faire. C'est seulement
     * l'oubli de la valeur mémorisée pour la requête en cours.
     */
    public function oublie(): void
    {
        $this->memoire = null;
    }

    /** @param class-string $entite */
    private function projectionDe(string $entite): string
    {
        $colonnes = $this->colonnesDe($entite);
        $table = $this->em->getClassMetadata($entite)->getTableName();

        $selection = implode(', ', array_map(
            fn (string $colonne): string => $this->connexion()->quoteSingleIdentifier($colonne),
            array_keys($colonnes),
        ));

        // `ORDER BY id` : sans lui, le moteur est libre de son ordre et l'empreinte
        // changerait au gré des `VACUUM` pour des données identiques.
        $lignes = $this->connexion()->fetchAllAssociative(\sprintf(
            'SELECT %s FROM %s ORDER BY %s ASC',
            $selection,
            $this->connexion()->quoteSingleIdentifier($table),
            $this->connexion()->quoteSingleIdentifier('id'),
        ));

        $rendu = [$table];

        foreach ($lignes as $ligne) {
            $valeurs = [];

            foreach ($colonnes as $colonne => $type) {
                $valeurs[] = $this->canonique($ligne[$colonne] ?? null, $type);
            }

            $rendu[] = implode(self::SEPARATEUR_VALEUR, $valeurs);
        }

        return implode(self::SEPARATEUR_LIGNE, $rendu);
    }

    /**
     * Les colonnes d'une entité et leur type Doctrine, **triées par nom de colonne**.
     *
     * Triées, et non dans l'ordre de déclaration : l'ordre des propriétés d'une classe
     * change au gré des relectures, et l'empreinte ne doit pas bouger parce que
     * quelqu'un a déplacé un accesseur.
     *
     * Les clés étrangères (`husband_id`, `family_id`…) sont incluses : ce sont **elles**
     * qui portent la parenté, donc la topologie du graphe.
     *
     * @param class-string $entite
     *
     * @return array<string, string> colonne → type Doctrine
     */
    private function colonnesDe(string $entite): array
    {
        $meta = $this->em->getClassMetadata($entite);
        $colonnes = [];

        foreach ($meta->getFieldNames() as $champ) {
            $colonnes[$meta->getColumnName($champ)] = (string) $meta->getTypeOfField($champ);
        }

        foreach ($meta->getAssociationMappings() as $association) {
            foreach ($association['joinColumns'] ?? [] as $jointure) {
                if (\is_array($jointure) && \is_string($jointure['name'] ?? null)) {
                    $colonnes[$jointure['name']] = Types::INTEGER;
                }
            }
        }

        ksort($colonnes, \SORT_STRING);

        return $colonnes;
    }

    /**
     * La forme canonique d'une valeur, indépendante du pilote et du moteur.
     *
     * Seuls trois types divergent réellement d'un moteur à l'autre ; les autres sont des
     * nombres et des chaînes, que tous rendent pareil.
     */
    private function canonique(mixed $valeur, string $type): string
    {
        if (null === $valeur) {
            return self::MARQUE_NULL;
        }

        return match ($type) {
            // PostgreSQL rend `'t'` / `'f'` (ou un booléen selon le pilote), SQLite `1` / `0`.
            Types::BOOLEAN => $this->booleen($valeur) ? '1' : '0',

            // Une date nue n'a pas de fuseau : on la **reformate sans jamais la
            // convertir**. La convertir en UTC décalerait `1901-01-01` au 31 décembre
            // 1889 dès que le fuseau du processus est à l'est de Greenwich.
            Types::DATE_MUTABLE, Types::DATE_IMMUTABLE => $this->horodatage($valeur, 'Y-m-d', false),

            // Un instant, lui, se compare en UTC. Voir la note sur SQLite en tête de classe.
            Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE,
            Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE,
            Types::TIME_MUTABLE, Types::TIME_IMMUTABLE => $this->horodatage($valeur, 'Y-m-d\TH:i:s', true),

            default => \is_scalar($valeur) ? (string) $valeur : self::MARQUE_NULL,
        };
    }

    private function booleen(mixed $valeur): bool
    {
        if (\is_bool($valeur)) {
            return $valeur;
        }

        return \in_array(strtolower(trim((string) (\is_scalar($valeur) ? $valeur : ''))), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }

    private function horodatage(mixed $valeur, string $format, bool $versUtc): string
    {
        try {
            // Le fuseau passé en second argument ne s'applique **que** si la chaîne n'en
            // porte pas : c'est ce qui rend l'horodatage sans décalage de SQLite
            // comparable à celui, daté, de PostgreSQL.
            $instant = $valeur instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($valeur)
                : new \DateTimeImmutable((string) (\is_scalar($valeur) ? $valeur : 'now'), new \DateTimeZone('UTC'));
        } catch (\Exception) {
            // Une valeur illisible reste hachée telle quelle : mieux vaut une empreinte
            // qui bouge qu'une exception sur le chemin de lecture du graphe.
            return \is_scalar($valeur) ? (string) $valeur : self::MARQUE_NULL;
        }

        if ($versUtc) {
            $instant = $instant->setTimezone(new \DateTimeZone('UTC'));
        }

        return $instant->format($format);
    }
}
