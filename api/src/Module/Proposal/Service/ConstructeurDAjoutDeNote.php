<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `ajout_note` — le dépôt de la demande.
 *
 * ## Le résultat absurde que cette nature supprime
 *
 * **On pouvait créer une personne avec sa note, et pas ajouter une note à une personne
 * qui existe.** `prp_draft_person` porte une colonne `note`, recopiée en `tree_note` à la
 * validation (`EcritureDeLArbrePourProposal::creerPersonne()`) ; c'était le **seul**
 * chemin par lequel une note entrait dans l'arbre après l'import.
 * `OpKind::AjoutNote` existait depuis le premier jour sans applicateur, et une op de
 * cette nature levait le même `LogicException` que `ajout_citation`.
 *
 * Toute la matière d'archives portant sur les fiches déjà en base n'avait donc aucun
 * moyen d'entrer : une transcription d'acte, une clôture de fratrie déclarée devant
 * notaire, la correction d'une cote fausse qui envoie le chercheur suivant dans le mauvais
 * fonds. Ce sont pourtant « les lignes les plus précieuses du fichier » (§ 3.5 du modèle),
 * et 116 d'entre elles avaient déjà été perdues une fois.
 *
 * ## Deux différences avec `ajout_citation`, et une seule est un choix
 *
 * **1. Le corps est obligatoire, et c'est le seul contrôle de fond.** Une citation
 * accepte trois champs facultatifs dont au moins un doit être rempli ; une note n'a qu'un
 * texte, et une note vide n'est pas une note. On refuse aussi une note réduite à des
 * espaces, qui n'en serait pas une non plus.
 *
 * **2. Il n'y a pas de doublon à détecter.** Deux citations de la même page de la même
 * source dans une seule demande sont une double soumission du formulaire ; deux notes sur
 * la même fiche sont deux remarques, et rien ne dit qu'elles se ressemblent. Le seul
 * doublon refusé est le **texte identique** dans la même demande — là, le formulaire a
 * bien été validé deux fois.
 *
 * ## Ce qu'il partage avec `ajout_citation`, et qu'on ne réinvente pas
 *
 * Même corps `ops[]`, même `field` à `null` (`ck_prp_op_champ`), même `baseVersion` à
 * `null` — le sujet de l'op est la personne, mais l'écriture est **à côté** d'elle, et
 * documenter une fiche ne peut pas entrer en conflit avec sa correction. Même cible
 * dénormalisée sur la personne, même refus explicite des références de brouillon, même
 * vocabulaire de portées lu au port plutôt que recopié ici.
 */
