<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LES DEUX ÉCRANS QUE L'ADMINISTRATEUR LIT **AVANT** DE CLIQUER.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * `GET /api/administration/demandes` — la file — et `GET /api/administration/demandes/{id}`
 * — le diff — n'avaient jamais reçu une seule requête HTTP. `DiffEtRoutesTest` couvre le
 * sérialiseur et l'analyse en appelant les services à la main ; c'est le bon niveau pour
 * ce qu'il éprouve, et ça ne dit rien du contrôleur, de la pagination, des filtres, ni
 * des gardes.
 *
 * Ce sont pourtant les deux routes où un trou coûte le plus cher, parce qu'elles ne
 * servent qu'à une chose : **fonder une décision qu'un humain va prendre**. Une écriture
 * fausse se rattrape — il y a un journal, des révisions, une version. Une décision prise
 * sur un écran qui ment ne laisse aucune trace de son erreur : tout, ensuite, est
 * cohérent.
 *
 * ── Ce que ce fichier éprouve, et qui n'est pas la forme de la charge ────────────
 *
 * La forme est vérifiée, mais elle n'est pas le sujet. Le sujet est **l'accord entre les
 * deux écrans et ce qui se passe au clic** :
 *
 * ```
 *   GET …/demandes/{id}  →  resolutionPrevue = « X »
 *   POST …/{id}/valider  →  il arrive exactement X
 * ```
 *
 * `testLAnnonceDuDiffEstTenueAuClic()` produit les **quatre** issues pour de vrai et
 * ferme la boucle sur chacune. C'est la seule assertion qui protège la promesse du
 * § 6.7 : « sans `resolutionPrevue`, l'administrateur découvre la caducité en cliquant,
 * ce qui la rend indistinguable d'une panne. » Un test qui lirait `resolutionPrevue`
 * sans jamais cliquer laisserait les deux moitiés diverger en silence.
 *
 * ── `cible_disparue`, la seconde caducité ───────────────────────────────────────
 *
 * `DeuxConflitsEnHttpTest` oppose `demande_caduque` et `verrou_concurrent`. Il manquait
 * la quatrième issue de `valider`, et c'est la plus facile à confondre avec la première :
 * `cible_disparue` est **aussi** une caducité, **aussi** non rejouable, et écrit aussi le
 * statut — mais son `details` porte `cible` là où l'autre porte `champs`, et un front qui
 * les traiterait pareil afficherait « la fiche a changé » pour une fiche qui n'existe
 * plus. Les trois conflits sont donc produits **dans un seul cas** et comparés champ par
 * champ, pour la raison déjà écrite dans l'autre fichier : trois tests séparés peuvent
 * passer tous les trois en décrivant la même réponse.
 */
final class FileEtDiffEnHttpTest extends CasDeTestDemandesHttp
{
    /** Une seconde fiche, pour que les filtres aient quelque chose à filtrer. */
    private const int AUTRE_PERSONNE = 41;

    private const int PERSONNE_OPALE = 63;

    private const int PERSONNE_TUF = 64;

    /** Les clés du § 6.5 « résumé », telles que la file les rend. */
    private const array CLES_DU_RESUME = [
        'id', 'kind', 'status', 'authorUserId', 'authorLabel', 'targetKind', 'targetId',
        'targetLabel', 'message', 'autoValidated', 'submittedAt', 'createdAt',
        // `complementDeDemandeId` (ADR 0017) : la demande que celle-ci complète, ou `null`.
        'nouvellesPersonnes', 'complementDeDemandeId',
    ];

    /** Les clés du § 6.7 « administration » : le résumé de l'auteur, plus de quoi décider. */
    private const array CLES_DU_DIFF = [
        'id', 'kind', 'status', 'authorUserId', 'authorLabel', 'targetKind', 'targetId',
        'targetLabel', 'message', 'decisionMessage', 'decidedByLabel', 'decidedAt',
        // `complementDe` et `empreinte` viennent du § 6.5 ; `complements`, du § 6.7 — les trois
        // depuis le 10/10/2026 (ADR 0017).
        'autoValidated', 'duplicateOfId', 'complementDe', 'submittedAt', 'createdAt', 'ops', 'brouillons',
        'liens', 'revisions', 'empreinte', 'cible', 'resolutionPrevue', 'dependances',
        'demandesConcurrentes', 'doublonsProbables', 'complements',
    ];

