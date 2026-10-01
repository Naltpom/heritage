<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * L'ARBRE EST DESSINÉ **DEPUIS LE LECTEUR**, PAS DEPUIS LA RACINE.
 *
 * Il ne l'était pas. Un seul placement était calculé pour toute l'application, bâti sur
 * `tree.personne_racine_id`, et servi tel quel à tout le monde. Or la mise en page en
 * découle entièrement :
 *
 * - `génération 0` est la rangée de cette personne — tout le monde se situe par rapport
 *   à elle, en positif vers ses descendants et en négatif vers ses ascendants ;
 * - la récursion d'ascendance part d'elle, donc le bloc paternel et le bloc maternel
 *   s'étalent autour de *sa* position ;
 * - les quatre couleurs de branche veulent dire « le quart d'ascendance **de cette
 *   personne** » — `pp` est la lignée de son grand-père paternel, pas du vôtre.
 *
 * C'est le même argument que {@see \App\Module\Tree\Serializer\ParenteDuLecteur} tient
 * déjà pour les libellés : servir le point de vue de la racine à tout le monde est juste
 * pour un compte et faux pour tous les autres, **sans en avoir l'air**. « Branche
 * paternelle » est une phrase parfaitement plausible, y compris quand elle désigne le
 * côté paternel de quelqu'un d'autre.
 *
 * ── Ce que le moteur n'a pas eu à changer ──────────────────────────────────────
 *
 * Rien. Il reçoit un `egoId` dans son jeu de placement, comme avant ; seul cet
 * identifiant varie désormais d'un lecteur à l'autre. Le fichier de référence des
 * 377 nœuds reste donc valable tel quel — la preuve du portage de `graphe.py` est
 * intacte, et {@see PlacementNonRegressionTest} continue de la tenir.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
final class ArbreVuDuLecteurTest extends ArbreWebTestCase
{
    /**
     * Le cas qui donne son sens à tout le reste : **deux lecteurs, deux mises en page**.
     *
     * L'un est rattaché à l'ego de l'arbre de test — la racine —, l'autre à l'aïeule. Les
     * générations de tous les nœuds doivent se décaler entre les deux charges, puisque
     * `génération 0` n'est plus la même rangée.
     */
    public function testDeuxLecteursRattachesADeuxPersonnesNObtiennentPasLeMemeArbre(): void
    {
        $this->rattacher('depuis-ego@heritage.test', 'ego');
        $this->rattacher('depuis-aieule@heritage.test', 'aieule');

        $vuDeLEgo = $this->rangeesVuesDe('depuis-ego@heritage.test');
        $vuDeLAieule = $this->rangeesVuesDe('depuis-aieule@heritage.test');

        self::assertNotSame(
            $vuDeLEgo,
            $vuDeLAieule,
            'Deux lecteurs rattachés à deux personnes ne regardent pas le même arbre.',
        );

        // Et le décalage est exactement celui qu'on attend : chacun est sur **sa** rangée
        // zéro. C'est la propriété, pas seulement « les charges diffèrent ».
        $ego = 'p'.$this->idDe('ego');
        $aieule = 'p'.$this->idDe('aieule');

        self::assertSame(0.0, $vuDeLEgo[$ego], 'Le lecteur rattaché à l’ego se voit en rangée 0.');
        self::assertSame(0.0, $vuDeLAieule[$aieule], 'Le lecteur rattaché à l’aïeule se voit en rangée 0.');
        self::assertNotSame(0.0, $vuDeLAieule[$ego], 'Vu de l’aïeule, l’ego n’est plus à la rangée zéro.');
    }

    /**
     * Le champ `ego` de la charge nomme **le lecteur**, et non la racine.
     *
     * C'est lui dont le viewer se sert pour son cadrage initial. S'il nommait la racine
     * pendant que les coordonnées, elles, sont calculées depuis le lecteur, la vue
     * s'ouvrirait sur la bonne personne pour une seule des deux — et ce serait pire que
     * l'état d'avant, où les deux étaient au moins d'accord.
     */
    public function testLaChargeNommeLeLecteurCommeEgo(): void
    {
        $this->rattacher('depuis-aieule@heritage.test', 'aieule');
        $this->connexionDe('depuis-aieule@heritage.test');
        $this->requete('/api/arbre/graphe');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('p'.$this->idDe('aieule'), $this->charge()['ego']);
    }

    /**
     * **Un lecteur rattaché à personne regarde depuis la racine**, comme avant.
     *
     * C'est le repli, et il doit rester celui-là : un placement sans ego ferait retomber
     * tout l'arbre sur la rangée zéro, c'est-à-dire empilé. Deux comptes dans ce cas
     * partagent donc leur mise en page — et peuvent partager leur entrée de cache.
     */
    public function testUnLecteurSansRattachementRegardeDepuisLaRacine(): void
    {
        $this->rattacher('depuis-ego@heritage.test', 'ego');

        $rattache = $this->rangeesVuesDe('depuis-ego@heritage.test');
        $flottant = $this->rangeesVuesDe('temoin@heritage.test');

        // `tree.personne_racine_id` **est** l'ego de l'arbre de test : les deux charges
        // doivent donc coïncider, et c'est ce qui prouve que le repli vise bien la racine
        // plutôt que « pas d'ego du tout ».
        self::assertSame($rattache, $flottant);
    }

