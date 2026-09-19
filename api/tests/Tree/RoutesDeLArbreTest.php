<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Service\SettingService;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Tests\Membership\Double\ArbreEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les quatre portes de sortie de `Tree`, **traversées pour de vrai** : pare-feu, routeur,
 * contrôleur, normaliseur, en-têtes.
 *
 * Le test de liste blanche ({@see ListeBlancheDeSerialisationTest}) éprouve la décision
 * de confidentialité ; celui-ci éprouve que la décision est bien celle qui sort du tuyau.
 * Les deux sont nécessaires, et pour une raison concrète : c'est en ouvrant l'onglet
 * réseau du navigateur qu'on constate qu'un filtrage à l'affichage n'est pas un filtrage
 * (§ 11 du contrat).
 */
final class RoutesDeLArbreTest extends ArbreWebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fournirLesRoutes(): iterable
    {
        yield 'graphe' => ['/api/arbre/graphe'];
        yield 'recherche' => ['/api/arbre/recherche?q=sureau'];
    }

    #[DataProvider('fournirLesRoutes')]
    public function testToutesLesRoutesSontDerriereLAuthentification(string $chemin): void
    {
        $this->requete($chemin);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame('authentification_requise', $this->charge()['erreur']['code']);
    }

    public function testLaFicheDUnePersonneEstDerriereLAuthentification(): void
    {
        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * **Le défaut fermé, vu de l'extérieur.** Le compte de ce test n'a aucun `mbr_member`
     * actif : il n'a donc pas de cercle, et ne reçoit que les sept clés sur une personne
     * vivante — quoi qu'il arrive au reste de la configuration.
     *
     * C'est la propriété qu'il faut garder quand on remaniera le câblage des services :
     * une erreur d'alias doit rendre des fiches muettes, jamais des dates de naissance.
     */
    public function testUnMembreActifNeVoitPasUnePersonneVivanteSansAdaptateurDeCercle(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/personnes/'.$this->idDe('cousin'));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'Une personne vivante hors cercle rend 200 et non 404 : son nœud est visible dans le graphe, cliquer dessus doit donner une impasse claire et non une panne.');

        $fiche = $this->charge();
        self::assertSame(['id', 'displayName', 'sex', 'generation', 'branch', 'vivant', 'visibilite'], array_keys($fiche));
        self::assertSame('restreinte', $fiche['visibilite']);
    }

    public function testUnePersonneDecedeeSortEntiereEtAvecSesCitations(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));

        $fiche = $this->charge();
        self::assertSame('complete', $fiche['visibilite']);
        self::assertSame('Saule, Ombrage, Sylvanie', $fiche['birth']['place']);
        self::assertArrayHasKey('enAttente', $fiche, 'La clé `enAttente` est toujours présente sur une fiche complète, à `{}` quand il n’y a rien (§ 13.1).');
        self::assertSame([], $fiche['enAttente']);

        // Sur le JSON **brut**, parce que `json_decode(…, true)` rend `[]` pour les deux
        // formes et masquerait la différence : un tableau PHP vide se sérialise en `[]`,
        // et le contrat exige un objet. Un front qui écrit `fiche.enAttente[champ]` sur
        // un tableau JavaScript ne trouverait rien le jour où le calque cesserait d'être
        // vide — un bogue qui n'apparaît qu'en présence de données.
        self::assertStringContainsString('"enAttente":{}', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le régime du § 12, de bout en bout : un compte `en_attente` lit l'arbre ancien et
     * **aucune personne vivante**.
     */
    public function testUnCompteEnAttenteLitLesDecedesEtRienDeVivant(): void
    {
        $this->creerUtilisateur('attente@heritage.test', statut: UserStatus::EnAttente);
        $this->connexionDe('attente@heritage.test');

        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('complete', $this->charge()['visibilite']);

        $this->requete('/api/arbre/personnes/'.$this->idDe('cousin'));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('restreinte', $this->charge()['visibilite']);

        // Et la recherche ne l'énumère pas davantage.
        $this->requete('/api/arbre/recherche?q=tilleul');
        self::assertSame([], $this->charge()['elements']);

        $this->requete('/api/arbre/recherche?q=sureau');
        self::assertCount(1, $this->charge()['elements'], 'L’arbre ancien reste consultable : c’est ce qui donne du sens à l’attente.');
    }

    public function testLaFicheDeFamilleReduitLEpouxMasqueEtGardeLaDateDUnion(): void
    {
        $this->connexionDe('membre@heritage.test');

        $id = $this->familles['mixte']->getId();
        self::assertNotNull($id);

        $this->requete('/api/arbre/familles/'.$id);

        $fiche = $this->charge();
        self::assertSame('restreinte', $fiche['husband']['visibilite']);
        self::assertSame('restreinte', $fiche['wife']['visibilite'], 'Sans cercle branché, les deux époux vivants sont masqués.');
        self::assertSame('1962', $fiche['marriage']['texte']);
    }

    public function testUnIdentifiantInconnuRend404(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/personnes/999999');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('introuvable', $this->charge()['erreur']['code']);
    }

    /**
     * `requirements: ['id' => '\d+']` fait le travail sans qu'une ligne de code s'en
     * soucie : un identifiant provisoire ne correspond tout simplement pas à la route.
     */
    public function testUnIdentifiantProvisoireNeCorrespondPasALaRoute(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/personnes/p1');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testUneRechercheTropCourteRend422(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/recherche?q=a');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());

        $erreur = $this->charge()['erreur'];
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertArrayHasKey('q', $erreur['champs']);
    }

    /**
     * `no-store` et non `no-cache` : ces réponses portent des données de personnes
     * vivantes, elles n'ont rien à faire dans le cache disque d'un navigateur familial
     * partagé (§ 1.8).
     */
    public function testUneFicheNEstJamaisMiseEnCacheParLeNavigateur(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));

        $reponse = $this->client->getResponse();
        // Symfony ajoute `private` dès qu'une session est ouverte : la valeur servie est
        // `no-store, private`, strictement plus stricte que le `no-store` du § 1.8 — on
        // vérifie donc la directive qui porte la règle, pas la chaîne entière.
        self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
        self::assertFalse($reponse->headers->has('ETag'), 'Le graphe est la seule route du contrat à porter un `ETag`.');
    }

    /**
     * Le parcours du § 5.2, de bout en bout : `200` avec ses trois en-têtes, puis `304`
     * sur revalidation.
     *
     * Les trois en-têtes vont ensemble et aucun n'est décoratif. `private` parce que la
     * charge dépend du lecteur ; `no-cache` parce qu'il faut **revalider** et non servir
     * de mémoire ; `Vary: Cookie` parce que sans lui un cache partagé peut servir le
     * graphe d'un membre du cercle à un membre hors cercle.
     */
    public function testLeGrapheSeSertAvecUnEtagPuisSeRevalideEn304(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/graphe');

        $reponse = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $reponse->getStatusCode());
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', (string) $reponse->headers->get('ETag'), 'L’`ETag` du graphe est fort, jamais faible (§ 1.8).');
        // Le § 1.8 écrit `private, no-cache`. Symfony y ajoute `max-age=0` et
        // `must-revalidate` dès qu'une session est ouverte (`AbstractSessionListener`),
        // ce qui ne change pas le comportement — les trois directives disent « revalide »
        // — mais change la chaîne. On vérifie donc les deux directives qui portent la
        // règle : `private`, parce que la charge dépend du lecteur, et `no-cache`, parce
        // qu'il faut revalider et non servir de mémoire. Et surtout **pas** `no-store`,
        // qui interdirait la revalidation par `ETag` que cette route existe pour offrir.
        $cacheControl = (string) $reponse->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringNotContainsString('no-store', $cacheControl);
        self::assertSame('Cookie', $reponse->headers->get('Vary'));

        $etag = (string) $reponse->headers->get('ETag');
        $charge = $this->charge();

        self::assertSame(['empreinte', 'ego', 'boite', 'calque', 'noeuds', 'aretes'], array_keys($charge));
        self::assertSame('p'.$this->idDe('ego'), $charge['ego'], 'L’ego est le nœud de `tree.personne_racine_id`, à x = 0 par construction.');
        self::assertSame(['x', 'y', 'w', 'h'], array_keys($charge['boite']), 'La boîte alimente directement le `viewBox` initial et n’est jamais recalculée côté client.');
        // Les trois compteurs sont **toujours** là, y compris à zéro : `actif` dit s'il y
        // a quelque chose en attente, il ne dit pas que le calque est complet — et c'est
        // la confusion des deux qui a laissé un calque écarter dix-sept nœuds sur
        // vingt-deux sans le dire ({@see CalqueIncompletTest}).
        self::assertSame(
            ['actif' => false, 'demandes' => [], 'proposes' => 0, 'places' => 0, 'nonPlaces' => []],
            $charge['calque'],
            'Aucune demande en cours : la légende « en attente » reste éteinte, et les compteurs sont à zéro plutôt qu’absents.',
        );

        $this->client->request('GET', '/api/arbre/graphe', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $this->client->getResponse()->getStatusCode());
        self::assertSame($etag, $this->client->getResponse()->headers->get('ETag'), 'Un 304 sans `ETag` ferait repartir le client de zéro au rechargement suivant.');
    }

    /**
     * **La porte de sortie n° 2 du § 11.4** : le nœud d'une personne vivante hors cercle.
     *
     * Le nœud **reste présent, à sa place, avec ses arêtes** — un arbre dont on retire des
     * nœuds n'est plus un arbre : les branches flottent, les fratries sont fausses, le
     * calcul de parenté est faux (ADR 0003). Seule `ligneDates` tombe.
     *
     * Et `w` ne bouge pas : la largeur est mesurée sur le nom et sur la **constante**
     * « vivant·e », jamais sur des dates. Elle est donc identique pour tous les lecteurs,
     * et c'est précisément ce qui permet à l'étage 1 du cache d'être partagé.
     */
    public function testUnNoeudRestreintGardeSaPositionEtPerdSaSeuleLigneDeDates(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/graphe');

        $graphe = $this->charge();
        $aretes = $graphe['aretes'];

        $noeuds = [];
        foreach ($graphe['noeuds'] as $noeud) {
            $noeuds[$noeud['id']] = $noeud;
        }

        $restreint = $noeuds['p'.$this->idDe('cousin')] ?? null;
        self::assertIsArray($restreint, 'Le nœud d’une personne masquée reste dans le graphe.');

        self::assertSame(
            ['id', 'type', 'personId', 'x', 'y', 'w', 'h', 'displayName', 'etiquetteNom', 'sex', 'generation', 'branch', 'vivant', 'visibilite'],
            array_keys($restreint),
            'La liste blanche du § 11.4 pour un nœud restreint : quatorze clés, exactement.',
        );
        self::assertSame('restreinte', $restreint['visibilite']);
        self::assertTrue($restreint['vivant']);
        self::assertGreaterThanOrEqual(104, $restreint['w'], 'Aucune fiche n’est plus étroite que la largeur minimale du moteur.');

        // Le test **dans l'autre sens**, champ par champ : une liste qui ne vérifierait
        // que la présence laisserait passer exactement la fuite qu'elle est censée garder.
        // Les quatre tombent pour deux raisons distinctes — formatage de dates pour la
        // première, interdiction nommée du § 16.2 pour les trois autres.
        foreach (['etiquetteDates', 'bestQuay', 'citationCount', 'avecImage'] as $interdit) {
            self::assertArrayNotHasKey($interdit, $restreint, \sprintf('`%s` ne doit pas sortir sur un nœud restreint.', $interdit));
        }

        // **`etiquetteNom` reste**, elle, et c'est l'asymétrie qui n'est pas intuitive :
        // c'est une troncature de `displayName`, donc strictement moins d'information
        // qu'une clé déjà servie.
        self::assertSame('Pyroxène Tilleul', $restreint['etiquetteNom']);

        $complet = $noeuds['p'.$this->idDe('aieule')] ?? null;
        self::assertIsArray($complet);
        self::assertSame('complete', $complet['visibilite']);

        // ─────────────────────────────────────────────────────────────────────────
        //  `ligneDates` DÉSIGNE DEUX OBJETS DIFFÉRENTS, ET CE TEST LES SÉPARE.
        // ─────────────────────────────────────────────────────────────────────────
        // Sur un **nœud**, c'est l'étiquette du moteur : la forme compacte de
        // `EtiquetteDeDateGedcom`, celle sur laquelle `w` a été mesurée. Sur une
        // **fiche**, c'est la prose française de `UncertainDate::format()`, deux fois
        // plus longue. Émettre la seconde sur un nœud ferait déborder l'étiquette de sa
        // boîte — et c'est l'erreur que la première rédaction de ce test a commise.
        self::assertSame('1901-1909 – 1987', $complet['etiquetteDates']);
        self::assertSame('Marne Sureau', $complet['etiquetteNom'], 'Le premier prénom et le nom — ce que le moteur a mesuré, pas les quatre prénoms de `displayName`.');
        self::assertSame('Marne Dolomite Sureau', $complet['displayName'], '`displayName` sert à lire, `etiquetteNom` à dessiner : les deux sortent.');

        // Les quatre champs de filtre de la barre d'outils, **présents** sur un nœud
        // complet. Le client teste la clé et non la valeur : `citationCount: 0` est une
        // donnée — « aucune source » —, la clé absente est un silence.
        self::assertArrayHasKey('bestQuay', $complet);
        self::assertArrayHasKey('citationCount', $complet);
        self::assertArrayHasKey('avecImage', $complet);
        self::assertIsBool($complet['avecImage']);

        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));
        $fiche = $this->charge();

        // Les **trois** formes du § 14.4, sur la même date, dans la même réponse :
        // la compacte sous `ligneDates`, la prose dans `birth.texte`, et l'étiquette
        // mesurée sur le nœud. Servir l'une à la place de l'autre est le défaut qui est
        // revenu trois fois dans ce projet, et il n'est visible qu'ici — dans la charge.
        self::assertSame('1901-1909 – 1987', $fiche['ligneDates'], 'Forme compacte : le producteur est `EtiquetteDeDateGedcom`.');
        self::assertSame('entre 1901 et 1909', $fiche['birth']['texte'], 'La prose garde sa place, et sa place est `texte`.');
        self::assertSame($complet['etiquetteDates'], $fiche['ligneDates'], 'Sur une personne décédée, l’étiquette du nœud et la ligne de la fiche coïncident — elles ne divergent que sur une personne vivante, où seule la première rend « vivant·e ».');

        // **Aucune arête ne porte de marqueur « longue ».** `.ar.long` est supprimée
        // (§ 7.3 du dossier, ADR 0007) : un back qui calculerait cette information
        // donnerait au front de quoi réintroduire les pointillés que le commanditaire a
        // demandé de retirer.
        self::assertNotSame([], $aretes, 'L’arbre de test porte au moins une union et une filiation.');

        foreach ($aretes as $arete) {
            foreach (['long', 'distance', 'portee'] as $interdit) {
                self::assertArrayNotHasKey($interdit, $arete);
            }
            self::assertContains($arete['genre'], ['union', 'filiation']);
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  L'INVARIANT QUI TIENT LA LARGEUR : `w` SE RECALCULE DEPUIS LES DEUX
     *  CHAÎNES ÉMISES, SUR CHAQUE NŒUD.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * `max(104, int(max(len(etiquetteNom) × 6.6, len(etiquetteDates) × 5.8) + 28))`.
     *
     * C'est la formule du moteur, appliquée à ce que l'API **envoie** plutôt qu'à ce
     * qu'elle a mesuré. Les deux doivent coïncider, et ce test existe parce qu'ils ont
     * déjà divergé deux fois dans ce projet : un nœud annoncé à `w: 173` mesuré sur la
     * prose française au lieu de la forme compacte, et 220 fiches sur 274 qui débordaient
     * de leur boîte parce que le canevas dessinait `displayName` là où la mesure avait
     * pris « premier prénom + nom ».
     *
     * Il couvre en particulier la duplication assumée de `EtiquetteDuNom`, qui recompose
     * à la main deux lignes privées de `MesureDeFiche::largeur()` faute d'un accesseur
     * public : si les deux compositions divergeaient d'un seul caractère, ce test
     * tomberait. C'est une garantie plus forte qu'un appel partagé — elle porte sur le
     * résultat, pas sur le chemin.
     *
     * Des **caractères**, pas des octets : « vivant·e » fait 8 signes et 9 octets, et le
     * moindre nom accentué décalerait le calcul.
     */
    public function testLaLargeurDeChaqueNoeudSeRecalculeDepuisLesDeuxChainesEmises(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/graphe');

        $verifies = 0;

        foreach ($this->charge()['noeuds'] as $noeud) {
            if ('personne' !== $noeud['type'] || !isset($noeud['etiquetteDates'])) {
                // Un nœud restreint ne porte pas de quoi refaire le calcul, et c'est
                // précisément ce qu'on veut : la largeur vient du moteur, le client ne la
                // recalcule jamais.
                continue;
            }

            // La largeur servie est celle de la fiche AFFICHÉE : la zone de texte, mesurée
            // sur les deux étiquettes émises, plus l'emplacement photo et son espace
            // (`MesureDeFiche::LARGEUR_PHOTO + ESPACE_PHOTO`), identique pour tout le
            // monde. Le client ne recalcule toujours rien ; ce test refait le calcul
            // pour vérifier que les étiquettes émises sont bien celles qui ont été
            // mesurées.
            $largeur = max(
                104,
                (int) (max(
                    self::signes($noeud['etiquetteNom']) * 6.6,
                    self::signes($noeud['etiquetteDates']) * 5.8,
                ) + 28),
            ) + 48;

            self::assertSame($largeur, $noeud['w'], \sprintf(
                'Le nœud %s annonce w = %d, mais ses deux étiquettes (« %s », « %s ») mesurent %d.',
                $noeud['id'],
                $noeud['w'],
                $noeud['etiquetteNom'],
                $noeud['etiquetteDates'],
                $largeur,
            ));

            ++$verifies;
        }

        // **Un test qui itère sur un ensemble découvert doit affirmer sa taille.** Sans
        // cette ligne, le jour où plus aucun nœud ne porte `etiquetteDates`, la boucle
        // tourne à vide et le test reste vert en ne vérifiant plus rien.
        self::assertGreaterThanOrEqual(3, $verifies, 'L’arbre de test porte au moins trois personnes décédées, donc trois nœuds complets.');
    }

    /** Des caractères, pas des octets — comme `MesureDeFiche`, qui emploie `iconv_strlen()`. */
    private static function signes(string $texte): int
    {
        $n = iconv_strlen($texte, 'UTF-8');

        return false === $n ? \strlen($texte) : $n;
    }

    /**
     * `avecImage` traverse une frontière de module, et c'est le seul des quatre filtres
     * qui le fasse : `bestQuay` et `citationCount` sont des colonnes de `tree_person`,
     * celui-ci dépend de `med_media`, que `Tree` ne lit pas.
     *
     * Le risque propre à ce champ est donc de rester **silencieusement faux** : tant que
     * `Media` n'avait pas posé son alias, il valait `false` pour les 274 personnes, et
     * rien ne le distinguait d'un arbre sans photos. Ce test pose un média et vérifie que
     * le booléen bascule — c'est la seule façon de prouver que la couture est branchée.
     *
     * Il vérifie aussi l'autre moitié de la règle, que les deux modules ont retenue
     * chacun de son côté sans se concerter : **une personne sans média est absente du
     * tableau rendu par le port, jamais présente à `false`**. Sur un nœud complet, cela
     * ressort en `false` — un nœud qu'on a le droit de lire a le droit de savoir qu'il
     * n'y a rien ; c'est côté port que l'absence n'affirme rien.
     */
    public function testLeFiltreAvecImageBasculeQuandUneImageExiste(): void
    {
        $media = new Media(
            MediaKind::Portrait,
            MediaSubject::Personne,
            'Marne Dolomite Sureau',
            'stockage-de-test.jpg',
            'image/jpeg',
            2048,
            str_repeat('a', 64),
            1,
            'Compte de test',
        );
        $media->rattacherA($this->idDe('aieule'))->setStatus(MediaStatus::Actif);

        $em = $this->em();
        $em->persist($media);
        $em->flush();

        // ── Le détour par le double de `Membership`, et pourquoi il est nécessaire ──
        //
        // `Media` ne sert un média que si son sujet est visible, et la visibilité passe
        // par le cercle, donc par `Membership\Service\Port\PortArbre` — que
        // `config/services/membership.yaml` remplace sous `when@test` par un arbre en
        // mémoire, **vide par défaut**. Or sa `estVivante()` rend `true` pour un
        // identifiant qu'elle ne connaît pas : sans cette amorce, les six personnes de
        // l'arbre passent pour vivantes, aucune n'est dans un cercle, et le média
        // disparaît pour une raison qui n'a rien à voir avec ce qu'on teste.
        //
        // Ce n'est pas un défaut de production — là-bas le port rend le vrai arbre — mais
        // c'est exactement le genre d'artefact qui ferait conclure à tort à une couture
        // débranchée. On amorce donc le double, et on le remet à zéro : son état est
        // **statique**, il survivrait au test.
        ArbreEnMemoire::ajouterPersonne($this->idDe('aieule'), 'Marne Dolomite', 'Sureau', vivante: false);
        ArbreEnMemoire::ajouterPersonne($this->idDe('aieul'), 'Émeri', 'Sarriette', vivante: false);

        try {
            $this->connexionDe('membre@heritage.test');
            $this->requete('/api/arbre/graphe');

            $noeuds = [];
            foreach ($this->charge()['noeuds'] as $noeud) {
                $noeuds[$noeud['id']] = $noeud;
            }

            self::assertTrue(
                $noeuds['p'.$this->idDe('aieule')]['avecImage'],
                'Le port des médias est branché : une personne qui porte un média actif le dit.',
            );
            self::assertFalse(
                $noeuds['p'.$this->idDe('aieul')]['avecImage'],
                'Et une personne qui n’en porte pas le dit aussi.',
            );

            // Sur un nœud **restreint**, la clé reste absente : elle révélerait qu'une
            // personne vivante hors cercle porte des médias (§ 16.2).
            self::assertArrayNotHasKey('avecImage', $noeuds['p'.$this->idDe('cousin')]);
        } finally {
            ArbreEnMemoire::reinitialiser();
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════════
     *  § 17.5 — LA DERNIÈRE RÈGLE DU CONTRAT QUI N'AVAIT PAS DE GARDIEN.
     * ═══════════════════════════════════════════════════════════════════════════════.
     *
     * > *Deux lecteurs de cercles différents, même empreinte d'arbre, aucune demande en
     * > cours, reçoivent deux `ETag` différents.*
     *
     * **La mesure de départ, sur la pile, avant correction :** les trois comptes réels
     * recevaient 124 412, 124 092 et 122 954 octets — trois `md5` distincts — sous **un
     * seul** `ETag` fort. En présentant celui frappé par l'administrateur, le compte
     * `en_attente` recevait `304 Not Modified`, c'est-à-dire l'autorisation de garder à
     * l'écran le graphe complet d'un administrateur.
     *
     * Le § 17.5 explique pourquoi ce test est ici et non dans `tests/Contrat` : *un test
     * qui relit le document ne peut en contrôler que le texte ; il passerait au vert sur
     * une formule fausse énoncée avec assurance.* **Une propriété du produit se teste
     * contre le produit** — ici à travers le pare-feu, le vrai cercle et la vraie base.
     *
     * Les trois assertions ne sont pas interchangeables et l'ordre compte :
     *
     * 1. **trois charges différentes** — sans quoi il n'y aurait rien à distinguer, et le
     *    test passerait au vert pour une raison qui n'a rien à voir avec ce qu'il garde ;
     * 2. **trois `ETag` différents** — la règle elle-même ;
     * 3. **aucun `304` croisé** — la conséquence observable, celle qui a été mesurée.
     */
    public function testDeuxLecteursDeCerclesDifferentsRecoiventDeuxEtagsDifferents(): void
    {
        $this->amorcerLaVueDuCercle();

        try {
            $this->creerUtilisateur('admin@heritage.test', ['ROLE_ADMIN']);
            $this->rattacherAUnePersonne('membre@heritage.test', 'ego');
            $this->creerUtilisateur('attente@heritage.test', statut: UserStatus::EnAttente);

            // Trois lecteurs nommés un par un, et non découverts : la liste est le sujet
            // du test. `assertCount` sur les valeurs distinctes est ce qui rougit le jour
            // où deux d'entre elles se confondent.
            $comptes = ['admin@heritage.test', 'membre@heritage.test', 'attente@heritage.test'];
            $etags = [];
            $charges = [];
            $empreintes = [];

            foreach ($comptes as $courriel) {
                $this->connexionDe($courriel);
                $this->requete('/api/arbre/graphe');

                $reponse = $this->client->getResponse();
                self::assertSame(Response::HTTP_OK, $reponse->getStatusCode(), $courriel.' doit recevoir le graphe.');

                $etags[$courriel] = (string) $reponse->headers->get('ETag');
                $charges[$courriel] = md5((string) $reponse->getContent());
                $empreintes[$courriel] = $this->charge()['empreinte'];
            }

            self::assertCount(3, $etags, 'Les trois lecteurs du § 17.5 doivent tous avoir répondu.');

            self::assertCount(
                1,
                array_unique($empreintes),
                'Même arbre, donc même empreinte (§ 2.3) : c’est l’hypothèse du § 17.5, et c’est ce qui rend le test concluant.',
            );

            self::assertCount(
                3,
                array_unique($charges),
                'Trois cercles, trois charges : sans cela il n’y aurait rien à distinguer.',
            );

            self::assertCount(
                3,
                array_unique($etags),
                \sprintf('Trois charges différentes ne peuvent pas partager un `ETag` — un `ETag` identique pour deux représentations différentes est une fuite (§ 5.2). Obtenu : %s', json_encode($etags, \JSON_THROW_ON_ERROR)),
            );

            // ── La conséquence observable, celle qui a été mesurée sur la pile ──
            $this->connexionDe('attente@heritage.test');
            $this->client->request('GET', '/api/arbre/graphe', server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_IF_NONE_MATCH' => $etags['admin@heritage.test'],
            ]);

            self::assertSame(
                Response::HTTP_OK,
                $this->client->getResponse()->getStatusCode(),
                'Un compte en attente qui présente l’`ETag` de l’administrateur doit recevoir 200 et sa propre charge — jamais 304, qui le laisserait afficher le graphe d’un autre.',
            );
        } finally {
            ArbreEnMemoire::reinitialiser();
        }
    }

    /**
     * ── LE TROISIÈME CAS MESURÉ : `cercle.generations`, ET IL SE FERME TOUT SEUL ──
     *
     * Le réglage vaut 1 à 6 et un administrateur le change en production, « avec effet à
     * la requête suivante, sans redéploiement » (§ 6.2 du dossier). Il entre dans la clé
     * de cache **serveur** du cercle (§ 9.3 du modèle) mais **ne touche aucune table
     * `tree_*`** : ni l'empreinte de l'arbre, ni celle des demandes ne bougent quand il
     * passe de 2 à 1.
     *
     * Avec l'ancienne formule, l'`ETag` restait donc identique : chaque navigateur de
     * membre revalidait, recevait `304`, et **continuait d'afficher le cercle large**. Le
     * durcissement n'atteignait jamais le client — et c'est la pire forme du défaut,
     * puisque l'administrateur, lui, voit bien le réglage changé dans son écran.
     *
     * Il n'y a rien de spécial dans le code pour ce cas, et c'est le point : un `N` plus
     * petit donne un cercle plus petit, donc une liste d'identifiants différente, donc une
     * empreinte de cercle différente. **Le cas se ferme parce qu'on hache le cercle
     * résolu, et non les réglages qui le produisent** — il resterait donc fermé pour
     * n'importe quelle autre cause de rétrécissement : un rattachement recalé, un membre
     * désactivé, une correction de parenté.
     *
     * > La vue de `Membership` est ici volontairement **plus profonde** que l'arbre du
     * > socle, qui ne compte que deux générations : `cercle.generations` n'a d'effet qu'à
     * > partir de la troisième. Ce qui est mesuré est l'effet du réglage sur l'`ETag` et
     * > sur la charge, pas la forme de l'arbre.
     */
    public function testResserrerCercleGenerationsChangeLEtagSansToucherALArbre(): void
    {
        $this->amorcerUneVueATroisGenerations();

        try {
            $this->rattacherAUnePersonne('membre@heritage.test', 'ego');

            $this->reglerGenerationsDuCercle(2);
            $this->connexionDe('membre@heritage.test');
            $this->requete('/api/arbre/graphe');

            $large = $this->client->getResponse();
            $etagLarge = (string) $large->headers->get('ETag');
            $chargeLarge = md5((string) $large->getContent());
            $empreinteLarge = $this->charge()['empreinte'];

            $this->reglerGenerationsDuCercle(1);
            $this->requete('/api/arbre/graphe');

            $resserre = $this->client->getResponse();
            $etagResserre = (string) $resserre->headers->get('ETag');

            self::assertSame(
                $empreinteLarge,
                $this->charge()['empreinte'],
                'Le réglage ne touche aucune table `tree_*` : l’empreinte de l’arbre ne bouge pas. C’est toute la difficulté du cas.',
            );
            self::assertNotSame(
                $chargeLarge,
                md5((string) $resserre->getContent()),
                'Resserrer le cercle doit bel et bien changer la charge : sans cela ce test ne garderait rien.',
            );
            self::assertNotSame(
                $etagLarge,
                $etagResserre,
                'Le cercle effectif est haché : un cercle resserré change l’`ETag`, même à arbre inchangé.',
            );

            // Et la conséquence pour le navigateur qui détient l'ancien jeton : il
            // retélécharge. C'est ce qui fait que le durcissement atteint le client.
            $this->client->request('GET', '/api/arbre/graphe', server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_IF_NONE_MATCH' => $etagLarge,
            ]);

            self::assertSame(
                Response::HTTP_OK,
                $this->client->getResponse()->getStatusCode(),
                'Un navigateur qui revalide avec le jeton d’avant le durcissement doit recevoir 200 et la charge resserrée — jamais 304, qui lui laisserait le cercle large à l’écran.',
            );
        } finally {
            ArbreEnMemoire::reinitialiser();
        }
    }

    /**
     * L'arbre du socle, **tel que `Membership` le voit** : mêmes personnes, mêmes unions,
     * mêmes états vivants que la base de ce test.
     *
     * Sans cette amorce, le double rend un arbre vide : sa `estVivante()` répond `true`
     * pour un identifiant qu'elle ne connaît pas — l'inconnu vaut vivant, donc masqué —,
     * les six personnes passent pour vivantes, aucune n'est dans un cercle, et les
     * lecteurs se ressemblent pour une raison qui n'a rien à voir avec ce qu'on teste.
     */
    private function amorcerLaVueDuCercle(): void
    {
        ArbreEnMemoire::reinitialiser();

        ArbreEnMemoire::ajouterPersonne($this->idDe('ego'), 'Natron', 'Romarin', vivante: true);
        ArbreEnMemoire::ajouterPersonne($this->idDe('conjointe'), 'Cobalt', 'Romarin', vivante: true);
        ArbreEnMemoire::ajouterPersonne($this->idDe('cousin'), 'Pyroxène', 'Tilleul', vivante: true);
        ArbreEnMemoire::ajouterPersonne($this->idDe('aieule'), 'Marne Dolomite', 'Sureau', vivante: false);
        ArbreEnMemoire::ajouterPersonne($this->idDe('aieul'), 'Émeri', 'Sarriette', vivante: false);
        ArbreEnMemoire::ajouterPersonne($this->idDe('sansDate'), 'Lapis', 'Fenouil', vivante: false);

        ArbreEnMemoire::ajouterFamille([$this->idDe('aieul'), $this->idDe('aieule')], [$this->idDe('ego')]);
        ArbreEnMemoire::ajouterFamille([$this->idDe('cousin'), $this->idDe('conjointe')], [$this->idDe('sansDate')]);
    }

    /**
     * La même vue, **rallongée d'une génération** : l'aïeul devient l'enfant du couple
     * mixte, ce qui place deux personnes **vivantes** — le cousin et la conjointe — au
     * deuxième rang d'ascendance de l'ego.
     *
     * C'est ce qui rend `cercle.generations` observable : à `N = 2` elles entrent dans le
     * cercle et leurs fiches sortent complètes ; à `N = 1` elles en sortent et leurs
     * nœuds se réduisent. L'arbre du socle, à deux générations, ne pourrait rien en dire.
     */
    private function amorcerUneVueATroisGenerations(): void
    {
        $this->amorcerLaVueDuCercle();

        ArbreEnMemoire::ajouterFamille(
            [$this->idDe('cousin'), $this->idDe('conjointe')],
            [$this->idDe('aieul')],
        );
    }

    /** Un compte actif **réellement rattaché** : c'est ce qui lui donne un cercle. */
    private function rattacherAUnePersonne(string $courriel, string $personne): void
    {
        $utilisateur = $this->creerUtilisateur($courriel);
        $identifiant = $utilisateur->getId();
        self::assertNotNull($identifiant);

        $membre = new Member(
            $identifiant,
            $courriel,
            $this->idDe($personne),
            $this->personnes[$personne]->getDisplayName(),
            MatchKind::PreLie,
        );
        $membre->activer();

        $em = $this->em();
        $em->persist($membre);
        $em->flush();
    }

    private function reglerGenerationsDuCercle(int $generations): void
    {
        $em = $this->em();
        $reglage = $em->getRepository(Setting::class)->findOneBy(['settingKey' => SettingService::CLE_CERCLE_GENERATIONS]);
        self::assertInstanceOf(Setting::class, $reglage, 'Le réglage `cercle.generations` est posé par le socle de test.');

        $reglage->setValue((string) $generations);
        $em->flush();
    }

    private function connexionDe(string $courriel): void
    {
        if (null === $this->em()->getRepository(\App\Module\Core\Entity\User::class)->findOneBy(['email' => $courriel])) {
            $this->creerUtilisateur($courriel);
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }
}
