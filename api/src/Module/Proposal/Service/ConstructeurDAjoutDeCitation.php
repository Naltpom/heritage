<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\SourceCitable;
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
 * `ajout_citation` — le dépôt de la demande.
 *
 * ## Ce qui manquait, et ce que son absence coûtait
 *
 * `RequestKind::ModificationCitation` savait **corriger** une citation : page, `QUAY`,
 * transcription. Rien ne savait en **créer** une. Le mot « citation » n'apparaissait dans
 * aucun contrôleur ni corps de dépôt du module, `OpKind::AjoutCitation` existait depuis le
 * premier jour sans applicateur, et `ValidationDeDemande::applicateurPour()` levait un
 * `LogicException` si jamais une op de cette nature atteignait la validation.
 *
 * La conséquence n'était pas théorique. La règle que le projet impose à tout contributeur —
 * *chaque fiche porte sa source* — était **inapplicable par le circuit** : un membre qui
 * relevait une cote d'archive n'avait, pour la consigner, que la note en prose d'une
 * demande de création. L'onglet « sources » de la fiche restait vide et `citation_count` à
 * zéro, pendant que la cote dormait dans un paragraphe que rien n'indexe.
 *
 * ## Une op par citation, sur une personne qui existe déjà
 *
 * Le corps est celui du § 6.2 — `ops[]` — avec deux différences que la nature impose :
 *
 * 1. **`field` est `null`.** `ck_prp_op_champ` en fait une règle de base :
 *    `(op_kind = 'champ') = (field IS NOT NULL)`. Une op de citation ne vise pas un champ,
 *    elle pose une ligne.
 * 2. **`baseVersion` est `null`, et c'est délibéré.** Le sujet de l'op est la *personne*,
 *    mais la citation ne touche aucune de ses colonnes : elle s'ajoute à côté. Exiger une
 *    version ferait rejouer — ou pire, condamner — une demande de source parce qu'un
 *    cousin a corrigé une date entre-temps. C'est le raisonnement déjà tenu par
 *    `ajout_media`, et pour la même raison : deux contributions qui n'écrivent pas au même
 *    endroit ne peuvent pas entrer en conflit.
 *
 * La disparition de la personne reste couverte : `AnalyseDeDemande` charge la cible de
 * toute op qui porte un `subject_id`, quelle que soit sa nature, et une personne effacée
 * entre le dépôt et la validation devient un `cible_disparue` — une caducité, avec son
 * bouton « refaire ».
 *
 * ## Les six contrôles, et ce que chacun attrape
 *
 * | Contrôle                                   | Sans lui                                          |
 * |--------------------------------------------|---------------------------------------------------|
 * | la personne visée existe                   | une demande qui pointe le vide, caduque d'avance   |
 * | la source désignée existe                  | une `500` à la validation, chez quelqu'un d'autre  |
 * | `quay` dans le vocabulaire de `Tree`       | la même `500`, chez le valideur (voir plus bas)    |
 * | `scope` dans le vocabulaire de `Tree`      | une citation rangée sous une portée inventée      |
 * | au moins un des trois champs renseigné     | une citation vide : une source qui n'étaye rien   |
 * | pas deux fois la même page de la même source | deux lignes pour une seule lecture              |
 *
 * **Le troisième mérite son paragraphe.** `modification_citation` ne fait pas ce contrôle :
 * `quay` y est un `ValueKind::Enum`, validé au dépôt comme une simple chaîne. Mesuré, le
 * champ y est même **inatteignable** — en entier il rend `422`, en chaîne il rend `409`
 * sur une caducité qui n'a pas eu lieu, parce que l'état lu au port est un `int`. On ne
 * reproduit pas ce chemin ; on ne recopie pas non plus les valeurs de `CitationQuality`
 * dans ce module, ce que la règle 3 de l'ADR 0002 interdit. `Tree` déclare son
 * vocabulaire par le port — voir
 * {@see \App\Module\Proposal\Contract\VocabulaireDAnnotation}.
 *
 * ## Ce qu'il ne sait pas faire, et qui est nommé plutôt que contourné
 *
 * **Une citation sur une personne créée par la même demande** (`subjectDraftRef: "#p1"`)
 * n'est pas acceptée, et le message le dit. Ce n'est pas un oubli : l'ordre des passes de
 * `ValidationDeDemande::ecrire()` applique les ops **avant** la nature de la demande, donc
 * avant que `ApplicateurDeCreation` n'ait inséré les brouillons et rempli la carte
 * `#p1 → 312`. Au moment où une op de citation s'appliquerait, la personne n'a pas encore
 * d'identifiant. Le rendre possible demande de trancher l'ordre des passes, pas d'ajouter
 * un contrôle ici.
 */
