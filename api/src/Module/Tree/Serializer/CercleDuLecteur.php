<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Security\ReciprociteInterface;
use App\Module\Core\ValueObject\ComposantesDeDate;

/**
 * Le cercle de visibilité, **tel que la sérialisation le consulte**.
 *
 * Ce n'est pas le service qui calcule le cercle : la formule de l'ADR 0003 appartient à
 * `Membership`, et il n'en existe qu'une implémentation dans le produit
 * ({@see CercleDeVisibiliteInterface}). C'est l'objet que les normaliseurs reçoivent en
 * paramètre, et c'est **le seul endroit de `Tree` où le filtre s'applique** (§ 8 du
 * dossier de mission : « le cercle de visibilité est appliqué dans la sérialisation, pas
 * dans le contrôleur »).
 *
 * ## Pourquoi un objet à nous, et pas le contrat du noyau directement
 *
 * Pour une raison et une seule : **les normaliseurs doivent être testables sans
 * conteneur**. Un normaliseur qui recevrait le contrat du noyau exigerait, pour chaque
 * test, une session, un pare-feu et un `mbr_member`. En recevant celui-ci, le test de
 * liste blanche — la vérification la plus importante du produit — s'écrit en une ligne :
 * `CercleDuLecteur::de([17])`. La classe ne réimplémente **aucune** règle : elle en
 * ajoute exactement une, celle des personnes décédées, expliquée plus bas.
 *
 * ## La seule décision prise ici : le sort des personnes décédées
 *
 * Une personne décédée est un **fait historique**, visible de tout compte connecté sans
 * restriction (§ 6.2 du dossier, § 9.3 du modèle). 256 fiches sur 274 ne sont jamais
 * concernées par le cercle, et {@see self::voit()} les laisse passer **avant** d'
 * interroger qui que ce soit.
 *
 * Ce court-circuit a une conséquence qu'il faut connaître : quand le cercle du noyau est
 * `Core\Security\CercleFerme` — le défaut, qui refuse tout —, `Tree` continue de servir
 * les fiches des personnes décédées. Ce n'est pas une contradiction. `CercleFerme` masque
 * aussi les décédés parce qu'il n'a **aucun moyen d'établir** qu'ils le sont ; `Tree`, lui,
 * l'établit par {@see EtatVivant}. Le défaut fermé porte sur les **personnes vivantes**,
 * et il le reste : sans adaptateur, aucune d'elles n'est visible.
 *
 * ## Révision du 2026-10-01 : ce que « voir » veut dire en production
 *
 * En production, {@see self::voit()} délègue au contrat du noyau, qui répond désormais
 * « oui » pour **tout vivant non confidentiel** quand le lecteur est en régime `Cercle`
 * (ADR 0003, « les vivants visibles des membres ») : une fiche réduite, pour un membre,
 * n'est plus qu'une fiche à confidentialité forcée hors de son cercle. La construction
 * pré-résolue {@see self::de()}, réservée aux tests des normaliseurs, ne connaît pas le
 * drapeau et garde l'ancienne lecture « dans la liste ou masqué » : c'est ce dont ces
 * tests ont besoin pour prouver la liste blanche, qui sert toujours.
 *
 * ## Le régime `Aucun` n'est pas un cercle vide
 *
 * Un compte en attente de validation n'a **pas de cercle du tout** : aucune personne
 * vivante ne franchit le test, y compris celle à laquelle il prétend se rattacher. Ce
 * n'est surtout pas « un cercle avec `N = 0` » — par la formule, `A(u) = { u }`, donc
 * après la passe des conjoints un `N = 0` contiendrait `u` **et son conjoint**, exactement
 * les deux fiches vivantes qu'on veut fermer (§ 12, ADR 0003).
 */
