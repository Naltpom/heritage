<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\ErreurDeDemande;

/**
 * Le dépôt à blanc, au niveau du service : {@see \App\Module\Proposal\Service\DepotDeDemande::verifierSansEcrire()}
 * (ADR 0022).
 *
 * Trois promesses, et chacune a ses tests :
 *
 * - **rien n'est écrit**, même par un `flush()` lancé après coup dans la même requête ;
 * - **le statut est celui du dépôt** : la première erreur rencontrée décide, et une `422`
 *   qui précède une `409` l'emporte, comme au dépôt ;
 * - **une `422` les porte toutes**, une par élément des listes, alors que le dépôt s'arrête
 *   toujours à la première. L'assistant, qui appelle la vérification sans lire `soumettre`,
 *   lit la même première clé qu'avant.
 */
final class DepotABlancTest extends BaseDeDemandes
{
    public function testUnFlushApresLeDepotABlancNEcritAucuneDemande(): void
    {
        $administrateur = $this->lecteur(self::ADMIN, true);

        $this->depot->verifierSansEcrire($this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]), $administrateur);

        // Un corps fautif après un op juste : l'op juste est entré dans l'unité de travail
        // avant que le second n'échoue, et doit en être ressorti.
        try {
            $this->depot->verifierSansEcrire($this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU'), $this->opFaux1()]), $administrateur);
            self::fail('Le second op est faux : la vérification doit le dire.');
        } catch (DonneesInvalides $erreur) {
            self::assertSame(['ops[1].subjectKind'], array_keys($erreur->champs() ?? []));
        }

        // Le `flush()` d'un journal ou d'une notification, plus loin dans la même requête.
        $this->em->flush();

        $connexion = $this->em->getConnection();
        self::assertSame(0, (int) $connexion->fetchOne('SELECT count(*) FROM prp_change_request'));
        self::assertSame(0, (int) $connexion->fetchOne('SELECT count(*) FROM prp_change_op'));
    }

    public function testLeDepotSArreteToujoursALaPremiereErreur(): void
    {
        try {
            $this->depot->deposer($this->corpsDeModification([$this->opFaux0(), $this->opFaux1()]), $this->lecteur(self::OPALE));
            self::fail('Les deux ops sont faux : le dépôt doit refuser.');
        } catch (DonneesInvalides $erreur) {
            self::assertSame(['ops[0].opKind'], array_keys($erreur->champs() ?? []), 'Le dépôt ne passe pas de recueil : une seule erreur, la première.');
        }
    }

    public function testLaVerificationRecueilleUneErreurParOp(): void
    {
        $erreur = $this->erreurDeLaVerification($this->corpsDeModification([$this->opFaux0(), $this->opFaux1()]));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertSame(['ops[0].opKind', 'ops[1].subjectKind'], array_keys($erreur->champs() ?? []));
    }

    /** `DemandesDeLAssistant` appelle la vérification sans `$lireSoumettre` : `soumettre` n'est pas lu. */
    public function testLAssistantLitLaMemePremiereErreur(): void
    {
        $corps = $this->corpsDeModification([$this->opFaux0(), $this->opFaux1()]);
        $corps['soumettre'] = 'oui';

        $erreur = $this->erreurDeLaVerification($corps);

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertSame('ops[0].opKind', array_key_first($erreur->champs() ?? []));
        self::assertArrayNotHasKey('soumettre', $erreur->champs() ?? []);
    }

    public function testUneSeuleErreurResteLExceptionDOrigine(): void
    {
        $erreur = $this->erreurDeLaVerification($this->corpsDeCreation([$this->fiche('#p1')], liens: []));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertSame('demande_sans_lien', $erreur->codeErreur());
        self::assertSame('Sans lien, la personne proposée flotterait hors de l\'arbre.', $erreur->getMessage());
        self::assertSame(['liens'], array_keys($erreur->champs() ?? []));
    }

    public function testLAjoutDeNoteRecueilleUneErreurParNote(): void
    {
        $erreur = $this->erreurDeLaVerification([
            'kind' => 'ajout_note',
            'message' => null,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'ops' => [$this->noteVide(1), $this->noteVide(2)],
        ]);

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertSame(['ops[0].newValue.body', 'ops[1].newValue.body'], array_keys($erreur->champs() ?? []));
    }

    /** Une `oldValue` périmée : la `409` du dépôt, et non une `422`. */
    public function testUneErreurNon422ArreteToutSiRienNeLaPrecede(): void
    {
        $erreur = $this->erreurDeLaVerification($this->corpsDeModification([$this->opTexte('surname', 'Faux', 'SUREAU')]));

        self::assertInstanceOf(Conflit::class, $erreur);
        self::assertSame('conflit', $erreur->codeErreur());
    }

    /** `message` est lu avant les ops, au dépôt comme à blanc : sa `422` passe avant la `409`. */
    public function testUne422QuiPrecedeLEmporteCommeAuDepot(): void
    {
        $corps = $this->corpsDeModification([$this->opTexte('surname', 'Faux', 'SUREAU')]);
        $corps['message'] = 42;

        $aBlanc = $this->erreurDeLaVerification($corps);

        self::assertInstanceOf(DonneesInvalides::class, $aBlanc);
        self::assertSame(['message'], array_keys($aBlanc->champs() ?? []));

        try {
            $this->depot->deposer($corps, $this->lecteur(self::OPALE));
            self::fail('Le dépôt doit refuser le même corps.');
        } catch (ErreurDeDemande $auDepot) {
            self::assertInstanceOf(DonneesInvalides::class, $auDepot);
            self::assertSame($aBlanc->codeErreur(), $auDepot->codeErreur());
            self::assertSame($aBlanc->champs(), $auDepot->champs());
        }
    }

    // ─── Outils ──────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $corps */
    private function erreurDeLaVerification(array $corps): ErreurDeDemande
    {
        try {
            $this->depot->verifierSansEcrire($corps, $this->lecteur(self::OPALE));
        } catch (ErreurDeDemande $erreur) {
            return $erreur;
        }

        self::fail('Ce corps est faux : la vérification doit le refuser.');
    }

    /**
     * Le premier op faux : une nature d'op inconnue.
     *
     * @return array<string, mixed>
     */
    private function opFaux0(): array
    {
        $op = $this->opTexte('surname', 'Sureau', 'SUREAU');
        $op['opKind'] = 'zzz';

        return $op;
    }

    /**
     * Le second, au rang 2 : une nature de sujet inconnue.
     *
     * @return array<string, mixed>
     */
    private function opFaux1(): array
    {
        $op = $this->opTexte('nickname', null, 'Mimi', seq: 2);
        $op['subjectKind'] = 'zzz';

        return $op;
    }

    /** @return array<string, mixed> */
    private function noteVide(int $seq): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'ajout_note',
            'subjectKind' => 'personne',
            'subjectId' => self::PERSONNE,
            'field' => null,
            'valueKind' => 'note',
            'oldValue' => null,
            'newValue' => ['body' => '  '],
        ];
    }

    /**
     * @param list<array<string, mixed>> $brouillons
     * @param list<array<string, mixed>> $liens
     *
     * @return array<string, mixed>
     */
    private function corpsDeCreation(array $brouillons, array $liens): array
    {
        return [
            'kind' => 'creation_personne',
            'message' => 'épouse de Jargon, j\'ai le livret de famille',
            'soumettre' => true,
            'brouillons' => $brouillons,
            'liens' => $liens,
        ];
    }

    /** @return array<string, mixed> */
    private function fiche(string $reference): array
    {
        return [
            'localRef' => $reference,
            'givenNames' => 'Tourmaline',
            'surname' => 'Muguet',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => true,
            'birth' => $this->blocDate($this->annee(1889)),
            'death' => $this->blocDate($this->annee(1972)),
            'note' => null,
        ];
    }
}
