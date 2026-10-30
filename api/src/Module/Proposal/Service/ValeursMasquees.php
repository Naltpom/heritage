<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Security\ReciprociteInterface;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UNE DEMANDE PEUT DÉSORMAIS VISER UN VIVANT HORS DU CERCLE DE SON AUTEUR.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Jusqu'à l'assistant de proposition, c'était impossible en pratique : le formulaire ne
 * montre pas une fiche restreinte, donc ne permet pas d'en modifier un champ, et un op
 * ne portait jamais qu'un `oldValue` que son auteur avait déjà lu. Le commanditaire a
 * décidé que l'assistant **peut** proposer sur toute personne (la demande reste soumise à
 * un administrateur, qui voit tout). Le corps complet est alors gardé et déposé par le
 * serveur — mais la demande déposée se relit ensuite par `GET /api/demandes/{id}`, et
 * ses ops y portaient l'`oldValue` lu en base : **une date de naissance que l'auteur n'a
 * pas le droit de connaître**, servie par la route « mes demandes ».
 *
 * Ce service répond à une seule question — *cette valeur d'avant peut-elle être rendue
 * à l'auteur ?* — pour les deux sorties côté membre qui en portent : la vue auteur
 * ({@see SerialiseurDeDemande::pourAuteur()}) et la reprise ({@see RefaireDemande}).
 * La vue d'administration n'est pas concernée : l'administrateur voit tout.
 *
 * ── La règle, et pourquoi elle lit deux sources ─────────────────────────────────
 *
 * Masquée ⇔ la cible est une **personne**, **non décédée** sur sa fiche, et **hors du
 * cercle** du lecteur (`Core\Security\CercleDeVisibiliteInterface`, dont la règle
 * « vivant » est celle du graphe). Le drapeau `deceased` est lu d'abord : une personne
 * décédée est un fait historique, visible de tout compte connecté, et c'est l'immense
 * majorité des cibles — la question du cercle ne se pose alors même pas.
 *
 * *Depuis la révision du 2026-10-01 de l'ADR 0003*, le contrat répond « visible » pour
 * tout vivant non confidentiel quand le lecteur est un membre validé : ce service ne
 * masque donc plus, pour un membre, que les valeurs et libellés d'une fiche à
 * confidentialité forcée hors de son cercle. Rien à changer ici — la question est posée
 * au contrat, et c'est sa réponse qui a changé.
 *
 * ── Et, depuis le 2026-10-01, les **noms** ──────────────────────────────────────
 *
 * La révision du 2026-10-01 de l'ADR 0003 retire le nom d'affichage de la liste blanche :
 * un vivant hors cercle n'expose plus que {@see CercleDeVisibiliteInterface::NOM_MASQUE}.
 * Or une demande **enregistre** des libellés au dépôt — `targetLabel`, `subjectLabel` et
 * consorts sur ses liens, `familyLabel` (le nom des deux époux), `subjectLabel` de ses
 * révisions —, et ils sont le vrai `display_name`. L'aperçu de l'assistant les masquait ;
 * la demande déposée, relue par « Mes demandes », les rendait tels quels. D'où
 * {@see self::libelle()} et {@see self::libelleDeFamille()} : la même règle, appliquée à
 * un libellé stocké. Le libellé stocké reste en base, intact — l'administrateur, dont le
 * cercle couvre tout l'arbre, le lit toujours ; c'est le **rendu** au membre qui masque.
 */
