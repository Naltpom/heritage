<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Enum\NoteScope;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UNE DEMANDE VALIDÉE POSE UNE LIGNE DANS `tree_note` — la chaîne entière.
 *
 * ## Le résultat absurde que cette nature supprime
 *
 * **On savait créer une personne avec sa note, et pas documenter une personne qui
 * existe.** `prp_draft_person.note` était l'unique chemin par lequel une `tree_note`
 * entrait dans l'arbre après l'import, et il ne sert qu'à la création. Les 337 fiches
 * déjà en base n'avaient aucun moyen de recevoir une transcription d'acte, une clôture de
 * fratrie déclarée devant notaire, ou la correction d'une cote fausse qui enverra le
 * prochain chercheur dans le mauvais fonds.
 *
 * Ce sont pourtant « les lignes les plus précieuses du fichier » (§ 3.5 du modèle), celles
 * qui portent le raisonnement derrière une correction — et 116 d'entre elles avaient déjà
 * été perdues une fois.
 *
 * ## Ce que ce fichier prouve et que `tests/Proposal/AjoutDeNoteTest` ne peut pas prouver
 *
 * Qu'une ligne apparaît réellement dans `tree_note`, avec son corps, sa portée et **son
 * auteur**. Le reste — le dépôt, les refus, la caducité — est éprouvé côté `Proposal`,
 * contre le double, et c'est le bon endroit pour cela. Voir
 * {@see BaseDeDemandeSurArbreReel} pour le mécanisme de substitution du port.
 */
