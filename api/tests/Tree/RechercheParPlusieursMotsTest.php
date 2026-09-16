<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Service\EcritureDeLArbrePourProposal;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  « MARNE BOURRACHE » NE TROUVAIT RIEN — et personne ne pouvait le voir tomber.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Deux défauts distincts, tous deux mesurés sur la base de travail avant correction,
 * tous deux invisibles de la suite : **aucun test n'exerçait la recherche avec un terme
 * de plus d'un mot.** Les trois seuls tests qui traversaient `candidatsDeRecherche()`
 * (`RoutesDeLArbreTest`) employaient des termes d'un seul mot, que l'ancienne et la
 * nouvelle forme satisfont à l'identique. Rien ne pouvait donc rougir, ni dans un sens
 * ni dans l'autre.
 *
 * ── Défaut A — l'ordre des mots ─────────────────────────────────────────────────
 *
 * `search_key` s'écrit « patronyme, prénoms » ; un membre tape « Prénom Nom ». La requête
 * cherchait le terme **entier** en préfixe ou en infixe, si bien que « Prénom Nom » ne
 * correspondait à rien. Mesuré : sur les **272** fiches de la base de travail qui portent
 * un prénom et un patronyme, interrogées chacune avec son propre « Prénom Nom », la forme
 * d'avant en retrouvait **0**. Pas « peu » : zéro. Et « deuxième prénom + patronyme »
 * échouait pareillement sur **156** fiches.
 *
 * ── Défaut B — le surnom hors de la clé ─────────────────────────────────────────
 *
 * `display_name` se compose `nickname ?? given_names`, puis `surname` : le surnom
 * **remplace** les prénoms en tête. La clé, elle, ne recevait que les prénoms et le
 * patronyme. Valider une op `nickname` renommait donc la fiche **sans** la rendre
 * trouvable sous son nouveau nom. Mesuré : 12 fiches portent un surnom, **3** l'avaient
 * hors de leur clé — trois fiches affichées sous un nom que la recherche ne trouvait pas.
 *
 * Ce comportement-là était même **épinglé vert** par
 * `EcritureDeLArbrePourProposalTest::testLesColonnesDenormaliseesSuiventLesNoms()`, qui
 * affirmait `BREVAL HELENE` après une op `nickname`. Retourner cette assertion fait
 * partie du correctif, pas de la casse.
 *
 * ── Ce que ce fichier protège en plus, et qui compte davantage ──────────────────
 *
 * Élargir une recherche est le genre de correctif qui répare l'usage et ouvre une fuite.
 * Trois tests ci-dessous existent pour que cela ne puisse pas arriver sans rougir :
 * l'exigence de **tous** les mots (§ {@see self::testChaqueMotEstExigeEtNonSeulementLUnDEux()}),
 * la disparition d'une personne vivante hors cercle **même quand tous ses mots
 * correspondent** ({@see self::testUneVivanteHorsCercleResteAbsenteMemeQuandTousSesMotsCorrespondent()}),
 * et l'inertie du comportement sur un terme d'un seul mot
 * ({@see self::testUnTermeDUnSeulMotSeComportePeutEtreCommeAvant()}).
 *
 * > **La recherche de l'arbre n'est pas la recherche de rattachement.** Le § 4.2 et
 * > `PlafondIndistinguableTest` portent sur `POST /api/inscription/rattachement`, servie
 * > par `Membership\Service\RechercheDeRattachement`, qui compare `surname` et
 * > `given_names` **en PHP** et ne lit jamais `tree_person.search_key` — son port
 * > `PersonneDeLArbre` ne porte même pas la colonne. Aucune ligne de ce fichier ne touche
 * > donc au plafond par jeton ni à son indistinguabilité ; ce qui protège la route
 * > éprouvée ici est le **cercle**, et c'est lui qu'on remet à l'épreuve ci-dessous.
 */
final class RechercheParPlusieursMotsTest extends ArbreWebTestCase
{
    /**
     * **Défaut A.** « Marne Sureau » — prénom puis patronyme, l'ordre naturel d'une
     * saisie — doit retrouver l'aïeule, dont la clé s'écrit « SUREAU MARNE DOLOMITE ».
     *
     * C'est le test qui échoue avant le correctif : l'ancienne requête cherchait
     * `'MARNE SUREAU%'` et `'% MARNE SUREAU%'`, qui ne correspondent à rien.
     */
    public function testUnPrenomEtUnPatronymeDansLOrdreNaturelRetrouventLaFiche(): void
    {
        $this->connexionDe('membre@heritage.test');

        $aieule = $this->idDe('aieule');

        foreach (['Marne Sureau', 'Sureau Marne', 'marne   sureau', 'Dolomite Sureau'] as $terme) {
            $elements = $this->rechercher($terme);

            self::assertSame(
                [$aieule],
                array_column($elements, 'personId'),
                \sprintf('« %s » doit retrouver l’aïeule : la clé porte ses trois mots, dans un ordre que l’utilisateur n’a pas à connaître.', $terme),
            );
        }
    }

