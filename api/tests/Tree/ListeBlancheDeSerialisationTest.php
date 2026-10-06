<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Contract\CalqueEnAttenteInterface;
use App\Module\Core\Contract\CalqueFerme;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Tree\Serializer\AppuiDUneCitation;
use App\Module\Tree\Serializer\CercleDuLecteur;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\FabriqueDuCercle;
use App\Module\Tree\Serializer\LecteurDeLArbre;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\MediasNonBranches;
use App\Module\Tree\Serializer\NormaliseurDeDate;
use App\Module\Tree\Serializer\NormaliseurDeFamille;
use App\Module\Tree\Serializer\NormaliseurDePersonne;
use App\Module\Tree\Serializer\NormaliseurDeRecherche;
use App\Module\Tree\Serializer\NormaliseurDeRequete;
use App\Module\Tree\Serializer\ParenteDuLecteur;
use App\Module\Tree\Service\AdressesDArbresEnLigne;
use App\Tests\Tree\Double\CalqueEnMemoire;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE TEST DE LISTE BLANCHE — § 11.4 du contrat, exigence de l'ADR 0003.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * « L'ADR 0003 exige **un test fonctionnel par porte de sortie**, comparant la réponse à
 * une **liste blanche** de champs — jamais à une liste noire, qui oublierait le champ
 * ajouté demain. »
 *
 * D'où la forme de ces assertions : elles comparent **l'ensemble exact des clés**, pas un
 * sous-ensemble. Un champ en plus les fait échouer, et c'est le but — sans quoi le reste
 * de l'ADR 0003 est une intention.
 *
 * Deux choses qu'il faut avoir en tête en lisant ce fichier :
 *
 * 1. **L'arbre de test contient une citation et une note sur la personne masquée.** Sans
 *    elles, un test de liste blanche passerait pour de mauvaises raisons : il n'y aurait
 *    rien à fuir. Voir {@see ArbreWebTestCase}.
 * 2. **Les normaliseurs sont appelés directement, avec un cercle construit à la main.**
 *    C'est possible parce qu'ils prennent un {@see CercleDuLecteur} en paramètre plutôt
 *    que d'aller le chercher — et c'est cette décision qui rend la règle la plus sensible
 *    du produit réellement testable, plutôt que testable en principe. Les mêmes règles
 *    sont éprouvées à travers HTTP dans {@see RoutesDeLArbreTest}.
 */
final class ListeBlancheDeSerialisationTest extends ArbreWebTestCase
{
    /**
     * Les sept clés du § 11.1, recopiées telles quelles. **Ne pas en ajouter une pour
     * faire passer un test** : si un champ manque à l'écran, il manque à l'ADR, et c'est
     * l'ADR qu'on amende — c'est ce qui s'est passé pour `branch` (arbitrage **M**) et
     * pour `vivant` (§ 18, point 4).
     */
    private const array SEPT_CLES = [
        'id', 'displayName', 'sex', 'generation', 'branch', 'vivant', 'visibilite',
    ];