final class NoteCreeeParUneDemandeTest extends BaseDeDemandeSurArbreReel
{
    private const string TEXTE = "Acte notarié du 12 frimaire an IX, étude de maître Gentiane à Saule.\n"
        ."La fratrie y est déclarée close à quatre enfants, et la cote portée jusqu'ici était fausse : "
        .'lire 61 Q, bureau de Beaumont, et non 3 Q.';

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * LE TEST QUI COMPTE.
     *
     * Un membre dépose une op `ajout_note`, un administrateur valide, et **une ligne
     * apparaît dans `tree_note`** : rattachée à la bonne fiche, avec son texte entier et
     * sa portée.
     *
     * La ligne est relue par une requête après `clear()` : une entité que l'unité de
     * travail tient encore dirait « écrite » d'une donnée qui n'a jamais quitté PHP.
     */
    public function testUneDemandeValideePoseUneLigneDansTreeNote(): void
    {
        $personneId = $this->poserUnePersonne('Marne Dolomite', 'Sureau');

        self::assertSame(0, $this->compterLesNotes(), 'La fiche part sans aucune note.');

        $demande = $this->deposer($personneId);

        // Le dépôt n'écrit pas dans l'arbre : vérifié ici contre la vraie table, et non
        // contre un compteur de double.
        self::assertSame(0, $this->compterLesNotes(), 'Un dépôt ne touche pas la table visée.');

        $resultat = $this->validation()->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertSame(RequestStatus::Validee, $demande->getStatus());

        $this->em()->clear();

        self::assertSame(1, $this->compterLesNotes(), 'La validation pose une ligne, et une seule.');

        $note = $this->laNote();

        self::assertSame($personneId, $note->getPerson()?->getId(), 'La note est rattachée à la fiche visée.');
        self::assertSame(self::TEXTE, $note->getBody(), 'Le corps entre entier : une note tronquée perd le raisonnement.');
        self::assertSame(NoteScope::Naissance, $note->getScope());

        // Ni famille ni fait : `ck_tree_note_sujet_unique` veut exactement un sujet.
        self::assertNull($note->getFamily());
        self::assertNull($note->getFact());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **L'auteur écrit en base est le demandeur, et c'est vérifiable sur la ligne.**.
     *
     * `tree_note.author_label` est rendu sur la fiche par le normaliseur de personne.
     * `null` y signifie « importée du GEDCOM » (§ 3.5) : le laisser vide ferait passer la
     * contribution d'un membre pour un artefact d'import, et y mettre l'administrateur
     * qui valide effacerait celui qui a fait le travail.
     */
    public function testLaLigneEnBasePorteLAuteurDeLaDemande(): void
    {
        $personneId = $this->poserUnePersonne('Jargon', 'Sarriette', Sex::Masculin);

        $this->validation()->valider($this->deposer($personneId), $this->admin());
        $this->em()->clear();

        $note = $this->laNote();

        self::assertSame(self::OPALE, $note->getAuthorUserId(), 'La note doit porter le demandeur.');
        self::assertSame('Compte contributeur', $note->getAuthorLabel());
        self::assertNotSame(self::ADMIN, $note->getAuthorUserId(), 'Le valideur n\'est pas l\'auteur.');
    }

    /**
     * **Les agrégats de la fiche ne bougent pas** — et c'est la différence avec la citation.
     *
     * `best_quay` et `citation_count` mesurent ce sur quoi la fiche **s'appuie**. Une note
     * documente, elle n'étaye pas : dix notes ne rendent pas une fiche mieux sourcée. Les
     * rafraîchir ici ferait passer une fiche pour appuyée sur un acte parce que quelqu'un
     * a écrit une phrase à son sujet — l'inverse exact de ce que cherche le § 9.7.
     */
    public function testLesAgregatsDeLaFicheNeBougentPas(): void
    {
        $personneId = $this->poserUnePersonne('Opale', 'Sarriette');

        $this->validation()->valider($this->deposer($personneId), $this->admin());
        $this->em()->clear();

        $personne = $this->personne($personneId);

        self::assertSame(0, $personne->getCitationCount(), 'Une note n\'est pas une source.');
        self::assertNull($personne->getBestQuay());
    }

    /**
     * La révision désigne la ligne réellement créée, et son libellé est **la première
     * ligne** de la note.
     *
     * `prp_revision.subject_label` est du `VARCHAR(255)` et se relit longtemps, alors
     * qu'une note fait parfois plusieurs paragraphes. Le corps entier, lui, est dans
     * `changes`, qui est du `JSONB` sans borne.
     */
    public function testLaRevisionDesigneLaLigneEtPorteSaPremiereLigne(): void
    {
        $personneId = $this->poserUnePersonne('Cobalt', 'Sureau', Sex::Masculin);

        $resultat = $this->validation()->valider($this->deposer($personneId), $this->admin());

        self::assertCount(1, $resultat->revisions);
        $revision = $resultat->revisions[0];

        self::assertSame(SubjectKind::Note, $revision->getSubjectKind());
        self::assertSame('Acte notarié du 12 frimaire an IX, étude de maître Gentiane à Saule.', $revision->getSubjectLabel());
        self::assertSame(['avant' => null, 'apres' => self::TEXTE], $revision->getChanges()['body']);

        $this->em()->clear();

        self::assertSame($this->laNote()->getId(), $revision->getSubjectId());
    }

    /**
     * Une première ligne très longue est tronquée et **marquée**, plutôt que coupée net.
     *
     * `subject_label` est borné à 255 caractères en base ; une transcription d'acte
     * commence parfois par une phrase de plusieurs centaines de signes. Sans la marque,
     * l'historique laisserait croire que la note s'arrête là.
     */
    public function testUnePremiereLigneTropLongueEstTronqueeEtMarquee(): void
    {
        $personneId = $this->poserUnePersonne('Lave', 'Sarriette');
        $longue = str_repeat('Le douze frimaire an IX, devant nous notaire soussigné, ', 12);

        $resultat = $this->validation()->valider($this->deposer($personneId, $longue), $this->admin());

        $libelle = $resultat->revisions[0]->getSubjectLabel();

        self::assertSame(120, mb_strlen($libelle));
        self::assertStringEndsWith('…', $libelle);
        self::assertLessThan(256, mb_strlen($libelle), 'Le libellé doit tenir dans le VARCHAR(255) de prp_revision.');

        // Le corps, lui, n'est pas tronqué : c'est `changes` qui garde ce qui a été ajouté.
        $this->em()->clear();
        self::assertSame(trim($longue), $this->laNote()->getBody());
    }

    /**
     * Une note de vingt-trois pages passe.
     *
     * C'est le cas qui a motivé cette nature : un acte notarié transcrit intégralement.
     * `tree_note.body` est un `TEXT` sans borne, et la limite du constructeur est posée
     * très au-dessus pour ne pas refuser exactement ce qu'on veut recevoir.
     */
    public function testUneTranscriptionDeVingtTroisPagesPasse(): void
    {
        $personneId = $this->poserUnePersonne('Gentiane', 'Muguet');

        // ~2 500 signes par page, vingt-trois pages.
        $acte = str_repeat('Par-devant maître Gentiane, notaire à Saule, ont comparu les soussignés. ', 800);

        $this->validation()->valider($this->deposer($personneId, $acte), $this->admin());
        $this->em()->clear();

        self::assertSame(trim($acte), $this->laNote()->getBody());
        self::assertGreaterThan(50000, mb_strlen($this->laNote()->getBody()));
    }

    // ────────────────────────────────────────────────────────────── le gréement

    private function deposer(int $personneId, string $corps = self::TEXTE): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'ajout_note',
            'message' => 'Relevé dans l\'acte notarié.',
            'soumettre' => true,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'ajout_note',
                'subjectKind' => 'personne',
                'subjectId' => $personneId,
                'valueKind' => 'note',
                'oldValue' => null,
                'newValue' => ['body' => $corps, 'scope' => 'naissance'],
            ]],
        ]);
    }

    private function compterLesNotes(): int
    {
        return (int) $this->em()
            ->createQuery('SELECT COUNT(n.id) FROM '.Note::class.' n')
            ->getSingleScalarResult();
    }

    private function laNote(): Note
    {
        $note = $this->em()->getRepository(Note::class)->findOneBy([]);
        self::assertInstanceOf(Note::class, $note);

        return $note;
    }
}