    /**
     * **Défaut A, l'autre moitié** : le deuxième prénom. « Dolomite » n'est ni le premier
     * mot de la clé ni le deuxième — les deux mots cherchés ne sont pas contigus, ce
     * qu'aucune forme fondée sur un motif unique ne peut satisfaire.
     */
    public function testUnDeuxiemePrenomEtUnPatronymeRetrouventLaFiche(): void
    {
        $this->connexionDe('membre@heritage.test');

        self::assertSame(
            [$this->idDe('aieule')],
            array_column($this->rechercher('Dolomite Marne'), 'personId'),
            'Deux prénoms non contigus dans la clé doivent suffire.',
        );
    }

    /**
     * **Défaut B.** Une op `nickname` validée change `display_name` ; la fiche doit
     * devenir trouvable **sous le nom qu'elle affiche désormais**.
     *
     * On passe par le port d'écriture réel — celui que la validation d'une demande
     * appelle — plutôt que par un `setSearchKey()` à la main : c'est justement le
     * recalcul qui était en cause.
     */
    public function testApresUnSurnomValideLaFicheEstTrouvableSousSonNouveauNom(): void
    {
        $this->connexionDe('membre@heritage.test');

        $aieule = $this->idDe('aieule');

        // Avant : « Marne Dolomite Sureau », introuvable par « Silice ».
        self::assertSame([], $this->rechercher('Silice'), 'Le surnom n’est pas encore posé.');

        $port = self::getContainer()->get(EcritureDeLArbrePourProposal::class);
        \assert($port instanceof EcritureDeLArbrePourProposal);

        $applique = $port->appliquerChamps(SubjectKind::Personne, $aieule, ['nickname' => 'Silice']);

        self::assertSame('Silice Sureau', $applique->libelle, 'Le surnom pilote le nom d’affichage (§ 3.1).');

        $this->em()->clear();

        self::assertSame(
            'SUREAU MARNE DOLOMITE SILICE',
            $this->ficheDe($aieule)->getSearchKey(),
            'Le surnom entre dans la clé, EN DERNIER : le patronyme doit rester en tête de l’index de préfixe.',
        );

        // C'est l'assertion qui porte le défaut : la fiche s'affiche « Silice Sureau »,
        // elle doit se trouver sous ce nom-là.
        self::assertSame(
            [$aieule],
            array_column($this->rechercher('Silice Sureau'), 'personId'),
            'Une fiche renommée que la recherche ne retrouve pas est une fiche perdue.',
        );
        self::assertSame(
            [$aieule],
            array_column($this->rechercher('Silice'), 'personId'),
            'Et par le surnom seul.',
        );
    }

    /**
     * **Le surnom ne doit pas être ajouté deux fois.** Quand il reprend l'un des prénoms
     * — 9 des 12 cas réels — la clé ne doit pas gagner un mot déjà présent : ce serait
     * réécrire 12 lignes au lieu de 3, donc incrémenter 12 `version` et périmer les
     * demandes en attente qui les visaient (§ 9.6).
     */
    public function testUnSurnomQuiRepeteUnPrenomNAjouteRienALaCle(): void
    {
        $this->connexionDe('membre@heritage.test');

        $aieule = $this->idDe('aieule');
        $avant = $this->ficheDe($aieule)->getSearchKey();

        $port = self::getContainer()->get(EcritureDeLArbrePourProposal::class);
        \assert($port instanceof EcritureDeLArbrePourProposal);

        $port->appliquerChamps(SubjectKind::Personne, $aieule, ['nickname' => 'Dolomite']);
        $this->em()->clear();

        self::assertSame(
            $avant,
            $this->ficheDe($aieule)->getSearchKey(),
            'Le surnom figurait déjà parmi les prénoms : la clé ne change pas, donc la ligne n’est pas réécrite.',
        );
    }

    /**
     * **La conjonction est une conjonction.** Chaque mot est EXIGÉ. Si la requête
     * devenait une disjonction — l'erreur naturelle en élargissant une recherche —, un
     * terme dont un seul mot correspond rendrait quand même la fiche, et la recherche
     * deviendrait un listage.
     */
    public function testChaqueMotEstExigeEtNonSeulementLUnDEux(): void
    {
        $this->connexionDe('membre@heritage.test');

        // « Sureau » existe (l'aïeule), « Bourrache » n'existe dans aucune clé du socle.
        self::assertNotSame([], $this->rechercher('Sureau'), 'Le premier mot, seul, correspond bien.');

        self::assertSame(
            [],
            $this->rechercher('Sureau Bourrache'),
            'Un mot qui ne correspond à rien doit annuler le résultat : sinon le ET est devenu un OU.',
        );
    }

