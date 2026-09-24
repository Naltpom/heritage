<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\Entity\Place;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * L'état du recours en ligne **pendant une exécution** de `heritage:resoudre-les-lieux` :
 * combien de requêtes sont parties, combien ont abouti, et si le tiers est tombé.
 *
 * Sorti de la commande pour une raison de lecture : la boucle de la commande dit **quand**
 * on demande en ligne ; ce petit objet dit **si l'on peut encore**, et il garde les trois
 * règles qui n'ont rien à faire dans une boucle — le plafond par exécution, le
 * coupe-circuit après une panne, et les trente jours après un échec.
 *
 * Construit avec `null`, il est **inerte** : il ne demande jamais rien. C'est l'état sans
 * `--en-ligne`, sous `--dry-run`, ou quand `HERITAGE_GEOCODAGE_EN_LIGNE=0` coupe tout — et
 * la boucle n'a pas à distinguer ces cas.
 *
 * Ce n'est pas un service : il ne vit que le temps d'une exécution, et un second appel de
 * la commande dans le même processus (les tests) doit repartir de zéro.
 */
final class RecoursEnLigne
{
    /** Trente jours sans redemander un lieu que Nominatim n'a pas su situer. */
    public const int JOURS_AVANT_NOUVELLE_DEMANDE = 30;

    /**
     * Le plafond de requêtes en ligne par exécution : à une par seconde, un peu plus de
     * trois minutes. Il borne la durée d'un déploiement comme la charge chez le tiers ; le
     * reste attend la nuit suivante, dans l'ordre des occurrences décroissantes.
     */
    public const int REQUETES_MAXIMUM = 200;

    private int $requetes = 0;
    private int $situes = 0;
    private int $introuvables = 0;
    private ?string $panne = null;

    public function __construct(private readonly ?GeocodeurEnLigne $geocodeur)
    {
    }

    /**
     * Peut-on demander ce lieu en ligne, **maintenant** ?
     *
     * Non si le recours est inerte, si le tiers est tombé plus tôt dans l'exécution, si le
     * plafond est atteint, ou si Nominatim a déjà dit « introuvable » pour cette ligne il y
     * a moins de trente jours.
     */
    public function peutDemander(?Place $ligne): bool
    {
        if (null === $this->geocodeur || null !== $this->panne || $this->plafondAtteint()) {
            return false;
        }

        if (null === $ligne || Place::SOURCE_NOMINATIM !== $ligne->getSource() || null !== $ligne->getLat()) {
            return true;
        }

        $limite = new \DateTimeImmutable(\sprintf('-%d days', self::JOURS_AVANT_NOUVELLE_DEMANDE));

        return $ligne->getResoluLe() < $limite;
    }

    public function plafondAtteint(): bool
    {
        return null !== $this->geocodeur && $this->requetes >= self::REQUETES_MAXIMUM;
    }

    /**
     * Demande un lieu. Trois issues, comme {@see GeocodeurEnLigne::chercher()} — à ceci
     * près qu'une panne ne lève plus : elle rend `false`, ouvre le coupe-circuit pour le
     * reste de l'exécution, et sera rapportée par {@see rendreCompte()}.
     *
     * @return array{libelle: string, commune: string|null, subdivision: string|null, pays: string|null, lat: float|null, lon: float|null, source: string, confiance: int, passe: string, candidats: list<string>}|false|null
     */
    public function demander(string $lieu): array|false|null
    {
        if (null === $this->geocodeur) {
            return false;
        }

        ++$this->requetes;

        try {
            $trouve = $this->geocodeur->chercher($lieu);
        } catch (GeocodageIndisponible $e) {
            $this->panne = $e->getMessage();

            return false;
        }

        if (null === $trouve) {
            ++$this->introuvables;
        } else {
            ++$this->situes;
        }

        return $trouve;
    }

    /** Rien à dire quand le recours n'a pas servi : la ligne de la commande l'a déjà dit. */
    public function rendreCompte(SymfonyStyle $style): void
    {
        if (null === $this->geocodeur) {
            return;
        }

        $style->definitionList(
            ['Requêtes envoyées à Nominatim' => $this->requetes],
            ['Situés en ligne' => $this->situes],
            ['Introuvables en ligne (non redemandés avant '.self::JOURS_AVANT_NOUVELLE_DEMANDE.' jours)' => $this->introuvables],
        );

        if (null !== $this->panne) {
            // Un avertissement, pas une erreur : rien n'est cassé côté produit. Les lieux
            // non demandés le seront à la prochaine exécution, et rien n'a été mémorisé
            // comme introuvable à cause de la panne.
            $style->warning('Nominatim est tombé pendant l’exécution — recours en ligne interrompu, les lieux restants seront redemandés à la prochaine exécution. Motif : '.$this->panne);
        }

        if ($this->plafondAtteint()) {
            $style->note(\sprintf('Plafond de %d requêtes atteint : les lieux restants attendent la prochaine exécution.', self::REQUETES_MAXIMUM));
        }
    }
}