final readonly class ValeursMasquees
{
    public function __construct(
        private CercleDeVisibiliteInterface $cercle,
        private PortArbre $arbre,
        private ReciprociteInterface $reciprocite,
    ) {
    }

    /**
     * La naissance de cette personne est-elle **masquée en partie** au lecteur — la
     * réciprocité des vivants (ADR 0016) ? Vrai pour un vivant dont il ne voit pas toutes
     * les composantes ; jamais pour un défunt.
     */
    public function naissanceMasquee(SubjectKind $kind, ?int $id): bool
    {
        if (SubjectKind::Personne !== $kind || null === $id || $this->reciprocite->naissance($id)->estComplete()) {
            return false;
        }

        // Comme {@see self::masquee()} : un défunt n'est jamais concerné, et c'est l'arbre
        // de ce module qui le dit.
        $cible = $this->arbre->chargerCible($kind, $id);

        return null !== $cible && true !== (bool) ($cible->valeurs['deceased'] ?? false);
    }

    /**
     * La ligne qu'une demande vise est-elle fermée au lecteur ? C'est la question du dépôt :
     * une valeur de cette ligne, dite dans un `409`, serait lue par qui n'a pas le droit de
     * la lire.
     *
     * Une personne : {@see self::masquee()}. Une famille : un époux masqué suffit
     * ({@see self::sujetFerme()}). Une citation appartient à une personne : c'est elle qui
     * décide. (Un fait, une note ou un média n'ont aucun champ modifiable par un op.)
     */
    public function cibleFermee(SubjectKind $kind, int $id): bool
    {
        if (SubjectKind::Citation === $kind) {
            $proprietaire = $this->arbre->chargerCible($kind, $id)?->personneId;

            return null !== $proprietaire && $this->masquee(SubjectKind::Personne, $proprietaire);
        }

        return $this->sujetFerme($kind, $id);
    }

    public function masquee(SubjectKind $kind, ?int $id): bool
    {
        if (SubjectKind::Personne !== $kind || null === $id) {
            return false;
        }

        // Le cercle d'abord : il est résolu une fois par requête et répond « vue » pour
        // un défunt comme pour un proche — l'immense majorité, et la totalité pour un
        // administrateur. La fiche n'est chargée que pour les autres : depuis que les
        // libellés passent ici (2026-10-01), une liste de demandes en ferait sinon une
        // lecture par ligne.
        if ($this->cercle->voitPersonne($id)) {
            return false;
        }

        // L'arbre d'un autre compte : masquée, défunte comprise — la règle des défunts ne
        // vaut que dans le périmètre (révision du 2026-10-04 de l'ADR 0003).
        if (!$this->cercle->dansLePerimetre($id)) {
            return true;
        }

        $cible = $this->arbre->chargerCible($kind, $id);

        return null !== $cible && true !== (bool) ($cible->valeurs['deceased'] ?? false);
    }

    /**
     * Le sujet d'un historique (§ 6.11) est-il fermé au lecteur ?
     *
     * Une personne masquée, ou une famille dont un époux l'est : l'historique porte des
     * dates et des lieux — tout ce que la liste blanche exclut —, et le contrat le dit
     * `404`. Une famille se ferme dès qu'**un** époux est masqué : ses révisions portent
     * la date d'union du couple, qui date aussi ce vivant-là.
     */
    public function sujetFerme(SubjectKind $kind, int $id): bool
    {
        if (SubjectKind::Famille === $kind) {
            foreach ($this->arbre->epouxDeFamille($id) as $epoux) {
                if ($this->masquee(SubjectKind::Personne, $epoux)) {
                    return true;
                }
            }

            return false;
        }

        return $this->masquee($kind, $id);
    }

    /**
     * Le libellé stocké d'une ligne de l'arbre, tel qu'il peut être rendu au lecteur.
     *
     * Une personne masquée ({@see self::masquee()}) ne se nomme plus : le masque, jamais
     * le libellé stocké. Une famille passe par {@see self::libelleDeFamille()}, puisque son
     * libellé est fait du nom de ses deux époux. Une citation, une note ou une source n'ont
     * pas de nom de personne à cacher : leur libellé passe.
     */
    public function libelle(?SubjectKind $kind, ?int $id, ?string $libelle): ?string
    {
        if (null === $kind || null === $id || null === $libelle) {
            return $libelle;
        }

        if (SubjectKind::Famille === $kind) {
            return $this->libelleDeFamille($id, $libelle);
        }

        return $this->masquee($kind, $id) ? CercleDeVisibiliteInterface::NOM_MASQUE : $libelle;
    }

    /**
     * Le libellé d'un couple — « A × B » —, refait avec le masque à la place de chaque
     * époux masqué.
     *
     * Refait et non découpé : le libellé stocké est une photographie du dépôt, dont la
     * forme a pu changer, et découper une chaîne sur « × » se tromperait sur un nom qui en
     * contient un. Quand aucun époux n'est masqué — l'immense majorité —, le libellé stocké
     * passe tel quel, sans relecture.
     */
    public function libelleDeFamille(int $familleId, ?string $libelle): ?string
    {
        $epoux = $this->arbre->epouxDeFamille($familleId);
        $masques = array_filter($epoux, fn (int $id): bool => $this->masquee(SubjectKind::Personne, $id));

        if ([] === $masques) {
            return $libelle;
        }

        $noms = array_map(
            fn (int $id): string => \in_array($id, $masques, true)
                ? CercleDeVisibiliteInterface::NOM_MASQUE
                : ($this->arbre->chargerCible(SubjectKind::Personne, $id)->libelle ?? CercleDeVisibiliteInterface::NOM_MASQUE),
            $epoux,
        );

        return implode(' × ', $noms);
    }
}
