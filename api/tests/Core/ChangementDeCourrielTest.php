<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\EmailChange;
use App\Module\Core\Entity\User;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mime\Email;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE CHANGEMENT DE COURRIEL — § 2.3 : DEUX PREUVES, UN SEUL CAS OBSERVABLE AU REFUS.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Le courriel est l'identifiant du compte. Ce fichier tient les propriétés qui font
 * qu'on ne peut pas le prendre à quelqu'un : le mot de passe est exigé à la demande, le
 * clic sur un lien reçu à la **nouvelle** adresse est exigé à la confirmation, et tout
 * lien douteux — inconnu, rejoué, périmé, mal formé — répond `404` avec le même corps.
 *
 * Le courriel n'est pas envoyé : en test, le transport est `null://null` et le mailer
 * de Symfony collecte les messages. C'est dans ce message qu'on lit le jeton, comme le
 * ferait le titulaire — il n'existe nulle part ailleurs, pas même en base.
 *
 * ── Deux sessions, pour dire vrai sur ce qui tombe ───────────────────────────────
 *
 * La première version de ces cas affirmait que « toute session ouverte sous l'ancien
 * courriel tombe ». Mesuré : **faux pour la session qui fait la confirmation**. Le
 * jeton de sécurité porte l'entité `User` ; elle est modifiée pendant la requête et
 * **resérialisée avec la nouvelle adresse** à la fin. `isEqualTo` compare ensuite
 * nouveau contre nouveau, et la session continue. Seules les sessions ouvertes
 * ailleurs — un autre navigateur, un autre appareil — gardent l'ancien identifiant et
 * tombent à leur requête suivante.
 *
 * Deux sessions sont donc ouvertes, par le même client, en échangeant son cookie : la
 * première reste sous l'ancienne identité pendant que la seconde confirme. C'est la
 * seule façon d'observer la chute sans inventer un second navigateur.
 */
final class ChangementDeCourrielTest extends CoreWebTestCase
{
    private const string COURRIEL = 'ada@exemple.test';
    private const string NOUVEAU = 'ada.nouvelle@exemple.test';
    private const string MOT_DE_PASSE = 'motdepasse-de-test';

    public function testLaDemandeEnvoieUnLienALaNouvelleAdresseEtNeChangeRien(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $this->connexion(self::COURRIEL);

        $this->demander(self::NOUVEAU);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->charge()['courrielEnvoye']);
        self::assertArrayHasKey('expiresAt', $this->charge());

        // Un courriel, à la nouvelle adresse, qui porte le lien — et rien d'autre ne bouge.
        self::assertEmailCount(1);
        $message = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailAddressContains($message, 'to', self::NOUVEAU);
        self::assertMatchesRegularExpression('#/compte/courriel/[A-Za-z0-9_-]{43}#', (string) $message->getTextBody());

        self::assertNotNull(
            $this->em()->getRepository(User::class)->findOneBy(['email' => self::COURRIEL]),
            'Le courriel du compte ne change pas à la demande.',
        );

