<?php

declare(strict_types=1);

namespace App\Tests\Evenement;

use App\Module\Evenement\Service\LimiteurDesLiens;
use App\Module\Membership\Entity\Member;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le lien personnel d'un invité — `/e/<clé>` côté front, `/api/evenements/lien/{clé}` ici —,
 * **de bout en bout, sans session**.
 *
 * Les trois propriétés du lien de tirage (`tests/Tirage/LiensPersonnelsTest`), et une
 * quatrième, propre aux événements :
 *
 * 1. **il ne montre que son invitation** — l'annonce, sa réponse, et aucun autre nom ;
 * 2. **un seul refus** pour toutes les façons de ne plus valoir ;
 * 3. **seule l'empreinte est stockée** ;
 * 4. **l'ouvrir pose la vue** de l'invité — sauf quand c'est l'organisateur qui le vérifie.
 */
final class LiensDEvenementTest extends CasDeTestEvenements
{
    public function testLeLienMontreLAnnonceEtRienDAutreEtPoseLaVue(): void
    {
        $id = $this->cousinade();
        $cle = $this->creerLien($id, 'oncle');

        // Le détail dit qu'un lien existe — jamais sa clé.
        $this->requete("/api/evenements/{$id}");
        $nonVus = array_column($this->charge()['suivi']['nonVus'], null, 'personId');
        self::assertTrue($nonVus[$this->idDe('oncle')]['lienActif']);
        self::assertFalse($nonVus[$this->idDe('enfant')]['lienActif']);
        self::assertStringNotContainsString($cle, (string) $this->client->getResponse()->getContent());

        $this->deconnexion();
        $this->requete("/api/evenements/lien/{$cle}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        self::assertSame([
            'evenement' => [
                'titre' => 'Cousinade été '.$this->annee,
                'debut' => $this->annee.'-07-17',
                'fin' => $this->annee.'-07-18',
                'lieu' => 'Chez Tante Ardoise',
                'description' => "Apportez un plat.\nOn dort sur place.",
                'annule' => false,
                'passe' => false,
            ],
            'invite' => ['libelle' => 'Basalte Romarin', 'reponse' => null, 'aConfirmer' => false, 'peutRepondre' => true],
        ], $this->charge());