    // ---------------------------------------------------------------------------------
    // Les gardes — avant tout le reste
    // ---------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function lesDeuxLectures(): iterable
    {
        yield 'la file' => ['/api/administration/demandes'];
        yield 'le diff' => ['/api/administration/demandes/1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lesDeuxLectures')]
    public function testUnAnonymeEstRefuseSurLesDeuxLectures(string $chemin): void
    {
        $this->requete('GET', $chemin);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // `erreur()` du socle vérifie déjà l'enveloppe à quatre clés du § 15.1, dans
        // l'ordre, `champs` et `details` présents à `null` plutôt qu'absents.
        self::assertSame('authentification_requise', $this->erreur()['code']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lesDeuxLectures')]
    public function testUnContributeurNonAdministrateurEstRefuseSurLesDeuxLectures(string $chemin): void
    {
        $this->connexionContributeur();

        $this->requete('GET', $chemin);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->erreur()['code']);
    }

    /**
     * La file est fermée **avant** d'être calculée : un `403` ne doit pas dépendre du
     * contenu de la base, sinon son absence sur une base vide passerait inaperçue.
     */
    public function testLaFileEstRefuseeAUnContributeurMemeQuandElleEstVide(): void
    {
        $this->connexionContributeur();

        $this->requete('GET', '/api/administration/demandes');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame([], $this->em()->getRepository(\App\Module\Proposal\Entity\ChangeRequest::class)->findAll());
    }

    public function testUnIdentifiantInconnuRend404(): void
    {
        $this->connexionAdministrateur();

        $this->requete('GET', '/api/administration/demandes/999999');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('introuvable', $this->erreur()['code']);
    }

    // ---------------------------------------------------------------------------------
    // La file
    // ---------------------------------------------------------------------------------

    /**
     * **Le filtre par demandeur.** Sur une file qui mêle les dépôts de plusieurs
     * personnes, répondre à « qu'est devenue ma proposition » obligeait à parcourir
     * tout l'écran à l'œil.
     *
     * Le filtre se pose sur la **même requête** que celui de l'état, et non après
     * coup en mémoire : sans quoi la pagination compterait les demandes des autres,
     * et le total afficherait un nombre que la liste ne montre pas.
     */
    public function testLaFileSeFiltreParDemandeur(): void
    {
        $this->connexionContributeur();
        $sien = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')], 'du contributeur');

        $this->connexionSecondContributeur();
        $autre = $this->deposer([$this->opTexte('nickname', null, 'Perle', seq: 2)], 'du second');

        $this->connexionAdministrateur();
        $this->requete('GET', '/api/administration/demandes');

        $toutes = $this->charge()['elements'];
        $auteurDuSien = null;
        foreach ($toutes as $ligne) {
            if ($ligne['id'] === $sien) {
                $auteurDuSien = $ligne['authorUserId'];
            }
        }

        self::assertNotNull($auteurDuSien, 'Sans cet identifiant, le cas ne prouverait rien.');

        $this->requete('GET', '/api/administration/demandes?auteur='.$auteurDuSien);

        self::assertResponseIsSuccessful();

        $ids = array_column($this->charge()['elements'], 'id');

        self::assertContains($sien, $ids, 'La demande de cet auteur est là.');
        self::assertNotContains($autre, $ids, 'Celle de quelqu\'un d\'autre, non.');
        self::assertSame(1, $this->charge()['total'], 'Le total suit le filtre, sinon la pagination ment.');
    }

    /**
     * **Les auteurs sont servis par leur propre route, jamais par l'enveloppe.**.
     *
     * Le premier jet les avait glissés dans la charge de la file. Le cas
     * `testLaFileRendLEnveloppeEtTrieParDepotCroissant` l'a refusé — « l'enveloppe
     * du § 1.5, sans une clé de plus » — et il avait raison : une enveloppe de
     * liste qui varie selon l'écran cesse d'être une enveloppe de liste.
     *
     * La route ne prend aucun filtre, et c'est voulu : changer de statut ne doit
     * pas faire disparaître sous le doigt l'auteur qu'on venait de choisir.
     */
    public function testLesAuteursSontServisParUneRouteAPart(): void
    {
        $this->connexionContributeur();
        $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')], 'une demande');

        $this->connexionAdministrateur();
        $this->requete('GET', '/api/administration/demandes/auteurs');

        self::assertResponseIsSuccessful();

        $charge = $this->charge();

        self::assertSame(['elements'], array_keys($charge), 'Une route, une forme, rien de plus.');
        self::assertNotSame([], $charge['elements'], 'Un auteur a déposé : il doit paraître.');
        self::assertSame(['userId', 'libelle'], array_keys($charge['elements'][0]));
    }

    /**
     * L'enveloppe du § 1.5, et l'ordre promis : `submittedAt` **croissant**.
     *
     * L'ordre n'est pas cosmétique. La file est une file d'attente : le premier déposé
     * est le premier traité, et c'est ce qui rend acceptable de faire patienter un
     * contributeur. Un tri par `id` décroissant — le défaut de bien des listes — inverse
     * silencieusement la règle et enterre les demandes anciennes.
     */
    public function testLaFileRendLEnveloppeEtTrieParDepotCroissant(): void
    {
        $this->connexionContributeur();
        $premier = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')], 'déposée en premier');
        $deuxieme = $this->deposer([$this->opTexte('nickname', null, 'Perle', seq: 2)], 'déposée en deuxième');
        $troisieme = $this->deposer([$this->opTexte('givenNames', 'Marne Dolomite', 'Marne', seq: 3)], 'déposée en troisième');

        $this->connexionAdministrateur();
        $this->requete('GET', '/api/administration/demandes');

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(
            ['elements', 'total', 'page', 'parPage'],
            array_keys($charge),
            'L’enveloppe de liste du § 1.5, sans une clé de plus.',
        );

        self::assertSame(3, $charge['total']);
        self::assertSame(1, $charge['page']);
        self::assertSame(25, $charge['parPage']);

        $elements = $charge['elements'];
        self::assertIsArray($elements);
        self::assertCount(3, $elements);

        self::assertSame(
            [$premier, $deuxieme, $troisieme],
            array_column($elements, 'id'),
            'La file est une file : le premier déposé est le premier rendu.',
        );

        self::assertSame(
            self::CLES_DU_RESUME,
            array_keys((array) $elements[0]),
            'Le résumé du § 6.5, à la clé près — `assertSame` sur les clés voit aussi ce qui est EN TROP.',
        );
    }

    /**
     * Le résumé ne porte **ni le courriel de l'auteur ni son mot de passe haché**.
     *
     * La file est l'endroit le plus tentant pour ajouter « juste l'adresse, pour pouvoir
     * écrire au contributeur ». Le § 10.1 ne la donne pas ; ce test fige l'absence, pour
     * que l'ajouter demande de changer un test qui dit pourquoi.
     */
    public function testLaFileNeDivulguePasLIdentifiantDeConnexionDeLAuteur(): void
    {
        $this->connexionContributeur();
        $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]);