    /**
     * **L'`ETag` distingue les deux points de vue.**.
     *
     * Sans cela, deux lecteurs de même cercle et sans demande en cours recevraient le
     * même `ETag` sur deux charges dont *toutes* les coordonnées diffèrent : en le
     * présentant, l'un obtiendrait `304` sur le graphe de l'autre. C'est la règle écrite
     * en tête de {@see \App\Module\Tree\Serializer\EtagDuGraphe} — « quand on hésite, on
     * distingue » —, appliquée à une dimension de plus.
     */
    public function testLEtagDistingueLesDeuxPointsDeVue(): void
    {
        $this->rattacher('depuis-ego@heritage.test', 'ego');
        $this->rattacher('depuis-aieule@heritage.test', 'aieule');

        self::assertNotSame(
            $this->etagVuDe('depuis-ego@heritage.test'),
            $this->etagVuDe('depuis-aieule@heritage.test'),
        );
    }

    /**
     * Le pendant : **un même lecteur retrouve son `ETag`** et reçoit `304`.
     *
     * C'est la propriété dont dépend toute la revalidation ; ajouter une composante ne
     * doit pas la coûter.
     */
    public function testUnMemeLecteurRevalideEtObtient304(): void
    {
        $this->rattacher('depuis-aieule@heritage.test', 'aieule');

        $etag = $this->etagVuDe('depuis-aieule@heritage.test');

        $this->client->request('GET', '/api/arbre/graphe', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Les rangées de chaque personne, telles que ce compte les reçoit.
     *
     * On compare les **ordonnées** et non les abscisses : elles ne dépendent que de la
     * génération, donc du point de vue, là où `x` bouge aussi pour des raisons de largeur
     * de fiche. Un test qui comparerait `x` serait vert pour la bonne raison mais illisible
     * quand il rougit.
     *
     * @return array<string, float>
     */
    private function rangeesVuesDe(string $courriel): array
    {
        $this->connexionDe($courriel);
        $this->requete('/api/arbre/graphe');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $rangees = [];
        foreach ($this->charge()['noeuds'] as $noeud) {
            self::assertIsArray($noeud);
            if ('personne' === ($noeud['type'] ?? null)) {
                $id = $noeud['id'];
                self::assertIsString($id);
                $y = $noeud['y'];
                // `json_decode` rend un entier pour `-1200` et un flottant pour `-1200.5` :
                // la charge ne les arrondit jamais (§ 1.4), donc les deux formes arrivent.
                self::assertIsNumeric($y);
                $rangees[$id] = (float) $y;
            }
        }

        ksort($rangees);

        return $rangees;
    }

    /**
     * **`parente` et `pont` sur chaque nœud de personne** (décision du 2026-10-01, § 5.2).
     *
     * Vu de l'ego de l'arbre de test : ses parents sont « famille » ; le couple mixte
     * (Pyroxène × Cobalt, et leur enfant) n'est relié à l'ego par **aucun** chemin — c'est
     * l'îlot. Il n'est **pas** `alliance` — la décision vise « tout le reste qui est
     * relié », et l'îlot n'est relié à rien : il est servi `famille`, sans pont, pour rester
     * affiché comme avant. Et sans branche : le repli sur la colonne persistée, qui lui
     * aurait rendu le quart de la racine, ne joue plus.
     */
    public function testChaqueNoeudDitSaParenteVueDuLecteur(): void
    {
        $this->rattacher('depuis-ego@heritage.test', 'ego');
        $this->connexionDe('depuis-ego@heritage.test');
        $this->requete('/api/arbre/graphe');

        $noeuds = [];
        foreach ($this->charge()['noeuds'] as $noeud) {
            if ('personne' === $noeud['type']) {
                $noeuds[$noeud['id']] = $noeud;
            }
        }

        foreach (['ego', 'aieule', 'aieul'] as $cle) {
            $noeud = $noeuds['p'.$this->idDe($cle)];
            self::assertSame('famille', $noeud['parente'], $cle);
            self::assertArrayNotHasKey('pont', $noeud, '`pont` n’existe que sur une famille par alliance.');
        }

        foreach (['cousin', 'conjointe', 'sansDate'] as $cle) {
            $noeud = $noeuds['p'.$this->idDe($cle)];
            self::assertSame('famille', $noeud['parente'], $cle.' : un îlot n’est relié à rien, il n’est pas « par alliance ».');
            self::assertArrayNotHasKey('pont', $noeud, 'Un îlot n’a pas de pont.');
            self::assertNull($noeud['branch'], 'L’îlot ne reçoit aucune branche du lecteur.');
        }
    }

    private function etagVuDe(string $courriel): string
    {
        $this->connexionDe($courriel);
        $this->requete('/api/arbre/graphe');

        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertIsString($etag);

        return $etag;
    }

    /** Un compte actif **réellement rattaché** : c'est ce qui lui donne un point de vue. */
    private function rattacher(string $courriel, string $personne): void
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

    private function connexionDe(string $courriel): void
    {
        if (null === $this->em()->getRepository(\App\Module\Core\Entity\User::class)->findOneBy(['email' => $courriel])) {
            $this->creerLecteurHorsCercle($courriel);
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }
}