    public function testUnePersonneVivanteHorsCercleNExposeQueSeptCles(): void
    {
        $fiche = $this->fiche('cousin', $this->membre(), CercleDuLecteur::de([]));

        self::assertSame(self::SEPT_CLES, array_keys($fiche), 'La fiche réduite doit porter exactement les sept clés de la liste blanche.');
        self::assertSame('restreinte', $fiche['visibilite']);
        // Révision du 2026-10-01 de l'ADR 0003 : la clé reste, la valeur est le masque.
        // Plus aucune colonne nominative ne sort hors cercle.
        self::assertSame('Personne vivante', $fiche['displayName'], 'Hors cercle, une personne vivante n’expose plus son nom.');
        self::assertStringNotContainsString('Pyroxène', json_encode($fiche, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Tilleul', json_encode($fiche, \JSON_THROW_ON_ERROR));
        self::assertTrue($fiche['vivant'], '`vivant` sort même hors cercle : `.fiche.prive` en dépend (ADR 0007).');
    }

    /**
     * Le même constat, mais dit dans l'autre sens et champ par champ : c'est celui-ci qui
     * échouera le jour où quelqu'un remplira la charge complète avant d'en retirer les
     * clés interdites — parce qu'un `unset()` oublié laisse passer **un** champ, pas
     * sept.
     */
    public function testAucuneDateAucunLieuAucunMediaNeSortDUneFicheReduite(): void
    {
        $fiche = $this->fiche('cousin', $this->membre(), CercleDuLecteur::de([]));
        $json = json_encode($fiche, \JSON_THROW_ON_ERROR);

        foreach ([
            'birth', 'death', 'ligneDates', 'place', 'medias', 'portrait', 'citations',
            'notes', 'faits', 'parents', 'fratrie', 'unions', 'version', 'gedcomXref',
            'bestQuay', 'citationCount', 'forcePrivacy', 'privacyNote', 'parente',
            'enAttente', 'demandesEnAttente', 'givenNames', 'surname', 'marriedName',
            'nickname', 'deceased',
        ] as $interdit) {
            self::assertArrayNotHasKey($interdit, $fiche, \sprintf('`%s` ne doit pas sortir pour une personne vivante hors cercle.', $interdit));
        }

        // Et la valeur, pas seulement la clé : un lieu recopié dans un autre champ
        // resterait un lieu. Les deux chaînes n'apparaissent nulle part dans le JSON.
        self::assertStringNotContainsString('Sorbière', $json);
        self::assertStringNotContainsString('1934', $json);
    }

    public function testUnePersonneVivanteDansLeCercleExposeSaFicheEntiere(): void
    {
        $fiche = $this->fiche('cousin', $this->membre(), CercleDuLecteur::de([$this->idDe('cousin')]));

        self::assertSame('complete', $fiche['visibilite']);
        self::assertIsArray($fiche['birth']);
        self::assertSame('Sorbière, Fougerie, Florie', $fiche['birth']['place']);
        self::assertSame('vers 1934', $fiche['birth']['texte'], 'Une date `ABT` n’est jamais élargie ni aplatie (§ 14.3).');
        self::assertSame('n. ~1934', $fiche['ligneDates'], '`ligneDates` est la forme **compacte** (§ 14.4) ; la prose « vers 1934 » est la valeur de `texte`, dans le bloc date.');
        self::assertCount(1, $fiche['citations']);
        self::assertCount(1, $fiche['notes']);
    }

    /**
     * **La réciprocité des vivants** (ADR 0016) : un vivant que le cercle montre, mais
     * dont le lecteur ne partage pas — ou n'obtient pas — la naissance, sort avec un bloc
     * **masqué**. Ni `raw`, ni bornes, ni année dans aucune clé ; le lieu, lui, reste.
     */
    public function testUneNaissanceNonPartageeNeSortParAucuneCle(): void
    {
        $cercle = CercleDuLecteur::de([$this->idDe('cousin')])->avecPartage([$this->idDe('cousin') => ComposantesDeDate::aucune()]);
        $fiche = $this->fiche('cousin', $this->membre(), $cercle);

        self::assertSame('complete', $fiche['visibilite'], 'Le cercle décide de la fiche ; la réciprocité ne fait que réduire la naissance.');
        self::assertIsArray($fiche['birth']);
        self::assertSame('masquee', $fiche['birth']['qualifier']);
        self::assertNull($fiche['birth']['raw']);
        self::assertNull($fiche['birth']['min']);
        self::assertNull($fiche['birth']['max']);
        self::assertSame('', $fiche['birth']['texte']);
        self::assertSame('Sorbière, Fougerie, Florie', $fiche['birth']['place']);
        self::assertSame('—', $fiche['ligneDates']);
        self::assertFalse($fiche['naissanceModifiable'], 'On ne corrige pas une date qu’on ne voit pas.');
        self::assertStringNotContainsString('1934', json_encode($fiche, \JSON_THROW_ON_ERROR));
    }

    public function testUneNaissancePartageeALAnneeNeLivreQueLAnnee(): void
    {
        $cercle = CercleDuLecteur::de([$this->idDe('cousin')])->avecPartage([$this->idDe('cousin') => new ComposantesDeDate(annee: true)]);
        $fiche = $this->fiche('cousin', $this->membre(), $cercle);

        self::assertSame('masquee', $fiche['birth']['qualifier']);
        self::assertSame('vers 1934', $fiche['birth']['texte'], '« vers » ne dit qu’une année, et c’est elle qui est partagée.');
        self::assertSame('n. ~1934', $fiche['ligneDates']);
        self::assertStringNotContainsString('ABT', json_encode($fiche, \JSON_THROW_ON_ERROR), '`raw` ne sort jamais d’une naissance réduite.');
    }

    /**
     * Une citation se date comme une note : `createdAt`, au format ATOM, celui de la
     * ligne. L'écran l'affiche sur chaque source, et l'onglet Historique de la fiche
     * range par elle ce qui a été ajouté — une clé absente y ferait disparaître la
     * citation, sans erreur.
     */
    public function testUneCitationDeLaFichePorteSaDateDAjout(): void
    {
        $fiche = $this->fiche('cousin', $this->membre(), CercleDuLecteur::de([$this->idDe('cousin')]));

        $citation = $fiche['citations'][0];
        self::assertArrayHasKey('createdAt', $citation);
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $citation['createdAt']);
        self::assertNotFalse($date, 'La date d’une citation est rendue au format ATOM, comme celle d’une note.');
        self::assertSame($citation['createdAt'], $date->format(\DateTimeInterface::ATOM));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $fiche['notes'][0]['createdAt']);
    }

