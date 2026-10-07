<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Membership\Service\Port\FamilleDeLArbre;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\PortArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'implémentation, côté `Tree`, de {@see PortArbre} — ce que `Membership` lit de l'arbre
 * pour calculer un cercle de visibilité et une recherche de rattachement.
 *
 * C'est une **traduction, pas un calcul**. Les trois choses demandées existent déjà :
 * l'adjacence dans `tree_family` et `tree_child`, l'état vivant dans
 * {@see ReglePersonneVivante}, l'empreinte dans {@see TreeFingerprint}. Ce fichier les
 * met dans le vocabulaire de `Membership`, et rien d'autre.
 *
 * ## La règle qu'il ne faut surtout pas « améliorer »
 *
 * **L'arbre rendu est COMPLET, sans aucun filtre de confidentialité.** C'est
 * contre-intuitif pour un port qui sert justement la confidentialité, et c'est pourtant la
 * seule forme juste : le cercle de `u` contient des personnes que le **lecteur** n'a pas
 * le droit de voir — c'est même tout l'objet de la bascule « voir comme un membre », qui
 * calcule le cercle de quelqu'un d'autre. Filtrer ici donnerait un cercle amputé, donc
 * faux ; et un cercle faux est une fuite ou une fiche muette selon le sens de l'erreur.
 * Le filtrage a lieu **une seule fois, dans la sérialisation** (§ 6.2 du dossier).
 *
 * ## Pourquoi on ne passe pas par `FournisseurDeJeuDePlacement`
 *
 * Il charge exactement les mêmes lignes, et le réflexe serait de le réutiliser. Mais il
 * substitue `'?'` au prénom vide — `pre or "?"` dans `graphe.py`, pour qu'une fiche sans
 * prénom mesure quand même un caractère. Cette substitution est **juste pour mesurer une
 * boîte et fausse pour composer un libellé** : le § 4.2 du contrat d'API interdit
 * explicitement le `?. Romarin` qui en sortirait, « le point isolé en dirait autant
 * qu'une absence tout en étant plus laid ». On relit donc les prénoms bruts.
 *
 * L'état vivant, en revanche, passe bien par {@see ReglePersonneVivante} : **il n'existe
 * qu'une définition de « vivant » dans le produit**, et c'est elle. Une seconde, écrite
 * ici, déciderait un jour autrement de ce que la sérialisation masque.
 *
 * ## Mémorisé pour la durée de la requête
 *
 * `Membership` pose la question à chaque cercle manquant du cache, et le graphe interroge
 * le cercle 274 fois. Recharger l'arbre à chaque fois coûterait 274 × 551 lignes pour un
 * résultat identique.
 */