    /**
     * **Le cercle décide toujours seul, et élargir la requête n'y change rien.**.
     *
     * Le cousin est vivant et hors du cercle du lecteur. Ses deux mots — « Pyroxène » et
     * « Tilleul » — correspondent exactement à sa clé : il est donc **candidat** de la
     * requête élargie, et c'est précisément ce qui rend ce test utile. Il doit rester
     * **absent** de la réponse, pas réduit (ADR 0003).
     *
     * Sans cette assertion, le correctif de rappel serait exactement le genre de
     * changement qui répare l'usage en ouvrant une énumération.
     */
    public function testUneVivanteHorsCercleResteAbsenteMemeQuandTousSesMotsCorrespondent(): void
    {
        $this->connexionDe('membre@heritage.test');

        // Le nom complet du cousin, dans les deux ordres : la requête le retient comme
        // candidat dans les deux cas.
        foreach (['Pyroxène Tilleul', 'Tilleul Pyroxène'] as $terme) {
            self::assertSame(
                [],
                $this->rechercher($terme),
                \sprintf('« %s » désigne une personne vivante hors cercle : elle est ABSENTE, pas réduite.', $terme),
            );
        }

        // Et la réponse ne dit pas non plus qu'elle a été retirée.
        $this->requete('/api/arbre/recherche?q='.rawurlencode('Pyroxène Tilleul'));
        self::assertFalse($this->charge()['tronque'], '`tronque` se calcule sur le jeu déjà filtré : il ne doit rien apprendre.');
    }

    /**
     * **Sur un terme d'un seul mot, le comportement est inchangé** — et c'est ce qui rend
     * ce correctif sûr.
     *
     * Mesuré hors suite avant livraison : 749 termes d'un seul mot confrontés aux 279
     * clés de la base de travail donnent **0 divergence** ligne à ligne entre l'ancienne
     * et la nouvelle forme. Ce test rejoue la propriété sur le socle : préfixe, infixe,
     * et absence.
     */
    public function testUnTermeDUnSeulMotSeComportePeutEtreCommeAvant(): void
    {
        $this->connexionDe('membre@heritage.test');

        // Préfixe de clé : « SUREAU MARNE DOLOMITE ».
        self::assertSame([$this->idDe('aieule')], array_column($this->rechercher('Sureau'), 'personId'));
        // Infixe, sur une frontière de mot.
        self::assertSame([$this->idDe('aieule')], array_column($this->rechercher('Marne'), 'personId'));
        // Rien.
        self::assertSame([], $this->rechercher('Bourrache'));
    }

    /**
     * **Les accents sont repliés des deux côtés**, et c'est la garantie que
     * {@see \App\Module\Core\Service\CleDeRecherche} existe pour tenir.
     *
     * Ce test ne pouvait pas passer avant que le socle cesse de semer ses clés à la main
     * (`mb_strtoupper($nom.' '.$prenoms)`) : « Émeri Sarriette » était semé
     * `SARRIETTE ÉMERI`, que le terme « Émeri » — replié en `EMERI` — ne trouve pas. La
     * fixture, pas le produit, était en cause : c'est exactement l'angle mort qu'une
     * fixture écrite à la main fabrique.
     */
    public function testLesAccentsSontReplierDesDeuxCotes(): void
    {
        $this->connexionDe('membre@heritage.test');

        $aieul = $this->idDe('aieul');

        foreach (['Émeri Sarriette', 'Emeri Sarriette', 'emeri', 'ÉMERI'] as $terme) {
            self::assertSame(
                [$aieul],
                array_column($this->rechercher($terme), 'personId'),
                \sprintf('« %s » doit retrouver la même fiche : une seule définition du repliement, appelée des deux côtés.', $terme),
            );
        }
    }

    /**
     * Un terme fait de ponctuation seule ne produit aucun mot, donc aucune requête —
     * et surtout **aucun joker**. `%` et `_` ne survivent pas au repliement ; s'ils y
     * survivaient, `LIKE '%%'` listerait l'arbre entier.
     */
    public function testUnTermeSansMotUtileNeListeRien(): void
    {
        $this->connexionDe('membre@heritage.test');

        foreach (['%%', '__', '---', '% %'] as $terme) {
            self::assertSame(
                [],
                $this->rechercher($terme),
                \sprintf('« %s » ne doit rien lister : un joker qui passerait rendrait tout l’arbre.', $terme),
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * La recherche par HTTP, jusqu'aux `elements`.
     *
     * @return list<array<string, mixed>>
     */
    private function rechercher(string $terme): array
    {
        $this->requete('/api/arbre/recherche?q='.rawurlencode($terme));

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            \sprintf('La recherche « %s » doit aboutir.', $terme),
        );

        /** @var list<array<string, mixed>> $elements */
        $elements = $this->charge()['elements'];

        return $elements;
    }

    private function ficheDe(int $id): Person
    {
        $personne = $this->em()->getRepository(Person::class)->find($id);
        self::assertInstanceOf(Person::class, $personne);

        return $personne;
    }

    private function connexionDe(string $courriel): void
    {
        if (null === $this->em()->getRepository(User::class)->findOneBy(['email' => $courriel])) {
            $this->creerUtilisateur($courriel);
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            'La connexion de test doit réussir.',
        );
    }
}