        $this->connexionAdministrateur();
        $this->requete('GET', '/api/administration/demandes');

        $json = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('opale@example.net', $json);
        self::assertStringNotContainsString('@example.net', $json);
        self::assertStringNotContainsString('password', $json);
    }

    /**
     * `total` est le nombre d'éléments **après** filtrage.
     *
     * C'est écrit dans le sérialiseur — « sans cela, un lecteur déduit du compte ce que la
     * liste lui cache » — et c'est le genre de promesse qu'une implémentation naïve casse
     * sans le voir, en comptant la table avant d'appliquer le `WHERE`. Trois demandes
     * déposées, une seule visée : `total` doit valoir 1, pas 3.
     */
    public function testLesFiltresRestreignentAussiLeTotalEtPasSeulementLaPage(): void
    {
        $this->arbre()->poser(SubjectKind::Personne, self::AUTRE_PERSONNE, 'Silice Bourrache', 3, ['surname' => 'Bourrache']);

        $this->connexionContributeur();
        $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]);
        $this->deposer([$this->opTexte('nickname', null, 'Perle', seq: 2)]);
        $surLAutre = $this->deposerSur(self::AUTRE_PERSONNE, 'surname', 'Bourrache', 'BOURRACHE', 3);

        $this->connexionAdministrateur();

        // Sans filtre : les trois.
        $this->requete('GET', '/api/administration/demandes');
        self::assertSame(3, $this->charge()['total']);

        // Filtré sur la seconde fiche : une seule, ET un total de 1.
        $this->requete('GET', '/api/administration/demandes?targetId='.self::AUTRE_PERSONNE);
        $charge = $this->charge();

        self::assertSame(1, $charge['total'], 'Le total compte APRÈS le filtre, jamais avant.');
        self::assertCount(1, (array) $charge['elements']);
        self::assertSame($surLAutre, ((array) ((array) $charge['elements'])[0])['id']);

        // Filtré sur un statut qu'aucune demande ne porte : zéro, et l'enveloppe tient.
        $this->requete('GET', '/api/administration/demandes?status=validee');
        $vide = $this->charge();

        self::assertSame(0, $vide['total']);
        self::assertSame([], $vide['elements'], 'Une liste vide reste une liste, pas un `null`.');
    }

    /**
     * La recherche de la file (2026-10-04) : un **numéro** désigne sa demande seule —
     * `42`, `#42` ou `n° 42` —, tout autre texte cherche dans la fiche visée, l'auteur et
     * le message. Et le filtre de nature accepte une liste, pour les onglets.
     */
    public function testLaFileSeChercheParNumeroEtParNomEtSeFiltreParPlusieursNatures(): void
    {
        $this->arbre()->poser(SubjectKind::Personne, self::AUTRE_PERSONNE, 'Silice Bourrache', 3, ['surname' => 'Bourrache']);

        $this->connexionContributeur();
        $premiere = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]);
        $surLAutre = $this->deposerSur(self::AUTRE_PERSONNE, 'surname', 'Bourrache', 'BOURRACHE', 3);

        $this->connexionAdministrateur();

        foreach ([(string) $premiere, '#'.$premiere, 'n° '.$premiere] as $terme) {
            $this->requete('GET', '/api/administration/demandes?status=&q='.rawurlencode($terme));
            self::assertSame([$premiere], array_column((array) $this->charge()['elements'], 'id'), $terme);
        }

        $this->requete('GET', '/api/administration/demandes?q=bourr');
        self::assertSame([$surLAutre], array_column((array) $this->charge()['elements'], 'id'));
        self::assertSame([], ((array) ((array) $this->charge()['elements'])[0])['nouvellesPersonnes']);

        $this->requete('GET', '/api/administration/demandes?kind=creation_personne,ajout_lien');
        self::assertSame(0, $this->charge()['total']);

        $this->requete('GET', '/api/administration/demandes?kind=modification_personne,ajout_lien');
        self::assertSame(2, $this->charge()['total']);
    }

    /**
     * La pagination découpe la page **sans toucher au total** : c'est ce qui permet à
     * l'écran d'afficher « 2 sur 3 » plutôt que « 2 sur 2 ».
     */
    public function testLaPaginationDecoupeLaPageSansMentirSurLeTotal(): void
    {
        $this->connexionContributeur();
        $ids = [
            $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->deposer([$this->opTexte('nickname', null, 'Perle', seq: 2)]),
            $this->deposer([$this->opTexte('givenNames', 'Marne Dolomite', 'Marne', seq: 3)]),
        ];

        $this->connexionAdministrateur();

        $this->requete('GET', '/api/administration/demandes?page=1&parPage=2');
        $premiere = $this->charge();

        self::assertSame(3, $premiere['total'], 'Le total est celui de la file, pas celui de la page.');
        self::assertSame(2, $premiere['parPage']);
        self::assertSame([$ids[0], $ids[1]], array_column((array) $premiere['elements'], 'id'));

        $this->requete('GET', '/api/administration/demandes?page=2&parPage=2');
        $seconde = $this->charge();

        self::assertSame(3, $seconde['total']);
        self::assertSame(2, $seconde['page']);
        self::assertSame([$ids[2]], array_column((array) $seconde['elements'], 'id'));

        // Une page au-delà de la fin rend une liste vide, pas une erreur : l'écran qui
        // demande la page 9 après une suppression ne doit pas afficher une panne.
        $this->requete('GET', '/api/administration/demandes?page=9&parPage=2');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->charge()['elements']);
    }

    /**
     * Les bornes de `parPage` sont **appliquées, pas seulement documentées**.
     *
     * `min(100, max(1, …))` dans le contrôleur : sans ce test, un client qui demande
     * `parPage=100000` obtiendrait la table entière en une requête, et personne ne s'en
     * apercevrait avant que la file soit longue.
     */
    public function testLesBornesDeParPageSontAppliquees(): void
    {
        $this->connexionContributeur();
        $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]);

        $this->connexionAdministrateur();

        $this->requete('GET', '/api/administration/demandes?parPage=100000');
        self::assertSame(100, $this->charge()['parPage'], 'Le plafond de 100 doit être appliqué.');

        $this->requete('GET', '/api/administration/demandes?parPage=0');
        self::assertSame(1, $this->charge()['parPage'], 'Le plancher de 1 doit être appliqué.');

        $this->requete('GET', '/api/administration/demandes?page=-5');
        self::assertSame(1, $this->charge()['page'], 'Une page négative retombe sur la première.');
    }

    // ---------------------------------------------------------------------------------
    // Le diff
    // ---------------------------------------------------------------------------------

    /**
     * La charge du § 6.7, et les trois blocs qui la distinguent de la vue de l'auteur :
     * `valeurCourante`/`aBouge` par op, le bandeau `cible`, et `resolutionPrevue`.
     */
    public function testLeDiffPorteLaValeurCouranteLaCibleEtLaResolutionPrevue(): void
    {
        $this->connexionContributeur();
        $demande = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')], 'la graphie de l’acte');

        $this->connexionAdministrateur();
        $this->requete('GET', '/api/administration/demandes/'.$demande);

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(self::CLES_DU_DIFF, array_keys($charge), 'La charge du § 6.7, à la clé près.');

        $ops = (array) $charge['ops'];
        self::assertCount(1, $ops);

        $op = (array) $ops[0];
        self::assertSame('surname', $op['field']);
        self::assertSame('Sureau', $op['oldValue']);
        self::assertSame('SUREAU', $op['newValue']);
        self::assertSame('Sureau', $op['valeurCourante'], 'Ce que la fiche porte MAINTENANT, relu à chaque lecture.');
        self::assertFalse($op['aBouge'], 'Rien n’a bougé depuis le dépôt.');

        // Le bandeau « cette fiche a changé depuis la demande ». Il porte les deux
        // versions et le booléen — pas l'identité de la cible, qui est déjà dans
        // `targetKind`/`targetId` au-dessus.
        self::assertSame(
            ['version', 'versionALaSoumission', 'aChangeDepuisSoumission'],
            array_keys((array) $charge['cible']),
        );

        $cible = (array) $charge['cible'];
        self::assertSame(self::VERSION_DE_DEPART, $cible['version']);
        self::assertSame(self::VERSION_DE_DEPART, $cible['versionALaSoumission']);
        self::assertFalse($cible['aChangeDepuisSoumission']);

        self::assertSame('application', $charge['resolutionPrevue']);
        self::assertSame([], $charge['demandesConcurrentes']);
    }

    /**
     * Deux demandes sur le même champ se voient l'une l'autre.
     *
     * `demandesConcurrentes` est la seule chose qui empêche l'administrateur de valider la
     * première sans savoir qu'une seconde attend sur le même champ — c'est-à-dire de
     * fabriquer la caducité qu'il découvrira au clic suivant.
     */
    public function testLeDiffMontreLesDemandesConcurrentesSurLeMemeChamp(): void
    {
        $this->connexionContributeur();
        $premiere = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')], 'l’acte donne SUREAU');

        $this->connexionSecondContributeur();
        $seconde = $this->deposer([$this->opTexte('surname', 'Sureau', 'Sureaut')], 'la table décennale donne Sureaut');

        $this->connexionAdministrateur();
        $this->requete('GET', '/api/administration/demandes/'.$premiere);

        $concurrentes = (array) $this->charge()['demandesConcurrentes'];
        self::assertCount(1, $concurrentes, 'La seconde demande doit apparaître dans la vue de la première.');
        self::assertSame($seconde, ((array) $concurrentes[0])['id']);

        // Et réciproquement — une relation qui ne serait vraie que dans un sens serait
        // pire qu'absente : l'administrateur qui ouvre la seconde croirait être seul.
        $this->requete('GET', '/api/administration/demandes/'.$seconde);

        $reciproques = (array) $this->charge()['demandesConcurrentes'];
        self::assertCount(1, $reciproques);
        self::assertSame($premiere, ((array) $reciproques[0])['id']);
    }

    /**
     * **La boucle fermée** : ce que le diff annonce est ce qui arrive au clic, pour les
     * quatre issues.
     *
     * Chaque cas produit un état de l'arbre pour de vrai, lit `resolutionPrevue` par HTTP,
     * puis clique par HTTP et compare. C'est le seul test du dépôt qui interdit aux deux
     * moitiés de la promesse de diverger.
     */
    public function testLAnnonceDuDiffEstTenueAuClic(): void
    {
        $constate = [];

        // ── 1. `application` — rien n'a bougé.
        $constate['application'] = $this->annonceEtClic(static function (): void {});

        // ── 2. `rejeu` — la version a bougé, la valeur visée non.
        $constate['rejeu'] = $this->annonceEtClic(function (): void {
            $this->arbre()->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'nickname', 'Muguet');
        });

        // ── 3. `caducite` — la valeur visée a bougé sous la demande.
        $constate['caducite'] = $this->annonceEtClic(function (): void {
            $this->arbre()->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'surname', 'Sureaut');
        });

        // ── 4. `cible_disparue` — la fiche n'existe plus.
        $constate['cible_disparue'] = $this->annonceEtClic(function (): void {
            $this->arbre()->supprimer(SubjectKind::Personne, self::PERSONNE);
        });

        self::assertSame(
            [
                'application' => ['annonce' => 'application', 'statutHttp' => 200, 'issue' => 'validee'],
                'rejeu' => ['annonce' => 'rejeu', 'statutHttp' => 200, 'issue' => 'rejouee'],
                'caducite' => ['annonce' => 'caducite', 'statutHttp' => 409, 'issue' => 'demande_caduque'],
                'cible_disparue' => ['annonce' => 'cible_disparue', 'statutHttp' => 409, 'issue' => 'cible_disparue'],
            ],
            $constate,
            'Ce que l’écran annonce AVANT le clic doit être ce qui arrive APRÈS. '
            .'Les quatre issues du § 6.8, chacune produite pour de vrai.',
        );
    }

    // ---------------------------------------------------------------------------------
    // `cible_disparue`, en face des deux autres conflits
    // ---------------------------------------------------------------------------------

    /**
     * **Les trois conflits produits dans un seul cas**, et comparés champ par champ.
     *
     * `demande_caduque` et `cible_disparue` sont deux caducités : même `statut`, même
     * `rejouable: false`, et toutes deux **écrivent** le statut avant de lever. Elles ne
     * se distinguent que par `details` — `champs` contre `cible` — et c'est exactement ce
     * qu'un front doit lire pour dire « la fiche a changé » plutôt que « la fiche n'existe
     * plus ». `verrou_concurrent` est leur contraire sur les deux points qui comptent : il
     * est rejouable, et il n'écrit rien.
     */
    public function testLesTroisConflitsNeSeRessemblentPasEnHttp(): void
    {
        $caduque = $this->produireUnConflit(function (): void {
            $this->arbre()->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'surname', 'Sureaut');
        });

        $disparue = $this->produireUnConflit(function (): void {
            $this->arbre()->supprimer(SubjectKind::Personne, self::PERSONNE);
        });

        $verrou = $this->produireUnConflit(function (): void {
            $this->arbre()->simulerVerrouConcurrent(true);
        });

        // ── Les trois sont des 409. C'est tout ce qu'ils ont en commun côté transport.
        self::assertSame([409, 409, 409], [$caduque['statutHttp'], $disparue['statutHttp'], $verrou['statutHttp']]);

        self::assertSame(
            ['demande_caduque', 'cible_disparue', 'verrou_concurrent'],
            [$caduque['code'], $disparue['code'], $verrou['code']],
        );

        // ── Le conseil donné au front : rejouer, ou pas.
        self::assertSame(
            [false, false, true],
            [$caduque['rejouable'], $disparue['rejouable'], $verrou['rejouable']],
            'Les deux caducités ne se rejouent pas ; le verrou se rejoue. C’est le seul champ '
            .'sur lequel un front peut se fonder pour proposer « réessayer ».',
        );

        // ── Ce que le serveur a fait AVANT de lever, relu par HTTP.
        self::assertSame(
            ['caduque', 'caduque', 'en_attente'],
            [$caduque['statutApres'], $disparue['statutApres'], $verrou['statutApres']],
            'Les deux caducités ÉCRIVENT le statut avant de lever ; le verrou ne laisse aucune trace.',
        );

        // ── Et ce qui les distingue l'une de l'autre : le contenu de `details`.
        self::assertArrayHasKey('champs', $caduque['details']);
        self::assertArrayNotHasKey('cible', $caduque['details']);

        self::assertArrayHasKey('cible', $disparue['details']);
        self::assertArrayNotHasKey('champs', $disparue['details']);

        self::assertSame(
            ['kind' => 'personne', 'id' => self::PERSONNE, 'label' => 'Marne Dolomite Sureau'],
            $disparue['details']['cible'],
            'La cible disparue est nommée : un identifiant nu n’aide pas l’administrateur à comprendre '
            .'ce qui s’est passé, puisque la fiche n’est plus consultable.',
        );
    }

    /**
     * Une demande caduque n'est pas une demande effacée : son diff reste lisible, et
     * `resolutionPrevue` continue de dire pourquoi.
     *
     * C'est ce qui rend le bouton « refaire » utilisable — « une caducité sans ce bouton
     * est une contribution perdue ». Un diff qui rendrait `404` après la caducité
     * retirerait à l'auteur la seule page qui lui explique ce qui est arrivé.
     */
    public function testLeDiffResteLisibleApresUneCaducite(): void
    {
        $conflit = $this->produireUnConflit(function (): void {
            $this->arbre()->supprimer(SubjectKind::Personne, self::PERSONNE);
        });

        $this->connexionAdministrateur();
        $this->requete('GET', '/api/administration/demandes/'.$conflit['demandeId']);

        self::assertResponseIsSuccessful('Une demande caduque reste consultable.');

        $charge = $this->charge();
        self::assertSame('caduque', $charge['status']);
        self::assertSame('cible_disparue', $charge['resolutionPrevue'], 'Le diff continue de dire POURQUOI.');
    }

    // ---------------------------------------------------------------------------------
    // Les rouages
    // ---------------------------------------------------------------------------------

    /**
     * Dépose une demande, applique `$bouger` à l'arbre, lit l'annonce du diff, clique.
     *
     * @param \Closure(): void $bouger
     *
     * @return array{annonce: string, statutHttp: int, issue: string}
     */
    private function annonceEtClic(\Closure $bouger): array
    {
        $this->reinitialiserLArbre();

        $this->connexionContributeur();
        $demande = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]);

        $bouger();

        $this->connexionAdministrateur();

        // 1. Ce que l'écran annonce.
        $this->requete('GET', '/api/administration/demandes/'.$demande);
        self::assertResponseIsSuccessful();
        $annonce = $this->charge()['resolutionPrevue'];
        self::assertIsString($annonce);

        // 2. Ce qui arrive au clic.
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider');
        $statutHttp = $this->client->getResponse()->getStatusCode();

        $issue = Response::HTTP_CONFLICT === $statutHttp
            ? (string) $this->erreur()['code']
            : (string) $this->charge()['resultat'];

        return ['annonce' => $annonce, 'statutHttp' => $statutHttp, 'issue' => $issue];
    }

    /**
     * Dépose une demande, applique `$bouger`, valide, et rend ce que le `409` a dit **et**
     * ce que la demande est devenue, relu par HTTP.
     *
     * @param \Closure(): void $bouger
     *
     * @return array{demandeId: int, statutHttp: int, code: string, rejouable: bool, statutApres: string, details: array<string, mixed>}
     */
    private function produireUnConflit(\Closure $bouger): array
    {
        $this->reinitialiserLArbre();

        $this->connexionContributeur();
        $demande = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]);

        $bouger();

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider');

        $statutHttp = $this->client->getResponse()->getStatusCode();
        self::assertSame(Response::HTTP_CONFLICT, $statutHttp, 'Ce cas doit produire un conflit.');

        $erreur = $this->erreur();
        $details = (array) $erreur['details'];

        // Le verrou simulé doit être relâché avant de relire, sinon la lecture suivante
        // le déclencherait à son tour.
        $this->arbre()->simulerVerrouConcurrent(false);

        self::assertIsBool($details['rejouable']);

        return [
            'demandeId' => $demande,
            'statutHttp' => $statutHttp,
            'code' => (string) $erreur['code'],
            'rejouable' => $details['rejouable'],
            'statutApres' => $this->statutDeLaDemande($demande),
            'details' => $details,
        ];
    }

    /** Repose la fiche de référence dans son état de départ, entre deux cas d'un même test. */
    private function reinitialiserLArbre(): void
    {
        $this->arbre()->simulerVerrouConcurrent(false);
        $this->poserLaFicheDeReference();
    }

    /**
     * Dépose une demande visant **une autre fiche** que celle de référence.
     *
     * `deposer()` du socle vise toujours `self::PERSONNE` ; les filtres ont besoin d'une
     * seconde cible pour avoir quelque chose à séparer.
     */
    private function deposerSur(int $personne, string $champ, ?string $ancienne, ?string $nouvelle, int $baseVersion): int
    {
        $this->requete('POST', '/api/demandes', [
            'kind' => 'modification_personne',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => $personne,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'personne',
                'subjectId' => $personne,
                'field' => $champ,
                'valueKind' => 'texte',
                'oldValue' => $ancienne,
                'newValue' => $nouvelle,
                'baseVersion' => $baseVersion,
            ]],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $id = $this->charge()['id'];
        self::assertIsInt($id);

        return $id;
    }

    private function connexionContributeur(): void
    {
        if (null === $this->em()->getRepository(\App\Module\Core\Entity\User::class)->findOneBy(['email' => 'opale@example.net'])) {
            $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        }

        $this->connexion('opale@example.net');
    }

    private function connexionSecondContributeur(): void
    {
        if (null === $this->em()->getRepository(\App\Module\Core\Entity\User::class)->findOneBy(['email' => 'tuf@example.net'])) {
            $this->creerContributeur('tuf@example.net', self::PERSONNE_TUF, 'Tuf Romarin');
        }

        $this->connexion('tuf@example.net');
    }

    private function connexionAdministrateur(): void
    {
        if (null === $this->em()->getRepository(\App\Module\Core\Entity\User::class)->findOneBy(['email' => 'natron@example.net'])) {
            $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Natron Romarin');
        }

        $this->connexion('natron@example.net');
    }
}