final class VueDeLArbrePourMembership implements PortArbre
{
    private ?VueDeLArbre $memoire = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglePersonneVivante $regleVivante,
        private readonly ReglagesDeLArbre $reglages,
        private readonly TreeFingerprint $empreinte,
        private readonly EtiquetteDeDateGedcom $dates = new EtiquetteDeDateGedcom(),
        private readonly ChronometreDuGraphe $chrono = new ChronometreDuGraphe(),
    ) {
    }

    public function vue(): VueDeLArbre
    {
        return $this->memoire ??= $this->chrono->mesure('cercle', fn (): VueDeLArbre => $this->charger());
    }

    public function empreinte(): string
    {
        return $this->empreinte->empreinte();
    }

    private function charger(): VueDeLArbre
    {
        $personnes = $this->chargeLesPersonnes();

        // Toute la population d'un coup : le repli du § 9.2 passe par la médiane des
        // années de naissance de chaque génération, qui est un calcul global.
        $vivants = $this->regleVivante->pourTous(
            $personnes,
            $this->reglages->seuilVivantAnnees(),
            new \DateTimeImmutable(),
        );

        $traduites = [];

        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null === $id) {
                continue;
            }

            $naissance = $personne->getBirth();

            $traduites[] = new PersonneDeLArbre(
                id: $id,
                // Les prénoms **bruts** : ni `'?'`, ni nom d'usage. Voir l'en-tête.
                prenoms: $personne->getGivenNames(),
                nom: $personne->getSurname(),
                libelle: $personne->getDisplayName(),
                anneeNaissance: $this->anneeDeNaissance($personne),
                etiquetteNaissance: $this->dates->etiquette($naissance),
                // « Un décès inconnu vaut vivant, donc masqué » : c'est la règle qui
                // tranche, jamais l'absence de date lue ici.
                vivante: $vivants[$id] ?? true,
                // Le drapeau brut : c'est lui, et non l'état vivant, qui ferme encore une
                // fiche à un membre validé (ADR 0003, révision du 2026-10-01).
                confidentielle: $personne->isForcePrivacy(),
            );
        }

        return new VueDeLArbre($traduites, $this->chargeLesFamilles());
    }

    /**
     * L'année de naissance telle qu'un humain l'énoncerait — le premier millésime de la
     * chaîne GEDCOM, où qu'il soit, et `birth_min` seulement à défaut.
     *
     * La nuance n'est pas décorative, et c'est la même que celle du fournisseur de
     * placement : `BEF 1920` n'a pas de borne basse — `birth_min` est nul — mais l'année
     * que le candidat au rattachement écrira sur le formulaire est bien 1920.
     */
    private function anneeDeNaissance(Person $personne): ?int
    {
        $naissance = $personne->getBirth();
        $brut = $this->dates->annee($naissance->getRaw());

        if (null !== $brut) {
            return $brut;
        }

        $min = $naissance->getMin();

        return null === $min ? null : (int) $min->format('Y');
    }

    /** @return list<Person> */
    private function chargeLesPersonnes(): array
    {
        /** @var list<Person> $personnes */
        $personnes = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->orderBy('p.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $personnes;
    }

    /**
     * Les unions, avec leurs époux et leurs enfants — en **identifiants nus**, que
     * {@see VueDeLArbre} dérive ensuite en parents, enfants et conjoints.
     *
     * On rend les familles telles quelles et on ne dérive rien ici : c'est le constructeur
     * de `VueDeLArbre` qui possède la définition de « conjoint » — *deux personnes qui
     * figurent toutes deux dans `epoux` d'une même famille* —, et c'est cette nuance qui
     * décide de la dernière passe de la formule de l'ADR 0003, celle qui « avale la moitié
     * de l'arbre au premier remariage » si on la prend de travers.
     *
     * @return list<FamilleDeLArbre>
     */
    private function chargeLesFamilles(): array
    {
        /** @var list<Family> $familles */
        $familles = $this->em->createQueryBuilder()
            ->select('f', 'h', 'w')
            ->from(Family::class, 'f')
            // Jointures explicites : sans elles, chaque `getHusband()` déclenche une
            // requête, soit 206 allers-retours pour 103 familles.
            ->leftJoin('f.husband', 'h')
            ->leftJoin('f.wife', 'w')
            ->orderBy('f.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $enfants = $this->chargeLesEnfants();
        $sortie = [];

        foreach ($familles as $famille) {
            $id = $famille->getId();

            if (null === $id) {
                continue;
            }

            $epoux = [];
            foreach ([$famille->getHusband(), $famille->getWife()] as $personne) {
                $epouxId = $personne?->getId();

                if (null !== $epouxId) {
                    $epoux[] = $epouxId;
                }
            }

            $sortie[] = new FamilleDeLArbre($epoux, $enfants[$id] ?? [], $famille->isDivorced());
        }

        return $sortie;
    }

    /** @return array<int, list<int>> */
    private function chargeLesEnfants(): array
    {
        /** @var list<Child> $liens */
        $liens = $this->em->createQueryBuilder()
            ->select('c', 'f', 'p')
            ->from(Child::class, 'c')
            ->join('c.family', 'f')
            ->join('c.person', 'p')
            ->orderBy('c.siblingOrder', \SortDirection::Ascending)
            ->addOrderBy('c.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $parFamille = [];

        foreach ($liens as $lien) {
            $familleId = $lien->getFamily()->getId();
            $personneId = $lien->getPerson()->getId();

            if (null !== $familleId && null !== $personneId) {
                $parFamille[$familleId][] = $personneId;
            }
        }

        return $parFamille;
    }
}
