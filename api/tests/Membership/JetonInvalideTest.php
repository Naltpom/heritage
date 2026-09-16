<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Entity\User;
use App\Module\Membership\Entity\Invitation;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * **Sans jeton valide : `404`, jamais `403`.**.
 *
 * « Un `403` confirmerait que la route existe et que le jeton a une forme valide » (§ 6.1
 * du dossier, § 4 du contrat). Cinq refus — jeton inconnu, mal formé, expiré, révoqué,
 * déjà consommé — et **un seul corps d'erreur**, message compris : `"Ressource
 * introuvable."`, jamais « jeton expiré », qui distinguerait un jeton ayant existé d'un
 * jeton inventé.
 *
 * Ce fichier vérifie les cinq cas **sur les trois routes publiques**, et compare les corps
 * entre eux plutôt qu'à une constante : c'est leur identité mutuelle qui porte la
 * propriété, pas leur contenu.
 */
final class JetonInvalideTest extends CasDeTestMembership
{
    private const string ROUTE_VERIFIER = '/api/inscription/verifier';
    private const string ROUTE_RATTACHEMENT = '/api/inscription/rattachement';
    private const string ROUTE_COMPTE = '/api/inscription/compte';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function routesPubliques(): iterable
    {
        yield 'vérifier' => [self::ROUTE_VERIFIER];
        yield 'rattachement' => [self::ROUTE_RATTACHEMENT];
        yield 'compte' => [self::ROUTE_COMPTE];
    }

    #[DataProvider('routesPubliques')]
    public function testUnJetonInconnuRend404EtNon403(string $route): void
    {
        $this->appeler($route, $this->jetonBienForme());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();
        self::assertIsArray($corps['erreur'] ?? null);
        self::assertSame('introuvable', $corps['erreur']['code']);
        self::assertSame('Ressource introuvable.', $corps['erreur']['message']);
    }

    #[DataProvider('routesPubliques')]
    public function testUnJetonMalFormeRendLaMemeChoseQuUnJetonInconnu(string $route): void
    {
        $this->appeler($route, 'pas-un-jeton');
        $malForme = $this->charge();
        $statutMalForme = $this->client->getResponse()->getStatusCode();

        $this->appeler($route, $this->jetonBienForme());
        $inconnu = $this->charge();

        self::assertSame(404, $statutMalForme);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame($inconnu, $malForme, 'Un jeton mal formé et un jeton inventé se répondent à l’identique.');
    }

    /**
     * Les quatre états d'un jeton **qui a existé** rendent tous la même chose qu'un jeton
     * inventé : c'est le cœur de la règle. Un `410 Gone` sur l'expiration, un `409` sur la
     * consommation — les deux réflexes naturels — diraient au porteur qu'il détient un
     * vrai jeton.
     */
    public function testExpireRevoqueEtConsommeSeRepondentCommeInconnu(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);

        $this->appeler(self::ROUTE_VERIFIER, $this->jetonBienForme());
        $reference = $this->charge();