    /**
     * « Une personne décédée est un fait historique : visible de tout membre connecté,
     * sans restriction » — y compris d'un compte **sans cercle**, ce qui est tout l'objet
     * du régime en attente.
     */
    public function testUnePersonneDecedeeEstVisibleDUnCompteSansCercle(): void
    {
        $fiche = $this->fiche('aieule', $this->enAttente(), CercleDuLecteur::sansCercle());

        self::assertSame('complete', $fiche['visibilite']);
        self::assertFalse($fiche['vivant']);
        self::assertSame('entre 1901 et 1909', $fiche['birth']['texte']);
        self::assertSame('1901-1909 – 1987', $fiche['ligneDates'], 'Forme compacte, pas la prose : le producteur est `EtiquetteDeDateGedcom`, pas `UncertainDate::format()`.');
    }

    /**
     * Le régime du § 12, et le piège qu'il referme : **pas même la personne à laquelle il
     * prétend se rattacher**. Confirmer une date n'est pas la lire, et le pré-lien
     * n'ouvre aucun accès.
     *
     * Le test le dit en construisant un lecteur dont l'identité *est* celle du candidat
     * au rattachement : si le régime était implémenté comme « un cercle avec `N = 0` »,
     * la formule `A(u) = { u }` ferait entrer `u` — et son conjoint — dans le cercle, et
     * cette assertion tomberait.
     */
    public function testUnCompteEnAttenteNeVoitAucunePersonneVivanteYComprisLaSienne(): void
    {
        $lecteur = $this->enAttente();
        $cercle = CercleDuLecteur::sansCercle();

        self::assertSame(Regime::Aucun, $cercle->regime);

        foreach (['ego', 'conjointe', 'cousin'] as $vivant) {
            $fiche = $this->fiche($vivant, $lecteur, $cercle);

            self::assertSame(
                'restreinte',
                $fiche['visibilite'],
                \sprintf('Un compte en attente ne doit voir aucune personne vivante, `%s` comprise.', $vivant),
            );
            self::assertSame(self::SEPT_CLES, array_keys($fiche));
        }
    }

    /**
     * **Défaut fermé, sur le câblage réel.** {@see FabriqueDuCercle} délègue au contrat du
     * noyau `Core\Security\CercleDeVisibiliteInterface` ; ce test n'ouvre aucune session,
     * donc aucun cercle ne peut être résolu, et **aucune personne vivante ne doit être
     * visible**.
     *
     * Il porte sur la fabrique branchée par le conteneur, pas sur un double : c'est lui
     * qui tiendra le jour où quelqu'un remaniera la configuration des services, et c'est
     * tout son intérêt. Une erreur d'alias doit produire des fiches muettes, jamais des
     * dates de naissance de personnes vivantes.
     *
     * La seconde assertion dit l'autre moitié de la règle, et il ne faut pas la retirer
     * « par prudence » : une personne **décédée** reste visible même quand tout le reste
     * est fermé. C'est un fait historique (§ 6.2 du dossier), et c'est `Tree` — non le
     * noyau — qui sait l'établir, par {@see EtatVivant}.
     */
    public function testSansAdaptateurDeCercleUnePersonneVivanteResteInvisible(): void
    {
        $fabrique = self::getContainer()->get(FabriqueDuCercle::class);
        self::assertInstanceOf(FabriqueDuCercle::class, $fabrique);

        $cercle = $fabrique->pour($this->membre());

        self::assertFalse(
            $cercle->voit($this->idDe('cousin'), true),
            'Sans adaptateur de cercle branché, une personne vivante n’est visible de personne.',
        );
        self::assertTrue(
            $cercle->voit($this->idDe('aieule'), false),
            'Une personne décédée reste visible : le cercle ne décide que du sort des vivants.',
        );
    }

