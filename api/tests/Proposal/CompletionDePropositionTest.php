<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\ErreurDeDemande;
use App\Module\Proposal\Exception\Introuvable;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * COMPLÉTER SUR PLACE UNE FICHE PROPOSÉE — § 6.6 ter, ADR 0017.
 *
 * Le besoin, dans les mots du commanditaire (09/10/2026) : l'auteur d'une proposition, et
 * l'administrateur, la **complètent sur place** — une date de décès retrouvée, un lien de
 * plus — au lieu de la retirer pour tout ressaisir, ou d'en déposer une seconde.
 *
 * Ce fichier tient les règles du service, sur une vraie base ; les codes HTTP et le voter
 * sont dans {@see CompletionEtFusionEnHttpTest}.
 */
final class CompletionDePropositionTest extends BaseDeCompletion
{
    // ---------------------------------------------------------------------------------
    // La fiche garde son identifiant
    // ---------------------------------------------------------------------------------

    /**
     * **La fiche est réécrite en place, jamais recréée.** Une autre demande la cite par
     * l'identifiant de sa ligne, sans clé étrangère : recréée, elle laisserait ce lien
     * pendant, et sa validation tomberait en `dependance_morte` sur une personne qui existe.
     */
    public function testLaFicheCompleteeGardeSonIdentifiantEtUneDemandeQuiLaCiteSeValideToujours(): void
    {
        $barnabe = $this->proposer(self::OPALE, ['deceased' => true]);
        $idDeLaFiche = $this->ficheDe($barnabe)->getId();
        self::assertNotNull($idDeLaFiche);

        $celeste = $this->proposer(self::OPALE, ['givenNames' => 'Céleste'], [[
            'seq' => 1,
            'kind' => 'enfant_de',
            'subjectDraftRef' => '#p1',
            'otherDraftPersonId' => $idDeLaFiche,
        ]]);

        $this->completion()->completer(
            $barnabe,
            'p1',
            $this->corps(['deceased' => true, 'death' => $this->blocDate($this->annee(1968))]),
            $this->lecteur(self::OPALE),
        );

        $relue = $this->recharger($barnabe);
        $fiches = $this->fiches()->pourDemande($relue);
        self::assertCount(1, $fiches, 'Une fiche, toujours la même : rien n\'a été recréé.');
        self::assertSame($idDeLaFiche, $fiches[0]->getId());
        self::assertSame('1968', $fiches[0]->getDeath()->format());

        // La demande de Céleste désigne toujours une fiche vivante : validée après celle de
        // Barnabé, elle rattache Céleste à la personne que la première a créée.
        self::assertSame('validee', $this->validation->valider($this->recharger($barnabe), $this->admin())->resultat);
        self::assertSame('validee', $this->validation->valider($this->recharger($celeste), $this->admin())->resultat);
        self::assertCount(2, $this->arbre->personnesCreees());
        self::assertSame(
            '1968',
            $this->arbre->valeur(SubjectKind::Personne, $this->arbre->personnesCreees()[0], 'death')?->format(),
            'La validation crée la personne telle que complétée.',
        );
    }

    /**
     * `fiche` **remplace tous les champs** : une clé absente vaut `null`, comme au dépôt. Le
     * surnom saisi au dépôt disparaît si on ne le renvoie pas — c'est la règle, pas un
     * accident.
     */
    public function testLaFicheEstRemplaceeEntiereEtUneCleAbsenteVautNull(): void
    {
        $demande = $this->proposer(self::OPALE, ['nickname' => 'Bébert']);

        $this->completion()->completer(
            $demande,
            'p1',
            ['fiche' => ['givenNames' => 'Barnabé', 'surname' => 'Muguet', 'sex' => 'masculin']],
            $this->lecteur(self::OPALE),
        );

        $fiche = $this->ficheDe($this->recharger($demande));
        self::assertNull($fiche->getNickname());
        self::assertSame('masculin', $fiche->getSex()->value);
    }

    // ---------------------------------------------------------------------------------
    // Qui complète, et qui en est averti
    // ---------------------------------------------------------------------------------