        $this->assertAucunAutreNom((string) $this->client->getResponse()->getContent(), ['oncle']);
        self::assertNotNull($this->invitationDe($id, 'oncle')->getVuLe(), 'Ouvrir son lien, c’est voir l’annonce.');
        self::assertContains('oncle', $this->groupesDuSuivi($id)['fantomes']);
    }

    /** L'organisateur qui vérifie le lien avant de l'envoyer ne voit pas à la place de l'invité. */
    public function testLOrganisateurQuiOuvreLeLienNeMarquePasLaVue(): void
    {
        $id = $this->cousinade();
        $cle = $this->creerLien($id, 'oncle');

        $this->requete("/api/evenements/lien/{$cle}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame('Basalte Romarin', $this->charge()['invite']['libelle'], 'La réponse est la même que pour l’invité…');
        self::assertNull($this->invitationDe($id, 'oncle')->getVuLe(), '… mais la vue ne se pose pas.');
    }

    public function testRepondreParLeLienJusquALAnnulation(): void
    {
        $id = $this->cousinade();
        $cle = $this->creerLien($id, 'oncle');

        $this->deconnexion();
        $this->envoyer('PUT', "/api/evenements/lien/{$cle}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame('present', $this->charge()['invite']['reponse']);

        $this->envoyer('PUT', "/api/evenements/lien/{$cle}/reponse", ['reponse' => 'oui']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());

        self::assertSame(['oncle'], $this->groupesDuSuivi($id)['presents']);

        $this->envoyer('POST', "/api/evenements/{$id}/annulation");
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->deconnexion();
        $this->requete("/api/evenements/lien/{$cle}");
        self::assertSame(Response::HTTP_OK, $this->statut(), 'Le lien dit encore que l’événement est annulé.');
        self::assertTrue($this->charge()['evenement']['annule']);
        self::assertFalse($this->charge()['invite']['peutRepondre']);

        $this->envoyer('PUT', "/api/evenements/lien/{$cle}/reponse", ['reponse' => 'absent']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('reponse_figee', $this->codeDErreur());
    }

    /**
     * Le lien d'une personne qui a son compte se refuse : le tenir, ce serait répondre et
     * « voir » à sa place. Et seul l'organisateur crée un lien.
     */
    public function testLeLienEstReserveALOrganisateurPourUnInviteSansCompte(): void
    {
        $id = $this->cousinade();

        $this->envoyer('POST', "/api/evenements/{$id}/invites/{$this->idDe('cousin')}/lien");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
        self::assertSame('acces_refuse', $this->codeDErreur());
        self::assertArrayNotHasKey('cle', $this->charge());

        $this->envoyer('POST', "/api/evenements/{$id}/invites/{$this->idDe('tante')}/lien");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut(), 'Pas invitée, pas de lien.');

        $this->deconnexion();
        $this->envoyer('POST', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/lien");
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statut(), 'Créer un lien demande une session.');
    }

    /**
     * Mal formée, inconnue, révoquée, remplacée, invité retiré, invité qui a ouvert son compte
     * depuis : la **même** réponse, au même corps.
     */
    public function testToutesLesClesQuiNOuvrentRienRendentLaMemeReponse(): void
    {
        $id = $this->cousinade();

        $revoquee = $this->creerLien($id, 'oncle');
        $this->envoyer('DELETE', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/lien");
        self::assertSame(Response::HTTP_NO_CONTENT, $this->statut());

        $remplacee = $this->creerLien($id, 'oncle');
        $valide = $this->creerLien($id, 'oncle');
        self::assertNotSame($remplacee, $valide);

        $retire = $this->creerLien($id, 'enfant');
        $this->envoyer('DELETE', "/api/evenements/{$id}/invites/{$this->idDe('enfant')}");
        self::assertSame(Response::HTTP_OK, $this->statut());

        $compteOuvert = $this->creerLien($this->cousinade(['ego', 'tante']), 'tante', avecCompte: false);

        $this->deconnexion();
        $corps = [];

        foreach ([
            'inconnue' => str_repeat('A', 43),
            'mal formée' => 'trop-courte',
            'révoquée' => $revoquee,
            'remplacée' => $remplacee,
            'invité retiré' => $retire,
            'invité qui a son compte' => $compteOuvert,
        ] as $cas => $cle) {
            $this->requete('/api/evenements/lien/'.rawurlencode($cle));
            self::assertSame(Response::HTTP_NOT_FOUND, $this->statut(), $cas);
            self::assertSame('lien_invalide', $this->codeDErreur(), $cas);
            $corps[$cas] = (string) $this->client->getResponse()->getContent();

            $this->envoyer('PUT', '/api/evenements/lien/'.rawurlencode($cle).'/reponse', ['reponse' => 'absent']);
            self::assertSame(Response::HTTP_NOT_FOUND, $this->statut(), $cas);
        }

        self::assertCount(1, array_unique($corps), 'Un seul corps de refus, quel que soit le cas.');

        $this->requete("/api/evenements/lien/{$valide}");
        self::assertSame(Response::HTTP_OK, $this->statut(), 'La clé régénérée, elle, vaut.');
    }

    public function testSeuleLEmpreinteEstStockee(): void
    {
        $id = $this->cousinade();
        $cle = $this->creerLien($id, 'oncle');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $cle);

        $invitation = $this->invitationDe($id, 'oncle');
        self::assertTrue($invitation->aUnLienActif());

        $ligne = $this->em()->getConnection()->fetchAssociative('SELECT lien_token_hash FROM evt_invitation WHERE id = ?', [$invitation->getId()]);
        self::assertIsArray($ligne);
        self::assertSame(hash('sha256', $cle), $ligne['lien_token_hash']);
    }

    public function testUneAdresseQuiEnchaineLesClesInconnuesEstArretee(): void
    {
        $id = $this->cousinade();
        $cle = $this->creerLien($id, 'oncle');
        $this->deconnexion();

        for ($i = 0; $i < LimiteurDesLiens::ECHECS_PAR_IP; ++$i) {
            $this->requete('/api/evenements/lien/'.str_pad((string) $i, 43, 'x'));
            self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
        }

        // Même une clé valide : c'est l'adresse qui est arrêtée, pas la clé.
        $this->requete("/api/evenements/lien/{$cle}");
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->statut());
        self::assertSame('trop_de_tentatives', $this->codeDErreur());
    }

    /* ───────────────── Outils ───────────────── */

    /**
     * Crée (ou régénère) le lien d'un invité, en organisateur, et rend la clé.
     *
     * `avecCompte: false` sert le seul cas où l'on veut un lien **puis** un compte : la
     * personne (`tante`, rattachée à Ambre par le socle) est d'abord détachée, le lien créé,
     * puis le rattachement rétabli — exactement ce qui arrive à une grand-mère qui ouvre son
     * compte après avoir reçu son lien.
     */
    private function creerLien(int $evenement, string $personne, bool $avecCompte = true): string
    {
        $rattachement = null;

        if (!$avecCompte) {
            $rattachement = $this->em()->getRepository(Member::class)->findOneBy(['personId' => $this->idDe($personne)]);
            self::assertNotNull($rattachement);
            $this->em()->remove($rattachement);
            $this->em()->flush();
        }

        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/evenements/{$evenement}/invites/{$this->idDe($personne)}/lien");
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        $charge = $this->charge();
        self::assertIsString($charge['cle']);
        self::assertSame('/e/'.$charge['cle'], $charge['url']);

        if (null !== $rattachement) {
            $this->rattacher('ardoise@example.invalid', $personne);
        }

        return $charge['cle'];
    }

    /**
     * Aucun nom de personne ne figure dans la réponse, hors ceux qu'on autorise.
     *
     * @param list<string> $permis
     */
    private function assertAucunAutreNom(string $contenu, array $permis): void
    {
        $texte = json_encode(json_decode($contenu, false, flags: \JSON_THROW_ON_ERROR), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        foreach ($this->personnes as $cle => $personne) {
            if (\in_array($cle, $permis, true)) {
                continue;
            }

            self::assertStringNotContainsString($personne->getDisplayName(), $texte, \sprintf('Le lien ne doit pas nommer « %s ».', $cle));
        }

        self::assertStringNotContainsString('personId', $texte, 'Le lien ne désigne aucune personne par son identifiant.');
    }
}
