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
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Service\Port\EtatCible;
use App\Module\Proposal\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les trois natures `modification_*` : **un op par champ**, avec ancienne et nouvelle
 * valeur (§ 6.2 du contrat d'API).
 *
 * **La table de l'objet visé n'est pas touchée.** Ce constructeur lit l'arbre — pour
 * vérifier que la cible existe et que l'`oldValue` annoncée est bien la valeur
 * courante — mais il n'écrit que dans `prp_change_request` et `prp_change_op`. C'est
 * la règle centrale de l'ADR 0004, et c'est le premier test du lot.
 *
 * ── Les six contrôles, et ce que chacun attrape ──────────────────────────────────
 *
 * | Contrôle                              | Sans lui                                        |
 * |---------------------------------------|-------------------------------------------------|
 * | `field` dans la surface éditable      | affectation de masse sur une colonne dérivée     |
 * | `valueKind` imposé par le champ       | une date reçue comme une chaîne, aplatie          |
 * | `baseVersion` présent                 | le travail de mardi écrasé vendredi sans trace    |
 * | `oldValue` = valeur courante          | une demande déposée déjà fausse, caduque d'avance |
 * | `oldValue ≠ newValue`                 | une « modification » qui ne modifie rien          |
 * | cible cohérente avec l'op de plus petit `seq` | une file filtrable sur une cible fausse   |
 */
final readonly class ConstructeurDeModification implements ConstructeurDeDemande
{
    public function __construct(
        private EntityManagerInterface $em,
        private PortArbre $arbre,
        private NormaliseurDeValeur $normaliseur,
        private SurfaceEditable $surface,
        private ValeursMasquees $masques,
    ) {
    }

    public function natures(): array
    {
        return [
            RequestKind::ModificationPersonne,
            RequestKind::ModificationFamille,
            RequestKind::ModificationCitation,
        ];
    }

    public function construire(ChangeRequest $demande, array $corps, Lecteur $lecteur): void
    {
        $ops = $corps['ops'] ?? null;

        if (!\is_array($ops) || [] === $ops) {
            throw DonneesInvalides::pourChamp('ops', 'Une modification porte au moins un op.');
        }

        $construits = [];
        $seqVus = [];

        foreach (array_values($ops) as $index => $brut) {
            if (!\is_array($brut)) {
                throw DonneesInvalides::pourChamp("ops[$index]", 'Chaque op est un objet.');
            }

            $op = $this->construireOp($demande, $brut, $index);

            if (\in_array($op->getSeq(), $seqVus, true)) {
                throw DonneesInvalides::pourChamp("ops[$index].seq", 'Deux ops ne peuvent pas porter le même rang.');
            }

            $seqVus[] = $op->getSeq();
            $construits[] = $op;
            $this->em->persist($op);
        }

        // La cible dénormalisée sert à filtrer et grouper la file **sans jointure**
        // (§ 5.1), et à détecter les demandes concurrentes sur la même fiche. Elle est
        // tirée de l'op de plus petit `seq` — pas du corps —, et le corps doit y
        // correspondre, sinon la file afficherait une cible que la demande ne touche pas.
        usort($construits, static fn (ChangeRequestOp $a, ChangeRequestOp $b): int => $a->getSeq() <=> $b->getSeq());
        $premier = $construits[0];
        $sujet = $premier->getSubjectId();

        if (null === $sujet) {
            throw DonneesInvalides::pourChamp('ops[0].subjectId', 'Une modification vise une ligne existante.');
        }

        $this->verifierCoherenceDeLaCible($corps, $premier->getSubjectKind(), $sujet);

        $cible = $this->arbre->chargerCible($premier->getSubjectKind(), $sujet);

        if (SubjectKind::Personne === $premier->getSubjectKind() && null !== $cible) {
            $this->verifierQueLeDecesEstCoherent($construits, $cible);
        }

        $demande->setTarget(
            $premier->getSubjectKind(),
            $sujet,
            $cible?->libelle,
        );
    }

    /**
     * **Une date de décès sur quelqu'un qu'on laisse marqué vivant.**.
     *
     * ── Ce qui se passait avant ────────────────────────────────────────────────────
     *
     * Rien, jusqu'à l'écriture. La règle n'existait qu'en base —
     * `ck_tree_person_deces_coherent`, « une date de décès implique le décès » — et elle
     * s'appliquait au `flush`. Le contributeur recevait donc une erreur de pilote de base
     * de données, c'est-à-dire un message qui ne nomme ni le champ ni le geste à faire, et
     * il n'avait aucun moyen de deviner qu'il lui manquait une case à cocher.
     *
     * ── Pourquoi on refuse plutôt que de cocher la case à sa place ─────────────────
     *
     * Parce que c'est un fait d'état civil, pas un détail d'affichage. Et parce que
     * `deceased` **ouvre la visibilité** : un vivant hors du cercle du lecteur est absent
     * de l'arbre (ADR 0003), un défunt est « un fait historique, visible de tout compte
     * connecté ». Basculer ce drapeau au nom de quelqu'un qui n'a saisi qu'une date
     * élargirait l'audience d'une fiche sans que personne ne l'ait demandé.
     *
     * C'est exactement l'arbitrage déjà pris pour la rupture d'union à la saisie : « on ne
     * devine pas une rupture à partir d'une date — le lecteur a pu saisir la date avant de
     * cocher ». Le message dit donc les deux gestes possibles, et laisse choisir.
     *
     * ── L'état vérifié est celui d'**après** ───────────────────────────────────────
     *
     * Une demande qui pose la date **et** coche la case est parfaitement valide, et c'est
     * le cas courant. On compose donc l'état résultant — les valeurs de la fiche, écrasées
     * par les ops — au lieu de regarder chaque op isolément, qui refuserait la moitié des
     * demandes légitimes.
     *
     * Le critère reprend celui de la contrainte, terme pour terme : `raw`, `min` ou `max`
     * renseigné. Un **lieu** de décès seul passe, et c'est voulu — la contrainte l'admet,
     * et « inhumé à Trochu » sans date est une information qu'on n'a pas à refuser.
     *
     * @param list<ChangeRequestOp> $ops
     */
    private function verifierQueLeDecesEstCoherent(array $ops, EtatCible $cible): void
    {
        $deces = $this->normaliseur->versJson(ValueKind::DateIncertaine, $cible->valeurs['death'] ?? null);
        $decedee = (bool) ($cible->valeurs['deceased'] ?? false);
        $touche = false;

        foreach ($ops as $op) {
            if ('death' === $op->getField()) {
                $deces = $op->getNewValue();
                $touche = true;
            }

            if ('deceased' === $op->getField()) {
                $decedee = (bool) $op->getNewValue();
                $touche = true;
            }
        }

        // **On juge la modification, pas la fiche.** Corriger un prénom sur une fiche dont
        // l'état de décès serait incohérent ne doit pas être bloqué : le contributeur
        // n'a pas créé cette incohérence, et on n'a pas à lui demander de la trancher au
        // passage. Le cas ne devrait pas exister — la contrainte de base l'interdit — mais
        // un contrôle qui dépend de cette promesse en hérite les conséquences le jour où
        // elle est levée, et ce jour-là il bloquerait des corrections sans rapport.
        if (!$touche || $decedee || !self::porteUneDate($deces)) {
            return;
        }

        throw DonneesInvalides::pourChamp('ops', 'Une date de décès implique le décès : cochez « personne décédée », ou retirez la date. Ce n’est pas un détail d’affichage — une fiche marquée décédée devient visible de tout compte connecté, et on ne bascule pas cela à votre place.');
    }

    /**
     * Le critère de la contrainte `ck_tree_person_deces_coherent`, recopié : `raw`, `min`
     * ou `max` renseigné. Le lieu n'en fait pas partie.
     */
    private static function porteUneDate(mixed $date): bool
    {
        if (!\is_array($date)) {
            return false;
        }

        foreach (['raw', 'min', 'max'] as $cle) {
            $valeur = $date[$cle] ?? null;

            if (null !== $valeur && '' !== $valeur) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une modification n'a rien à vérifier de plus à la soumission : ses ops ont été
     * contrôlés à la création, et une demande sans op n'a pas pu être créée.
     *
     * La méthode existe quand même, parce que l'interface la porte pour la création de
     * personne — dont le contrôle « au moins un lien » est justement *à la soumission*.
     */
    public function verifierAvantSoumission(ChangeRequest $demande): void
    {
    }

    /**
     * @param array<string, mixed> $brut
     */
    private function construireOp(ChangeRequest $demande, array $brut, int $index): ChangeRequestOp
    {
        $chemin = "ops[$index]";

        $opKind = $this->enumeration(OpKind::class, $brut['opKind'] ?? null, "$chemin.opKind");
        $subjectKind = $this->enumeration(SubjectKind::class, $brut['subjectKind'] ?? null, "$chemin.subjectKind");

        if (OpKind::Champ !== $opKind) {
            throw DonneesInvalides::pourChamp("$chemin.opKind", 'Une demande de modification ne porte que des ops de champ.');
        }

        $field = $brut['field'] ?? null;
        if (!\is_string($field) || '' === $field) {
            throw DonneesInvalides::pourChamp("$chemin.field", 'Le champ modifié est obligatoire.');
        }

        // La surface éditable est close, et le message le dit : un `field` refusé n'est
        // pas une faute de frappe dans neuf cas sur dix, c'est une colonne dérivée que
        // quelqu'un a cru modifiable.
        $nature = $this->surface->natureDe($subjectKind, $field);
        if (null === $nature) {
            throw DonneesInvalides::pourChamp("$chemin.field", \sprintf('Le champ « %s » n\'est pas modifiable par une demande.', $field));
        }

        // Le `valueKind` n'est pas au choix du client : il découle du champ. L'exiger
        // quand même et le comparer attrape un front qui enverrait une date en texte —
        // ce qui l'aplatirait — au lieu de l'accepter en silence.
        $valueKind = $this->enumeration(ValueKind::class, $brut['valueKind'] ?? null, "$chemin.valueKind");
        if ($valueKind !== $nature) {
            throw DonneesInvalides::pourChamp("$chemin.valueKind", \sprintf('Le champ « %s » attend une valeur de type %s.', $field, $nature->value));
        }

        $seq = $brut['seq'] ?? null;
        if (!\is_int($seq) || $seq < 1) {
            throw DonneesInvalides::pourChamp("$chemin.seq", 'Le rang d\'un op est un entier positif.');
        }

        $subjectId = $brut['subjectId'] ?? null;
        if (!\is_int($subjectId)) {
            throw DonneesInvalides::pourChamp("$chemin.subjectId", 'Une modification vise une ligne existante.');
        }

        $baseVersion = $brut['baseVersion'] ?? null;
        if (!\is_int($baseVersion)) {
            throw DonneesInvalides::pourChamp("$chemin.baseVersion", 'La version vue à la lecture de la fiche est obligatoire : sans elle, une demande déposée lundi et validée vendredi écrase le travail de mardi.');
        }

        // ADR 0016 : **avant** toute lecture de la valeur courante — le contrôle de
        // l'ancienne valeur rendrait sinon la date complète dans son `409`.
        if ('birth' === $field && $this->masques->naissanceMasquee($subjectKind, $subjectId)) {
            throw DonneesInvalides::naissanceMasquee("$chemin.field");
        }

        if (!\array_key_exists('oldValue', $brut) || !\array_key_exists('newValue', $brut)) {
            throw DonneesInvalides::pourChamp($chemin, 'Un op porte toujours son ancienne et sa nouvelle valeur.');
        }

        // Les deux valeurs passent par le normaliseur : c'est là que les cinq clés d'un
        // bloc date sont exigées, que `texte` est refusé en entrée, et que les deux
        // contraintes `CHECK` sur les qualificatifs sont appliquées **avant** la base.
        $ancienne = $this->normaliseur->versJson(
            $valueKind,
            $this->normaliseur->versPhp($valueKind, $brut['oldValue'], "$chemin.oldValue"),
        );
        $nouvelle = $this->normaliseur->versJson(
            $valueKind,
            $this->normaliseur->versPhp($valueKind, $brut['newValue'], "$chemin.newValue"),
        );

        if (SubjectKind::Citation === $subjectKind && 'url' === $field) {
            // Le lien finit en `href` sur la fiche : seuls http et https passent.
            $nouvelle = AdresseDeCitation::controler(\is_string($nouvelle) ? $nouvelle : null, "$chemin.newValue");
        }

        if ($ancienne === $nouvelle) {
            throw DonneesInvalides::pourChamp("$chemin.newValue", 'Une modification qui ne modifie rien n\'a rien à faire dans une demande.');
        }

        $this->verifierAncienneValeur($subjectKind, $subjectId, $field, $valueKind, $ancienne, $chemin);

        $op = new ChangeRequestOp($demande, $seq, $opKind, $subjectKind, $valueKind);
        $op->setField($field)
            ->viserLigneExistante($subjectId, $baseVersion)
            ->setValues($ancienne, $nouvelle);

        return $op;
    }

    /**
     * L'`oldValue` doit correspondre à la valeur courante **au moment du dépôt**.
     *
     * Si elle ne correspond déjà pas, la fiche a changé pendant que le formulaire était
     * ouvert : `409 conflit`, avec la valeur courante dans `details`. Laisser passer
     * une demande née fausse produirait une caducité à coup sûr trois jours plus tard,
     * c'est-à-dire une contribution perdue pour une raison qu'on connaissait dès la
     * première seconde.
     */
    private function verifierAncienneValeur(
        SubjectKind $kind,
        int $id,
        string $field,
        ValueKind $valueKind,
        mixed $ancienne,
        string $chemin,
    ): void {
        $cible = $this->arbre->chargerCible($kind, $id);

        if (null === $cible) {
            throw Introuvable::cible($kind->value, $id);
        }

        if (!\array_key_exists($field, $cible->valeurs)) {
            throw DonneesInvalides::pourChamp("$chemin.field", \sprintf('Le champ « %s » n\'existe pas sur cette ligne.', $field));
        }

        $courante = $this->normaliseur->versJson($valueKind, $cible->valeurs[$field]);

        if ($courante !== $ancienne) {
            throw Conflit::valeurDejaChangee(['field' => $field, 'valeurCourante' => $this->normaliseur->pourAffichage($valueKind, $courante), 'valeurAnnoncee' => $this->normaliseur->pourAffichage($valueKind, $ancienne), 'version' => $cible->version]);
        }
    }

    /** @param array<string, mixed> $corps */
    private function verifierCoherenceDeLaCible(array $corps, SubjectKind $kind, int $id): void
    {
        $annonceKind = $corps['targetKind'] ?? null;
        $annonceId = $corps['targetId'] ?? null;

        if (null !== $annonceKind && $annonceKind !== $kind->value) {
            throw DonneesInvalides::pourChamp('targetKind', 'La cible annoncée ne correspond pas à celle des ops.');
        }

        if (null !== $annonceId && $annonceId !== $id) {
            throw DonneesInvalides::pourChamp('targetId', 'La cible annoncée ne correspond pas à celle des ops.');
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function enumeration(string $classe, mixed $valeur, string $chemin): \BackedEnum
    {
        if (!\is_string($valeur)) {
            throw DonneesInvalides::pourChamp($chemin, 'Valeur attendue : une chaîne d\'énumération.');
        }

        $cas = $classe::tryFrom($valeur);

        if (null === $cas) {
            throw DonneesInvalides::pourChamp($chemin, \sprintf('« %s » n\'est pas une valeur admise.', $valeur));
        }

        return $cas;
    }
}