final readonly class ConstructeurDAjoutDeCitation implements ConstructeurDeDemande
{
    public function __construct(
        private EntityManagerInterface $em,
        private PortArbre $arbre,
    ) {
    }

    public function natures(): array
    {
        return [RequestKind::AjoutCitation];
    }

    public function construire(ChangeRequest $demande, array $corps, Lecteur $lecteur): void
    {
        $brut = $corps['ops'] ?? null;

        if (!\is_array($brut) || [] === $brut) {
            throw DonneesInvalides::pourChamp('ops', 'Une demande d\'ajout de source porte au moins une citation.');
        }

        $construits = [];
        $seqVus = [];
        $empreintes = [];

        foreach (array_values($brut) as $index => $donnees) {
            if (!\is_array($donnees)) {
                throw DonneesInvalides::pourChamp("ops[$index]", 'Chaque op est un objet.');
            }

            $chemin = "ops[$index]";
            [$op, $empreinte] = $this->construireOp($demande, $donnees, $index, $chemin);

            if (\in_array($op->getSeq(), $seqVus, true)) {
                throw DonneesInvalides::pourChamp("$chemin.seq", 'Deux ops ne peuvent pas porter le même rang.');
            }

            // Deux fois la même page de la même source sur la même fiche, dans **une
            // seule** demande, est une double saisie et non deux lectures : le
            // formulaire a été validé deux fois. Deux demandes distinctes qui le font
            // restent légitimes — deux personnes ont lu la pièce chacune de son côté —,
            // et c'est pourquoi le contrôle s'arrête au bord de la demande.
            if (\in_array($empreinte, $empreintes, true)) {
                throw DonneesInvalides::pourChamp("$chemin.newValue", 'Cette demande cite déjà la même page de la même source sur cette fiche.');
            }

            $seqVus[] = $op->getSeq();
            $empreintes[] = $empreinte;
            $construits[] = $op;
            $this->em->persist($op);
        }

        // La cible dénormalisée est tirée de l'op de plus petit `seq` — comme pour une
        // modification —, parce que c'est elle qui filtre et groupe la file
        // d'administration sans jointure (§ 5.1). La cible d'une demande de source est la
        // **personne**, jamais la citation : la citation n'existe pas encore.
        usort($construits, static fn (ChangeRequestOp $a, ChangeRequestOp $b): int => $a->getSeq() <=> $b->getSeq());
        $premier = $construits[0];
        $sujet = $premier->getSubjectId();
        \assert(null !== $sujet);

        $this->verifierCoherenceDeLaCible($corps, $sujet);

        $cible = $this->arbre->chargerCible(SubjectKind::Personne, $sujet);

        $demande->setTarget(SubjectKind::Personne, $sujet, $cible?->libelle);
    }

    /**
     * Une demande de source sans op ne désigne rien.
     *
     * Contrôlé à la soumission, comme « au moins un lien » pour une création et « au moins
     * un média » pour un ajout de média : un brouillon incomplet doit pouvoir
     * s'enregistrer (§ 6.3).
     */
    public function verifierAvantSoumission(ChangeRequest $demande): void
    {
        if ([] === $this->opsDe($demande)) {
            throw DonneesInvalides::pourChamp('ops', 'Une demande d\'ajout de source porte au moins une citation.');
        }
    }

    /**
     * @param array<string, mixed> $donnees
     *
     * @return array{ChangeRequestOp, string} l'op, et l'empreinte qui détecte un doublon dans la demande
     */
    private function construireOp(ChangeRequest $demande, array $donnees, int $index, string $chemin): array
    {
        $this->verifierLaForme($donnees, $chemin);

        $seq = $donnees['seq'] ?? $index + 1;

        if (!\is_int($seq) || $seq < 1) {
            throw DonneesInvalides::pourChamp("$chemin.seq", 'Le rang d\'un op est un entier positif.');
        }

        $personneId = $this->personneVisee($donnees, $chemin);
        $brut = $this->objetDeValeur($donnees, $chemin);

        // La source **avant** le reste du formulaire, et l'ordre est le message : celui
        // qui se trompe d'identifiant de source doit lire « cette source n'existe pas »,
        // et non « votre citation est vide » — qui serait vrai, et qui ne l'aiderait pas.
        $source = $this->sourceDesignee($brut, $chemin);
        $valeur = $this->valeur($brut, $chemin);

        $op = new ChangeRequestOp(
            $demande,
            $seq,
            OpKind::AjoutCitation,
            SubjectKind::Personne,
            ValueKind::Citation,
        );

        // `baseVersion` à `null` : voir l'en-tête. Le sujet est la personne, mais
        // l'écriture est à côté d'elle.
        $op->viserLigneExistante($personneId, null);

        // `oldValue` est `null` — la citation n'existait pas. `sourceTitle` est recopié
        // depuis la **ligne** et non depuis le corps : un client qui annoncerait un titre
        // différent de celui de la source ferait diverger le diff de ce qui sera
        // réellement écrit, et c'est le diff que l'administrateur lit pour décider.
        $op->setValues(null, [
            'sourceId' => $source->id,
            'sourceTitle' => $source->titre,
            'page' => $valeur['page'],
            'quay' => $valeur['quay'],
            'dataText' => $valeur['dataText'],
            'scope' => $valeur['scope'],
        ]);

        return [$op, \sprintf('%d|%d|%s', $personneId, $source->id, $valeur['page'] ?? '')];
    }

    /**
     * Les deux contraintes de base, appliquées **avant** la base : « le `CHECK` est le
     * filet, pas le contrôle » (§ 14.3).
     *
     * @param array<string, mixed> $donnees
     */
    private function verifierLaForme(array $donnees, string $chemin): void
    {
        $nature = $donnees['opKind'] ?? OpKind::AjoutCitation->value;

        if (OpKind::AjoutCitation->value !== $nature) {
            throw DonneesInvalides::pourChamp("$chemin.opKind", 'Une demande d\'ajout de source ne porte que des ops « ajout_citation ».');
        }

        if (null !== ($donnees['field'] ?? null)) {
            // `ck_prp_op_champ` : `field` est `null` pour tout `opKind` autre que `champ`.
            // Le dire en 422 plutôt que de laisser la contrainte rendre une 500.
            throw DonneesInvalides::pourChamp("$chemin.field", 'Un op de citation ne vise aucun champ : il pose une ligne entière.');
        }

        $valueKind = $donnees['valueKind'] ?? ValueKind::Citation->value;

        if (ValueKind::Citation->value !== $valueKind) {
            throw DonneesInvalides::pourChamp("$chemin.valueKind", 'La valeur d\'un op de citation est de type « citation ».');
        }

        $sujet = $donnees['subjectKind'] ?? SubjectKind::Personne->value;

        if (SubjectKind::Personne->value !== $sujet) {
            // Le modèle autorise une citation sur une famille ou sur un fait
            // (`ck_tree_citation_sujet_unique`) ; ce circuit-ci ne sait poser que celles
            // qui visent une personne. Le dire vaut mieux que d'accepter une portée qu'on
            // écrirait quand même sur une personne.
            throw DonneesInvalides::pourChamp("$chemin.subjectKind", 'Une source se rattache à une personne : les citations d\'union et de fait ne passent pas encore par une demande.');
        }
    }

    /**
     * La personne que la citation étaye — une ligne qui existe **déjà**.
     *
     * @param array<string, mixed> $donnees
     */
    private function personneVisee(array $donnees, string $chemin): int
    {
        if (null !== ($donnees['subjectDraftRef'] ?? null)) {
            // Nommé plutôt que contourné : voir l'en-tête. L'ordre des passes de la
            // validation applique les ops avant que les brouillons n'aient d'identifiant.
            throw DonneesInvalides::pourChamp("$chemin.subjectDraftRef", 'Une source ne peut pas être rattachée à une personne créée par la même demande : déposez d\'abord la création, puis la source sur la fiche obtenue.');
        }

        $id = $donnees['subjectId'] ?? null;

        if (!\is_int($id) || $id < 1) {
            throw DonneesInvalides::pourChamp("$chemin.subjectId", 'Une source se rattache à une fiche existante, désignée par son identifiant.');
        }

        if (null === $this->arbre->chargerCible(SubjectKind::Personne, $id)) {
            // `404` et non `422` : c'est la **cible** de la demande, celle que l'écran
            // affichait. Même geste que pour une modification, dont l'auteur vient du même
            // bouton sur la même fiche.
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
            throw DonneesInvalides::pourChamp("$chemin.newValue", 'La valeur d\'un op de citation est un objet.');
        }

        if (\array_key_exists('oldValue', $donnees) && null !== $donnees['oldValue']) {
            // Une création n'a pas d'avant. Accepter une `oldValue` non nulle ferait
            // afficher, dans le diff, une valeur « remplacée » qui n'a jamais existé.
            throw DonneesInvalides::pourChamp("$chemin.oldValue", 'Une citation créée n\'a pas de valeur précédente : « oldValue » vaut null.');
        }

        return $brut;
    }

    /**
     * Les quatre valeurs de la citation, contrôlées et normalisées.
     *
     * @param array<string, mixed> $brut la `newValue`, déjà reconnue comme un objet
     *
     * @return array{page: string|null, quay: int|null, dataText: string|null, scope: string}
     */
    private function valeur(array $brut, string $chemin): array
    {
        $vocabulaire = $this->arbre->vocabulaireDAnnotation();

        $page = $this->texte($brut['page'] ?? null, "$chemin.newValue.page");
        $transcription = $this->texte($brut['dataText'] ?? null, "$chemin.newValue.dataText");
        $quay = $brut['quay'] ?? null;
        $scope = $brut['scope'] ?? 'generale';

        if (null !== $quay && (!\is_int($quay) || !\in_array($quay, $vocabulaire->qualites, true))) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.quay", \sprintf('Le niveau de confiance est un entier parmi %s, ou null quand on ne se prononce pas.', implode(', ', array_map(strval(...), $vocabulaire->qualites))));
        }

        if (!\is_string($scope) || !\in_array($scope, $vocabulaire->portees, true)) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.scope", \sprintf('Ce que la source étaye se dit par l\'une de ces portées : %s.', implode(', ', $vocabulaire->portees)));
        }

        if (null === $page && null === $transcription && null === $quay) {
            // Une citation qui ne dit ni où retrouver la pièce, ni ce qu'elle dit, ni
            // quelle confiance lui accorder n'apporte rien à la fiche : elle la fait
            // seulement passer pour sourcée. C'est exactement l'inverse du but.
            throw DonneesInvalides::pourChamp("$chemin.newValue", 'Une citation dit au moins où retrouver la pièce, ce qu\'elle dit, ou quelle confiance lui accorder.');
        }

        return ['page' => $page, 'quay' => $quay, 'dataText' => $transcription, 'scope' => $scope];
    }

    /**
     * La source citée — une ligne `tree_source` qui existe déjà.
     *
     * **`422` et non `404`**, contrairement à la personne : la source n'est pas la cible
     * de la demande, c'est une valeur saisie dans le formulaire. Même geste que
     * `ConstructeurDAjoutDeMedia` sur `mediaId`, et pour la même raison — « on valide un
     * formulaire », et l'erreur doit se poser sur la ligne fautive de l'écran.
     *
     * @param array<string, mixed> $brut la `newValue` telle qu'elle est arrivée
     */
    private function sourceDesignee(array $brut, string $chemin): SourceCitable
    {
        $id = $brut['sourceId'] ?? null;

        if (!\is_int($id) || $id < 1) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.sourceId", 'Une citation désigne la source qu\'elle cite, par son identifiant.');
        }

        $source = $this->arbre->chargerSource($id);

        if (null === $source) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.sourceId", 'Cette source n\'existe pas dans l\'arbre.');
        }

        return $source;
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

    private function texte(mixed $valeur, string $chemin): ?string
    {
        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur)) {
            throw DonneesInvalides::pourChamp($chemin, 'Cette valeur doit être une chaîne ou null.');
        }

        // Une chaîne vide et une absence disent la même chose ; les stocker différemment
        // ferait diverger deux citations identiques à la comparaison.
        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }

    /**
     * Les ops de la demande, **qu'elle soit déjà en base ou non**.
     *
     * Même motif que chez `ConstructeurDAjoutDeMedia` : `soumettre: true` construit et
     * soumet dans le même geste, avant le premier `flush`. Interroger l'unité de travail
     * est ce qui garde le dépôt atomique sans transaction.
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
