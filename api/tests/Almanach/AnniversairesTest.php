<?php

declare(strict_types=1);

namespace App\Tests\Almanach;

use App\Module\Almanach\Contract\TypeDEvenement;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\UserStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/anniversaires` — la porte du jour, les compteurs, et le 29 février.
 *
 * Les cas partent de l'état réel : le cercle n'est pas simulé, c'est le vrai
 * `Membership\Service\CercleDeVisibilite` qui répond depuis une session ouverte par
 * `POST /api/connexion`. Voir l'en-tête de {@see CasDeTestAlmanach}.
 */
final class AnniversairesTest extends CasDeTestAlmanach
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->poserPersonne(self::LECTEUR, 'Natron Romarin', vivant: true, sexe: Sex::Masculin);
        $this->poserPersonne(self::PERE, 'Père Romarin', vivant: true, sexe: Sex::Masculin);
        $this->poserPersonne(self::HORS_CERCLE, 'Cousin Éloigné', vivant: true, sexe: Sex::Masculin);
        $this->poserPersonne(self::SUREAU, 'Marne Dolomite Sureau', vivant: false);
        $this->poserPersonne(self::SARRIETTE, 'Émeri Sarriette', vivant: false, sexe: Sex::Masculin);

        // Le lecteur est l'enfant du père ; les deux défunts n'ont aucun lien posé — ils
        // sont visibles parce qu'ils sont décédés, pas par le cercle.
        $this->poserFamille([self::PERE], [self::LECTEUR]);
    }

    // ── La porte du jour ─────────────────────────────────────────────────────────

    /**
     * **Le test le plus précieux de ce module**.
     *
     * Une date-année (`1887`) est encodée `min = 1887-01-01`. Lire `min` pour en tirer un
     * jour ne rendrait pas « on ne sait pas » : il rendrait le 1<sup>er</sup> janvier. Sur
     * l'arbre réel, **119 des 620 dates** sont de ce qualificatif — le jour de l'An
     * deviendrait, de très loin, le jour le plus chargé de l'histoire de la famille.
     *
     * Le défaut a les trois propriétés qui le rendent redoutable : il est **plausible**
     * (des gens naissent le 1<sup>er</sup> janvier), **silencieux** (aucune exception, une
     * charge bien formée), et **indétectable à l'usage** (personne ne connaît par cœur les
     * 620 dates de son arbre).
     *
     * Le cas pose **deux** événements : la date-année qui ne doit pas être placée, et une
     * date exacte au 1<sup>er</sup> janvier qui doit l'être. Sans la seconde, le test
     * serait vert même si le 1<sup>er</sup> janvier n'existait pas du tout dans la réponse
     * — c'est-à-dire pour la mauvaise raison.
     */
    public function testUneDateAnneeNEstPasPoseeAuPremierJanvier(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '1887', 'Saule');
        $this->poserEvenement(self::SARRIETTE, TypeDEvenement::Naissance, '1 JAN 1899', 'Saule');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/anniversaires');

        $premierJanvier = $this->jour(1, 1);

        self::assertNotNull($premierJanvier, 'Le 1er janvier existe : une naissance réelle y tombe.');
        self::assertSame(
            ['naissance:'.self::SARRIETTE],
            array_column($premierJanvier['evenements'], 'cle'),
            'Seule la date EXACTE du 1er janvier y figure. La date-année « 1887 » encode '
            .'min = 1887-01-01 : la poser ici mettrait 119 des 620 dates de l’arbre réel au jour de l’An.',
        );

        self::assertSame(
            ['naissance' => 1] + ['deces' => 0, 'union' => 0, 'bapteme' => 0, 'autre' => 0],
            $this->resume()['parGenre'],
            'Un seul anniversaire placé.',
        );
        self::assertSame(1, $this->resume()['sansJour']['naissance'], 'La date-année est comptée, pas placée.');
    }

    /**
     * Le même défaut, au mois — `FEB 1920` encode `min = 1920-02-01`.
     *
     * **Cinq lignes seulement sur l'arbre réel**, et c'est précisément pour cela qu'il faut
     * l'écrire : à cinq lignes, personne ne le verra jamais à l'œil, et la règle qui
     * l'autorise resterait en place pour le jour où le fichier en compterait cinquante.
     */
    public function testUneDateMoisNEstPasPoseeAuPremierDuMois(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, 'FEB 1920', 'Saule');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/anniversaires');

        self::assertNull($this->jour(2, 1), 'Une date-mois ne pose rien au 1er du mois.');
        self::assertSame([], $this->charge()['jours'], 'Aucun jour n’est émis : la seule date de l’arbre n’en porte pas.');
        self::assertSame(1, $this->resume()['sansJour']['naissance']);
    }

    /**
     * Les événements sans jour sont **comptés** — et jamais placés « au mieux ».
     *
     * Les taire donnerait un calendrier qui semble couvrir toute la famille alors qu'il
     * n'en montre qu'une fraction. Le compte est ventilé par genre parce que la répartition
     * ne l'est pas : les naissances anciennes sont souvent réduites à une année, les décès
     * récents portent un jour. Un total unique cacherait la différence, qui est justement
     * ce qu'un lecteur veut savoir avant de conclure que « son » ancêtre manque.
     */
    public function testLesEvenementsSansJourSontComptesEtJamaisPlaces(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, 'BET 1901 AND 1909', 'Saule');
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Deces, '1987', 'Charmille');
        $this->poserEvenement(self::SARRIETTE, TypeDEvenement::Deces, 'ABT 1980');
        $this->poserEvenement(self::SARRIETTE, TypeDEvenement::Naissance, '11 MAR 1899', 'Saule');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/anniversaires');

        $resume = $this->resume();

        self::assertSame(
            ['naissance' => 1, 'deces' => 0, 'union' => 0, 'bapteme' => 0, 'autre' => 0],
            $resume['parGenre'],
            'Un seul des quatre événements porte un jour.',
        );
        self::assertSame(
            ['naissance' => 1, 'deces' => 2, 'union' => 0, 'bapteme' => 0, 'autre' => 0],
            $resume['sansJour'],
            'Les trois autres sont comptés, par genre.',
        );

        // Et ils ne se sont posés nulle part : un seul jour dans toute l'année.
        $jours = $this->charge()['jours'];
        self::assertIsArray($jours);
        self::assertCount(1, $jours, 'Trois dates sans jour ne doivent créer aucune case.');
        self::assertSame(['mois' => 3, 'jour' => 11], ['mois' => $jours[0]['mois'], 'jour' => $jours[0]['jour']]);
    }

    /**
     * **Le 29 février sort tel quel.** Ni le 28 février, ni le 1<sup>er</sup> mars.
     *
     * C'est une date réelle, qui revient tous les quatre ans. Ce qu'on en fait les trois
     * autres années est un **choix d'affichage** : le client sait s'il montre un mois, une
     * année ou la journée en cours, et lui seul peut arbitrer. Un serveur qui déplacerait la
     * date mentirait sur la donnée pour simplifier un rendu qu'il ne voit pas — et le
     * mensonge se propagerait à tout client futur, y compris à celui qui voulait faire
     * autrement.
     */
    public function testLeVingtNeufFevrierEstEmisTelQuelSansEtreDeplace(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '29 FEB 1908', 'Saule');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/anniversaires');

        self::assertSame(
            [['mois' => 2, 'jour' => 29]],
            array_map(
                static fn (array $jour): array => ['mois' => $jour['mois'], 'jour' => $jour['jour']],
                $this->charge()['jours'],
            ),
            'Le serveur n’a ni déplacé ni dupliqué le 29 février.',
        );

        self::assertNull($this->jour(2, 28), 'Pas de repli sur le 28 février.');
        self::assertNull($this->jour(3, 1), 'Pas de report au 1er mars non plus.');
    }

    // ── Le cercle ────────────────────────────────────────────────────────────────

    /**
     * **Un mariage de deux défunts est visible d'un compte en attente**.
     *
     * Le pendant indispensable de la règle du « ET » : elle ferme, elle ne doit pas
     * verrouiller. Un compte `en_attente` relève du régime `Regime::Aucun` — il n'a
     * **pas** de cercle et ne voit que des personnes décédées. Ses deux époux l'étant,
     * l'union sort entière, avec ses deux sujets et son libellé composé.
     *
     * Sans ce cas, `testUnMariageDisparaitDesQuUnDesDeuxEpouxEstVivantHorsCercle` serait
     * vert même si la règle était « aucune union ne sort jamais ».
     */
    public function testUnMariageDeDeuxDefuntsEstVisibleDunCompteEnAttente(): void
    {
        $this->poserUnion(42, [self::SUREAU, self::SARRIETTE], '23 SEP 1927', 'Saule');

        $this->connecterUnMembre(UserStatus::EnAttente);
        $this->requete('GET', '/api/anniversaires');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $jour = $this->jour(9, 23);
        self::assertNotNull($jour, 'L’union des deux défunts est placée au 23 septembre.');

        $evenement = $jour['evenements'][0];

        self::assertSame('mariage:42', $evenement['cle']);
        self::assertSame('union', $evenement['genre'], 'Mariage et divorce partagent la pastille « union ».');
        self::assertSame(42, $evenement['familyId']);
        self::assertSame(
            [self::SUREAU, self::SARRIETTE],
            array_column($evenement['sujets'], 'personId'),
            'Une union porte ses DEUX époux — c’est ce qui la distingue d’un jalon de chronologie.',
        );
        self::assertSame(
            'Marne Dolomite Sureau & Émeri Sarriette',
            $evenement['libelle'],
            'Le libellé d’une union se compose APRÈS le filtre, sur des sujets que le cercle a déjà autorisés.',
        );
    }

    /**
     * Les jours sortent **triés et creux** : ni les 365 cases, ni un ordre à retrier.
     *
     * Le tri se fait sur une clé `MM-JJ` zéro-préfixée ; sans ce détail, le 10 du mois
     * passerait avant le 3, et le défaut resterait invisible tant qu'aucun événement ne
     * tomberait après le 9.
     */
    public function testLesJoursSortentTriesEtCreux(): void
    {
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '10 MAR 1887');
        $this->poserEvenement(self::SUREAU, TypeDEvenement::Deces, '3 MAR 1960');
        $this->poserEvenement(self::SARRIETTE, TypeDEvenement::Naissance, '2 JAN 1899');

        $this->connecterUnMembre();
        $this->requete('GET', '/api/anniversaires');

        self::assertSame(
            [[1, 2], [3, 3], [3, 10]],
            array_map(
                static fn (array $jour): array => [$jour['mois'], $jour['jour']],
                $this->charge()['jours'],
            ),
            'Trois jours, dans l’ordre du calendrier — et le 3 mars avant le 10 mars.',
        );
    }

    /**
     * Un jour de la réponse, ou `null` — la clé est le couple `(mois, jour)`, jamais un
     * indice de tableau : la liste est creuse.
     *
     * @return array{mois: int, jour: int, evenements: list<array<string, mixed>>}|null
     */
    private function jour(int $mois, int $jour): ?array
    {
        $charge = $this->charge();
        self::assertIsArray($charge['jours'] ?? null, 'La charge porte une clé `jours`.');

        foreach ($charge['jours'] as $candidat) {
            self::assertIsArray($candidat);

            if ($candidat['mois'] === $mois && $candidat['jour'] === $jour) {
                /* @var array{mois: int, jour: int, evenements: list<array<string, mixed>>} */
                return $candidat;
            }
        }

        return null;
    }
}
