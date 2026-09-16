<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Service\Port\EtatCible;

/**
 * Le résultat du **contrôle préalable** : tout ce qu'il faut savoir avant d'écrire quoi
 * que ce soit.
 *
 * > « Le contrôle est **intégralement fait avant la première écriture**, décision de
 * > rejeu comprise. Une validation est tout ou rien. Un rejeu qui écrirait trois ops
 * > puis découvrirait une caducité sur le quatrième laisserait l'arbre dans un état que
 * > personne n'a approuvé — même annulé par un `ROLLBACK`, ce serait un motif de doute
 * > permanent. » (§ 9.4 du modèle de données.)
 *
 * Le même plan sert **deux appelants** : l'écran d'administration, qui l'affiche sans
 * rien écrire (§ 6.7), et le validateur, qui l'exécute (§ 6.8). Un seul calcul pour les
 * deux, sans quoi le bandeau annoncerait un rejeu et le clic produirait une caducité —
 * ou l'inverse.
 */
final readonly class PlanDeValidation
{
    /**
     * @param list<EtatDOp>                      $ops       dans l'ordre de `seq`
     * @param array<string, EtatCible>           $cibles    indexées par {@see EtatCible::cleDe()}
     * @param list<array{kind: string, id: int}> $disparues lignes visées qui n'existent plus
     */
    public function __construct(
        public ResolutionPrevue $resolution,
        public array $ops,
        public array $cibles,
        public array $disparues = [],
    ) {
    }

    /**
     * Les champs qui ont bougé sous les pieds de l'auteur — la charge de
     * `erreur.details.champs` du § 6.8, et du corps de la notification de caducité.
     *
     * Les deux doivent dire exactement la même chose : l'auteur qui lit sa notification
     * et l'administrateur qui regarde l'écran regardent le même conflit.
     *
     * @return list<array{field: string, propose: mixed, valeurALaSoumission: mixed, valeurCourante: mixed}>
     */
    public function champsEnConflit(NormaliseurDeValeur $normaliseur): array
    {
        $champs = [];

        foreach ($this->ops as $etat) {
            if (!$etat->aBouge) {
                continue;
            }

            $champ = $etat->op->getField();
            if (null === $champ) {
                continue;
            }

            $nature = $etat->op->getValueKind();

            $champs[] = [
                'field' => $champ,
                'propose' => $normaliseur->pourAffichage($nature, $etat->op->getNewValue()),
                'valeurALaSoumission' => $normaliseur->pourAffichage($nature, $etat->op->getOldValue()),
                'valeurCourante' => $normaliseur->pourAffichage($nature, $etat->valeurCourante),
            ];
        }

        return $champs;
    }

    /**
     * Les champs vérifiés par le rejeu — repris tels quels dans `rejeu.champsVerifies`
     * (§ 6.8). Ils disent ce que le rejeu a réellement confronté, et c'est ce qui rend
     * la trace `demande_rejouee` lisible six mois plus tard.
     *
     * @return list<string>
     */
    public function champsVerifies(): array
    {
        $champs = [];

        foreach ($this->ops as $etat) {
            $champ = $etat->op->getField();

            if (null !== $champ) {
                $champs[] = $champ;
            }
        }

        return $champs;
    }

    /** La version la plus haute vue à la soumission, pour le bandeau `cible` du § 6.7. */
    public function versionALaSoumission(): ?int
    {
        $versions = [];

        foreach ($this->ops as $etat) {
            $version = $etat->op->getBaseVersion();

            if (null !== $version) {
                $versions[] = $version;
            }
        }

        return [] === $versions ? null : max($versions);
    }

    public function uneVersionABouge(): bool
    {
        foreach ($this->ops as $etat) {
            if ($etat->versionABouge) {
                return true;
            }
        }

        return false;
    }
}
