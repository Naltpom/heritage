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
     * **`monheritage.fr` n'est pas `myheritage.fr`.**.
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

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LA COUTURE SUR UN TEXTE MULTI-LIGNES** — la ligne que 1 077 tests verts
     * n'auraient pas sauvée.
     *
     * Le cas monoligne était couvert ; celui-ci ne l'était pas, et c'est lui qui exerce le
     * second `preg_replace()` de `recoudre()` sur ce pour quoi il existe : un séparateur
     * resté **en tête d'une ligne suivante**, après qu'une adresse a été coupée en fin de
     * la précédente.
     *
     * ## Ce que ce test n'aurait pas attrapé, et ce qu'il faut savoir
     *
     * Ce défaut n'était pas une absence de couverture : le motif **était** exécuté par le
     * cas monoligne. Il était exécuté sur une **PCRE qui pardonne**. La forme d'abord
     * écrite — `(?<=^|\R)` — est une assertion arrière dont les branches n'ont pas la même
     * longueur ; PCRE2 ne l'accepte que depuis la 10.43. La machine de développement porte
     * la 10.44 et compilait ; l'intégration continue porte une version antérieure et
     * refusait. `phpstan` était vert des deux côtés — ce n'est pas l'analyseur qui diffère,
     * c'est la bibliothèque.
     *
     * Aucun test PHP ne peut donc garantir ce point sur une seule machine. Ce qui le
     * garantit est {@see testTousLesMotifsDuLotSeCompilent()} : un motif se fait
     * **compiler**, et une compilation refusée y devient un échec nommé plutôt qu'un
     * `null` silencieux.
     */
    public function testLaCoutureFonctionneSurUnTexteMultiLignes(): void
    {
        $adresses = new AdressesDArbresEnLigne();

        $propre = $adresses->nettoyer("Sorbière, 5 E 341/7\nRelevé sur https://www.myheritage.fr/x\n— vue 3");

        // L'espace que l'adresse laissait derrière elle **reste**, en fin de ligne, et
        // c'est assumé : elle est invisible à l'écran, et la retirer demanderait un
        // troisième motif dont le seul effet serait cosmétique. Ce qui compte est que
        // l'adresse soit partie et que le tiret orphelin de la ligne suivante soit recousu.
        self::assertSame("Sorbière, 5 E 341/7\nRelevé sur \nvue 3", $propre);
        self::assertStringNotContainsString('myheritage', (string) $propre);
        self::assertStringNotContainsString('— vue', (string) $propre, 'Le tiret orphelin en tête de ligne est recousu.');
        self::assertSame(3, substr_count((string) $propre, "\n") + 1, 'Les trois lignes survivent : on recoud, on ne recolle pas.');
    }

    /** Les fins de ligne Windows passent la couture comme les autres. */
    public function testLaCoutureSupporteLesFinsDeLigneWindows(): void
    {
        $propre = (new AdressesDArbresEnLigne())->nettoyer("Acte\r\n; https://www.myheritage.fr/x\r\nsuite");

        self::assertStringNotContainsString('myheritage', (string) $propre);
        self::assertStringNotContainsString('; ', (string) $propre);
        self::assertStringContainsString('suite', (string) $propre);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **CHAQUE MOTIF DU LOT SE COMPILE** — et c'est le seul contrôle qui vaille.
     *
     * Un motif se fait **compiler**, pas seulement analyser. `phpstan` lit la chaîne ;
     * PCRE la compile, et les deux ne répondent pas à la même question. Un motif que la
     * bibliothèque refuse fait rendre `null` à `preg_replace()` — et comme tout ce lot
     * écrit `?? $texte` derrière, le texte ressortirait **sans sa couture**, sans erreur,
     * sur la ligne même qui retire les adresses.
     *
     * Ce test compile les motifs sur la machine qui l'exécute. Il ne prouve donc rien
     * d'une autre machine — c'est précisément pour cela qu'il **affiche la version de
     * PCRE en cas d'échec** : le jour où l'intégration continue en refusera un, le
     * message dira laquelle des deux bibliothèques diverge, plutôt que de laisser
     * chercher.
     */
    public function testTousLesMotifsDuLotSeCompilent(): void
    {
        $motifs = [
            'adresse dans du texte' => '~https?://[^\s<>"\']+~i',
            'séparateur en fin de ligne' => '~[ \t]*[—–\-:;,]+[ \t]*(?=$|\R)~u',
            'séparateur en tête de ligne' => '~^[ \t]*[—–\-:;,]+[ \t]*~mu',
            'espaces doublés' => '~[ \t]{2,}~',
            'parenthèse restée vide' => '~\(\s*\)~',
        ];

        foreach ($motifs as $role => $motif) {
            $erreur = null;
            set_error_handler(static function (int $niveau, string $message) use (&$erreur): bool {
                $erreur = $message;

                return true;
            });

            $resultat = preg_match($motif, 'essai');

            restore_error_handler();

            self::assertNotFalse(
                $resultat,
                \sprintf('Le motif « %s » ne se compile pas sur PCRE %s : %s', $role, \PCRE_VERSION, (string) $erreur),
            );
            self::assertNull($erreur, \sprintf('Le motif « %s » compile en avertissant sur PCRE %s : %s', $role, \PCRE_VERSION, (string) $erreur));
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **CE QUE LE MOTIF RETENU PRODUIT**, figé sur les huit formes réelles du fonds.
     *
     * ## Ce test a d'abord été écrit autrement, et cette version-là était piégée
     *
     * Il comparait la sortie du motif retenu à celle de la forme interdite, `(?<=^|\R)`,
     * pour **prouver** qu'ils coïncident. L'intention était bonne : nommer la forme
     * piégée pour qu'on ne la réintroduise pas. Elle portait un garde d'exécution qui
     * sautait le test là où PCRE refuse de la compiler.
     *
     * **Ce garde protégeait phpunit et pas phpstan.** `api/phpstan.neon` analyse `tests`
     * autant que `src`, sa règle `regexp.pattern` lit les motifs **littéralement**, elle
     * résout la variable, et l'arobase ne la concerne pas. Un test qui documente un motif
     * illégal **en l'écrivant** le remet donc sous les yeux de l'analyseur, et fait tomber
     * la porte 1 exactement là où le correctif venait de la relever.
     *
     * > **Le vaccin portait la maladie.**
     *
     * ## Pourquoi figer les sorties plutôt que masquer la règle
     *
     * Trois issues existaient. Construire le motif de façon illisible pour que l'analyseur
     * ne le résolve pas — c'est le tromper, en moins visible qu'une directive d'exemption,
     * et le procédé se recopierait. Poser une exemption locale — c'est faire taire, au seul
     * endroit où elle a servi, la règle qui a attrapé deux défauts réels le même soir.
     *
     * Reste celle-ci, et elle n'est pas le moindre mal : **la comparaison dynamique ne
     * prouvait déjà plus rien là où ça compte.** Elle ne peut tourner que sur une PCRE
     * ≥ 10.43, c'est-à-dire précisément là où le défaut ne se reproduit pas ; sur
     * l'intégration continue et sur l'image de production, elle se serait sautée. Son
     * apport permanent était nul, et son coût une porte rouge.
     *
     * L'équivalence, elle, a bien été mesurée — sur ces huit formes, terminaisons `\r\n`
     * comprises, zéro écart — **au moment d'écrire le correctif**, sur la machine où les
     * deux compilent. C'était une vérification de conception, pas un invariant à
     * surveiller ; elle est consignée ici et dans l'en-tête du service.
     *
     * Ce qui se surveille, en revanche, c'est le **comportement** : les huit couples
     * ci-dessous tournent partout, et ils attrapent la réécriture qui changerait ce que la
     * couture produit — ce qui était le vrai risque du correctif.
     *
     * ## Avertissement daté — ce que ce fichier ne sait plus faire
     *
     * Un test d'équivalence a vécu ici jusqu'au **17 septembre 2026**. Il comparait deux
     * formes du même motif et disait si elles rendaient la même chose. Il a été retiré
     * parce que, pour comparer, il devait **écrire la forme interdite** — et un motif
     * illégal ne peut pas vivre dans le dépôt : « l'analyseur ne le voit plus » et « le
     * motif n'y est plus » ne sont pas la même propriété, et seule la seconde résiste à
     * quelqu'un qui recopiera la ligne ailleurs. Un motif présent dans un test que
     * l'intégration continue accepte est un motif qui a l'air d'avoir passé la revue.
     *
     * **À lire avant de retoucher `recoudre()` :** il n'existe plus de comparaison
     * automatique entre la forme d'avant et la vôtre. Les huit couples ci-dessous disent
     * ce que la couture **doit rendre** ; ils ne diront pas si votre réécriture coïncide
     * avec celle d'hier. Si vous avez besoin de cette réponse-là, mesurez-la hors du
     * dépôt et consignez le résultat ici — ne réintroduisez pas la forme pour l'obtenir.
     *
     * Le prix est borné, et il faut le dire aussi : cette preuve n'avait de valeur que le
     * jour de la substitution, pour établir que la nouvelle forme faisait ce que faisait
     * l'ancienne. Cette question-là ne se repose plus ; après, elle devient inerte. Et ce
     * qui protège l'utilisateur reste en place — `\r\n`, tiret seul, virgule doublée,
     * chaîne vide sont testés à travers `nettoyer()`, sur la forme retenue.
     */
    public function testLeMotifRetenuProduitExactementCesSorties(): void
    {
        $retenue = '~^[ \t]*[—–\-:;,]+[ \t]*~mu';

        $attendus = [
            '— vue 3' => 'vue 3',
            '  ; reste' => 'reste',
            "ligne une\n— vue 3" => "ligne une\nvue 3",
            "ligne une\r\n— vue 3" => "ligne une\r\nvue 3",
            "a\n  ,  b\n- c" => "a\nb\nc",
            "— début\nmilieu\n; fin" => "début\nmilieu\nfin",
            // Un tiret **au milieu** d'une ligne n'est pas une cicatrice : il reste.
            'Sorbière, 5 E 341/7, vue 1/6 — relevé' => 'Sorbière, 5 E 341/7, vue 1/6 — relevé',
            '' => '',
        ];

        foreach ($attendus as $entree => $attendu) {
            self::assertSame(
                $attendu,
                preg_replace($retenue, '', (string) $entree),
                \sprintf('La couture doit rendre « %s » sur « %s ».', addcslashes($attendu, "\r\n"), addcslashes((string) $entree, "\r\n")),
            );
        }
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