final readonly class ConstructeurDAjoutDeNote implements ConstructeurDeDemande
{
    /**
     * `tree_note.body` est un `TEXT` sans borne en base. La borne est ici, et elle est
     * généreuse : une transcription d'acte notarié de vingt-trois pages doit passer. Elle
     * existe pour écarter un corps de requête qui ne serait pas une note du tout.
     */
    private const int LONGUEUR_MAXIMALE = 200000;

    public function __construct(
        private EntityManagerInterface $em,
        private PortArbre $arbre,
    ) {
    }

    public function natures(): array
    {
        return [RequestKind::AjoutNote];
    }

    public function construire(ChangeRequest $demande, array $corps, Lecteur $lecteur): void
    {
        $brut = $corps['ops'] ?? null;

        if (!\is_array($brut) || [] === $brut) {
            throw DonneesInvalides::pourChamp('ops', 'Une demande d\'ajout de note porte au moins une note.');
        }

        $construits = [];
        $seqVus = [];
        $corpsVus = [];

        foreach (array_values($brut) as $index => $donnees) {
            if (!\is_array($donnees)) {
                throw DonneesInvalides::pourChamp("ops[$index]", 'Chaque op est un objet.');
            }

            $chemin = "ops[$index]";
            [$op, $empreinte] = $this->construireOp($demande, $donnees, $index, $chemin);

            if (\in_array($op->getSeq(), $seqVus, true)) {
                throw DonneesInvalides::pourChamp("$chemin.seq", 'Deux ops ne peuvent pas porter le même rang.');
            }

            // Deux notes au texte identique sur la même fiche, dans une seule demande :
            // le formulaire a été validé deux fois. Deux notes **différentes** restent
            // légitimes — ce sont deux remarques.
            if (\in_array($empreinte, $corpsVus, true)) {
                throw DonneesInvalides::pourChamp("$chemin.newValue.body", 'Cette demande porte déjà cette note, mot pour mot, sur cette fiche.');
            }

            $seqVus[] = $op->getSeq();
            $corpsVus[] = $empreinte;
            $construits[] = $op;
            $this->em->persist($op);
        }

        usort($construits, static fn (ChangeRequestOp $a, ChangeRequestOp $b): int => $a->getSeq() <=> $b->getSeq());
        $sujet = $construits[0]->getSubjectId();
        \assert(null !== $sujet);

        $this->verifierCoherenceDeLaCible($corps, $sujet);

        $cible = $this->arbre->chargerCible(SubjectKind::Personne, $sujet);

        $demande->setTarget(SubjectKind::Personne, $sujet, $cible?->libelle);
    }

    /**
     * Une demande de note sans op ne documente rien.
     *
     * Contrôlé à la soumission, comme partout ailleurs dans ce module : un brouillon
     * incomplet doit pouvoir s'enregistrer (§ 6.3).
     */
    public function verifierAvantSoumission(ChangeRequest $demande): void
    {
        if ([] === $this->opsDe($demande)) {
            throw DonneesInvalides::pourChamp('ops', 'Une demande d\'ajout de note porte au moins une note.');
        }
    }

    /**
     * @param array<string, mixed> $donnees
     *
     * @return array{ChangeRequestOp, string} l'op, et l'empreinte qui détecte un doublon
     */
    private function construireOp(ChangeRequest $demande, array $donnees, int $index, string $chemin): array
    {
        $this->verifierLaForme($donnees, $chemin);

        $seq = $donnees['seq'] ?? $index + 1;

        if (!\is_int($seq) || $seq < 1) {
            throw DonneesInvalides::pourChamp("$chemin.seq", 'Le rang d\'un op est un entier positif.');
        }

        $personneId = $this->personneVisee($donnees, $chemin);
        $valeur = $this->valeur($this->objetDeValeur($donnees, $chemin), $chemin);

        $op = new ChangeRequestOp(
            $demande,
            $seq,
            OpKind::AjoutNote,
            SubjectKind::Personne,
            ValueKind::Note,
        );

        $op->viserLigneExistante($personneId, null);
        $op->setValues(null, ['body' => $valeur['body'], 'scope' => $valeur['scope']]);

        return [$op, \sprintf('%d|%s', $personneId, $valeur['body'])];
    }

    /**
     * Les contraintes de base, appliquées **avant** la base : « le `CHECK` est le filet,
     * pas le contrôle » (§ 14.3).
     *
     * @param array<string, mixed> $donnees
     */
    private function verifierLaForme(array $donnees, string $chemin): void
    {
        $nature = $donnees['opKind'] ?? OpKind::AjoutNote->value;

        if (OpKind::AjoutNote->value !== $nature) {
            throw DonneesInvalides::pourChamp("$chemin.opKind", 'Une demande d\'ajout de note ne porte que des ops « ajout_note ».');
        }

        if (null !== ($donnees['field'] ?? null)) {
            // `ck_prp_op_champ` : `field` est `null` pour tout `opKind` autre que `champ`.
            throw DonneesInvalides::pourChamp("$chemin.field", 'Un op de note ne vise aucun champ : il pose une ligne entière.');
        }

        $valueKind = $donnees['valueKind'] ?? ValueKind::Note->value;

        if (ValueKind::Note->value !== $valueKind) {
            throw DonneesInvalides::pourChamp("$chemin.valueKind", 'La valeur d\'un op de note est de type « note ».');
        }

        $sujet = $donnees['subjectKind'] ?? SubjectKind::Personne->value;

        if (SubjectKind::Personne->value !== $sujet) {
            // Le modèle autorise une note sur une famille ou sur un fait
            // (`ck_tree_note_sujet_unique`) ; ce circuit-ci ne sait poser que celles qui
            // documentent une personne.
            throw DonneesInvalides::pourChamp("$chemin.subjectKind", 'Une note se rattache à une personne : les notes d\'union et de fait ne passent pas encore par une demande.');
        }
    }

    /**
     * La personne que la note documente — une ligne qui existe **déjà**.
     *
     * @param array<string, mixed> $donnees
     */
    private function personneVisee(array $donnees, string $chemin): int
    {
        if (null !== ($donnees['subjectDraftRef'] ?? null)) {
            // Même refus, et même cause, que pour une citation : les ops s'appliquent
            // avant que les brouillons n'aient d'identifiant. Mais ici le message peut
            // dire mieux — une création **sait déjà** porter sa note, dans `brouillons[]`.
            throw DonneesInvalides::pourChamp("$chemin.subjectDraftRef", 'Une note ne se rattache pas à une personne créée par la même demande : la fiche proposée porte déjà son propre champ « note ».');
        }

        $id = $donnees['subjectId'] ?? null;

        if (!\is_int($id) || $id < 1) {
            throw DonneesInvalides::pourChamp("$chemin.subjectId", 'Une note se rattache à une fiche existante, désignée par son identifiant.');
        }

        if (null === $this->arbre->chargerCible(SubjectKind::Personne, $id)) {
            throw Introuvable::cible(SubjectKind::Personne->value, $id);
        }

        return $id;
    }

    /**
     * La `newValue`, dont on vérifie la forme et l'absence d'un « avant ».
     *
     * @param array<string, mixed> $donnees
     *
     * @return array<string, mixed>
     */
    private function objetDeValeur(array $donnees, string $chemin): array
    {
        $brut = $donnees['newValue'] ?? null;

        if (!\is_array($brut)) {
            throw DonneesInvalides::pourChamp("$chemin.newValue", 'La valeur d\'un op de note est un objet.');
        }

        if (\array_key_exists('oldValue', $donnees) && null !== $donnees['oldValue']) {
            throw DonneesInvalides::pourChamp("$chemin.oldValue", 'Une note créée n\'a pas de valeur précédente : « oldValue » vaut null.');
        }

        return $brut;
    }

    /**
     * Le corps et la portée, contrôlés et normalisés.
     *
     * @param array<string, mixed> $brut la `newValue`, déjà reconnue comme un objet
     *
     * @return array{body: string, scope: string}
     */
    private function valeur(array $brut, string $chemin): array
    {
        $corps = $brut['body'] ?? null;

        if (!\is_string($corps)) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.body", 'Le texte de la note est obligatoire.');
        }

        // Les fins de ligne sont normalisées à l'entrée : le même texte saisi au
        // navigateur (« \r\n ») et recopié depuis un fichier (« \n ») doit produire la
        // même note, sinon la détection de doublon ci-dessus ne verrait pas deux envois
        // identiques, et le libellé de révision dépendrait du système de l'auteur.
        $corps = trim(str_replace(["\r\n", "\r"], "\n", $corps));

        if ('' === $corps) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.body", 'Une note vide n\'est pas une note : elle n\'apprend rien à la fiche.');
        }

        if (mb_strlen($corps) > self::LONGUEUR_MAXIMALE) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.body", \sprintf('Cette note dépasse %d caractères : ce n\'est plus une note de recherche, c\'est une pièce à joindre en média.', self::LONGUEUR_MAXIMALE));
        }

        $portee = $brut['scope'] ?? 'generale';
        $portees = $this->arbre->vocabulaireDAnnotation()->portees;

        if (!\is_string($portee) || !\in_array($portee, $portees, true)) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.scope", \sprintf('Ce que la note documente se dit par l\'une de ces portées : %s.', implode(', ', $portees)));
        }

        return ['body' => $corps, 'scope' => $portee];
    }

    /**
     * @param array<string, mixed> $corps
     */
    private function verifierCoherenceDeLaCible(array $corps, int $personneId): void
    {
        $annonceKind = $corps['targetKind'] ?? null;
        $annonceId = $corps['targetId'] ?? null;

        if (null !== $annonceKind && SubjectKind::Personne->value !== $annonceKind) {
            throw DonneesInvalides::pourChamp('targetKind', 'La cible annoncée ne correspond pas à celle des ops.');
        }

        if (null !== $annonceId && $annonceId !== $personneId) {
            throw DonneesInvalides::pourChamp('targetId', 'La cible annoncée ne correspond pas à celle des ops.');
        }
    }

    /**
     * Les ops de la demande, **qu'elle soit déjà en base ou non** — même motif que chez
     * ses deux voisins : `soumettre: true` construit et soumet avant le premier `flush`.
     *
     * @return list<ChangeRequestOp>
     */
    private function opsDe(ChangeRequest $demande): array
    {
        $ops = [];

        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof ChangeRequestOp && $entite->getRequest() === $demande) {
                $ops[] = $entite;
            }
        }

        if ([] === $ops && null !== $demande->getId()) {
            return $this->em->getRepository(ChangeRequestOp::class)->findBy(['request' => $demande], ['seq' => 'ASC']);
        }

        return $ops;
    }
}
