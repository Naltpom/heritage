<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\AmorcageDuCompteAdministrateur;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'amorçage du compte administrateur depuis l'environnement.
 *
 * Deux garanties portent tout le reste, et les deux viennent d'un incident réel : le
 * compte d'amorçage a été **perdu deux fois** dans la soirée, une fois par un réimport
 * GEDCOM qui remet `core_user` à zéro, une fois par un `doctrine:fixtures:load` qui purge
 * la base.
 *
 * 1. **Rejouable sans effet de bord** — sinon on n'ose pas la rejouer, et une commande
 *    qu'on n'ose pas lancer ne répare rien.
 * 2. **Le mot de passe existant n'est jamais réécrit** — sinon l'amorçage reprend au
 *    titulaire celui qu'il a changé, sans le dire.
 */
final class AmorcageDuCompteAdministrateurTest extends CoreWebTestCase
{
    private const string COURRIEL = 'commanditaire@example.net';

    private const string MOT_DE_PASSE = 'un-mot-de-passe-d-amorcage';

    public function testSansVariablesIlNeCreeRienEtLeDit(): void
    {
        $resultat = $this->amorcage(courriel: null, motDePasse: null)->amorcer();

        self::assertFalse($resultat['fait']);
        self::assertNull($resultat['utilisateur']);
        // Le motif nomme les variables et le fichier : un refus qui ne dit pas quoi faire
        // envoie lire le code.
        self::assertStringContainsString('HERITAGE_ADMIN_EMAIL', $resultat['motif']);
        self::assertStringContainsString('.env.local', $resultat['motif']);

        self::assertNull($this->depot()->findOneBy(['email' => self::COURRIEL]), 'Aucun compte par défaut.');
    }

    public function testUnMotDePasseTropCourtEstRefuseSansRienCreer(): void
    {
        $resultat = $this->amorcage(motDePasse: 'court')->amorcer();

        self::assertFalse($resultat['fait']);
        self::assertNull($resultat['utilisateur']);
        self::assertNull($this->depot()->findOneBy(['email' => self::COURRIEL]));
    }

    public function testIlCreeUnAdministrateurActifQuiPeutSeConnecter(): void
    {
        $resultat = $this->amorcage()->amorcer();

        self::assertTrue($resultat['fait']);
        $compte = $resultat['utilisateur'];
        self::assertInstanceOf(User::class, $compte);
        self::assertContains('ROLE_ADMIN', $compte->getRoles());
        self::assertSame(UserStatus::Actif, $compte->getStatus());

        // La preuve qui compte : le commanditaire entre réellement avec ce mot de passe.
        $this->connexion(self::COURRIEL, self::MOT_DE_PASSE);
        self::assertResponseIsSuccessful();
        self::assertSame(self::COURRIEL, $this->charge()['utilisateur']['email']);
    }

    public function testRejouerNeDupliquePasEtNeCassePasLeMotDePasse(): void
    {
        $this->amorcage()->amorcer();

        // Le titulaire change son mot de passe, comme il en a le droit.
        $em = $this->em();
        $compte = $this->depot()->findOneBy(['email' => self::COURRIEL]);
        self::assertInstanceOf(User::class, $compte);
        $hacheur = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hacheur);
        $compte->setPassword($hacheur->hashPassword($compte, 'celui-que-le-titulaire-a-choisi'));
        $em->flush();

        $resultat = $this->amorcage()->amorcer();

        self::assertFalse($resultat['fait'], 'Rien à faire sur un compte en état de marche.');
        self::assertCount(1, $this->depot()->findBy(['email' => self::COURRIEL]), 'Aucun doublon.');

        // **Le mot de passe du titulaire tient.** C'est la garantie centrale.
        $this->connexion(self::COURRIEL, 'celui-que-le-titulaire-a-choisi');
        self::assertResponseIsSuccessful();
    }

    /**
     * Le cas qui justifie de réaligner rôle et statut : un administrateur suspendu ou
     * rétrogradé par erreur ne peut plus rien réparer, et le `409 dernier_administrateur`
     * n'a d'autre porte de secours que la console.
     */
    public function testIlRetablitUnCompteSuspenduOuRetrograde(): void
    {
        $this->amorcage()->amorcer();

        $em = $this->em();
        $compte = $this->depot()->findOneBy(['email' => self::COURRIEL]);
        self::assertInstanceOf(User::class, $compte);
        $compte->setRoles([])->setStatus(UserStatus::Suspendu);
        $em->flush();

        $resultat = $this->amorcage()->amorcer();

        self::assertTrue($resultat['fait']);
        self::assertStringContainsString('rôle administrateur rétabli', $resultat['motif']);
        self::assertStringContainsString('réactivé', $resultat['motif']);

        $this->connexion(self::COURRIEL, self::MOT_DE_PASSE);
        self::assertResponseIsSuccessful();
        self::assertContains('ROLE_ADMIN', $this->charge()['utilisateur']['roles']);
    }

    private function amorcage(
        ?string $courriel = self::COURRIEL,
        ?string $motDePasse = self::MOT_DE_PASSE,
        ?string $nom = 'Le Commanditaire',
    ): AmorcageDuCompteAdministrateur {
        $hacheur = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hacheur);

        return new AmorcageDuCompteAdministrateur(
            $this->em(),
            $this->depot(),
            $hacheur,
            $courriel,
            $motDePasse,
            $nom,
        );
    }

    private function depot(): UserRepository
    {
        $depot = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $depot);

        return $depot;
    }
}