    public function testLAdministrateurCompleteLaDemandeDUnMembreEtLAuteurEstPrevenu(): void
    {
        $demande = $this->proposer(self::OPALE);

        $this->completion()->completer(
            $demande,
            'p1',
            $this->corps(['birth' => $this->blocDate($this->annee(1901))], [$this->lienParent(null)]),
            $this->admin(),
        );

        $evenements = $this->notificateur->de('demande_completee');
        self::assertCount(1, $evenements);
        self::assertSame($demande->getId(), $evenements[0]['demande']);
        self::assertTrue($evenements[0]['rejouee'], 'Par l\'administration : c\'est l\'auteur qui est prévenu.');
        self::assertSame(
            [['libelles' => ['Naissance'], 'liensAjoutes' => 1]],
            $evenements[0]['champs'],
            'Les noms des champs touchés, jamais leurs valeurs.',
        );
    }

    public function testLAuteurQuiCompleteSaDemandeEnAttentePrevientLesAdministrateurs(): void
    {
        $demande = $this->proposer(self::OPALE);

        $this->completion()->completer($demande, 'p1', $this->corps(['nickname' => 'Bébert']), $this->lecteur(self::OPALE));

        $evenements = $this->notificateur->de('demande_completee');
        self::assertCount(1, $evenements);
        self::assertFalse($evenements[0]['rejouee'], 'Par l\'auteur : ce sont les administrateurs qui sont prévenus.');
        self::assertSame([['libelles' => ['Surnom'], 'liensAjoutes' => 0]], $evenements[0]['champs']);
    }

    /** Un brouillon n'est vu de personne d'autre : le compléter ne prévient personne. */
    public function testCompleterSonBrouillonNePrevientPersonneEtNePoseAucuneCle(): void
    {
        $demande = $this->proposer(self::OPALE, soumettre: false);

        $this->completion()->completer($demande, 'p1', $this->corps(['nickname' => 'Bébert']), $this->lecteur(self::OPALE));

        $relue = $this->recharger($demande);
        self::assertSame(RequestStatus::Brouillon, $relue->getStatus());
        self::assertSame('Bébert', $this->ficheDe($relue)->getNickname());
        self::assertNull($relue->getDedupKey(), 'La clé de doublon se pose à la soumission, pas avant.');
        self::assertSame([], $this->notificateur->de('demande_completee'));
    }

    /** L'administrateur qui complète **sa** demande vient de cliquer : rien à lui annoncer. */
    public function testLAdministrateurQuiCompleteSaPropreDemandeNePrevientPersonne(): void
    {
        $demande = $this->depot->deposer([
            'kind' => 'creation_personne',
            'soumettre' => true,
            'brouillons' => [$this->fiche()],
            'liens' => [$this->lienConjoint()],
        ], $this->admin());

        $this->completion()->completer($demande, 'p1', $this->corps(['nickname' => 'Bébert']), $this->admin());

        self::assertSame([], $this->notificateur->de('demande_completee'));
    }

    /**
     * Un brouillon est le travail en cours de son auteur : l'administrateur ne le complète
     * pas, comme il n'y joint pas de pièce.
     */
    public function testLAdministrateurNeCompletePasLeBrouillonDUnMembre(): void
    {
        $demande = $this->proposer(self::OPALE, soumettre: false);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p1', $this->corps(['nickname' => 'Bébert']), $this->admin()));

        self::assertInstanceOf(AccesRefuse::class, $erreur);
        self::assertSame(403, $erreur->statutHttp());
        self::assertSame('acces_refuse', $erreur->codeErreur());
        self::assertNull($this->ficheDe($this->recharger($demande))->getNickname());
    }

    /** Un autre membre dépose un complément ; il ne complète pas sur place. */
    public function testUnAutreMembreNeCompletePasLaPropositionDUnAutre(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p1', $this->corps(['nickname' => 'Bébert']), $this->lecteur(self::TUF)));

        self::assertInstanceOf(AccesRefuse::class, $erreur);
        self::assertSame('acces_refuse', $erreur->codeErreur());
    }

