<?php

declare(strict_types=1);

namespace App\Tests\Core;

use Symfony\Component\HttpFoundation\Response;

/**
 * Le carnet de notes de l'administrateur — personnel, épinglable, hors de l'arbre.
 */
final class CarnetDeNotesTest extends CoreWebTestCase
{
    public function testLAdministrateurEcritModifieEtSupprimeUneNote(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('POST', '/api/administration/notes', [
            'titre' => '  Vidéo « Le carnet »  ',
            'texte' => "Voix, musique, bruitages.\nComme les autres vidéos d'aide.",
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $note = $this->charge();
        self::assertSame('Vidéo « Le carnet »', $note['titre']);
        self::assertFalse($note['epinglee']);
        $id = $note['id'];
        \assert(\is_int($id));

        $this->requete('PUT', "/api/administration/notes/{$id}", ['titre' => 'Vidéo', 'texte' => 'Fait.', 'epinglee' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame('Fait.', $this->charge()['texte']);
        self::assertTrue($this->charge()['epinglee']);

        $this->requete('DELETE', "/api/administration/notes/{$id}");
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->requete('GET', '/api/administration/notes');
        self::assertSame([], $this->charge());
    }

    public function testLesEpingleesPassentDevant(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('POST', '/api/administration/notes', ['titre' => 'Épinglée', 'epinglee' => true]);
        $this->requete('POST', '/api/administration/notes', ['titre' => 'Récente']);

        $this->requete('GET', '/api/administration/notes');
        self::assertSame(['Épinglée', 'Récente'], array_column($this->charge(), 'titre'));
    }

    public function testUneNoteDUnAutreAdministrateurEstIntrouvable(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->creerUtilisateur('autre@example.net', roles: ['ROLE_ADMIN']);

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/notes', ['titre' => 'À moi']);
        $id = $this->charge()['id'];
        \assert(\is_int($id));
        $this->requete('POST', '/api/deconnexion');

        $this->connexion('autre@example.net');
        $this->requete('GET', '/api/administration/notes');
        self::assertSame([], $this->charge());

        $this->requete('PUT', "/api/administration/notes/{$id}", ['titre' => 'Volée']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->requete('DELETE', "/api/administration/notes/{$id}");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUnTitreVideEstRefuse(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('POST', '/api/administration/notes', ['titre' => '   ', 'texte' => 'x']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey('titre', $this->charge()['erreur']['champs']);
    }

    public function testUnMembreNOuvrePasLeCarnet(): void
    {
        $this->creerUtilisateur('membre@example.net');
        $this->connexion('membre@example.net');

        $this->requete('GET', '/api/administration/notes');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testLeTexteDUneNoteNEntrePasAuJournal(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('POST', '/api/administration/notes', ['titre' => 'Idée', 'texte' => 'secret-du-carnet']);

        foreach ($this->journal() as $ligne) {
            self::assertStringNotContainsString('secret-du-carnet', json_encode($ligne->getChanges(), \JSON_THROW_ON_ERROR));
        }
    }
}
