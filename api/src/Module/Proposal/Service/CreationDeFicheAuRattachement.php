<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Assistant\Contract\CreationDeFichePourLeRattachement;
use App\Module\Assistant\Contract\FicheCreee;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Repository\DraftPersonRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  « CRÉER LA FICHE ET RATTACHER » — la moitié `Proposal` du geste.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * L'adaptateur de `Assistant\Contract\CreationDeFichePourLeRattachement`. Il ne contient
 * **aucune règle** : il enchaîne les deux services qui les portent déjà.
 *
 * 1. {@see DepotDeDemande::deposer()} — le dépôt de `POST /api/demandes`, avec ses
 *    constructeurs, son contrôle « au moins un lien » (`422 demande_sans_lien`), sa clé
 *    de doublon et sa notification ;
 * 2. {@see ValidationDeDemande::valider()} — la validation de
 *    `POST /api/administration/demandes/{id}/valider`, avec ses révisions, son empreinte
 *    et son `auto_validated` (l'administrateur valide sa propre demande : autorisé, et
 *    marqué, ADR 0004).
 *
 * Écrire la fiche autrement — directement par `Tree` — aurait été plus court et aurait
 * fait une fiche **sans histoire** : ni demande, ni révision, ni auteur. C'est exactement
 * ce que l'ADR 0004 interdit.
 *
 * ── Le lecteur n'est pas un paramètre ───────────────────────────────────────────
 *
 * Même choix que {@see DemandesDeLAssistant} : il est résolu ici, depuis la session, par
 * {@see FabriqueDeLecteur}. Le contrôleur de l'assistant a déjà refusé la bascule « voir
 * comme » (`409`) ; la fabrique la relit quand même, et `DepotDeDemande` refuse un régime
 * en attente — deux garde-fous qui viennent avec, sans être réécrits.
 *
 * ── La transaction ──────────────────────────────────────────────────────────────
 *
 * Aucune ici : l'appelant en ouvre une englobante. Le `flush()` du dépôt et la
 * transaction de la validation s'y logent (points de sauvegarde, DBAL 4) ; une caducité
 * ou un verrou concurrent lève, et l'appelant annule **tout**, demande comprise.
 */
final readonly class CreationDeFicheAuRattachement implements CreationDeFichePourLeRattachement
{
    /** La personne que le rattachement vise : toujours la première du corps. */
    private const string TITULAIRE = '#p1';

    public function __construct(
        private DepotDeDemande $depot,
        private ValidationDeDemande $validation,
        private FabriqueDeLecteur $lecteurs,
        private DraftPersonRepository $brouillons,
    ) {
    }

    public function creerEtValider(array $corps, string $message): FicheCreee
    {
        // Filet : l'appelant a déjà imposé la nature ; un autre corps ne crée pas de fiche
        // à rattacher, et ce port n'a pas à le laisser passer.
        if (RequestKind::CreationPersonne->value !== ($corps['kind'] ?? null)) {
            throw DonneesInvalides::pourChamp('kind', 'Seule une demande d’ajout de personne crée la fiche d’un compte.');
        }

        $corps['soumettre'] = true;
        $corps['message'] = $message;

        $lecteur = $this->lecteurs->courant();
        $demande = $this->depot->deposer($corps, $lecteur);
        $this->validation->valider($demande, $lecteur);

        $titulaire = $this->brouillons->parReference($demande, self::TITULAIRE);
        $personId = $titulaire?->getResolvedPersonId();

        if (null === $titulaire || null === $personId) {
            // Inatteignable si la validation a réussi : l'applicateur de création pose
            // `resolved_person_id` sur chaque brouillon. Une exception plutôt qu'un `0` :
            // rattacher un compte à la personne n° 0 serait pire qu'une 500.
            throw new \LogicException('La validation n’a pas produit la fiche #p1.');
        }

        return new FicheCreee($personId, $titulaire->nomPropose(), $demande->getId() ?? 0);
    }
}