    public function testUnCompteEnAttenteNeCompleteRien(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p1', $this->corps(), $this->lecteur(self::OPALE, enAttente: true)));

        self::assertSame('regime_en_attente', $erreur->codeErreur());
        self::assertSame(403, $erreur->statutHttp());
    }

    public function testUneDemandeTrancheeNeSeCompletePlus(): void
    {
        $demande = $this->proposer(self::OPALE);
        $this->refus->refuser($demande, $this->admin(), 'Déjà dans l\'arbre.', null);

        $erreur = $this->echec(fn () => $this->completion()->completer($this->recharger($demande), 'p1', $this->corps(['nickname' => 'Bébert']), $this->lecteur(self::OPALE)));

        self::assertInstanceOf(Conflit::class, $erreur);
        self::assertSame('demande_deja_decidee', $erreur->codeErreur());
        self::assertSame(['statut' => 'refusee'], $erreur->details());
    }

    public function testUneFicheInconnueDeLaDemandeRendIntrouvable(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p2', $this->corps(), $this->lecteur(self::OPALE)));

        self::assertInstanceOf(Introuvable::class, $erreur);
        self::assertSame(404, $erreur->statutHttp());
    }

    // ---------------------------------------------------------------------------------
    // L'empreinte
    // ---------------------------------------------------------------------------------

    /**
     * Compléter sur une demande **relue**, jamais sur celle d'il y a dix minutes : le `PUT`
     * remplace tous les champs, et effacerait sans le voir ce que l'autre vient d'écrire.
     */
    public function testUneEmpreintePerimeeEstRefuseeSansRienEcrire(): void
    {
        $demande = $this->proposer(self::OPALE);
        $luParLAdministrateur = $this->empreinte($demande);

        // L'auteur complète entre-temps — avec la bonne empreinte, lui.
        $this->completion()->completer($demande, 'p1', $this->corps(['nickname' => 'Bébert'], [], $luParLAdministrateur), $this->lecteur(self::OPALE));

        $relue = $this->recharger($demande);
        $courante = $this->empreinte($relue);
        self::assertNotSame($luParLAdministrateur, $courante, 'Une fiche complétée change d\'empreinte.');

        $erreur = $this->echec(fn () => $this->completion()->completer($relue, 'p1', $this->corps(['marriedName' => 'Dupont'], [], $luParLAdministrateur), $this->admin()));

        self::assertInstanceOf(Conflit::class, $erreur);
        self::assertSame('demande_modifiee_entre_temps', $erreur->codeErreur());
        self::assertSame(['demandeId' => $demande->getId(), 'empreinte' => $courante, 'rejouable' => true], $erreur->details());

        $fiche = $this->ficheDe($this->recharger($demande));
        self::assertSame('Bébert', $fiche->getNickname(), 'Ce que l\'auteur avait écrit est intact.');
        self::assertNull($fiche->getMarriedName());
    }

    public function testUneEmpreinteQuiNEstPasUneChaineEstRefusee(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p1', ['fiche' => $this->fiche(), 'empreinte' => 12], $this->lecteur(self::OPALE)));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertArrayHasKey('empreinte', (array) $erreur->champs());
    }

    // ---------------------------------------------------------------------------------
    // Les refus du corps, et ce qu'ils laissent derrière eux : rien
    // ---------------------------------------------------------------------------------

    /**
     * Une date de décès sans « personne décédée » : refusée comme au dépôt — `deceased`
     * ouvre la visibilité, on ne le coche pas à la place de l'auteur.
     *
     * Et **rien n'est écrit**, pas même en mémoire : un `flush()` de la même requête après
     * la `422` — un journal, une notification — ne doit pas écrire une fiche à moitié
     * réécrite, ni le lien pourtant valide qui accompagnait le corps refusé.
     */
    public function testUnDecesSansDecedeEstRefuseEtNeLaisseRienAEcrire(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer(
            $demande,
            'p1',
            $this->corps(['nickname' => 'Bébert', 'death' => $this->blocDate($this->annee(1968))], [$this->lienParent(null)]),
            $this->lecteur(self::OPALE),
        ));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertArrayHasKey('fiche.death', (array) $erreur->champs());