        foreach ($this->jetonsMorts($administrateur) as $cas => $jeton) {
            $this->appeler(self::ROUTE_VERIFIER, $jeton);

            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $cas);
            self::assertSame($reference, $this->charge(), \sprintf('Cas « %s » : la réponse doit être celle d’un jeton inventé.', $cas));
        }
    }

    /**
     * Le corps de la requête n'est pas un oracle non plus.
     *
     * Un `POST /api/inscription/rattachement` sans `surname` rend `422` — sur le **corps**,
     * pas sur le jeton. Si ce `422` n'arrivait qu'après le contrôle du jeton, il suffirait
     * d'omettre un champ pour transformer la route en test de validité de jeton : corps
     * incomplet + `422` ⇒ jeton bon, corps incomplet + `404` ⇒ jeton mauvais. Les deux
     * réponses ci-dessous doivent donc être identiques.
     */
    public function testUnCorpsIncompletNeTrahitPasLaValiditeDuJeton(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $bon] = $this->creerInvitation($administrateur);

        $this->requete('POST', self::ROUTE_RATTACHEMENT, ['jeton' => $bon, 'surname' => 'Romarin']);
        $avecBonJeton = [$this->client->getResponse()->getStatusCode(), $this->charge()];

        $this->requete('POST', self::ROUTE_RATTACHEMENT, ['jeton' => $this->jetonBienForme(), 'surname' => 'Romarin']);
        $avecMauvaisJeton = [$this->client->getResponse()->getStatusCode(), $this->charge()];

        self::assertSame(422, $avecBonJeton[0], 'Un corps incomplet est un 422 — sur le corps.');
        self::assertSame($avecBonJeton, $avecMauvaisJeton, 'Le jeton ne doit pas changer la réponse à un corps incomplet.');
    }

    /** Un jeton valide, lui, répond — sinon les assertions ci-dessus ne prouveraient rien. */
    public function testUnJetonValideRepondEtNeDitRienDeLaPersonnePreLiee(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $this->appeler(self::ROUTE_VERIFIER, $jeton);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();

        self::assertSame('confirmation', $corps['mode']);
        self::assertSame(['surname', 'givenNames', 'birthDate'], $corps['champsDemandes']);
        self::assertSame(3, $corps['candidatsMax']);

        // Ni `personId`, ni `personLabel`, ni aucun indice — pas même une initiale.
        self::assertSame(['mode', 'expiresAt', 'candidatsMax', 'champsDemandes'], array_keys($corps));
        self::assertStringNotContainsStringIgnoringCase('romarin', json_encode($corps, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsStringIgnoringCase('natron', json_encode($corps, \JSON_THROW_ON_ERROR));
    }

    /**
     * Sans pré-lien, `mode` vaut `recherche` — et les deux modes demandent **les mêmes
     * trois champs**. « Un formulaire qui ne demanderait que la date en mode confirmation
     * dirait au porteur *nous savons déjà ton nom* » (§ 4.1).
     */
    public function testLesDeuxModesDemandentLesMemesChamps(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);

        [, $avecPreLien] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');
        [, $sansPreLien] = $this->creerInvitation($administrateur);

        $this->appeler(self::ROUTE_VERIFIER, $avecPreLien);
        $confirmation = $this->charge();

        $this->appeler(self::ROUTE_VERIFIER, $sansPreLien);
        $recherche = $this->charge();

        self::assertSame('confirmation', $confirmation['mode']);
        self::assertSame('recherche', $recherche['mode']);
        self::assertSame($confirmation['champsDemandes'], $recherche['champsDemandes']);
        self::assertSame(array_keys($confirmation), array_keys($recherche));
    }

    /**
     * @return array<string, string>
     */
    private function jetonsMorts(User $administrateur): array
    {
        $em = $this->em();

        [$expiree, $jetonExpire] = $this->creerInvitation($administrateur);
        $echeance = new \ReflectionProperty(Invitation::class, 'expiresAt');
        $echeance->setValue($expiree, new \DateTimeImmutable('-1 day'));

        [$revoquee, $jetonRevoque] = $this->creerInvitation($administrateur);
        $identifiant = $administrateur->getId();
        \assert(null !== $identifiant);
        $revoquee->revoquer($identifiant, $administrateur->getEmail());

        [$consommee, $jetonConsomme] = $this->creerInvitation($administrateur);
        $consommee->marquerUtilisee($identifiant, 'quelqun@example.net');

        $em->flush();

        return [
            'expiré' => $jetonExpire,
            'révoqué' => $jetonRevoque,
            'déjà consommé' => $jetonConsomme,
        ];
    }

    private function appeler(string $route, string $jeton): void
    {
        $corps = ['jeton' => $jeton];

        if (self::ROUTE_RATTACHEMENT === $route) {
            $corps += ['surname' => 'Romarin', 'givenNames' => 'Natron', 'birthDate' => '1980-07-27'];
        }

        if (self::ROUTE_COMPTE === $route) {
            $corps += ['choix' => 'c1', 'email' => 'x@example.net', 'password' => 'douze-signes-au-moins'];
        }

        $this->requete('POST', $route, $corps);
    }

    /** 43 caractères `[A-Za-z0-9_-]` : la forme exacte, pour qu'aucun refus ne porte sur elle. */
    private function jetonBienForme(): string
    {
        return str_repeat('a', 43);
    }
}