    /**
     * « Le graphe montre, il n'énumère pas. » Une personne vivante hors cercle n'est pas
     * rendue en version réduite dans la recherche : elle est **absente**.
     */
    public function testLaRechercheOmetUnePersonneVivanteHorsCercle(): void
    {
        $horsCercle = $this->recherche('tilleul', CercleDuLecteur::de([]));
        self::assertSame([], $horsCercle['elements'], 'Une personne vivante hors cercle n’apparaît dans aucune recherche.');
        self::assertFalse($horsCercle['tronque']);

        $dansLeCercle = $this->recherche('tilleul', CercleDuLecteur::de([$this->idDe('cousin')]));
        self::assertCount(1, $dansLeCercle['elements']);
        self::assertSame('Pyroxène Tilleul', $dansLeCercle['elements'][0]['displayName']);
    }

    /**
     * **Révision du 2026-10-04 — un arbre par famille.** Une défunte de l'arbre d'un autre
     * compte n'apparaît pas dans la recherche : la règle des défunts ne vaut que dans le
     * périmètre du lecteur.
     */
    public function testLaRechercheOmetUnDefuntDUnAutreArbre(): void
    {
        self::assertCount(1, $this->recherche('sureau', CercleDuLecteur::de([]))['elements']);

        $autreArbre = $this->recherche('sureau', CercleDuLecteur::de([])->avecHorsPerimetre([$this->idDe('aieule')]));
        self::assertSame([], $autreArbre['elements']);
    }

    /**
     * Le décompte est un champ dérivé, donc une donnée masquée (§ 9.2 du modèle). La
     * réponse ne porte **pas** de `total`, et `tronque` se calcule sur le jeu déjà
     * filtré.
     */
    public function testLaRechercheNeRendAucunDecomptePouvantTrahirCeQuElleCache(): void
    {
        $resultat = $this->recherche('romarin', CercleDuLecteur::de([]));

        self::assertSame(['elements', 'tronque'], array_keys($resultat), 'La recherche ne porte ni `total`, ni `page`, ni `parPage` : ce serait une énumération déguisée.');
        self::assertSame([], $resultat['elements'], 'Les deux Romarin sont vivants et hors cercle.');
    }

