<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Service\SettingService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les réglages d'administration — et `cercle.generations` en particulier, qui doit être
 * modifiable **sans redéploiement** (§ 6.2 du dossier de mission, ADR 0003).
 */
final class ReglagesTest extends CoreWebTestCase
{
    public function testLaValeurEstRendueCasteeSelonSonType(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('GET', '/api/administration/reglages');
        self::assertResponseIsSuccessful();

        $reglages = [];
        foreach ($this->charge() as $ligne) {
            $reglages[$ligne['key']] = $ligne;
        }

        // « `value` est rendu casté selon `valueType`, jamais en texte brut » (§ 10.2).
        self::assertSame(3, $reglages['cercle.generations']['value']);
        self::assertSame('entier', $reglages['cercle.generations']['valueType']);
        self::assertSame(1, $reglages['cercle.generations']['minValue']);
        self::assertSame(6, $reglages['cercle.generations']['maxValue']);

        self::assertSame(
            ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
            $reglages['media.mimes_autorises']['value'],
        );
    }

    public function testLAdministrateurChangeLeNDuCercleSansRedeploiement(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('PATCH', '/api/administration/reglages/cercle.generations', ['value' => 4]);

        self::assertResponseIsSuccessful();
        $charge = $this->charge();
        self::assertSame(4, $charge['value']);
        self::assertSame('natron@example.net', $charge['updatedByEmail']);

        // Et le service, qui est ce que lira `Membership`, voit bien la nouvelle valeur.
        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);
        self::assertSame(4, $reglages->generationsDuCercle());
    }

    /**
     * « Au-delà de 6 le cercle couvre l'arbre entier et la règle ne protège plus
     * personne » : la borne n'est pas décorative.
     */
    public function testUneValeurHorsBornesEstRefuseeAvecLesBornes(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('PATCH', '/api/administration/reglages/cercle.generations', ['value' => 12]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $erreur = $this->charge()['erreur'];
        self::assertSame('valeur_hors_bornes', $erreur['code']);
        self::assertSame(['minValue' => 1, 'maxValue' => 6], $erreur['details']);

        // Zéro est refusé pour la même raison, et c'est le point de l'ADR 0003 : un
        // `N = 0` n'est pas « pas de cercle », c'est un cercle qui contient encore `u` et
        // son conjoint. Le régime « aucun » ne s'obtient pas par ce réglage.
        $this->requete('PATCH', '/api/administration/reglages/cercle.generations', ['value' => 0]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('valeur_hors_bornes', $this->charge()['erreur']['code']);
    }

    public function testUneValeurQuiNeCastePasEstRefusee(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        foreach (['quatre', 4.5, true, null] as $valeur) {
            $this->requete('PATCH', '/api/administration/reglages/cercle.generations', ['value' => $valeur]);

            self::assertResponseStatusCodeSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                \sprintf('« %s » ne caste pas en entier.', var_export($valeur, true)),
            );
            self::assertSame('donnees_invalides', $this->charge()['erreur']['code']);
        }
    }

    public function testUneCleInconnueRend404EtNonUneListeDeClesExistantes(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('PATCH', '/api/administration/reglages/cercle.inexistant', ['value' => 1]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('introuvable', $this->charge()['erreur']['code']);
    }

    public function testUnMembreNeToucheNiNeLitLesReglages(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $this->requete('GET', '/api/administration/reglages');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->requete('PATCH', '/api/administration/reglages/cercle.generations', ['value' => 6]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);
        self::assertSame(3, $reglages->generationsDuCercle(), 'Le refus doit avoir empêché l’écriture.');
    }

    public function testLeReplideLectureNOuvrePasLeCercle(): void
    {
        // Base sans la ligne : la lecture retombe sur le défaut de la migration, pas sur
        // une valeur permissive ni sur une exception.
        $em = $this->em();
        $reglage = $em->getRepository(\App\Module\Core\Entity\Setting::class)
            ->findOneBy(['settingKey' => 'cercle.generations']);
        self::assertNotNull($reglage);
        $em->remove($reglage);
        $em->flush();

        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);

        self::assertSame(SettingService::DEFAUT_CERCLE_GENERATIONS, $reglages->generationsDuCercle());
    }
}