        $this->em->flush();

        $relue = $this->recharger($demande);
        self::assertNull($this->ficheDe($relue)->getNickname());
        self::assertCount(1, $this->liensEnBase()->pourDemande($relue));
    }

    public function testUneFicheSansNomEstRefusee(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p1', $this->corps(['givenNames' => ' ', 'surname' => '']), $this->lecteur(self::OPALE)));

        self::assertArrayHasKey('fiche.surname', (array) $erreur->champs());
    }

    public function testUnCorpsSansFicheEstRefuse(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p1', ['liens' => []], $this->lecteur(self::OPALE)));

        self::assertArrayHasKey('fiche', (array) $erreur->champs());
    }

    public function testUneReferenceDuCorpsQuiDesigneUneAutreFicheEstRefusee(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p1', ['fiche' => $this->fiche(['localRef' => '#p2'])], $this->lecteur(self::OPALE)));

        self::assertArrayHasKey('fiche.localRef', (array) $erreur->champs());
    }

    // ---------------------------------------------------------------------------------
    // Les liens ajoutés
    // ---------------------------------------------------------------------------------

    /**
     * Le rang d'un lien ajouté est **attribué par le serveur**, à la suite : le client ne
     * sait pas lesquels sont pris, et `uniq_prp_draft_link_seq` refuserait un doublon.
     */
    public function testLesLiensAjoutesPrennentLesRangsSuivants(): void
    {
        $this->arbre->poser(SubjectKind::Personne, 62, 'Opale Sarriette', 1, $this->champsDUnePersonne('Opale', 'Sarriette'));
        $demande = $this->proposer(self::OPALE);

        $this->completion()->completer($demande, 'p1', $this->corps([], [$this->lienParent(null), $this->lienParent(null, 62)]), $this->lecteur(self::OPALE));

        $liens = $this->liensEnBase()->pourDemande($this->recharger($demande));
        self::assertSame([1, 2, 3], array_map(static fn (DraftLink $l): int => $l->getSeq(), $liens));
        self::assertSame([self::JARGON, self::ENFANT, 62], array_map(static fn (DraftLink $l): ?int => $l->getOtherPersonId(), $liens));
        self::assertSame('Barnabé Muguet', $liens[1]->getSubjectLabel());
    }

    public function testUnRangEnvoyeEstRefuse(): void
    {
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer($demande, 'p1', $this->corps([], [$this->lienParent(7)]), $this->lecteur(self::OPALE)));

        self::assertArrayHasKey('liens[0].seq', (array) $erreur->champs());
        self::assertCount(1, $this->liensEnBase()->pourDemande($this->recharger($demande)));
    }

    /**
     * **La fiche citée doit être visible de l'auteur de la demande**, pas du lecteur.
     * L'administrateur voit toutes les propositions ; il ne glisse pas pour autant dans la
     * demande d'Opale une fiche de Tuf, qu'Opale ne voit pas et dont la validation
     * dépendrait.
     */
    public function testUneFicheCiteeDoitEtreVisibleDeLAuteurDeLaDemandeEtNonDuLecteur(): void
    {
        $chezTuf = $this->ficheDe($this->proposer(self::TUF, ['givenNames' => 'Rose']))->getId();
        $chezOpale = $this->ficheDe($this->proposer(self::OPALE, ['givenNames' => 'Céleste']))->getId();
        $demande = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completion()->completer(
            $demande,
            'p1',
            $this->corps([], [['kind' => 'parent_de', 'subjectDraftRef' => '#p1', 'otherDraftPersonId' => $chezTuf]]),
            $this->admin(),
        ));

        self::assertInstanceOf(Introuvable::class, $erreur, '404 : la fiche de Tuf n\'existe pas pour Opale.');

        // Une fiche d'Opale, elle, se cite — par l'administrateur comme par Opale.
        $this->completion()->completer(
            $this->recharger($demande),
            'p1',
            $this->corps([], [['kind' => 'parent_de', 'subjectDraftRef' => '#p1', 'otherDraftPersonId' => $chezOpale]]),
            $this->admin(),
        );

        $liens = $this->liensEnBase()->pourDemande($this->recharger($demande));
        self::assertSame($chezOpale, $liens[1]->getOtherDraftPersonId());
    }

    /**
     * Le filet de `ConstructeurDeCreation::ficheProposeeAilleurs()`, longtemps
     * inatteignable : dans sa propre demande, une fiche se désigne par `#p1`, jamais par
     * sa ligne.
     */
    public function testUneFicheDeLaMemeDemandeNeSeCitePasParSaLigne(): void
    {
        $demande = $this->proposer(self::OPALE);
        $sienne = $this->ficheDe($demande)->getId();

        $erreur = $this->echec(fn () => $this->completion()->completer(
            $demande,
            'p1',
            $this->corps([], [['kind' => 'conjoint_de', 'subjectDraftRef' => '#p1', 'otherDraftPersonId' => $sienne]]),
            $this->lecteur(self::OPALE),
        ));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertArrayHasKey('liens[0].otherDraftPersonId', (array) $erreur->champs());
    }

    // ---------------------------------------------------------------------------------
    // Ce que la complétion entraîne
    // ---------------------------------------------------------------------------------

    public function testLaCleDeDoublonEstRecalculee(): void
    {
        $demande = $this->proposer(self::OPALE);
        self::assertSame('muguet|barnabe|?', $demande->getDedupKey());

        $this->completion()->completer($demande, 'p1', $this->corps(['surname' => 'Génévrolle', 'birth' => $this->blocDate($this->annee(1904))]), $this->lecteur(self::OPALE));

        self::assertSame('genevrolle|barnabe|1904', $this->recharger($demande)->getDedupKey());
    }

    /**
     * **`updated_at` doit bouger** : l'`ETag` du graphe en dépend, et un `304` laisserait
     * dans l'arbre du lecteur le nœud au nom d'avant.
     */
    public function testLaCompletionToucheLaDemandeEtChangeLEmpreinteDuCalque(): void
    {
        $demande = $this->vieillir($this->proposer(self::OPALE));
        $avant = $demande->getUpdatedAt()->getTimestamp();
        $calqueAvant = $this->propositions->empreinteDesDemandes($this->lecteur(self::OPALE));

        $this->completion()->completer($demande, 'p1', $this->corps(['givenNames' => 'Barnabé Jules']), $this->lecteur(self::OPALE));

        $relue = $this->recharger($demande);
        self::assertGreaterThan($avant, $relue->getUpdatedAt()->getTimestamp());
        self::assertNotSame($calqueAvant, $this->propositions->empreinteDesDemandes($this->lecteur(self::OPALE)));
    }

    /** Un « Enregistrer » sans rien changer ne touche rien et ne prévient personne. */
    public function testUneCompletionSansChangementNeToucheRien(): void
    {
        $demande = $this->vieillir($this->proposer(self::OPALE));
        $avant = $demande->getUpdatedAt()->getTimestamp();
        $empreinte = $this->empreinte($demande);

        $this->completion()->completer($demande, 'p1', $this->corps(), $this->lecteur(self::OPALE));

        $relue = $this->recharger($demande);
        self::assertSame($avant, $relue->getUpdatedAt()->getTimestamp());
        self::assertSame($empreinte, $this->empreinte($relue));
        self::assertSame([], $this->notificateur->de('demande_completee'));
    }

    /**
     * Les libellés stockés des liens qui citent la fiche reprennent son nouveau nom — dans
     * sa demande toujours, dans une autre demande du **même auteur** aussi (qui est alors
     * touchée), mais **pas** dans la demande d'un autre auteur quand la personne est
     * vivante : on ne recopie pas chez l'un ce que l'autre a saisi.
     */
    public function testLesLibellesDesLiensQuiCitentLaFicheSontRafraichisSelonLAuteur(): void
    {
        $famille = $this->depot->deposer([
            'kind' => 'creation_personne',
            'soumettre' => true,
            'brouillons' => [$this->fiche(), $this->fiche(['localRef' => '#p2', 'givenNames' => 'Céleste'])],
            'liens' => [
                $this->lienConjoint(),
                ['seq' => 2, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p2', 'otherDraftRef' => '#p1'],
            ],
        ], $this->lecteur(self::OPALE));
        $barnabe = $this->ficheDe($famille)->getId();

        $citation = static fn (string $prenom): array => [
            'kind' => 'creation_personne',
            'soumettre' => true,
            'brouillons' => [['localRef' => '#p1', 'givenNames' => $prenom, 'surname' => 'Muguet']],
            'liens' => [['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherDraftPersonId' => $barnabe]],
        ];

        $memeAuteur = $this->vieillir($this->depot->deposer($citation('Rose'), $this->lecteur(self::OPALE)));
        $autreAuteur = $this->vieillir($this->depot->deposer($citation('Lys'), $this->admin()));
        $avantAutre = $autreAuteur->getUpdatedAt()->getTimestamp();
        $avantMeme = $memeAuteur->getUpdatedAt()->getTimestamp();

        $this->completion()->completer($this->recharger($famille), 'p1', $this->corps(['givenNames' => 'Barnabé Jules']), $this->lecteur(self::OPALE));

        $this->em->clear();
        $dansSaDemande = $this->liensEnBase()->pourDemande($this->recharger($famille))[1];
        self::assertSame('Barnabé Jules Muguet', $dansSaDemande->getOtherLabel());

        $chezLeMemeAuteur = $this->recharger($memeAuteur);
        self::assertSame('Barnabé Jules Muguet', $this->liensEnBase()->pourDemande($chezLeMemeAuteur)[0]->getOtherLabel());
        self::assertGreaterThan($avantMeme, $chezLeMemeAuteur->getUpdatedAt()->getTimestamp(), 'Son arête a changé : elle est touchée.');

        $chezUnAutre = $this->recharger($autreAuteur);
        self::assertSame('Barnabé Muguet', $this->liensEnBase()->pourDemande($chezUnAutre)[0]->getOtherLabel());
        self::assertSame($avantAutre, $chezUnAutre->getUpdatedAt()->getTimestamp());
    }

    /** Un défunt est un fait historique : son nom se recopie aussi chez un autre auteur. */
    public function testLeNomDUnDefuntSeRecopieAussiChezUnAutreAuteur(): void
    {
        $origine = $this->proposer(self::OPALE, ['deceased' => true]);
        $barnabe = $this->ficheDe($origine)->getId();

        $chezLAdministrateur = $this->depot->deposer([
            'kind' => 'creation_personne',
            'soumettre' => true,
            'brouillons' => [['localRef' => '#p1', 'givenNames' => 'Lys', 'surname' => 'Muguet']],
            'liens' => [['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherDraftPersonId' => $barnabe]],
        ], $this->admin());

        $this->completion()->completer($origine, 'p1', $this->corps(['givenNames' => 'Barnabé Jules', 'deceased' => true]), $this->lecteur(self::OPALE));

        self::assertSame('Barnabé Jules Muguet', $this->liensEnBase()->pourDemande($this->recharger($chezLAdministrateur))[0]->getOtherLabel());
    }

    // ---------------------------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------------------------

    /**
     * Le corps d'une complétion : la fiche par défaut, modifiée.
     *
     * @param array<string, mixed>       $fiche
     * @param list<array<string, mixed>> $liens
     *
     * @return array<string, mixed>
     */
    private function corps(array $fiche = [], array $liens = [], ?string $empreinte = null): array
    {
        return ['fiche' => $this->fiche($fiche), 'liens' => $liens, 'empreinte' => $empreinte];
    }

    /** L'erreur métier que lève le geste — le cas échoue s'il n'en lève aucune. */
    private function echec(\Closure $geste): ErreurDeDemande
    {
        try {
            $geste();
        } catch (ErreurDeDemande $erreur) {
            return $erreur;
        }

        self::fail('Le geste devait être refusé.');
    }
}
