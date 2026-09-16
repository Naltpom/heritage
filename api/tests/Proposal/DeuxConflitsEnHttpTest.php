<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\RequestStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LES DEUX `409` AUX CONSEILS OPPOSÉS — **en réponse HTTP réelle**.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * `Conflit` les décrit, `ValidationDeDemandeTest` les éprouve au niveau du service, le
 * contrat d'API y consacre une divergence entière (§ 6.8, § 15.4) — et pourtant
 * **personne ne les avait jamais vues sortir d'une requête**. Entre l'exception levée et
 * l'objet JSON que le front reçoit, il y a `ApiExceptionSubscriber`, `ErrorEnvelope` et
 * la sérialisation de `details` : trois étages qu'un test de service ne traverse pas.
 *
 * |                                 | `demande_caduque`      | `verrou_concurrent` |
 * |---------------------------------|------------------------|---------------------|
 * | HTTP                            | `409`                  | `409`               |
 * | `details.rejouable`             | **`false`**            | **`true`**          |
 * | `details.statut`                | `caduque`              | `en_attente`        |
 * | Écrit dans `prp_change_request` | **oui — le serveur a agi** | **non**         |
 * | Écrit dans l'arbre              | non                    | non                 |
 * | Ce que le front doit proposer   | « Refaire cette demande » | **« Réessayer »** |
 *
 * **Les traiter pareil est un bug de DONNÉES, pas d'affichage.** Un front écrit contre
 * « 409 ⇒ bouton Réessayer » — ce que tout le monde écrit — fait boucler l'administrateur
 * sur une demande morte ; et si le rejeu aboutissait, la modification serait écrite deux
 * fois. C'est pour cela que les deux cas sont produits **dans le même fichier**, et que
 * {@see self::testLesDeuxConflitsNeSeRessemblentPasEnHttp()} les compare côte à côte :
 * deux tests séparés peuvent passer tous les deux tout en décrivant la même réponse.
 *
 * Le scénario de la caducité est celui du dossier, joué en quatre requêtes : deux
 * demandes sur **le même champ** depuis **le même cliché** (`baseVersion = 7`), la
 * seconde validée, puis la première.
 */
final class DeuxConflitsEnHttpTest extends CasDeTestDemandesHttp
{
    private const int PERSONNE_OPALE = 41;
    private const int PERSONNE_TUF = 42;

    /**
     * **La `409` qui écrit.** Rien ne part dans l'arbre, mais la demande passe `caduque`
     * et n'est plus dans la file : « la lever sans avoir commité rendrait le code menteur,
     * et le front rechargerait une demande toujours dans la file ».
     */
    public function testUneDemandeDoubleeSurLeMemeChampRendDemandeCaduqueEtEcrit(): void
    {
        [$opale] = $this->deuxDemandesSurLeMemeChamp();

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$opale.'/valider');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $erreur = $this->erreur();
        self::assertSame('demande_caduque', $erreur['code']);

        $details = $erreur['details'];
        self::assertIsArray($details, 'C’est `details` qui porte le conseil ; une enveloppe sans lui ne sert à rien.');
        self::assertFalse($details['rejouable'], 'Une caduque ne se rejoue pas : la valeur elle-même a bougé.');
        self::assertSame(RequestStatus::Caduque->value, $details['statut']);
        self::assertSame($opale, $details['demandeId']);

        // `champs` dit CE QUI a bougé, et c'est ce qui permet au front d'afficher le diff
        // sans une seconde requête. On affirme la taille avant de le parcourir.
        self::assertIsArray($details['champs']);
        self::assertCount(1, $details['champs']);
        self::assertSame('surname', $details['champs'][0]['field']);
        self::assertSame('Sureau', $details['champs'][0]['valeurALaSoumission']);
        self::assertSame('Sureaut', $details['champs'][0]['valeurCourante']);

        // ── LE SERVEUR A AGI. La demande n'est plus en attente, et on le relit par HTTP.
        self::assertSame(RequestStatus::Caduque->value, $this->statutDeLaDemande($opale));

