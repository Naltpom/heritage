<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\SourceKind;
use App\Module\Tree\Service\TreeFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * L'empreinte de l'arbre (§ 2.3 du modèle de données, ADR 0005).
 *
 * Deux propriétés à protéger, et elles tirent en sens opposés — c'est pour ça qu'elles
 * sont testées ensemble :
 *
 * - **elle bouge dès qu'une donnée structurante bouge**, sinon un cache périmé devient
 *   une fuite vers un compte qui n'aurait plus dû voir la fiche ;
 * - **elle ne bouge pas pour le reste**, sinon téléverser une photo recalculerait les
 *   377 nœuds et tous les cercles de visibilité pour un graphe identique.
 *
 * Ces tests tournent **en CI** : ils n'ont besoin d'aucune donnée réelle.
 */
final class EmpreinteDeLArbreTest extends TestCase
{
    private EntityManagerInterface $em;
    private TreeFingerprint $empreinte;

    protected function setUp(): void
    {
        $this->em = FabriqueDEntityManager::enMemoire();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->empreinte = $this->fabrique();
    }

    private function fabrique(): TreeFingerprint
    {
        return new TreeFingerprint($this->em);
    }

    /**
     * Elle se calcule sur SQLite. C'est le défaut que ce test existe pour empêcher de
     * revenir : la première version portait le `sha256(convert_to(string_agg(…)))` du
     * § 2.3, du PostgreSQL pur, et la route du graphe levait une `DBALException` avant
     * même d'atteindre le placement.
     */
    public function testElleSeCalculeSurSqliteEtRend64Hexadecimaux(): void
    {
        $valeur = $this->empreinte->empreinte();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $valeur);
    }

    /** Sur un arbre vide aussi : la route du graphe doit répondre avant tout import. */
    public function testElleTientSurUnArbreVide(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->empreinte->empreinte());
    }

    public function testDeuxCalculsSurLaMemeBaseDonnentLaMemeValeur(): void
    {
        $this->personne('Dupont');

        self::assertSame($this->fabrique()->empreinte(), $this->fabrique()->empreinte());
    }

    public function testElleChangeQuandUnePersonneChange(): void
    {
        $personne = $this->personne('Dupont');
        $avant = $this->fabrique()->empreinte();

        $personne->setSurname('Durand');
        $this->em->flush();

        self::assertNotSame($avant, $this->fabrique()->empreinte());
    }

    /** `generation` est le rang vertical du nœud : la déplacer déplace le dessin. */
    public function testElleChangeQuandUneGenerationChange(): void
    {
        $personne = $this->personne('Dupont');
        $avant = $this->fabrique()->empreinte();

        $personne->setGeneration(-4);
        $this->em->flush();

        self::assertNotSame($avant, $this->fabrique()->empreinte());
    }

    public function testElleChangeQuandUneFamilleApparait(): void
    {
        $mari = $this->personne('Dupont');
        $avant = $this->fabrique()->empreinte();

        $this->em->persist(new Family($mari, null));
        $this->em->flush();

        self::assertNotSame($avant, $this->fabrique()->empreinte());
    }

    /** La filiation est la topologie même : elle doit compter. */
    public function testElleChangeQuandUneFiliationApparait(): void
    {
        $parent = $this->personne('Dupont');
        $enfant = $this->personne('Dupont');
        $famille = new Family($parent, null);
        $this->em->persist($famille);
        $this->em->flush();

        $avant = $this->fabrique()->empreinte();

        $this->em->persist(new Child($famille, $enfant));
        $this->em->flush();

        self::assertNotSame($avant, $this->fabrique()->empreinte());
    }

    /**
     * **Le test qui garde le périmètre étroit.** Une citation, une source — et par le
     * même raisonnement un média — ne déplacent aucun nœud et ne modifient aucune
     * parenté. Les faire entrer dans l'empreinte serait le défaut symétrique de celui du
     * compteur : invalider **trop**.
     *
     * « Élargir l'empreinte par prudence » est le réflexe naturel de qui l'implémente.
     * C'est ici une erreur, et c'est ce test qui la rattrape.
     */
    public function testElleNeChangePasQuandUneCitationOuUneSourceApparait(): void
    {
        $personne = $this->personne('Dupont');
        $this->em->flush();

        $avant = $this->fabrique()->empreinte();

        $source = new Source('Registre paroissial', SourceKind::RegistreOfficiel);
        $this->em->persist($source);
        $this->em->persist(new Citation($source));
        $this->em->flush();

        self::assertSame(
            $avant,
            $this->fabrique()->empreinte(),
            'Une citation ne déplace aucun nœud : elle ne doit pas invalider le placement.',
        );
    }

    /** Mémoïsée pour la durée de la requête : trois appelants la demandent. */
    public function testElleEstMemoiseePourLaDureeDeLaRequete(): void
    {
        $personne = $this->personne('Dupont');
        $premiere = $this->empreinte->empreinte();

        $personne->setSurname('Durand');
        $this->em->flush();

        self::assertSame($premiere, $this->empreinte->empreinte(), 'La valeur mémorisée ne se recalcule pas seule.');

        $this->empreinte->oublie();

        self::assertNotSame($premiere, $this->empreinte->empreinte(), '`oublie()` doit la libérer.');
    }

    /**
     * L'`ETag` porte le lecteur, la clé de cache de l'arbre non (§ 9.9). Et il le porte
     * par **trois** composantes : le cercle effectif, les demandes en cours (§ 5.2), et
     * depuis que la mise en page est calculée par lecteur, son point de vue.
     *
     * La formule est **recopiée ici en toutes lettres** et comparée au résultat : c'est
     * le seul moyen qu'un remaniement de l'ordre des composantes — ou l'oubli d'un
     * séparateur — fasse rougir quelque chose plutôt que de changer silencieusement tous
     * les `ETag` servis.
     *
     * > Les composantes ne peuvent pas se confondre entre elles : celle du cercle est
     * > **toujours** un condensat hexadécimal de 64 caractères
     * > ({@see \App\Module\Tree\Serializer\CercleDuLecteur::empreinte()}), donc de
     * > longueur fixe et sans `:`. C'est ce qui rend la concaténation lisible dans un
     * > seul sens, et non le choix du séparateur.
     */
    public function testLEtagDistingueLeCercleLesDemandesLePointDeVueEtLaMiseEnPage(): void
    {
        $this->personne('Dupont');

        $neutre = $this->empreinte->etag('', '', '', 'image-a');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $neutre);
        self::assertNotSame($neutre, $this->empreinte->etag('', 'demande-12', '', 'image-a'), 'Déposer une demande doit changer l’`ETag` : sinon l’auteur ne voit jamais son propre nœud en attente.');
        self::assertNotSame($neutre, $this->empreinte->etag('cercle-du-membre', '', '', 'image-a'), 'Deux cercles différents doivent donner deux `ETag` différents (§ 5.2, § 17.5).');
        self::assertNotSame($neutre, $this->empreinte->etag('', '', 'p100', 'image-a'), 'Deux points de vue différents donnent deux mises en page, donc deux `ETag`.');
        self::assertNotSame($this->empreinte->etag('', '', 'p100', 'image-a'), $this->empreinte->etag('', '', 'p200', 'image-a'));

        // La cinquième composante, et la seule qui bouge quand c'est le CODE qui change :
        // sans elle, un déploiement qui change les règles de placement laisse chaque
        // navigateur revalider, recevoir `304`, et redessiner l'ancienne image.
        self::assertNotSame(
            $neutre,
            $this->empreinte->etag('', '', '', 'image-b'),
            'Une autre mise en page doit donner un autre `ETag`, à arbre et lecteur inchangés.',
        );

        self::assertSame($neutre, $this->empreinte->etag('', '', '', 'image-a'), 'À composantes égales, l’`ETag` est stable : c’est ce qui rend la revalidation possible.');

        self::assertSame(
            hash('sha256', $this->empreinte->empreinte().':cercle-du-membre:demande-12:p100:image-a'),
            $this->empreinte->etag('cercle-du-membre', 'demande-12', 'p100', 'image-a'),
            'La formule : sha256( empreinte || ":" || cercle || ":" || demandes || ":" || point de vue || ":" || mise en page ).',
        );
    }

    private function personne(string $nom): Person
    {
        $personne = new Person('Jean '.$nom);
        $personne->setSurname($nom);
        $personne->setGivenNames('Jean');
        $personne->setGeneration(0);
        $this->em->persist($personne);
        $this->em->flush();

        return $personne;
    }
}
