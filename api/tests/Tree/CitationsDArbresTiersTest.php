<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\User;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Enum\SourceKind;
use App\Module\Tree\Serializer\AppuiDUneCitation;
use App\Module\Tree\Service\AdressesDArbresEnLigne;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LES CITATIONS D'ARBRES EN LIGNE — marquées, et leurs adresses retirées du rendu.
 *
 * ## Les deux demandes du commanditaire
 *
 * > « MyHeritage, retire tous les liens vers ces URL, ça sert à rien — ou du moins ne les
 * > affiche pas aux utilisateurs, garde-les en DB uniquement pour l'IA. »
 *
 * Et voir d'un coup d'œil, sur ses 483 citations, lesquelles sont tenues par un acte.
 * Environ deux sur cinq ne le sont pas.
 *
 * ## Ce que ce fichier prouve, et pourquoi il passe par HTTP
 *
 * Qu'une adresse d'arbre en ligne **n'atteint pas la charge utile**, et qu'une citation
 * d'arbre tiers **arrive marquée**. Les deux se vérifient de bout en bout, à travers la
 * vraie route, parce que la leçon de ce projet est qu'une règle écrite dans un service et
 * non branchée reste verte : `AdressesDArbresEnLigne::nettoyer()` testée seule prouverait
 * que la fonction découpe, jamais que le normaliseur l'appelle.
 *
 * Les cas unitaires — ce qui est un arbre en ligne, comment le texte se recoud — sont en
 * bas de fichier, sur les deux services pris isolément. L'essentiel est en haut.
 */
final class CitationsDArbresTiersTest extends ArbreWebTestCase
{
    /** Une cote d'archives dans le même champ que l'adresse : elle doit survivre. */
    private const string PAGE_MELANGEE = 'Sorbière, 5 E 341/7, vue 1/6 — relevé sur https://www.myheritage.fr/research/record-1234';

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LE TEST DU LOT** : l'adresse ne sort pas, la cote reste.
     *
     * C'est le cas exact du fonds : `tree_citation.page` porte 39 adresses **mélangées aux
     * cotes**. Retirer le champ entier emporterait la seule chose qui permette de
     * retrouver l'acte ; le laisser afficherait le lien qu'on veut cacher.
     */
    public function testLAdresseDArbreNeSortPasEtLaCoteReste(): void
    {
        $id = $this->poserUneCitation(SourceKind::ArbreTiers, self::PAGE_MELANGEE, CitationQuality::RecopieNonVerifiee);

        $citation = $this->citationDeLaFiche($id);

        self::assertStringNotContainsString('myheritage', (string) $citation['page'], 'L\'adresse d\'arbre en ligne ne doit pas atteindre l\'écran.');
        self::assertStringContainsString('5 E 341/7, vue 1/6', (string) $citation['page'], 'La cote d\'archives est la seule chose de ce champ qui retrouve l\'acte.');
        self::assertStringNotContainsString('http', (string) $citation['page']);
    }

