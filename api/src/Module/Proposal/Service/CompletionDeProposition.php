<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\Port\NotificateurDeDemandes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * COMPLÉTER SUR PLACE UNE FICHE PROPOSÉE — § 6.6 ter du contrat, ADR 0017.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Jusqu'au 10/10/2026, **aucune demande enregistrée ne se modifiait**, même par son
 * auteur : il pouvait la soumettre ou la retirer, rien d'autre. Celui qui retrouvait
 * l'acte de décès de sa grand-tante une semaine après l'avoir proposée n'avait que deux
 * gestes, retirer et tout ressaisir, ou déposer une seconde création — le doublon que
 * l'administration passait ensuite à écarter. Le commanditaire l'a tranché le 09/10 :
 * **l'auteur et l'administrateur complètent la proposition sur place** ; un autre membre
 * dépose un complément, que l'administrateur fusionne ({@see FusionDeComplement}).
 *
 * ── Qui, et sur quoi ────────────────────────────────────────────────────────────
 *
 * | Lecteur | `brouillon` | `en_attente` | tranchée |
 * |---|:-:|:-:|:-:|
 * | l'auteur | oui | oui | `409 demande_deja_decidee` |
 * | un administrateur, sur la demande d'un autre | `403` — un brouillon est le travail en cours de son auteur | oui, et l'auteur est prévenu | `409` |
 * | un autre membre | `403` (voter) | `403` | `403` |
 *
 * ── Ce que fait une complétion ──────────────────────────────────────────────────
 *
 * 1. `fiche` **remplace tous les champs éditables** — une clé absente vaut `null`, comme
 *    au dépôt. Une complétion qui rapiècerait laisserait le client deviner si « absent »
 *    veut dire « inchangé » ou « effacé », et la réponse dépendrait de l'écran ;
 * 2. `liens` **ajoute**, et seulement : retirer un lien d'une proposition, c'est en faire
 *    une autre, et cela se fait en la retirant. Le rang est attribué par le serveur
 *    ({@see ConstructeurDeCreation::liensEnPlus()}) ;
 * 3. la fiche est réécrite **en place**, jamais recréée : les liens d'autres demandes la
 *    citent par l'identifiant de sa ligne, **sans clé étrangère** (§ 6.3 bis) — la recréer
 *    les rendrait pendants en silence ;
 * 4. la clé de doublon est recalculée si la demande est en attente (un brouillon n'en a
 *    pas : elle se pose à la soumission) ;
 * 5. la demande est **touchée** : l'`ETag` du graphe repose sur `updated_at`
 *    (`LectureDesPropositions::empreinteDesDemandes()`), et un `304` laisserait dans l'arbre
 *    du lecteur un nœud au nom périmé ;
 * 6. les **libellés stockés** des liens qui citent la fiche sont rafraîchis — voir
 *    {@see LiensDUneFiche::rafraichirLesLibelles()} pour la règle, qui n'est pas « tous » :
 *    même auteur, ou personne décédée ;
 * 7. les **notifications**, après l'écriture : l'auteur apprend que l'administration a
 *    complété sa demande, les administrateurs que l'auteur a complété la sienne.
 *
 * ── L'ordre des refus, et pourquoi rien n'est écrit avant le dernier ────────────
 *
 * Tout est **lu et validé avant la première écriture** : la fiche par
 * {@see ConstructeurDeCreation::lireLaFiche()}, les liens par
 * {@see ConstructeurDeCreation::liensEnPlus()}, qui ne persiste rien. Une `422` au
 * troisième lien laisse donc l'entité gérée intacte — sans quoi le premier `flush()` de la
 * même requête écrirait une fiche à moitié réécrite que la réponse venait de refuser.
 *
 * L'empreinte (§ 6.5) est comparée **après** les refus de statut : une demande tranchée
 * entre-temps doit se dire `demande_deja_decidee`, plus précis que « modifiée ».
 */
