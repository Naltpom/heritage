<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\RequestStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/administration/demandes/{id}/refuser` — **le refus avec message, en HTTP**.
 *
 * ── La règle, et les trois endroits où elle est tenue ────────────────────────────
 *
 * « Le motif du refus est obligatoire » est une règle métier du § 6.3 du dossier, pas une
 * préférence d'interface. {@see \App\Module\Proposal\Service\RefusDeDemande} l'écrit en
 * toutes lettres : elle est tenue *ici* en `422`, par la contrainte
 * `ck_prp_request_motif_refus` en base, et par le front qui désactive le bouton. Les
 * trois, parce que « le front ne doit pas être le seul à la connaître, et l'API ne doit
 * pas compter sur le front ».
 *
 * Ce fichier vérifie la première des trois — **par la réponse HTTP**, la seule des trois
 * que le front reçoit réellement.
 *
 * ── Les trois espaces ────────────────────────────────────────────────────────────
 *
 * Le cas qui n'est pas décoratif : un motif de trois espaces. Le service applique un
 * `trim` **identique au `btrim` de la contrainte `CHECK`**, et c'est délibéré — « éviter
 * qu'une chaîne d'espaces passe le service pour se faire refuser par la base,
 * c'est-à-dire une `500` là où une `422` était due ». Un formulaire dont le champ a été
 * effleuré envoie exactement cela.
 *
 * ── Et le motif doit PARVENIR À L'AUTEUR ─────────────────────────────────────────
 *
 * Un refus sec est une contribution perdue et un contributeur perdu. Le test ne se
 * contente donc pas du `200` de l'administrateur : il **rouvre une session d'auteur** et
 * relit la demande par `GET /api/demandes/{id}`, parce que c'est là que le membre lira le
 * motif. Vérifier la valeur dans la réponse de l'administrateur ne prouverait que la
 * sérialisation ; la relire côté auteur prouve le trajet.
 */
final class RefusEnHttpTest extends CasDeTestDemandesHttp
{
    private const int PERSONNE_OPALE = 41;

    private const string MOTIF = 'La graphie de l’acte est bien « Sureau » : voir la table décennale de 1904.';

    /** Sans motif du tout : `422`, et `champs.message` dit où corriger. */
    public function testUnRefusSansMotifRend422(): void
    {
        $demande = $this->uneDemandeEnAttente();

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $erreur = $this->erreur();
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertIsArray($erreur['champs']);
        self::assertSame(['message'], array_keys($erreur['champs']), 'Une 422 nomme le champ à reprendre, et lui seul.');

        // Rien n'a été décidé : la demande est toujours dans la file.
        self::assertSame(RequestStatus::EnAttente->value, $this->statutDeLaDemande($demande));
    }

    /**
     * **Trois espaces.** Le serveur fait un `btrim` ; la chaîne est donc vide, et c'est
     * `422` et non `200` — ni `500` par la contrainte de base.
     */
    public function testUnMotifDeTroisEspacesRend422LuiAussi(): void
    {
        $demande = $this->uneDemandeEnAttente();

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', ['message' => '   ']);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Un motif de trois espaces est un motif absent : le service applique le même btrim que ck_prp_request_motif_refus.',
        );

        $erreur = $this->erreur();
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertIsArray($erreur['champs']);
        self::assertSame(['message'], array_keys($erreur['champs']));

        self::assertSame(RequestStatus::EnAttente->value, $this->statutDeLaDemande($demande));
    }

    /**
     * Les formes voisines du « motif vide » que le contrat range toutes en `422`.
     *
     * L'ensemble est **écrit ici et dénombré** : un fournisseur de données qui se viderait
     * ferait passer le test au vert en n'éprouvant plus rien.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function motifsRefuses(): iterable
    {
        yield 'clé absente' => [null];
        yield 'chaîne vide' => [''];
        yield 'trois espaces' => ['   '];
        yield 'espaces et tabulation' => ["\t \n"];
        yield 'nombre' => [42];
        yield 'booléen' => [true];
        yield 'tableau' => [['motif']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('motifsRefuses')]
    public function testAucuneDeCesFormesNeVautUnMotif(mixed $message): void
    {
        self::assertCount(7, iterator_to_array(self::motifsRefuses()), 'L’ensemble éprouvé est dénombré, pas supposé.');

        $demande = $this->uneDemandeEnAttente();

        $this->connexionAdministrateur();
        $this->requete(
            'POST',
            '/api/administration/demandes/'.$demande.'/refuser',
            null === $message ? [] : ['message' => $message],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('donnees_invalides', $this->erreur()['code']);
        self::assertSame(RequestStatus::EnAttente->value, $this->statutDeLaDemande($demande));
    }

    /**
     * Avec motif : `200`, et le motif **porté jusqu'à l'auteur**.
     *
     * Trois lectures, dans cet ordre : la réponse de l'administrateur, la relecture par
     * l'auteur dans sa propre session, et la notification. Les deux premières sont du
     * HTTP ; la troisième est le fait que le dossier demande explicitement.
     */
    public function testUnRefusMotiveRend200EtLeMotifParvientALAuteur(): void
    {
        $demande = $this->uneDemandeEnAttente();

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', ['message' => self::MOTIF]);

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(RequestStatus::Refusee->value, $charge['status']);
        self::assertSame(self::MOTIF, $charge['decisionMessage']);
        self::assertSame('Natron Romarin', $charge['decidedByLabel']);
        self::assertIsString($charge['decidedAt']);
        self::assertNull($charge['duplicateOfId']);

        // ── Le trajet : l'auteur, dans SA session, lit le motif sur SA demande.
        $this->connexionAuteur();
        $this->requete('GET', '/api/demandes/'.$demande);

        self::assertResponseIsSuccessful('Une demande refusée reste consultable par son auteur : elle n’est jamais supprimée.');

        $vueDeLAuteur = $this->charge();
        self::assertSame(RequestStatus::Refusee->value, $vueDeLAuteur['status']);
        self::assertSame(self::MOTIF, $vueDeLAuteur['decisionMessage']);

        // ── Et la notification porte le même texte, pas un résumé.
        $refus = $this->notificateur()->de('demande_refusee');
        self::assertCount(1, $refus);
        self::assertSame(self::MOTIF, $refus[0]['message']);

        // ── Un refus n'écrit rien dans l'arbre. Jamais.
        self::assertSame('Sureau', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame(0, $this->arbre()->ecritures());
    }

    /**
     * Le motif est **rogné** avant d'être stocké : la contrainte de base compare sur
     * `btrim`, et une valeur stockée avec ses espaces ferait diverger l'affichage de la
     * règle.
     */
    public function testLeMotifEstRogneAvantDEtreRendu(): void
    {
        $demande = $this->uneDemandeEnAttente();

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', ['message' => "  \t".self::MOTIF."  \n"]);

        self::assertResponseIsSuccessful();
        self::assertSame(self::MOTIF, $this->charge()['decisionMessage']);
    }

    /**
     * `doublonDeId` — refuser **en renvoyant à une autre demande**, ce à quoi sert
     * précisément le motif obligatoire quand deux cousins ont proposé la même correction.
     */
    public function testUnRefusPeutRenvoyerAUneAutreDemande(): void
    {
        $premiere = $this->uneDemandeEnAttente();

        $this->connexionAuteur();
        $seconde = $this->deposer([$this->opTexte('nickname', null, 'Perle')], 'le surnom familial');

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$seconde.'/refuser', [
            'message' => 'Votre proposition rejoint la demande n° '.$premiere.'.',
            'doublonDeId' => $premiere,
        ]);

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(RequestStatus::Refusee->value, $charge['status']);
        self::assertSame($premiere, $charge['duplicateOfId']);
    }

    /** On ne refuse pas deux fois : le second clic rend `409`, et le motif ne change pas. */
    public function testUnSecondRefusRend409SansEcraserLePremierMotif(): void
    {
        $demande = $this->uneDemandeEnAttente();

        $this->connexionAdministrateur();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', ['message' => self::MOTIF]);
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', ['message' => 'Un autre motif, posté par erreur.']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('demande_deja_decidee', $this->erreur()['code']);

        $this->connexionAuteur();
        $this->requete('GET', '/api/demandes/'.$demande);
        self::assertSame(self::MOTIF, $this->charge()['decisionMessage'], 'Le premier motif est celui qui fait foi.');
    }

    /**
     * La route est fermée au membre — y compris, et surtout, à l'auteur de la demande :
     * refuser la sienne serait se soustraire à la relecture.
     */
    public function testUnMembreNeRefusePasUneDemande(): void
    {
        $demande = $this->uneDemandeEnAttente();

        $this->connexionAuteur();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', ['message' => self::MOTIF]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->erreur()['code']);

        $this->requete('GET', '/api/demandes/'.$demande);
        self::assertSame(RequestStatus::EnAttente->value, $this->charge()['status']);
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** Une demande soumise par l'auteur, prête à être tranchée. */
    private function uneDemandeEnAttente(): int
    {
        $this->connexionAuteur();

        return $this->deposer([$this->opTexte('surname', 'Sureau', 'SUREAU')], 'l’acte donne SUREAU');
    }

    private function connexionAuteur(): void
    {
        if (null === $this->em()->getRepository(User::class)->findOneBy(['email' => 'opale@example.net'])) {
            $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        }

        $this->connexion('opale@example.net');
    }

    private function connexionAdministrateur(): void
    {
        if (null === $this->em()->getRepository(User::class)->findOneBy(['email' => 'natron@example.net'])) {
            $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Natron Romarin');
        }

        $this->connexion('natron@example.net');
    }
}