    /**
     * « Les dates d'union restent visibles : elles appartiennent à la famille, pas à la
     * personne — et une union dont un membre est masqué reste une union, sans quoi la
     * fratrie devient fausse » (§ 5.4).
     *
     * C'est le point où le réflexe « masquer un peu plus par prudence » casserait l'écran
     * de l'époux qui, lui, est visible.
     */
    public function testUneFamilleGardeSesDatesQuandUnEpouxEstMasque(): void
    {
        $normaliseur = $this->normaliseurDeFamille();
        $lecteur = $this->membre();

        $fiche = $normaliseur->fiche(
            $this->familles['mixte'],
            $lecteur,
            CercleDuLecteur::de([$this->idDe('conjointe')]),
            $this->regle(),
        );

        self::assertSame('restreinte', $fiche['husband']['visibilite'], 'Pyroxène est vivant et hors cercle.');
        self::assertSame('complete', $fiche['wife']['visibilite']);
        self::assertSame(['personId', 'displayName', 'visibilite'], array_keys($fiche['husband']));
        self::assertSame('Personne vivante', $fiche['husband']['displayName'], 'L’époux masqué garde sa place, plus son nom (révision du 2026-10-01).');
        self::assertSame('Cobalt Romarin', $fiche['wife']['displayName'], 'L’épouse est dans le cercle : son nom sort.');
        self::assertIsArray($fiche['marriage']);
        self::assertSame('1962', $fiche['marriage']['texte'], 'La date d’union appartient à la famille, pas à l’époux masqué.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LA PORTE QUI FUYAIT SANS BRUIT : LA FICHE VISIBLE D'UN PROCHE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * La fiche réduite d'un vivant ne disait rien de plus que son nom ; la fiche
     * **complète** de son aïeule défunte, visible de tous, le listait parmi ses enfants
     * — `unions[].enfants[]`, `unions[].conjoint`, `parents[]`, `fratrie[]`. Depuis la
     * révision du 2026-10-01 de l'ADR 0003, une référence suit le cercle : la personne
     * reste dans la liste (une fratrie amputée serait fausse), sous le masque.
     */
    public function testLaFicheDUnDefuntNeNommePasSesProchesVivantsHorsCercle(): void
    {
        $fiche = $this->fiche('aieule', $this->membre(), CercleDuLecteur::de([]));

        self::assertSame('complete', $fiche['visibilite'], 'L’aïeule est décédée : sa fiche est entière.');

        $enfants = array_merge(...array_map(static fn (array $u): array => $u['enfants'], $fiche['unions']));
        $ego = array_values(array_filter($enfants, fn (array $e): bool => $e['personId'] === $this->idDe('ego')));

        self::assertCount(1, $ego, 'L’enfant vivant reste dans la liste : une fratrie amputée serait fausse.');
        self::assertSame('Personne vivante', $ego[0]['displayName']);
        self::assertStringNotContainsString('Natron', json_encode($fiche, \JSON_THROW_ON_ERROR), 'Le prénom de l’enfant vivant hors cercle n’est nulle part dans la fiche.');

        // Dans le cercle, la même référence porte le vrai nom.
        $ouverte = $this->fiche('aieule', $this->membre(), CercleDuLecteur::de([$this->idDe('ego')]));
        $noms = array_column(array_merge(...array_map(static fn (array $u): array => $u['enfants'], $ouverte['unions'])), 'displayName');
        self::assertContains('Natron Romarin', $noms);
    }

    /**
     * La grille « personne · naissance · décès » des proches : les dates suivent le
     * cercle comme le nom. Hors cercle, les clés sont **absentes** — ni la date, ni
     * même `null`.
     */
    public function testLesDatesDesProchesSuiventLeCercle(): void
    {
        $enfantsDe = static fn (array $fiche): array => array_merge(...array_map(static fn (array $u): array => $u['enfants'], $fiche['unions']));
        $egoDans = fn (array $liste): array => array_values(array_filter($liste, fn (array $e): bool => $e['personId'] === $this->idDe('ego')))[0];

        $fermee = $this->fiche('aieule', $this->membre(), CercleDuLecteur::de([]));
        $ego = $egoDans($enfantsDe($fermee));
        self::assertArrayNotHasKey('naissance', $ego, 'Un vivant hors cercle : sa date de naissance n’est pas servie.');
        self::assertArrayNotHasKey('deces', $ego);

        $ouverte = $this->fiche('aieule', $this->membre(), CercleDuLecteur::de([$this->idDe('ego')]));
        $ego = $egoDans($enfantsDe($ouverte));
        self::assertArrayHasKey('naissance', $ego, 'Dans le cercle, la date suit le nom.');
        self::assertArrayHasKey('deces', $ego);
        self::assertNull($ego['deces'], 'Un vivant n’a pas de date de décès : `null`, pas l’absence.');
    }

    /**
     * Les **enfants** d'une famille sortaient sous leur vrai nom sans aucun contrôle de
     * cercle. Ils restent listés — `siblingOrder` et `relation` sont des faits de la
     * famille —, leur nom suit le cercle.
     */
    public function testLesEnfantsVivantsHorsCercleDUneFamilleSontMasques(): void
    {
        $fiche = $this->normaliseurDeFamille()->fiche(
            $this->familles['aieux'],
            $this->membre(),
            CercleDuLecteur::de([]),
            $this->regle(),
        );

        $ego = array_values(array_filter($fiche['enfants'], fn (array $e): bool => $e['personId'] === $this->idDe('ego')));
        self::assertCount(1, $ego);
        self::assertSame('Personne vivante', $ego[0]['displayName']);
        self::assertStringNotContainsString('Natron', json_encode($fiche, \JSON_THROW_ON_ERROR));
        self::assertSame('Marne Dolomite Sureau', $fiche['wife']['displayName'], 'Une défunte garde son nom.');
    }

    /**
     * Le calque et le cercle sont **composés**, jamais substitués (ADR 0004). Une
     * personne vivante hors cercle n'a pas de clé `enAttente` **du tout** — pas un calque
     * vide : servir `{}` signalerait quand même l'existence de la fiche.
     */
    public function testLeCalqueEnAttenteEstInvisibleDUnAutreMembre(): void
    {
        $auteur = new LecteurDeLArbre(7, 'Opale Romarin', false, Regime::Cercle);
        $autre = new LecteurDeLArbre(9, 'Tuf Romarin', false, Regime::Cercle);
        $administrateur = new LecteurDeLArbre(1, 'Admin', true, Regime::Cercle);

        $propositions = (new CalqueEnMemoire($auteur->userId))->poser(SubjectKind::Personne, $this->idDe('cousin'), [
            'surname' => [
                'propose' => 'TILLEUL',
                'actuel' => 'Tilleul',
                'demandeId' => 14,
                'auteur' => 'Opale Romarin',
                'depuis' => '2026-09-14T11:02:00+02:00',
                'autresDemandes' => [],
            ],
        ]);

        $cercleOuvert = CercleDuLecteur::de([$this->idDe('cousin')]);
        $normaliseur = $this->normaliseurDePersonne($propositions);

        $vueDeLAuteur = $normaliseur->fiche($this->personnes['cousin'], $auteur, $cercleOuvert, $this->regle());
        self::assertArrayHasKey('surname', (array) $vueDeLAuteur['enAttente'], 'L’auteur voit le calque de sa propre demande.');
        self::assertSame(14, ((array) $vueDeLAuteur['enAttente'])['surname']['demandeId']);
        self::assertSame('Tilleul', $vueDeLAuteur['surname'], 'La valeur validée reste dans le corps principal : le calque est à côté, jamais à la place.');

        $vueDUnAutre = $normaliseur->fiche($this->personnes['cousin'], $autre, $cercleOuvert, $this->regle());
        self::assertSame([], (array) $vueDUnAutre['enAttente'], 'Un autre membre voit les données validées, point.');
        self::assertSame([], $vueDUnAutre['demandesEnAttente']);

        $vueDeLAdmin = $normaliseur->fiche($this->personnes['cousin'], $administrateur, $cercleOuvert, $this->regle());
        self::assertArrayHasKey('surname', (array) $vueDeLAdmin['enAttente'], 'Un administrateur voit le calque de toutes les demandes.');

        // Et le point qui compose les deux filtres : hors cercle, **pas de clé du tout**.
        $vueHorsCercle = $normaliseur->fiche($this->personnes['cousin'], $auteur, CercleDuLecteur::de([]), $this->regle());
        self::assertArrayNotHasKey('enAttente', $vueHorsCercle, 'Hors cercle, la clé `enAttente` fait partie de ce qui n’est pas envoyé.');
    }

    /**
     * Sous la bascule « voir comme un membre », l'administrateur reçoit le calque du
     * **membre emprunté**, pas le sien : c'est ce que ce membre verra réellement
     * (§ 10.4, règle 3).
     */
    public function testSousLaBasculeLeCalqueSuitLeMembreEmprunte(): void
    {
        $auteur = new LecteurDeLArbre(7, 'Opale Romarin', false, Regime::Cercle);
        $propositions = (new CalqueEnMemoire($auteur->userId))->poser(SubjectKind::Personne, $this->idDe('cousin'), [
            'surname' => [
                'propose' => 'TILLEUL', 'actuel' => 'Tilleul', 'demandeId' => 14,
                'auteur' => 'Opale Romarin', 'depuis' => null, 'autresDemandes' => [],
            ],
        ]);

        $normaliseur = $this->normaliseurDePersonne($propositions);
        $cercle = CercleDuLecteur::de([$this->idDe('cousin')]);

        $surOpale = new LecteurDeLArbre(1, 'Admin', true, Regime::Cercle, membreEmprunteId: 3, membreEmprunteUserId: 7);
        $surTuf = new LecteurDeLArbre(1, 'Admin', true, Regime::Cercle, membreEmprunteId: 4, membreEmprunteUserId: 9);

        self::assertArrayHasKey('surname', (array) $normaliseur->fiche($this->personnes['cousin'], $surOpale, $cercle, $this->regle())['enAttente']);
        self::assertSame([], (array) $normaliseur->fiche($this->personnes['cousin'], $surTuf, $cercle, $this->regle())['enAttente']);
    }

    /**
     * Le repli par génération du § 9.2 — celui sans lequel la règle naïve produit 27 faux
     * positifs sur les données réelles.
     *
     * Lapis Fenouil n'a ni date de naissance, ni décès enregistré, et vit à la
     * génération −8. Seule la médiane de sa génération peut trancher, et l'arbre de test
     * lui en donne une par l'intermédiaire des autres personnes… qu'il n'a pas. On
     * vérifie donc le comportement de repli **explicitement**, sur la règle nue.
     */
    public function testLeRepliParGenerationTrancheUnePersonneSansAucuneDate(): void
    {
        $etat = $this->regle();

        self::assertFalse(
            $etat->estVivante($this->personnes['sansDate']),
            'Un ancêtre sans aucune date ne doit pas être classé vivant : c’est le repli par médiane du § 9.2 qui tranche, et sans lui 27 fiches d’ancêtres seraient masquées.',
        );
        self::assertTrue($etat->estVivante($this->personnes['cousin']), 'Né vers 1934, aucun décès enregistré : vivant à quatre-vingt-douze ans.');
        self::assertFalse($etat->estVivante($this->personnes['aieule']), 'Décès enregistré en 1987.');

        // `forcePrivacy` masque même une personne dont le décès est enregistré — et il
        // n'existe volontairement aucun moyen de forcer dans l'autre sens.
        $this->personnes['aieule']->forcerConfidentialite('Décision de caviardage, consignée pour ne pas être redéfaite.');
        self::assertTrue($etat->estVivante($this->personnes['aieule']));
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function fiche(string $cle, LecteurDeLArbre $lecteur, CercleDuLecteur $cercle): array
    {
        return $this->normaliseurDePersonne()->fiche($this->personnes[$cle], $lecteur, $cercle, $this->regle());
    }

    /** @return array{elements: list<array<string, mixed>>, tronque: bool} */
    private function recherche(string $terme, CercleDuLecteur $cercle): array
    {
        $normaliseur = new NormaliseurDeRecherche(
            new NormaliseurDeDate(),
            $this->lecture(),
            new NormaliseurDeRequete(),
        );

        return $normaliseur->resultats($terme, 20, $cercle, $this->regle());
    }

    private function normaliseurDePersonne(?CalqueEnAttenteInterface $propositions = null): NormaliseurDePersonne
    {
        $parente = self::getContainer()->get(ParenteDuLecteur::class);
        \assert($parente instanceof ParenteDuLecteur);

        return new NormaliseurDePersonne(
            new NormaliseurDeDate(),
            $this->lecture(),
            new MediasNonBranches(),
            $parente,
            $propositions ?? new CalqueFerme(),
            // Construits à la main comme le reste de ce gréement : ils sont sans état et
            // sans dépendance, et ce test a besoin de contrôler ce qu'il injecte.
            new AppuiDUneCitation(),
            new AdressesDArbresEnLigne(),
        );
    }

    private function normaliseurDeFamille(): NormaliseurDeFamille
    {
        return new NormaliseurDeFamille(new NormaliseurDeDate(), $this->lecture(), new CalqueFerme());
    }

    private function lecture(): LectureDeLArbre
    {
        $lecture = self::getContainer()->get(LectureDeLArbre::class);
        \assert($lecture instanceof LectureDeLArbre);

        return $lecture;
    }

    private function regle(): EtatVivant
    {
        $etat = self::getContainer()->get(EtatVivant::class);
        \assert($etat instanceof EtatVivant);

        return $etat;
    }

    private function membre(): LecteurDeLArbre
    {
        return new LecteurDeLArbre(42, 'Membre de test', false, Regime::Cercle);
    }

    private function enAttente(): LecteurDeLArbre
    {
        return new LecteurDeLArbre(43, 'Compte en attente', false, Regime::Aucun);
    }
}