final readonly class CompletionDeProposition
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConstructeurDeCreation $constructeur,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private EmpreinteDeDemande $empreintes,
        private NotificateurDeDemandes $notificateur,
        private SurfaceEditable $surface,
    ) {
    }

    /**
     * @param string               $ref   la référence provisoire, **avec ou sans** son dièse —
     *                                    la route la reçoit sans (`p1`), la base la porte
     *                                    avec (`#p1`)
     * @param array<string, mixed> $corps `{fiche, liens, empreinte}`
     */
    public function completer(ChangeRequest $demande, string $ref, array $corps, Lecteur $lecteur): ChangeRequest
    {
        DepotDeDemande::verifierQuIlPeutEcrire($lecteur);

        $statut = $demande->getStatus();

        if ($statut->estDefinitif()) {
            throw Conflit::dejaDecidee($statut);
        }

        $estAuteur = $demande->getAuthorUserId() === $lecteur->userId;

        // Le voter a déjà refusé tout autre membre ; ce contrôle est le filet du jour où une
        // route l'oublierait, et il porte en plus la règle que le voter ne voit pas : le
        // statut.
        if (!$estAuteur) {
            if (!$lecteur->administrateur) {
                throw AccesRefuse::demandeDAutrui();
            }

            if (RequestStatus::EnAttente !== $statut) {
                throw AccesRefuse::brouillonDAutrui();
            }
        }

        $reference = '#'.ltrim($ref, '#');
        $fiche = $this->brouillons->parReference($demande, $reference);

        if (null === $fiche) {
            throw Introuvable::brouillon(ltrim($ref, '#'));
        }

        $this->empreintes->exiger($demande, $corps['empreinte'] ?? null);

        // ── Lire et valider tout le corps, sans rien écrire ────────────────────────
        $brut = $corps['fiche'] ?? null;

        if (!\is_array($brut)) {
            throw DonneesInvalides::pourChamp('fiche', 'La fiche est un objet portant tous ses champs, les inchangés compris : une clé absente vaut null.');
        }

        // Le front réutilise volontiers le bloc d'un brouillon du dépôt, `localRef`
        // compris. Accepté s'il désigne bien cette fiche ; refusé sinon — l'URL et le corps
        // diraient deux fiches différentes, et ce n'est pas au serveur de choisir.
        $localRef = $brut['localRef'] ?? null;

        if (null !== $localRef && $localRef !== $fiche->getLocalRef()) {
            throw DonneesInvalides::pourChamp('fiche.localRef', 'Cette référence ne désigne pas la fiche de l\'adresse : retirez-la, ou envoyez celle de la fiche complétée.');
        }

        /** @var array<string, mixed> $brut */
        $valeurs = $this->constructeur->lireLaFiche($brut, 'fiche');
        $nouveaux = $this->constructeur->liensEnPlus($demande, $corps['liens'] ?? null, $lecteur);

        // ── Tout est accepté : écrire ───────────────────────────────────────────────
        $champs = $this->constructeur->reecrireLaFiche($fiche, $valeurs);

        foreach ($nouveaux as $lien) {
            $this->em->persist($lien);
        }

        if ([] === $champs && [] === $nouveaux) {
            // Rien n'a changé — un double clic, un « Enregistrer » sans modification. On
            // ne touche pas la demande : son empreinte resterait sinon périmée pour l'écran
            // de l'administrateur qui l'a ouverte, sans que rien n'y ait bougé.
            return $demande;
        }

        if (RequestStatus::EnAttente === $statut) {
            $this->constructeur->poserLaCleDeDoublon($demande);
        }

        $demande->toucher();

        // Les liens ajoutés ont pris le libellé de la fiche **avant** sa réécriture (voir
        // `ConstructeurDeCreation::liensEnPlus()`) : ils passent avec les autres.
        LiensDUneFiche::rafraichirLesLibelles([...$nouveaux, ...$this->liens->citant($fiche)], $fiche);

        $this->em->flush();

        $this->notifier($demande, $lecteur, $estAuteur, $champs, \count($nouveaux));

        return $demande;
    }

    /**
     * Après l'écriture, jamais avant (même règle que la validation) : une notification
     * partie pour une complétion qu'un `ROLLBACK` aurait annulée mentirait.
     *
     * - un **brouillon** ne prévient personne : personne d'autre que son auteur ne le voit ;
     * - un administrateur qui complète la demande **d'un autre** prévient l'auteur ;
     * - l'auteur qui complète sa demande en attente prévient les administrateurs — sauf
     *   s'il en est un : il vient de cliquer, et il n'y a en pratique qu'un administrateur.
     *
     * @param list<string> $champs
     */
    private function notifier(ChangeRequest $demande, Lecteur $lecteur, bool $estAuteur, array $champs, int $liensAjoutes): void
    {
        if (RequestStatus::EnAttente !== $demande->getStatus()) {
            return;
        }

        $libelles = array_map($this->surface->libelle(...), $champs);

        if (!$estAuteur) {
            $this->notificateur->demandeCompletee($demande, true, $libelles, $liensAjoutes);

            return;
        }

        if (!$lecteur->administrateur) {
            $this->notificateur->demandeCompletee($demande, false, $libelles, $liensAjoutes);
        }
    }
}