        // ── ET L'ARBRE N'A PAS ÉTÉ ÉCRASÉ : la valeur de Tuf tient.
        self::assertSame('Sureaut', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));

        // L'auteur est notifié, avec le diff — une caducité sans notification est une
        // contribution perdue en silence.
        self::assertCount(1, $this->notificateur()->de('demande_caduque'));
    }

    /**
     * **La `409` qui n'écrit rien**, et qui conseille l'inverse.
     *
     * Le second appel n'est pas une commodité de test : c'est la démonstration que
     * « réessayez » est un conseil tenable. Doctrine **ferme** l'`EntityManager` quand une
     * transaction échoue, si bien qu'un réessai n'est possible que dans une requête
     * neuve — ce que le client HTTP fait de lui-même entre deux `request()`.
     */
    public function testUnVerrouConcurrentNEcritRienEtLeReessaiAboutit(): void
    {
        $this->connexionContributeur();
        $demande = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]);

        $this->connexionAdministrateur();

        // La collision de transaction que `#[ORM\Version]` produirait, armée sur le
        // double : l'état est statique et survit donc au redémarrage du noyau.
        $this->arbre()->simulerVerrouConcurrent(true);

        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $erreur = $this->erreur();
        self::assertSame('verrou_concurrent', $erreur['code']);

        $details = $erreur['details'];
        self::assertIsArray($details);
        self::assertTrue($details['rejouable'], 'Rien n’a été écrit : réessayer est exactement la bonne action.');
        self::assertSame(RequestStatus::EnAttente->value, $details['statut']);
        self::assertSame($demande, $details['demandeId']);

        // ── RIEN N'A ÉTÉ ÉCRIT. Ni dans l'arbre, ni sur la demande, ni en notification.
        self::assertSame('Sureau', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame(self::VERSION_DE_DEPART, $this->arbre()->version(SubjectKind::Personne, self::PERSONNE));
        self::assertSame(RequestStatus::EnAttente->value, $this->statutDeLaDemande($demande));
        self::assertSame([], $this->notificateur()->de('demande_caduque'), 'Un verrou concurrent ne notifie pas l’auteur.');

        // ── ET LE RÉESSAI ABOUTIT, dans une requête neuve.
        $this->arbre()->simulerVerrouConcurrent(false);
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider');

        self::assertResponseIsSuccessful('« Réessayez » doit être un conseil tenable, pas une formule.');
        self::assertSame('validee', $this->charge()['resultat']);
        self::assertSame('SUREAU', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame(self::VERSION_DE_DEPART + 1, $this->arbre()->version(SubjectKind::Personne, self::PERSONNE));
    }

    /**
     * **Les deux, dans le même test, comparés champ par champ.**.
     *
     * Deux tests séparés peuvent passer tous les deux en décrivant la même réponse : il
     * suffit qu'une régression rende `demande_caduque` sur les deux chemins. Ce qu'on
     * veut prouver est une **différence**, et une différence se prouve en comparant, pas
     * en affirmant deux fois.
     */
    public function testLesDeuxConflitsNeSeRessemblentPasEnHttp(): void
    {
        $verrou = $this->conflitDeVerrou();
        $caduque = $this->conflitDeCaducite();

        // Même statut : c'est bien ce qui rend la confusion possible.
        self::assertSame(Response::HTTP_CONFLICT, $verrou['statut']);
        self::assertSame(Response::HTTP_CONFLICT, $caduque['statut']);

        // Et tout le reste les oppose.
        self::assertNotSame($verrou['erreur']['code'], $caduque['erreur']['code']);
        self::assertSame('verrou_concurrent', $verrou['erreur']['code']);
        self::assertSame('demande_caduque', $caduque['erreur']['code']);

        self::assertTrue($verrou['erreur']['details']['rejouable']);
        self::assertFalse($caduque['erreur']['details']['rejouable']);

        self::assertSame(RequestStatus::EnAttente->value, $verrou['erreur']['details']['statut']);
        self::assertSame(RequestStatus::Caduque->value, $caduque['erreur']['details']['statut']);

        // Le fait qui compte, et qui ne se lit pas dans la réponse : l'un a laissé la
        // demande dans la file, l'autre l'en a sortie.
        self::assertSame(RequestStatus::EnAttente->value, $verrou['statutApres']);
        self::assertSame(RequestStatus::Caduque->value, $caduque['statutApres']);
    }

    /**
     * **Rejouer une caduque n'écrit pas deux fois.**.
     *
     * C'est la conséquence concrète de la confusion : un front qui propose « Réessayer »
     * sur une caduque fait re-poster la validation. Le serveur doit alors répondre
     * `demande_deja_decidee` — et surtout ne rien écrire, sans quoi la modification
     * partirait une seconde fois dans l'arbre.
     */
    public function testRejouerUneCaduqueNePeutPasEcrireUneSecondeFois(): void
    {
        [$opale] = $this->deuxDemandesSurLeMemeChamp();

        $this->connexionAdministrateur();

        $this->requete('POST', '/api/administration/demandes/'.$opale.'/valider');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('demande_caduque', $this->erreur()['code']);

        $versionApresLaCaducite = $this->arbre()->version(SubjectKind::Personne, self::PERSONNE);

        // Le clic « Réessayer » qu'un front mal écrit produirait.
        $this->requete('POST', '/api/administration/demandes/'.$opale.'/valider');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(
            'demande_deja_decidee',
            $this->erreur()['code'],
            'Le second clic ne doit pas retomber sur demande_caduque : la demande est tranchée, pas caduque à nouveau.',
        );

        self::assertSame(
            $versionApresLaCaducite,
            $this->arbre()->version(SubjectKind::Personne, self::PERSONNE),
            'Rejouer une caduque ne doit écrire ABSOLUMENT rien dans l’arbre.',
        );
        self::assertSame('Sureaut', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
    }

    /**
     * Le chemin nominal, pour que les deux `409` se lisent par contraste : une validation
     * qui aboutit rend `200`, `resultat = validee`, et une empreinte d'arbre neuve.
     */
    public function testUneValidationSansConflitRend200EtEcritUneFois(): void
    {
        $this->connexionContributeur();
        $demande = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')], 'la graphie de l’acte');

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider');

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame('validee', $charge['resultat']);
        self::assertSame(RequestStatus::Validee->value, $charge['demande']['status']);
        self::assertFalse($charge['demande']['autoValidated'], 'La demande est celle d’un membre, pas de l’administrateur.');
        self::assertArrayNotHasKey('rejeu', $charge, 'Sans rejeu, la clé est ABSENTE — jamais nulle (§ 1.3).');

        // On affirme la taille de l'ensemble avant de le parcourir.
        self::assertIsArray($charge['revisions']);
        self::assertCount(1, $charge['revisions']);
        self::assertSame(self::VERSION_DE_DEPART, $charge['revisions'][0]['versionBefore']);
        self::assertSame(self::VERSION_DE_DEPART + 1, $charge['revisions'][0]['versionAfter']);

        // L'empreinte de l'arbre part avec la réponse : le front sait que son `ETag` de
        // graphe est périmé sans attendre une revalidation (§ 6.8).
        self::assertIsString($charge['empreinte']);
        self::assertNotSame('', $charge['empreinte']);

        self::assertSame('SUREAU', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame(1, $this->arbre()->ecritures(), 'Une validation, une écriture.');
    }

    /**
     * La route de validation est fermée au membre : `access_control` place
     * `^/api/administration` derrière `ROLE_ADMIN`, et un membre qui la forcerait
     * validerait sa propre demande.
     */
    public function testUnMembreNeValidePasSaPropreDemande(): void
    {
        $this->connexionContributeur();
        $demande = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')]);

        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->erreur()['code']);

        // Et rien n'a bougé : le refus est bien intervenu avant l'écriture.
        self::assertSame('Sureau', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame(0, $this->arbre()->ecritures());
    }

    /** Sans session du tout : `401`, avec l'enveloppe du § 15.1 et non une page HTML. */
    public function testLaValidationEstFermeeALAnonyme(): void
    {
        $this->requete('POST', '/api/administration/demandes/1/valider');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('authentification_requise', $this->erreur()['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Le scénario, et les deux conflits produits pour comparaison
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Le scénario du dossier : **deux demandes sur le même champ depuis le même cliché**,
     * la seconde validée. La première devient alors caduque, et c'est sa validation qui
     * produit la `409` qui écrit.
     *
     * @return array{0: int, 1: int} l'identifiant d'Opale (encore en attente), puis celui de Tuf (validée)
     */
    private function deuxDemandesSurLeMemeChamp(): array
    {
        $this->connexionContributeur();
        $opale = $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')], 'l’acte donne SUREAU');

        $this->connexionSecondContributeur();
        $tuf = $this->deposer([$this->opTexte('surname', 'Sureau', 'Sureaut')], 'la table décennale donne Sureaut');

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$tuf.'/valider');
        self::assertResponseIsSuccessful('La seconde demande doit se valider : c’est elle qui rend la première caduque.');
        self::assertSame('validee', $this->charge()['resultat']);

        return [$opale, $tuf];
    }

    /**
     * @return array{statut: int, erreur: array<string, mixed>, statutApres: string}
     */
    private function conflitDeVerrou(): array
    {
        $this->connexionContributeur();
        $demande = $this->deposer([$this->opTexte('nickname', null, 'Perle')]);

        $this->connexionAdministrateur();
        $this->arbre()->simulerVerrouConcurrent(true);
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider');

        $conflit = [
            'statut' => $this->client->getResponse()->getStatusCode(),
            'erreur' => $this->erreur(),
        ];

        $this->arbre()->simulerVerrouConcurrent(false);

        return $conflit + ['statutApres' => $this->statutDeLaDemande($demande)];
    }

    /**
     * @return array{statut: int, erreur: array<string, mixed>, statutApres: string}
     */
    private function conflitDeCaducite(): array
    {
        [$opale] = $this->deuxDemandesSurLeMemeChamp();

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$opale.'/valider');

        $conflit = [
            'statut' => $this->client->getResponse()->getStatusCode(),
            'erreur' => $this->erreur(),
        ];

        return $conflit + ['statutApres' => $this->statutDeLaDemande($opale)];
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