        $demandes = $this->em()->getRepository(EmailChange::class)->findAll();
        self::assertCount(1, $demandes);
        self::assertSame(self::NOUVEAU, $demandes[0]->getNewEmail());
        self::assertNull($demandes[0]->getUsedAt());
    }

    public function testUnMotDePasseFauxRend422SurLeChampEtNEnvoieRien(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $this->connexion(self::COURRIEL);

        $this->demander(self::NOUVEAU, 'pas-le-bon-mot-de-passe');

        // `422` et non `401` : le compte est authentifié, c'est la donnée qui est fausse.
        // Une `401` ferait déconnecter le front par son rattrapage global.
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('motDePasseActuel', $this->charge()['erreur']['champs']);
        self::assertEmailCount(0);
        self::assertCount(0, $this->em()->getRepository(EmailChange::class)->findAll());
    }

    public function testLaMemeAdresseEtUneAdresseInvalideSontRefusees(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $this->connexion(self::COURRIEL);

        $this->demander(self::COURRIEL);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('nouveauCourriel', $this->charge()['erreur']['champs']);

        $this->demander('pas-une-adresse');
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('nouveauCourriel', $this->charge()['erreur']['champs']);

        self::assertEmailCount(0);
    }

    public function testUneAdresseDejaPriseRend409(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $this->creerUtilisateur(self::NOUVEAU);
        $this->connexion(self::COURRIEL);

        $this->demander(self::NOUVEAU);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('courriel_deja_pris', $this->charge()['erreur']['code']);
        self::assertEmailCount(0);
    }

    /**
     * Le cœur du contrat. Le clic change l'identifiant ; la session qui confirme
     * continue sous la nouvelle adresse ; **une session restée sous l'ancienne tombe**.
     * Voir l'en-tête de classe pour la mesure qui a corrigé la première formulation.
     */
    public function testLeClicChangeLeCourrielEtFaitTomberLesSessionsResteesSousLAncien(): void
    {
        $this->creerUtilisateur(self::COURRIEL);

        // Session A : elle fait la demande, puis reste ouverte sous l'ancienne adresse.
        $this->connexion(self::COURRIEL);
        $this->demander(self::NOUVEAU);
        $jeton = $this->jetonDuDernierCourriel();
        $sessionA = $this->cookiesDeSession();

        // Session B : un autre appareil, qui clique sur le lien.
        $this->client->getCookieJar()->clear();
        $this->connexion(self::COURRIEL);
        $this->requete('POST', '/api/session/courriel/confirmer', ['jeton' => $jeton]);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->charge()['courrielChange']);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => self::COURRIEL]));
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['email' => self::NOUVEAU]));

        // B continue, et se voit déjà sous la nouvelle adresse.
        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::NOUVEAU, $this->charge()['utilisateur']['email']);

        // A, restée sous l'ancien identifiant, tombe à sa requête suivante.
        $this->reprendreLesCookies($sessionA);
        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(401);

        // Et la nouvelle adresse ouvre une session — avec le même mot de passe.
        $this->client->getCookieJar()->clear();
        $this->connexion(self::NOUVEAU);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::NOUVEAU, $this->charge()['utilisateur']['email']);
    }

    public function testLeLienNeSertQuUneFois(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $this->connexion(self::COURRIEL);
        $this->demander(self::NOUVEAU);
        $jeton = $this->jetonDuDernierCourriel();

        $this->requete('POST', '/api/session/courriel/confirmer', ['jeton' => $jeton]);
        self::assertResponseStatusCodeSame(200);

        $this->requete('POST', '/api/session/courriel/confirmer', ['jeton' => $jeton]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * **Un seul cas observable.** Inconnu, mal formé, absent : même statut, même corps.
     * La route est publique ; distinguer « a existé » de « n'a jamais existé » serait
     * offrir un oracle à qui balaie des jetons.
     */
    public function testToutJetonDouteuxRend404AvecLeMemeCorps(): void
    {
        $this->requete('POST', '/api/session/courriel/confirmer', ['jeton' => str_repeat('a', 43)]);
        self::assertResponseStatusCodeSame(404);
        $inconnu = (string) $this->client->getResponse()->getContent();

        $this->requete('POST', '/api/session/courriel/confirmer', ['jeton' => 'trop-court']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($inconnu, (string) $this->client->getResponse()->getContent());

        $this->requete('POST', '/api/session/courriel/confirmer', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($inconnu, (string) $this->client->getResponse()->getContent());
    }

    /**
     * Un compte n'a jamais qu'un lien valable : la nouvelle demande périme l'ancienne,
     * et l'ancien lien répond comme un lien inconnu.
     */
    public function testUneNouvelleDemandePerimeLaPrecedente(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $this->connexion(self::COURRIEL);

        $this->demander(self::NOUVEAU);
        $premier = $this->jetonDuDernierCourriel();

        $this->demander('ada.encore@exemple.test');
        $second = $this->jetonDuDernierCourriel();

        self::assertNotSame($premier, $second);

        $this->requete('POST', '/api/session/courriel/confirmer', ['jeton' => $premier]);
        self::assertResponseStatusCodeSame(404);

        $this->requete('POST', '/api/session/courriel/confirmer', ['jeton' => $second]);
        self::assertResponseStatusCodeSame(200);
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'ada.encore@exemple.test']));
    }

    public function testLaRouteDeDemandeExigeUneSession(): void
    {
        $this->requete('POST', '/api/session/courriel', ['motDePasseActuel' => 'x', 'nouveauCourriel' => self::NOUVEAU]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Ce cas ne teste pas le courriel : il tient ce que le § 2.1 affirme du changement
     * de **mot de passe** — « la session en cours survit » — et ce que `User::isEqualTo`
     * promet en plus : les **autres** sessions tombent. Les deux méritaient d'être
     * vérifiés plutôt que crus, et la page « Mon compte » s'appuie sur le premier.
     */
    public function testApresUnChangementDeMotDePasseLaSessionEnCoursSurvitEtLesAutresTombent(): void
    {
        $this->creerUtilisateur(self::COURRIEL);

        $this->connexion(self::COURRIEL);
        $sessionA = $this->cookiesDeSession();

        $this->client->getCookieJar()->clear();
        $this->connexion(self::COURRIEL);
        $this->requete('POST', '/api/session/mot-de-passe', [
            'motDePasseActuel' => self::MOT_DE_PASSE,
            'nouveauMotDePasse' => 'un-nouveau-mot-de-passe-long',
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(200, 'Le § 2.1 promet que la session en cours survit au changement de mot de passe.');

        $this->reprendreLesCookies($sessionA);
        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(401, 'Une session ouverte ailleurs porte l’ancienne empreinte : elle doit tomber.');

        $this->client->getCookieJar()->clear();
        $this->connexion(self::COURRIEL, self::MOT_DE_PASSE);
        self::assertResponseStatusCodeSame(401, 'L’ancien mot de passe ne doit plus ouvrir de session.');

        $this->connexion(self::COURRIEL, 'un-nouveau-mot-de-passe-long');
        self::assertResponseStatusCodeSame(200);
    }

    private function demander(string $nouveau, string $motDePasse = self::MOT_DE_PASSE): void
    {
        $this->requete('POST', '/api/session/courriel', [
            'motDePasseActuel' => $motDePasse,
            'nouveauCourriel' => $nouveau,
        ]);
    }

    /** Le jeton tel que le titulaire le lirait : dans le dernier courriel, nulle part ailleurs. */
    private function jetonDuDernierCourriel(): string
    {
        $messages = self::getMailerMessages();
        $dernier = $messages[\count($messages) - 1] ?? null;
        self::assertInstanceOf(Email::class, $dernier, 'Aucun courriel n’a été collecté.');

        $corps = (string) $dernier->getTextBody();
        self::assertSame(1, preg_match('#/compte/courriel/([A-Za-z0-9_-]{43})#', $corps, $capture), $corps);

        return $capture[1];
    }

    /** @return list<Cookie> */
    private function cookiesDeSession(): array
    {
        return array_values($this->client->getCookieJar()->all());
    }

    /** @param list<Cookie> $cookies */
    private function reprendreLesCookies(array $cookies): void
    {
        $this->client->getCookieJar()->clear();

        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
    }
}
