<?php

declare(strict_types=1);

namespace App\Tests\Almanach;

use App\Module\Almanach\Contract\TypeDEvenement;
use App\Module\Core\Enum\Sex;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/carte` — le cercle de visibilité, le rapprochement des lieux, les compteurs.
 *
 * Les cas partent de l'état réel : le cercle n'est pas simulé, c'est le vrai
 * `Membership\Service\CercleDeVisibilite` qui répond depuis une session ouverte par
 * `POST /api/connexion`. Voir l'en-tête de {@see CasDeTestAlmanach}.
 *
 * Les lieux posés par ces cas sont ceux de l'arbre de travail — `Saule`, `Charmille`,
 * `Sorbière` — avec des coordonnées arbitraires : ce qui se vérifie ici est le
 * **rapprochement** par clé repliée, pas la géographie.
 */
final class CarteTest extends CasDeTestAlmanach
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->poserPersonne(self::LECTEUR, 'Natron Romarin', vivant: true, sexe: Sex::Masculin);
        $this->poserPersonne(self::PERE, 'Père Romarin', vivant: true, sexe: Sex::Masculin);
        $this->poserPersonne(self::HORS_CERCLE, 'Cousin Éloigné', vivant: true, sexe: Sex::Masculin);
        $this->poserPersonne(self::SUREAU, 'Marne Dolomite Sureau', vivant: false);
        $this->poserPersonne(self::SARRIETTE, 'Émeri Sarriette', vivant: false, sexe: Sex::Masculin);

        $this->poserFamille([self::PERE], [self::LECTEUR]);

        $this->poserLieu(1, 'Saule, Ombrage, Sylvanie', 46.12, 2.56);
        $this->poserLieu(2, 'Charmille, Ombrage, Sylvanie', 47.03, 3.11);
    }

    // ── Le cercle ────────────────────────────────────────────────────────────────

    /**
     * **La règle centrale** : une personne vivante hors cercle n'apparaît nulle part — ni
     * dans `evenements`, ni dans les compteurs.
     *
     * Le test vérifie l'absence de la **clé**, et pas seulement l'absence de dates : un
     * événement présent avec `annee: null` serait déjà l'aveu qu'une personne existe.
     */
    public function testUnVivantHorsCercleEstAbsentDesEvenementsEtDesCompteurs(): void
    {
        $this->poserEvenement(self::HORS_CERCLE, TypeDEvenement::Naissance, '1979', 'Saule, Ombrage, Sylvanie');
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '1887', 'Saule, Ombrage, Sylvanie');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        $evenements = $this->evenementsDeLaCarte();

        self::assertArrayNotHasKey('naissance:'.self::HORS_CERCLE, $evenements, 'Le vivant hors cercle est absent.');
        self::assertArrayHasKey('naissance:'.self::SUREAU, $evenements, 'La défunte est là — sans quoi le cas serait vert par cécité.');

        self::assertSame(
            ['naissance' => 1, 'deces' => 0, 'union' => 0, 'bapteme' => 0, 'autre' => 0],
            $this->resume()['parGenre'],
            'Le compteur ne connaît que ce que le lecteur voit.',
        );
    }

    /**
     * **La règle du « ET », par le cas qui la justifie** : l'union disparaît dès qu'un seul
     * des deux époux est vivant hors cercle.
     *
     * Trois motifs, et ils ne disent pas la même chose :
     *
     * 1. le § 16.2 ferme « tout lieu, y compris un lieu de fait ou d'union » pour une
     *    personne vivante hors cercle ;
     * 2. **une date de mariage est la date des deux époux.** Ne nommer que celui qu'on voit
     *    ne protège pas l'autre : il reste l'autre extrémité d'une arête que le graphe
     *    publie, et la fuite se fait par jointure ;
     * 3. **un époux visible ne doit jamais déverrouiller un époux caché.** Avec un « ou »,
     *    c'est exactement ce qui arriverait.
     *
     * Le cas pose **deux** unions : celle qui doit disparaître, et celle de deux défunts qui
     * doit rester. Sans la seconde, la règle « aucune union ne sort jamais » passerait le
     * test.
     */
    public function testUnMariageDisparaitDesQuUnDesDeuxEpouxEstVivantHorsCercle(): void
    {
        $this->poserUnion(51, [self::LECTEUR, self::HORS_CERCLE], '14 MAR 2010', 'Saule, Ombrage, Sylvanie');
        $this->poserUnion(42, [self::SUREAU, self::SARRIETTE], '23 SEP 1927', 'Saule, Ombrage, Sylvanie');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        $evenements = $this->evenementsDeLaCarte();

        self::assertArrayNotHasKey(
            'mariage:51',
            $evenements,
            'Le lecteur est visible, son conjoint hors cercle ne l’est pas : l’union ne sort pas. '
            .'La publier sous le seul nom visible publierait la date de mariage de l’autre.',
        );
        self::assertArrayHasKey('mariage:42', $evenements, 'L’union de deux défunts, elle, sort entière.');
    }

    /**
     * **Aucun libellé d'époux hors cercle ne traverse la réponse** — par aucune clé.
     *
     * Le libellé d'une union est composé `« A & B »` **après** le filtre, sur des sujets que
     * le cercle a déjà autorisés. Le composer dans l'adaptateur l'aurait figé avant que
     * quiconque ait décidé qui est visible : la chaîne aurait traversé toute seule, et le
     * filtrage des `sujets` n'aurait plus rien empêché.
     *
     * Le contrôle porte sur le **corps entier** de la réponse, et non sur une clé : c'est la
     * seule forme qui attrape une fuite par un chemin qu'on n'avait pas prévu.
     */
    public function testAucunLibelleDEpouxHorsCercleNeTraverseLaReponse(): void
    {
        $this->poserUnion(51, [self::LECTEUR, self::HORS_CERCLE], '14 MAR 2010', 'Saule, Ombrage, Sylvanie');
        $this->poserEvenement(self::HORS_CERCLE, TypeDEvenement::Naissance, '1979', 'Charmille, Ombrage, Sylvanie');
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '1887', 'Saule, Ombrage, Sylvanie');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        $contenu = $this->client->getResponse()->getContent();
        self::assertIsString($contenu);

        self::assertStringNotContainsString(
            'Cousin Éloigné',
            $contenu,
            'Le nom d’une personne vivante hors cercle ne sort par aucune clé — ni sujet, ni libellé d’union.',
        );
        self::assertStringContainsString('Marne Dolomite Sureau', $contenu, 'La défunte, elle, est nommée.');
    }

    /**
     * **Les compteurs sont calculés après le cercle** — et aucun total global n'est publié.
     *
     * C'est la seconde moitié qui compte. Un `« sur N »` dirait par soustraction combien de
     * personnes le lecteur n'a pas le droit de voir : la fuite par déduction que le § 16.2
     * ferme, et la même que `Timeline` ferme sur ses bornes d'années.
     *
     * Le cas pose six événements dont trois portent sur le vivant hors cercle. Un compteur
     * calculé avant le cercle annoncerait 6 ; il doit annoncer 3.
     */
    public function testLesCompteursSontCalculesApresLeCercle(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '1887', 'Saule, Ombrage, Sylvanie');
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Deces, '1987', 'Charmille, Ombrage, Sylvanie');
        $this->poserEvenement(self::SARRIETTE, TypeDEvenement::Bapteme, '1899', 'Saule, Ombrage, Sylvanie');

        $this->poserEvenement(self::HORS_CERCLE, TypeDEvenement::Naissance, '1979', 'Saule, Ombrage, Sylvanie');
        $this->poserEvenement(self::HORS_CERCLE, TypeDEvenement::Residence, '1999', 'Charmille, Ombrage, Sylvanie');
        $this->poserUnion(51, [self::LECTEUR, self::HORS_CERCLE], '2010', 'Saule, Ombrage, Sylvanie');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        $resume = $this->resume();

        self::assertSame(
            ['naissance' => 1, 'deces' => 1, 'union' => 0, 'bapteme' => 1, 'autre' => 0],
            $resume['parGenre'],
            'Trois événements visibles sur six posés ; les trois autres portent sur le vivant hors cercle.',
        );

        self::assertArrayNotHasKey(
            'total',
            $resume,
            'Aucun total global : il dirait par soustraction combien de personnes le lecteur ne voit pas.',
        );
        self::assertSame(
            ['parGenre', 'sansLieu', 'lieuNonResolu', 'sansDate', 'bornes'],
            array_keys($resume),
            'Les cinq clés du résumé, et rien d’autre.',
        );

        // 1887 (naissance), 1899 (baptême), 1987 (décès) : les trois événements visibles.
        // Ni 1979 ni 2010, qui sont ceux du vivant hors cercle — les bornes aussi sont
        // calculées après le cercle, sans quoi elles diraient qu'il existe des fiches plus
        // récentes que tout ce que le lecteur voit.
        self::assertSame(['min' => 1887, 'max' => 1987], $resume['bornes'], 'Les bornes portent sur les seuls événements rendus.');
    }

    // ── Les lieux ────────────────────────────────────────────────────────────────

    /**
     * **Un lieu non résolu ne fait pas de bulle, mais il reste énumérable**.
     *
     * Le compter à part de `sansLieu` dit **quel geste** il appelle : un lieu écrit mais non
     * résolu se corrige dans `tree_place`, un lieu absent se cherche dans les actes. Deux
     * questions différentes, deux compteurs.
     *
     * ── CE CAS A CHANGÉ D'AVIS, ET IL FAUT DIRE POURQUOI ─────────────────────────────
     *
     * Il affirmait : « Seul l'événement au lieu résolu est rendu. » C'était le comportement
     * du premier jet, et **c'était un défaut** — trouvé en production le 17/09/2026, pas
     * ici. Sur le vrai arbre, **77 événements sur 396** portaient un lieu écrit que le
     * référentiel ne savait pas placer. Ils sortaient de `evenements`, ne laissaient qu'un
     * nombre dans `lieuNonResolu`, et l'écran — dont le bouton « N sans lieu situé » se
     * nourrit des événements à `placeId: null` — n'en recevait aucun. Le bouton ne
     * paraissait donc jamais, et un cinquième des lieux de l'arbre était invisible **sans
     * qu'aucun écran ne dise qu'il existait**.
     *
     * C'est la disparition silencieuse que ces trois compteurs existaient pour empêcher,
     * reproduite par les compteurs eux-mêmes : **compter une chose n'est pas la montrer.**
     *
     * L'événement est donc rendu, avec `placeId: null` et sa graphie brute. Un événement
     * **sans aucun lieu écrit** reste dehors : il n'a rien à montrer, et l'y faire entrer
     * gonflerait la liste des non situés de lignes vides — 198 sur le vrai arbre.
     */
    public function testUnLieuNonResoluResteEnumerableEtNeFaitPasDeBulle(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '1887', 'Saule, Ombrage, Sylvanie');
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Deces, '1987', 'Hameau introuvable, Nulle-Part');
        $this->poserEvenement(self::SARRIETTE, TypeDEvenement::Naissance, '1899');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        $evenements = $this->evenementsDeLaCarte();
        $resume = $this->resume();

        self::assertSame(
            ['naissance:'.self::SUREAU, 'deces:'.self::SUREAU],
            array_keys($evenements),
            'Le lieu résolu et le lieu non résolu sont tous deux rendus ; le lieu absent, non.',
        );

        self::assertSame(1, $evenements['naissance:'.self::SUREAU]['placeId'], 'Le lieu résolu porte son identifiant.');
        self::assertNull($evenements['deces:'.self::SUREAU]['placeId'], 'Le lieu non résolu n’en a pas — donc pas de bulle.');
        self::assertSame(
            'Hameau introuvable, Nulle-Part',
            $evenements['deces:'.self::SUREAU]['place'],
            'Il porte sa graphie brute : c’est elle qui permet au lecteur de corriger.',
        );

        self::assertSame(1, $resume['lieuNonResolu'], 'Le lieu écrit mais non résolu est compté.');
        self::assertSame(1, $resume['sansLieu'], 'Le lieu absent est compté séparément.');

        self::assertSame(
            [1],
            array_column($this->charge()['lieux'], 'placeId'),
            'Les lieux ne portent que ce qu’une bulle emploie : un non résolu n’y entre pas.',
        );
    }

    /**
     * **`lieux` ne publie pas la table `tree_place` entière**.
     *
     * Ce n'est pas une économie d'octets, c'est la seconde moitié du cercle : un lieu que
     * **seule** une personne vivante hors cercle porte apprendrait à un lecteur qu'il existe,
     * dans l'arbre, quelqu'un né à cet endroit. La liste se construit donc à partir des
     * événements **retenus**, jamais à partir du flux de lieux.
     */
    public function testUnLieuQueSeulUnVivantHorsCercleEmploieNeSortPas(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '1887', 'Saule, Ombrage, Sylvanie');
        $this->poserEvenement(self::HORS_CERCLE, TypeDEvenement::Naissance, '1979', 'Charmille, Ombrage, Sylvanie');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        self::assertSame(
            [1],
            array_column($this->charge()['lieux'], 'placeId'),
            'Charmille n’est portée que par le vivant hors cercle : la bulle n’existe pas pour ce lecteur.',
        );
    }

    /**
     * Un événement sans année est **absent de la carte** et compté dans `sansDate`.
     *
     * `annee` n'est donc jamais `null` dans cette charge : une bulle se place dans l'espace
     * **et** dans le temps, et un point qu'on ne peut pas dater n'est pas filtrable par le
     * curseur d'années. Le taire serait pire que l'omettre — d'où le compteur.
     */
    public function testUnEvenementSansAnneeEstAbsentDeLaCarteEtCompteDansSansDate(): void
    {
        // « Je sais où, pas quand » : un lieu écrit, aucune borne de date.
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '', 'Saule, Ombrage, Sylvanie');
        $this->poserEvenement(self::SARRIETTE, TypeDEvenement::Naissance, '1899', 'Saule, Ombrage, Sylvanie');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        $evenements = $this->evenementsDeLaCarte();

        self::assertSame(['naissance:'.self::SARRIETTE], array_keys($evenements));
        self::assertSame(1, $this->resume()['sansDate'], 'L’événement sans année est compté.');
        self::assertSame(0, $this->resume()['sansLieu'], 'Il portait pourtant un lieu : les compteurs sont indépendants.');

        foreach ($evenements as $evenement) {
            self::assertIsInt($evenement['annee'], '`annee` n’est jamais nulle dans cette charge.');
        }
    }

    /**
     * La forme d'un événement rendu — les onze clés du contrat, et la chaîne `PLAC`
     * **verbatim** à côté de l'identifiant de lieu.
     *
     * Montrer la provenance à côté du libellé normalisé est ce qui rend une résolution
     * douteuse visible au lecteur, plutôt qu'invisible sous un beau nom.
     */
    public function testUnEvenementPorteSonPlacVerbatimEtSaDateNonAplatie(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, 'BET 1901 AND 1909', 'Saule, Ombrage, Sylvanie');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        $evenement = $this->evenementsDeLaCarte()['naissance:'.self::SUREAU];

        self::assertSame('Saule, Ombrage, Sylvanie', $evenement['place'], 'Le PLAC verbatim, toujours présent.');
        self::assertSame(1, $evenement['placeId']);
        self::assertSame(1901, $evenement['annee'], 'La borne basse d’abord.');
        self::assertSame('entre 1901 et 1909', $evenement['texte'], 'La fourchette reste entière dans le texte.');
        self::assertSame('entre', $evenement['qualifier']);
        self::assertFalse($evenement['certain'], 'Une fourchette n’est pas certaine.');
        self::assertNull($evenement['familyId'], 'Une naissance ne relève d’aucune famille.');
    }

    /**
     * La charge dépend du cercle du lecteur, donc de son cookie : un cache partagé ne doit
     * ni la garder, ni la servir à quelqu'un d'autre.
     *
     * `Vary: Cookie` ne se devine pas, et son absence est **silencieuse** : tout marche en
     * développement, où il n'y a pas de cache intermédiaire.
     */
    public function testLaReponseInterditLeCachePartage(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/carte');

        $reponse = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $reponse->getStatusCode());

        // On compare des **directives nommées**, jamais la chaîne entière : Symfony
        // réordonne et complète lui-même `Cache-Control` — une assertion sur la chaîne
        // casserait au prochain composant qui y touche sans que le comportement ait changé.
        // C'est la règle déjà retenue par `tests/Membership/PlafondIndistinguableTest`.
        $cacheControl = (string) $reponse->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl, 'La charge dépend du lecteur.');
        self::assertStringContainsString('no-store', $cacheControl, 'Un cache partagé ne doit pas la garder.');
        self::assertSame('Cookie', $reponse->headers->get('Vary'));
    }

    /** Sans session, la route est fermée — `RegimeVoter::ATTENTE` exige un compte. */
    public function testLaCarteEstFermeeAUnVisiteurNonConnecte(): void
    {
        $this->requete('GET', '/api/carte');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }
}
