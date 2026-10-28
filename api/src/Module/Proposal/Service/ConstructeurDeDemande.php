<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Proposal\Contract\RecueilDErreurs;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestKind;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LE POINT DE GREFFE DES NATURES DE DEMANDE À VENIR.
 *
 * `POST /api/demandes` est **une seule route** avec un discriminant `kind` (§ 6.1 du
 * contrat d'API). C'est la traduction directe de « un seul mécanisme, pas deux » : deux
 * routes seraient deux écrans, deux formats de diff, deux chemins de notification, et
 * deux endroits où oublier le message obligatoire au refus.
 *
 * Mais « un seul mécanisme » ne veut pas dire « un seul bloc de code » : les six
 * `RequestKind` ne lisent pas le même corps. Chacun a son constructeur, et
 * {@see DepotDeDemande} n'est qu'un aiguillage — statut, horodatage, notification —
 * commun aux six.
 *
 * ── Ce que l'agent « création de personne » (§ 6.4 du dossier) a à faire ─────────
 *
 * Écrire **un** service implémentant cette interface pour `creation_personne` et
 * `ajout_lien` : il lit `brouillons` et `liens`, écrit des `DraftPerson` et des
 * `DraftLink`, calcule `dedup_key`. Il est découvert automatiquement (tag
 * `app.proposal.constructeur_de_demande`, posé par `autoconfigure`). **Rien d'autre
 * n'est à modifier ici** — ni la route, ni le contrôleur, ni le sérialiseur, ni la
 * validation : c'est l'intérêt du découpage.
 *
 * L'agent « médias » fait de même pour `ajout_media`.
 *
 * ── La règle à ne pas enfreindre en le faisant ───────────────────────────────────
 *
 * **Ne jamais supposer que la cible d'une demande existe déjà en base.** Une demande de
 * création vise une personne qui n'a pas d'identifiant : elle vit sous une référence
 * provisoire `#p1` jusqu'à la validation, qui la résout. `targetKind` et `targetId` sont
 * alors `null`, et c'est légal (`ck_prp_request_cible` n'exige que leur cohérence
 * mutuelle). Toute la machinerie de ce module a été écrite avec cette contrainte en
 * tête ; une passe qui la perdrait serait à refaire.
 *
 * Le tag est posé **par l'interface** et non dans `config/services/proposal.yaml` : une
 * classe qui l'implémente est découverte sans qu'aucun fichier de configuration ne
 * change, et le point de greffe se documente à l'endroit où on le lit.
 */
#[AutoconfigureTag('app.proposal.constructeur_de_demande')]
interface ConstructeurDeDemande
{
    /**
     * Les natures que ce constructeur sait lire.
     *
     * @return list<RequestKind>
     */
    public function natures(): array;

    /**
     * Lit le corps de la requête et garnit la demande de ses lignes filles.
     *
     * La demande est déjà créée (sans identifiant), et n'est **pas** encore soumise :
     * `DepotDeDemande` s'en charge après, pour que le contrôle de complétude à la
     * soumission (« au moins un lien ») soit au même endroit pour toutes les natures.
     *
     * Toute erreur de forme se signale par une
     * {@see \App\Module\Proposal\Exception\DonneesInvalides} dont les clés de `champs`
     * suivent le chemin du corps : `ops[0].field`, `liens[2].kind`.
     *
     * `$recueil` vaut `null` au dépôt : la première erreur part telle quelle. Au dépôt à blanc,
     * c'est un recueil. Chaque élément d'une liste du corps (op, fiche, lien, retrait) y note sa
     * première erreur, et la boucle passe à l'élément suivant. **À la fin de la boucle, si un
     * élément a échoué, `$recueil->leverSiNonVide()` arrête le constructeur** : ce qui suit (la
     * cible tirée du premier op, les liens qui désignent les fiches, la cohérence du décès)
     * dépend de tous les éléments. Un contrôle hors boucle reste immédiat.
     *
     * @param array<string, mixed> $corps
     */
    public function construire(ChangeRequest $demande, array $corps, Lecteur $lecteur, ?RecueilDErreurs $recueil = null): void;

    /**
     * Le contrôle de complétude, appliqué **à la soumission** et non à la création :
     * on doit pouvoir enregistrer un brouillon incomplet (§ 6.3).
     *
     * @throws \App\Module\Proposal\Exception\ErreurDeDemande si la demande n'est pas soumissible
     */
    public function verifierAvantSoumission(ChangeRequest $demande): void;
}