final readonly class CercleDuLecteur
{
    /**
     * @param array<int, true>                 $personnes ensemble d'identifiants pré-résolu, utilisé
     *                                                    seulement quand `$delegue` est nul
     * @param CercleDeVisibiliteInterface|null $delegue   le contrat du noyau, qui résout le lecteur
     *                                                    lui-même — bascule « voir comme un membre »
     *                                                    comprise
     */
    private function __construct(
        public Regime $regime,
        private array $personnes,
        private ?CercleDeVisibiliteInterface $delegue = null,
        private ?ReciprociteInterface $reciprocite = null,
        /** @var array<int, ComposantesDeDate>|null réciprocité pré-résolue, pour les tests */
        private ?array $partages = null,
        private bool $photo = true,
        /** @var array<int, true>|null hors périmètre pré-résolu, pour les tests ; `null` : aucun */
        private ?array $horsPerimetre = null,
    ) {
    }

    /**
     * Pour les tests : les personnes d'un **autre arbre** que celui du lecteur (révision du
     * 2026-10-04 de l'ADR 0003). Sans cet appel, un cercle pré-résolu n'a qu'un arbre.
     *
     * @param list<int> $personneIds
     */
    public function avecHorsPerimetre(array $personneIds): self
    {
        return new self($this->regime, $this->personnes, $this->delegue, $this->reciprocite, $this->partages, $this->photo, array_fill_keys($personneIds, true));
    }

    /**
     * La personne existe-t-elle pour ce lecteur — est-elle dans son **périmètre** : l'arbre
     * de sa fiche, plus les arbres qu'aucun compte ne tient (révision du 2026-10-04 de
     * l'ADR 0003, « un arbre par famille ») ?
     *
     * `false` : la personne **n'est pas servie du tout** — ni nœud masqué, ni fiche
     * réduite, ni référence : `404` sur ses routes, absente des listes, défunte comprise.
     * C'est ce qui sépare cette question de {@see self::voit()}, qui masque des champs
     * d'une fiche gardée à sa place. Une personne sans identifiant (proposée, pas encore
     * en base) n'appartient qu'aux demandes du lecteur : elle y est.
     */
    public function connait(?int $personneId): bool
    {
        if (null === $personneId) {
            return true;
        }

        if (null !== $this->delegue) {
            return $this->delegue->dansLePerimetre($personneId);
        }

        return !isset($this->horsPerimetre[$personneId]);
    }

    /**
     * Le cas de production : on interroge le contrat unique du noyau — et, pour la
     * naissance et le portrait des vivants, celui de la réciprocité (ADR 0016).
     */
    public static function delegue(CercleDeVisibiliteInterface $cercle, ?ReciprociteInterface $reciprocite = null): self
    {
        return new self($cercle->regime(), [], $cercle, $reciprocite);
    }

    /**
     * Pour les tests des normaliseurs : la même construction, avec une réciprocité
     * pré-résolue. Une personne vivante absente de `$partages` ne partage rien.
     *
     * @param array<int, ComposantesDeDate> $partages ce que le lecteur voit de chaque vivant
     * @param bool                          $photo    le lecteur a-t-il une photo de lui ?
     */
    public function avecPartage(array $partages, bool $photo = true): self
    {
        return new self($this->regime, $this->personnes, $this->delegue, $this->reciprocite, $partages, $photo, $this->horsPerimetre);
    }

    /**
     * Ce que le lecteur voit de la **naissance** de cette personne (ADR 0016). Tout pour une
     * personne décédée ; pour un vivant, ce que la réciprocité accorde.
     *
     * Un cercle pré-résolu sans {@see avecPartage()} — les tests antérieurs à la
     * réciprocité — garde l'ancienne lecture : tout ce que le cercle montre. En production,
     * {@see FabriqueDuCercle} branche toujours la réciprocité.
     */
    public function naissance(?int $personneId, bool $vivante): ComposantesDeDate
    {
        if (!$vivante) {
            return ComposantesDeDate::tout();
        }

        if (null === $personneId) {
            return ComposantesDeDate::aucune();
        }

        if (null !== $this->partages) {
            return $this->partages[$personneId] ?? ComposantesDeDate::aucune();
        }

        return null !== $this->reciprocite
            ? $this->reciprocite->naissance($personneId)
            : (null !== $this->delegue ? ComposantesDeDate::aucune() : ComposantesDeDate::tout());
    }

    /** Le portrait de cette personne peut-il être servi net (ADR 0016) ? */
    public function portraitNet(?int $personneId, bool $vivante): bool
    {
        if (!$vivante) {
            return true;
        }

        if (null !== $this->partages) {
            return $this->photo;
        }

        return null !== $this->reciprocite
            ? null !== $personneId && $this->reciprocite->portraitNet($personneId)
            : null === $this->delegue;
    }

    /** L'empreinte de la réciprocité, pour les `ETag` ; vide sans réciprocité branchée. */
    public function empreinteDeLaReciprocite(): string
    {
        if (null !== $this->partages) {
            $lignes = [];
            foreach ($this->partages as $id => $partage) {
                $lignes[] = $id.':'.$partage->masque();
            }
            sort($lignes);

            return hash('sha256', implode(',', $lignes).'|'.($this->photo ? 'p' : '-'));
        }

        return $this->reciprocite?->empreinte() ?? '';
    }

    /**
     * Le régime distinct du compte en attente de validation : **pas de cercle**.
     *
     * C'est aussi ce qu'on écrit dans un test pour signifier « ce lecteur ne voit aucune
     * personne vivante », et une seule construction garantit que les deux situations ne
     * divergeront jamais.
     */
    public static function sansCercle(): self
    {
        return new self(Regime::Aucun, []);
    }

    /**
     * Un cercle **déjà résolu**, pour les tests et pour un appelant qui possède déjà la
     * liste.
     *
     * @param list<int> $personneIds au plus 60 sur les données réelles (§ 9.3 du modèle),
     *                               conjoints compris — ajoutés en **une seule passe** par
     *                               le service qui l'a calculé, jamais par un point fixe
     */
    public static function de(array $personneIds): self
    {
        $ensemble = [];

        foreach ($personneIds as $id) {
            $ensemble[$id] = true;
        }

        return new self(Regime::Cercle, $ensemble);
    }

    /**
     * Le lecteur a-t-il le droit de voir **les données protégées** de cette personne —
     * dates, lieux, sources, citations, médias, portrait ?
     *
     * `false` ne veut pas dire « le nœud disparaît » : hors cercle, une personne vivante
     * expose encore les sept clés de la liste blanche et sa position dans le graphe.
     *
     * `$vivante` vient de {@see EtatVivant} — la propriété calculée du § 9.2, jamais une
     * colonne.
     */
    public function voit(?int $personneId, bool $vivante): bool
    {
        // Hors périmètre, rien — et avant la règle des défunts (révision du 2026-10-04).
        if (!$this->connait($personneId)) {
            return false;
        }

        if (!$vivante) {
            return true;
        }

        if (null === $personneId) {
            // Une personne vivante sans identifiant n'est pas encore en base : rien ne
            // permet de l'avoir dans un cercle, et l'ignorance ferme.
            return false;
        }

        if (null !== $this->delegue) {
            return $this->delegue->voitPersonne($personneId);
        }

        return Regime::Cercle === $this->regime && isset($this->personnes[$personneId]);
    }

    public function niveau(?int $personneId, bool $vivante): NiveauDeVisibilite
    {
        return $this->voit($personneId, $vivante)
            ? NiveauDeVisibilite::Complete
            : NiveauDeVisibilite::Restreinte;
    }

    /**
     * L'empreinte du **cercle effectif** — la seconde composante de l'`ETag` du graphe
     * (§ 5.2 du contrat d'API).
     *
     * ```
     * empreinte_du_cercle_effectif = sha256( regime || ':' || implode(',', identifiants_triés) )
     * ```
     *
     * ## La liste, jamais la taille
     *
     * Deux cercles de 35 personnes ne sont pas le même cercle. Une empreinte fondée sur
     * le compte les confondrait — c'est-à-dire referait exactement le défaut que cette
     * méthode existe pour fermer : un `ETag` identique pour deux représentations
     * différentes.
     *
     * Le coût est une concaténation d'entiers : **mesuré** sur les trois comptes réels,
     * la liste compte 274, 270 et 256 entrées — un peu plus de 1,5 ko de chaîne, hachés
     * une fois par requête de graphe. L'ordre vient du tri, jamais du moteur : deux
     * cercles identiques rendus dans deux ordres différents donneraient deux `ETag`, donc
     * un retéléchargement de 124 ko pour rien.
     *
     * ## Le régime entre dans le haché, en plus des identifiants
     *
     * **Il est aujourd'hui redondant, et il reste.** Mesuré sur les trois comptes réels de
     * la pile, les ensembles de fiches servies **complètes** sont déjà tous distincts —
     * 274 pour l'administrateur, 270 pour le membre actif, 256 pour le compte en attente :
     * la liste seule suffirait à les séparer. La redondance est assumée pour deux raisons,
     * et aucune des deux n'est « par prudence » :
     *
     * - c'est la formule du § 5.2, écrite telle quelle. Une implémentation qui
     *   « simplifie » une formule de confidentialité oblige à rouvrir l'arbitrage à chaque
     *   relecture ;
     * - la redondance n'est pas structurelle. Un membre actif dont le cercle ne contient
     *   aucune personne **vivante** verrait exactement les mêmes fiches qu'un compte en
     *   attente — le cas n'est pas théorique, c'est celui d'un rattachement à une personne
     *   dont toute la parenté proche est décédée. Le régime est alors le seul écart, et il
     *   en reste un : le calque en attente se referme en régime `Aucun`
     *   ({@see LecteurDeLArbre::pourLeCalque()}).
     *
     * ## Ce que `$population` fait ici
     *
     * Le contrat du noyau ne sait pas énumérer un cercle : il répond « celui-ci ? » et
     * « parmi ceux-ci, lesquels ? ». On lui présente donc la population de l'arbre
     * ({@see PopulationDeLArbre}) et on garde ce qu'il en retient.
     *
     * L'ensemble ainsi obtenu est, en production, **le cercle ∪ les personnes décédées** :
     * l'implémentation du noyau laisse passer un fait historique avant même de regarder
     * le cercle, exactement comme {@see self::voit()} le fait ici. C'est un sur-ensemble
     * de « la liste des identifiants du cercle » qu'énonce le § 5.2, et c'est le bon : ce
     * qui distingue deux charges du graphe, c'est l'ensemble des fiches servies
     * **complètes**, et c'est précisément celui-là. Le § 5.2 tranche d'ailleurs le sens du
     * doute en une phrase : *quand on hésite, on distingue.*
     *
     * Sur un cercle pré-résolu ({@see self::de()}), l'ensemble rendu est la liste reçue,
     * sans les décédées : cette construction ne sait pas qui est vivant, pas plus que
     * `voit()` ne le sait sans qu'on le lui dise. Les deux formes répondent à la même
     * question avec ce qu'elles savent, et aucune n'est utilisée là où l'autre l'est.
     *
     * @param list<int> $population les identifiants de toutes les personnes de l'arbre,
     *                              triés — c'est le domaine sur lequel le cercle est
     *                              interrogé, pas un filtre
     */
    public function empreinte(array $population): string
    {
        $identifiants = null !== $this->delegue
            ? $this->delegue->filtrerPersonnes($population)
            : array_values(array_filter(
                $population,
                fn (int $id): bool => Regime::Cercle === $this->regime && isset($this->personnes[$id]) && $this->connait($id),
            ));

        sort($identifiants);

        return hash('sha256', $this->regime->value.':'.implode(',', $identifiants));
    }
}