    /**
     * **La base garde tout** — « garde-les en DB uniquement pour l'IA ».
     *
     * Le masquage est une décision de **rendu**. Une colonne réécrite serait réécrite pour
     * tout le monde, y compris pour la machine qui s'en sert de piste, et un `UPDATE` ne se
     * défait pas. C'est l'assertion qui distingue ce lot d'un `UPDATE … SET page = …`.
     */
    public function testLaBaseGardeLAdresseIntacte(): void
    {
        $id = $this->poserUneCitation(SourceKind::ArbreTiers, self::PAGE_MELANGEE, CitationQuality::RecopieNonVerifiee);

        $this->citationDeLaFiche($id);
        $this->em()->clear();

        $enBase = $this->em()->getRepository(Citation::class)->find($id);
        self::assertInstanceOf(Citation::class, $enBase);
        self::assertSame(self::PAGE_MELANGEE, $enBase->getPage(), 'Rien n\'est effacé : le masquage vit à la sérialisation.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **L'ADRESSE D'UNE VISIONNEUSE D'ARCHIVES RESTE**, et c'est tout l'objet de la liste
     * fermée.
     *
     * Masquer « toute adresse » aurait été plus simple et aurait emporté les six liens
     * d'archives du fonds — exactement ce que le travail en cours **ajoute**. La règle
     * vise les domaines d'arbres, pas le protocole `http`.
     */
    public function testLAdresseDUneVisionneuseDArchivesResteAffichee(): void
    {
        $id = $this->poserUneCitation(
            SourceKind::ActeEtatCivil,
            'Point d\'entrée : https://archives.dordogne.fr/archives-numerisees/genealogie',
            CitationQuality::ActeOuRegistre,
        );

        $citation = $this->citationDeLaFiche($id);

        self::assertStringContainsString('archives.dordogne.fr', (string) $citation['page']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LE MARQUAGE** : trois niveaux, et le plus bas des deux colonnes l'emporte.
     *
     * **Aucune citation de l'arbre n'est aujourd'hui dans ce cas** — mesuré : les 194
     * citations d'arbres en ligne sont toutes à `QUAY 0`, l'import les a bien cotées. La
     * règle n'est donc pas là pour réparer un état existant mais pour **rendre cet état
     * impossible à afficher** : `quay` redevient modifiable dans ce même lot, et il suffira
     * d'une correction maladroite pour coter `2` une recopie. Le fonds l'emporte alors, et
     * la fiche ne peut pas paraître mieux tenue qu'elle ne l'est.
     */
    public function testUneCitationDArbreCoteeActeResteMarqueeCommeArbre(): void
    {
        $id = $this->poserUneCitation(SourceKind::ArbreTiers, 'Fiche de X', CitationQuality::ActeOuRegistre);

        $citation = $this->citationDeLaFiche($id);

        self::assertSame('arbre', $citation['appui'], 'Le fonds l\'emporte : un arbre en ligne coté « acte » est une contradiction.');
        self::assertSame('tenu par un arbre en ligne', $citation['appuiLabel']);
        self::assertSame(2, $citation['quay'], 'Le QUAY déclaré est rendu tel quel : on ne réécrit pas ce que le contributeur a posé.');
        self::assertSame('Arbre d\'un autre utilisateur', $citation['source']['sourceKindLabel']);
    }

    /** L'inverse est légitime : citer un acte sans l'avoir vérifié reste une recopie. */
    public function testUnActeCiteSansVerificationResteUneRecopie(): void
    {
        $id = $this->poserUneCitation(SourceKind::ActeEtatCivil, '5 E 341/7', CitationQuality::RecopieNonVerifiee);

        self::assertSame('arbre', $this->citationDeLaFiche($id)['appui'], 'L\'usage l\'emporte aussi : le plus bas des deux, dans les deux sens.');
    }

    /** Le niveau intermédiaire existe, et c'est la nuance que le commanditaire a validée. */
    public function testUnIndexEstUnNiveauAPart(): void
    {
        $id = $this->poserUneCitation(SourceKind::ActeEtatCivil, 'Table décennale 1883-1892', CitationQuality::IndexOuDeduction);

        $citation = $this->citationDeLaFiche($id);

        self::assertSame('index', $citation['appui']);
        self::assertSame('tenu par un index ou un relevé', $citation['appuiLabel']);
    }

    public function testUnActeVerifieEstTenuParUnActe(): void
    {
        $id = $this->poserUneCitation(SourceKind::ActeEtatCivil, '5 E 341/7, vue 1/6', CitationQuality::ActeOuRegistre);

        self::assertSame('acte', $this->citationDeLaFiche($id)['appui']);
    }

    /**
     * **Une citation sans `QUAY` vaut « arbre en ligne »**, et c'est le défaut fermé.
     *
     * Elle n'a jamais été évaluée. La faire passer pour un acte parce que personne n'a rien
     * dit serait exactement l'inverse de ce que ce marquage sert à montrer.
     */
    public function testUneCitationSansQuayNeVautPasUnActe(): void
    {
        $id = $this->poserUneCitation(SourceKind::ActeEtatCivil, 'Sans QUAY', null);

        self::assertSame('arbre', $this->citationDeLaFiche($id)['appui']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **`tree_note.body` N'EST JAMAIS TOUCHÉ** — ni par le masquage, ni par rien d'autre.
     *
     * Le masquage est borné à `tree_citation.page`, `tree_citation.data_text` et
     * `tree_citation.url`. Les notes en sont dehors, et c'est **délibéré, pas un oubli** :
     *
     * - une note de recherche porte le **raisonnement** derrière une correction, et
     *   « trouvé sur MyHeritage, mais l'acte dit l'inverse » est exactement la phrase qu'il
     *   faut pouvoir relire. Y découper l'adresse rendrait la note incompréhensible tout en
     *   la laissant paraître entière ;
     * - un travail en cours **dépose des adresses de visionneuses d'archives dans les
     *   notes**. Les masquer effacerait à l'affichage ce que quelqu'un est en train
     *   d'écrire, sans que rien ne le signale.
     *
     * Ce test gèle la frontière. Sans lui, un futur passage qui étendrait le nettoyage
     * « par cohérence » ne rencontrerait aucune résistance — et c'est le genre d'extension
     * qui paraît juste au moment où on l'écrit.
     */
    public function testUneAdresseDansUneNoteDeFicheNEstJamaisMasquee(): void
    {
        $archives = 'Point d’entrée : https://archives.dordogne.fr/archives-numerisees/genealogie';
        $arbre = 'Vu sur https://www.myheritage.fr/research/record-77, mais l’acte dit l’inverse.';

        $em = $this->em();
        $em->persist((new Note($archives, NoteScope::Generale))->setPerson($this->personnes['aieule']));
        $em->persist((new Note($arbre, NoteScope::Naissance))->setPerson($this->personnes['aieule']));
        $em->flush();

        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));

        /** @var list<array<string, mixed>> $notes */
        $notes = $this->charge()['notes'];
        $corps = array_map(static fn (array $note): string => (string) $note['body'], $notes);

        self::assertContains($archives, $corps, 'Une adresse de visionneuse dans une note reste : c’est le travail en cours.');
        self::assertContains($arbre, $corps, 'Une adresse d’arbre dans une note reste aussi : la note porte un raisonnement, pas une source.');
    }
    // ────────────────────────────────────────── les deux services, isolément

    /** La liste est un suffixe de nom d'hôte : les sous-domaines y passent. */
    public function testLesSousDomainesSontReconnus(): void
    {
        $adresses = new AdressesDArbresEnLigne();

        self::assertTrue($adresses->estUnArbreEnLigne('https://www.myheritage.fr/research/x'));
        self::assertTrue($adresses->estUnArbreEnLigne('https://sites-cf.mhcache.com/site-123/famille'), 'Le cache de sites MyHeritage héberge les « sites web de famille ».');
        self::assertTrue($adresses->estUnArbreEnLigne('http://gw.geneanet.org/untel'));
        self::assertFalse($adresses->estUnArbreEnLigne('https://recherche.archives.finistere.fr/ark:/72506/1286407'));
    }

    /**
     * **`monheritage.fr` n'est pas `myheritage.fr`.**
     *
     * Le point exigé devant le suffixe est ce qui l'empêche : un `str_ends_with` nu
     * attraperait tout domaine finissant par ces lettres, et masquerait le site d'un
     * cousin qui aurait eu la mauvaise idée de le nommer ainsi.
     */
    public function testUnDomaineQuiFinitParLesMemesLettresNEstPasAttrape(): void
    {
        self::assertFalse((new AdressesDArbresEnLigne())->estUnArbreEnLigne('https://monheritage.fr/arbre'));
    }

    /** Un champ qui ne portait que l'adresse rend `null`, pas une chaîne vide (§ 1.3). */
    public function testUnChampReduitALAdresseRendNull(): void
    {
        self::assertNull((new AdressesDArbresEnLigne())->nettoyer('https://www.myheritage.fr/x'));
    }

    /** Le texte est recousu : pas de ponctuation orpheline là où l'adresse était. */
    public function testLeTexteEstRecousuApresLaDecoupe(): void
    {
        $propre = (new AdressesDArbresEnLigne())->nettoyer('Relevé sur https://www.myheritage.fr/x — vue 3');

        self::assertSame('Relevé sur — vue 3', $propre);
        self::assertStringNotContainsString('  ', (string) $propre, 'Deux espaces trahiraient la découpe.');
    }

    /** Un texte sans adresse ressort **à la lettre près**, sans passer par la couture. */
    public function testUnTexteSansAdresseNEstPasTouche(): void
    {
        $intact = 'Sorbière, 5 E 341/7, vue 1/6 — table décennale ; relevé partiel,';

        self::assertSame($intact, (new AdressesDArbresEnLigne())->nettoyer($intact));
    }

    /** Le niveau est le plus bas des deux, jamais une moyenne. */
    public function testLeNiveauEstToujoursLePlusBasDesDeux(): void
    {
        $appui = new AppuiDUneCitation();

        self::assertSame('arbre', $appui->niveau(SourceKind::ArbreTiers, CitationQuality::PreuveDirecte));
        self::assertSame('arbre', $appui->niveau(SourceKind::ActeEtatCivil, CitationQuality::RecopieNonVerifiee));
        self::assertSame('index', $appui->niveau(SourceKind::Recensement, CitationQuality::PreuveDirecte));
        self::assertSame('index', $appui->niveau(SourceKind::ActeEtatCivil, CitationQuality::IndexOuDeduction));
        self::assertSame('acte', $appui->niveau(SourceKind::RegistreOfficiel, CitationQuality::ActeOuRegistre));
        self::assertTrue($appui->estUnArbreEnLigne(SourceKind::ArbreTiers, CitationQuality::ActeOuRegistre));
    }

    /**
     * **`autre` ne vaut pas `arbre`**, et c'est prudent dans le sens qui coûte.
     *
     * Ce classement couvre aussi bien un site familial qu'un acte mal rangé à l'import.
     * Le rabattre d'office marquerait comme recopie des fiches qui tiennent. C'est
     * `heritage:reclasser-les-arbres-tiers` qui range les sites familiaux, **sur preuve de
     * leur adresse** plutôt que sur une devinette.
     */
    public function testUnFondsAutreNEstPasRabattuDOffice(): void
    {
        self::assertSame('acte', (new AppuiDUneCitation())->niveau(SourceKind::Autre, CitationQuality::ActeOuRegistre));
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **UN RELEVÉ INDEXÉ N'EST PAS UN ARBRE**, même publié sous le même domaine.
     *
     * C'est le piège de ce fonds, et il ne se voit qu'en comptant. MyHeritage publie sous
     * `myheritage.fr` **deux choses sans rapport** :
     *
     * | chemin | nombre | ce que c'est |
     * |---|---:|---|
     * | `/research/collection-…` | **53** | recensement de 1921, de 1931, index des décès |
     * | `/profile-…` et `mhcache` | 49 | des fiches dans l'arbre de quelqu'un |
     *
     * Le plus gros groupe **n'est pas un arbre**. Un recensement nominatif vaut ce que vaut
     * un index — quelqu'un avait le registre sous les yeux et a recopié — et le classer
     * `arbre_tiers` écrirait une fausseté en base, en faisant tomber d'un cran la confiance
     * de citations qui ne le méritent pas.
     *
     * Les deux questions divergent donc, et c'est délibéré : **on masque un lien sur ce
     * qu'il coûte à l'écran, on classe une source sur ce qu'elle est.**
     */
    public function testUnReleveIndexeNEstPasLaPreuveDUnArbre(): void
    {
        $adresses = new AdressesDArbresEnLigne();

        $releve = 'https://www.myheritage.fr/research/collection-20396/recensement-francais-de-1931?s=1667972';
        $fiche = 'https://www.myheritage.fr/profile-OYYV6N5E7DX372EV6UXKCZFKNGNK72I-1000222/quelquun';

        // Les deux sont masqués : le commanditaire a demandé de retirer les liens
        // MyHeritage, sans distinguer, et un lien vers une collection payante n'est pas
        // plus utile au lecteur que l'arbre d'un inconnu.
        self::assertTrue($adresses->estUnArbreEnLigne($releve));
        self::assertTrue($adresses->estUnArbreEnLigne($fiche));

        // Un seul des deux prouve qu'on tient un arbre.
        self::assertFalse($adresses->designeUneFicheDArbre($releve), 'Un recensement n’est pas l’arbre de quelqu’un.');
        self::assertTrue($adresses->designeUneFicheDArbre($fiche));
    }

    /** Un site de famille hébergé par MyHeritage reste la preuve d'un arbre. */
    public function testUnSiteDeFamilleResteLaPreuveDUnArbre(): void
    {
        self::assertTrue(
            (new AdressesDArbresEnLigne())->designeUneFicheDArbre('https://sites-cf.mhcache.com/site-42/notre-famille'),
        );
    }
    // ────────────────────────────────────────────────────────────── le gréement

    /**
     * Pose une source et une citation sur l'aïeule — une personne **décédée**, donc dont
     * la fiche sort entière quel que soit le cercle du lecteur.
     */
    private function poserUneCitation(SourceKind $fonds, string $page, ?CitationQuality $quay): int
    {
        $em = $this->em();

        $source = new Source('Registre de test', $fonds);
        $em->persist($source);

        $citation = (new Citation($source, NoteScope::Naissance))
            ->setPerson($this->personnes['aieule'])
            ->setPage($page)
            ->setQuay($quay);

        $em->persist($citation);
        $em->flush();

        $id = $citation->getId();
        self::assertNotNull($id);

        return $id;
    }

    /**
     * La connexion de test — même recette que {@see RoutesDeLArbreTest}, qui la porte
     * en privé. Recopiée plutôt que remontée dans le socle : trois lignes, et remonter
     * une méthode dans une classe partagée par sept tests pour deux appelants coûterait
     * plus cher à relire qu'à écrire.
     */
    private function connexionDe(string $courriel): void
    {
        if (null === $this->em()->getRepository(User::class)->findOneBy(['email' => $courriel])) {
            $this->creerUtilisateur($courriel);
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }

    /**
     * La citation telle que la **route** la rend — pas telle qu'un service la calcule.
     *
     * @return array<string, mixed>
     */
    private function citationDeLaFiche(int $citationId): array
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));

        /** @var list<array<string, mixed>> $citations */
        $citations = $this->charge()['citations'];

        foreach ($citations as $citation) {
            if (($citation['id'] ?? null) === $citationId) {
                return $citation;
            }
        }

        self::fail(\sprintf('La citation %d n\'est pas dans la charge utile de la fiche.', $citationId));
    }
}
