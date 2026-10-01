<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/administration/membres` — **en HTTP, authentifié**.
 *
 * ── Pourquoi ce fichier existe ───────────────────────────────────────────────────
 *
 * Sur les cinquante routes de `debug:router`, celle-ci était **la seule qu'aucun test
 * ne touchait**, ni en déclaration ni en réponse. Et c'est aussi la seule dont on sache
 * qu'elle est déjà tombée : l'en-tête de `compose.yaml` raconte qu'« une ligne
 * `mbr_member` écrite à la main a déjà mis `GET /api/administration/membres` en `500`
 * pour tout le monde, avec un `match_kind` inventé que l'énumération ne connaît pas ».
 * La route qui est tombée était précisément celle que rien n'exerçait.
 *
 * Les tests de `Membership` couvraient largement le **service** — activation, cercle,
 * rattachement — mais la file elle-même, c'est-à-dire la lecture que l'écran
 * d'administration fait à chaque affichage, ne passait par aucun `$client->request()`.
 * Ce que ce fichier ajoute est donc la couche que les tests de service ne peuvent pas
 * voir : la garde d'`access_control`, l'enveloppe de liste du § 1.5, la forme exacte de
 * la charge du § 10.1, et le passage de `personLabel` par le cercle de visibilité.
 *
 * ── Les deux formes de ligne qui ont cassé, et qui sont ici ─────────────────────
 *
 * 1. **Sans invitation** — le rattachement posé en console n'en a pas, et
 *    `normaliser()` appelle `getInvitation()?->getId()` sur `null` ;
 * 2. **`person_id` pendant** — une personne absente de l'arbre. Le cercle répond alors
 *    « non » et les deux clés de personne disparaissent de la charge ; la route doit
 *    rendre `200` avec une ligne amputée, jamais `500`. C'est la forme la plus proche de
 *    l'incident de `compose.yaml` qu'un test puisse reproduire sans écrire une valeur
 *    d'énumération que PHP refuserait de construire.
 */
final class FileDesMembresHttpTest extends CasDeTestMembership
{
    /**
     * Les clés du § 10.1, **dans l'ordre**, pour une ligne dont la personne est visible.
     *
     * `assertSame` sur `array_keys()` et non une boucle de `assertArrayHasKey` : une
     * boucle ne voit jamais une clé **en trop**, et c'est par une clé en trop qu'une
     * donnée personnelle sort.
     *
     * @var list<string>
     */
    private const array CLES_ATTENDUES = [
        'id', 'status', 'matchKind', 'invitationId', 'linkedAt', 'activatedAt',
        'personId', 'personLabel',
        // ADR 0013 : la photo d'inscription, qui deviendra le portrait de la fiche.
        'portraitEnAttente',
    ];

    public function testLaFileEstFermeeALAnonyme(): void
    {
        $this->requete('GET', '/api/administration/membres');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $erreur = $this->charge()['erreur'];
        self::assertIsArray($erreur);
        self::assertSame(['code', 'message', 'champs', 'details'], array_keys($erreur));
        self::assertSame('authentification_requise', $erreur['code']);
    }

    /**
     * `access_control` place `^/api/administration` derrière `ROLE_ADMIN` : un membre
     * actif, parfaitement connecté, n'a rien à faire dans la file d'activation — il y
     * lirait les rattachements en attente des autres.
     */
    public function testLaFileEstFermeeAuMembreActif(): void
    {
        $membre = $this->creerUtilisateur('cobalt@example.net');
        $this->creerMembre($membre, ArbreFamilial::EGO, 'Natron Romarin');

        $this->connexion('cobalt@example.net');
        self::assertResponseIsSuccessful('La connexion du membre doit réussir avant qu’on teste le refus.');

        $this->requete('GET', '/api/administration/membres');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->charge()['erreur']['code']);
    }

    /**
     * Le cas nominal : trois rattachements, **deux** en attente, et la file n'en rend
     * que deux. La taille de l'ensemble parcouru est affirmée avant qu'on le parcoure —
     * une file devenue vide passerait sinon au vert en ne vérifiant plus rien.
     */
    public function testLaFileNeRendQueLesRattachementsEnAttente(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);

        $actif = $this->creerUtilisateur('cobalt@example.net');
        $this->creerMembre($actif, ArbreFamilial::EGO, 'Natron Romarin');

        $premier = $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);
        $this->creerMembre($premier, ArbreFamilial::COUSIN, 'Marbre Romarin', MemberStatus::EnAttente, MatchKind::RechercheConfirmee);

        $second = $this->creerUtilisateur('tante@example.net', statut: UserStatus::EnAttente);
        $this->creerMembre($second, ArbreFamilial::TANTE, 'Marbrée Romarin', MemberStatus::EnAttente, MatchKind::PreLie);

        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/administration/membres');

        self::assertResponseIsSuccessful();

        $charge = $this->charge();

        // L'enveloppe de liste du § 1.5 — quatre clés, et pas une de plus.
        self::assertSame(['elements', 'total', 'page', 'parPage'], array_keys($charge));
        self::assertSame(2, $charge['total'], 'Le membre actif n’appartient pas à la file d’activation.');
        self::assertIsArray($charge['elements']);
        self::assertCount(2, $charge['elements']);
        self::assertSame(1, $charge['page']);

        // On affirme la taille AVANT de parcourir, et on parcourt ensuite.
        foreach ($charge['elements'] as $element) {
            self::assertIsArray($element);
            self::assertSame(self::CLES_ATTENDUES, array_keys($element));
            self::assertSame(MemberStatus::EnAttente->value, $element['status']);
            self::assertNull($element['portraitEnAttente'], 'Aucune photo déposée dans ce scénario.');
        }

        // ── Règle 5 : la valeur du contrat est écrite par l'énumération, jamais recopiée.
        //    `MatchKind::RechercheConfirmee->value` fait échouer la COMPILATION le jour où
        //    le cas est renommé ; la chaîne « recherche_confirmee » se serait tue.
        self::assertSame(MatchKind::RechercheConfirmee->value, $charge['elements'][0]['matchKind']);
        self::assertSame(MatchKind::PreLie->value, $charge['elements'][1]['matchKind']);

        // Le tri est celui du dépôt (`createdAt ASC`) : l'administrateur traite la file
        // dans l'ordre d'arrivée, et le plus ancien en attente est en tête.
        self::assertSame(ArbreFamilial::COUSIN, $charge['elements'][0]['personId']);
        self::assertSame('Marbre Romarin', $charge['elements'][0]['personLabel']);
        self::assertSame(ArbreFamilial::TANTE, $charge['elements'][1]['personId']);

        // Aucune empreinte de mot de passe, aucun secret, aucun jeton d'invitation : la
        // file est une liste de rattachements, pas une liste de comptes (§ 16.1).
        $json = json_encode($charge, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('password', $json);
        self::assertStringNotContainsString('tokenHash', $json);
        self::assertStringNotContainsString('cousin@example.net', $json);
    }

    /**
     * La file est **vide** quand personne n'attend, et c'est `200` avec `total = 0` —
     * pas `404`, pas un tableau nu.
     */
    public function testUneFileVideRendUneEnveloppeVide(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);

        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/administration/membres');

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(['elements', 'total', 'page', 'parPage'], array_keys($charge));
        self::assertSame(0, $charge['total']);
        self::assertSame([], $charge['elements']);
    }

    /**
     * **Le cas de `compose.yaml`, au plus près de ce qu'un test peut reproduire.**.
     *
     * Deux lignes que le parcours d'invitation ne produit jamais et qu'une écriture
     * directe produit tout le temps :
     *
     * - un rattachement **sans invitation** — `getInvitation()?->getId()` sur `null` ;
     * - un `person_id` **pendant**, qui ne désigne aucune personne de l'arbre.
     *
     * La file doit rendre `200` et une ligne amputée de ses deux clés de personne. Elle
     * ne doit surtout pas rendre `500` : une seule ligne douteuse mettrait alors l'écran
     * d'activation hors service **pour tous les membres en attente à la fois**, ce qui
     * est exactement ce qui s'est produit.
     */
    public function testUneLigneSansInvitationEtAPersonnePendanteNeMetPasLaFileEn500(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);

        $orphelin = $this->creerUtilisateur('orphelin@example.net', statut: UserStatus::EnAttente);
        $membre = $this->creerMembre(
            $orphelin,
            // 7777 n'est dans aucune famille d'`ArbreFamilial` : le port rend `null`, et
            // le cercle refuse une personne qu'il ne sait pas dire décédée.
            7777,
            'Personne disparue de l’arbre',
            MemberStatus::EnAttente,
            MatchKind::ManuelAdmin,
        );

        self::assertNull($membre->getInvitation(), 'Le cas visé est justement celui d’un rattachement sans invitation.');

        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/administration/membres');

        self::assertResponseIsSuccessful(
            'Une ligne mbr_member écrite à la main ne doit pas mettre la file hors service pour tout le monde.',
        );

        $charge = $this->charge();
        self::assertSame(1, $charge['total']);

        $ligne = $charge['elements'][0];
        self::assertSame(['id', 'status', 'matchKind', 'invitationId', 'linkedAt', 'activatedAt', 'portraitEnAttente'], array_keys($ligne));
        self::assertNull($ligne['invitationId']);

        // Les deux clés de personne sont ABSENTES, pas nulles : le § 11.3 fait passer
        // `personId` et `personLabel` par le cercle, et le cercle a dit non.
        self::assertArrayNotHasKey('personId', $ligne);
        self::assertArrayNotHasKey('personLabel', $ligne);
        self::assertStringNotContainsString(
            'disparue',
            json_encode($charge, \JSON_THROW_ON_ERROR),
            'Le libellé d’une personne hors cercle ne sort pas, même dans la file d’administration.',
        );
    }

    /**
     * Les trois natures de rattachement traversent la file **sans exception**.
     *
     * Le test parcourt `MatchKind::cases()` et non une liste écrite à la main : le jour
     * où une quatrième nature est ajoutée à l'énumération, ce test la prend en charge
     * tout seul — et si elle casse la sérialisation, il rougit. Une liste recopiée, elle,
     * ne préviendrait jamais qu'elle a vieilli.
     */
    public function testChaqueNatureDeRattachementTraverseLaFile(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);

        $natures = MatchKind::cases();
        // Quatre depuis le 1er octobre 2026 : `a_rattacher`, le compte sans fiche de
        // l'ADR 0013. Relu au § 10.1 : il traverse la file **sans** `personId`.
        self::assertCount(4, $natures, 'Le § 4.5 du modèle et l\'ADR 0013 en décrivent quatre ; une cinquième demande une relecture du § 10.1.');

        $personnes = [ArbreFamilial::COUSIN, ArbreFamilial::TANTE, ArbreFamilial::GRAND_ONCLE, ArbreFamilial::GRAND_TANTE];

        foreach ($natures as $rang => $nature) {
            $compte = $this->creerUtilisateur(\sprintf('attente%d@example.net', $rang), statut: UserStatus::EnAttente);

            if (MatchKind::ARattacher === $nature) {
                $this->creerMembreSansFiche($compte);
                continue;
            }

            $this->creerMembre($compte, $personnes[$rang], 'Personne '.$rang, MemberStatus::EnAttente, $nature);
        }

        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/administration/membres');

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(\count($natures), $charge['total']);

        $rendues = array_map(static fn (array $ligne): string => (string) $ligne['matchKind'], $charge['elements']);
        self::assertSame(array_map(static fn (MatchKind $n): string => $n->value, $natures), $rendues);

        // Le compte sans fiche : ni `personId` ni `personLabel` — absents, pas nuls.
        $sansFiche = $charge['elements'][3];
        self::assertArrayNotHasKey('personId', $sansFiche);
        self::assertArrayNotHasKey('personLabel', $sansFiche);
    }

    /**
     * La file n'est **pas** un moyen détourné d'activer : la route de lecture ne change
     * ni `mbr_member.status` ni `core_user.status`.
     */
    public function testLaLectureDeLaFileNEcritRien(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);

        $compte = $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);
        $membre = $this->creerMembre($compte, ArbreFamilial::COUSIN, 'Marbre Romarin', MemberStatus::EnAttente, MatchKind::PreLie);
        $membreId = $membre->getId();
        self::assertNotNull($membreId);

        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/administration/membres');
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $em->clear();

        $recharge = $em->getRepository(Member::class)->find($membreId);
        self::assertInstanceOf(Member::class, $recharge);
        self::assertSame(MemberStatus::EnAttente, $recharge->getStatus());
        self::assertNull($recharge->getActivatedAt());
    }
}
