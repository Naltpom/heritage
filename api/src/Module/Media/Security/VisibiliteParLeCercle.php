<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Media\Enum\MediaSubject;

/**
 * **L'adaptateur : le seul fichier de `Media` qui connaisse le contrat du noyau.**.
 *
 * Il fait exactement deux choses, et surtout pas une troisième :
 *
 * 1. il **traduit** un sujet de média en identifiants de personnes, en demandant la
 *    traduction à `Tree` ({@see ResolutionDuSujetInterface}) ;
 * 2. il **transmet** la question à `Core\Security\CercleDeVisibiliteInterface` et rend
 *    sa réponse.
 *
 * Il ne juge de rien. Aucune ligne de ce fichier ne sait ce qu'est une personne vivante,
 * combien de générations remonte le cercle, ni ce que voit un compte en attente. Si une
 * telle ligne y apparaît un jour, c'est une seconde définition de la confidentialité qui
 * naît — et c'est précisément ce que le port du noyau existe pour empêcher.
 *
 * ## La seule règle qu'il applique, et pourquoi ce n'en est pas une
 *
 * **Un sujet est visible si toutes les personnes qu'il engage le sont.** Une preuve
 * d'union en dit autant sur l'un des époux que sur l'autre : la masquer dès que l'un des
 * deux est masqué n'est pas une règle de confidentialité supplémentaire, c'est la même
 * règle appliquée à une donnée qui en concerne deux. L'alternative — visible si *au
 * moins un* est visible — publierait les dates de l'époux masqué sous le prétexte que sa
 * femme est dans le cercle.
 *
 * Un sujet qui n'engage **personne** n'est pas visible non plus. C'est le cas d'un
 * résultat de résolution vide, qui ne devrait pas se produire ; le traiter comme « rien
 * à protéger, donc ouvert » ferait d'un bogue de `Tree` une fuite.
 *
 * ## Un seul aller-retour par sujet
 *
 * `filtrerPersonnes()` plutôt qu'une boucle sur `voitPersonne()` : le noyau documente que
 * la méthode existe pour éviter N résolutions de cercle là où une suffit. Une union en
 * demande deux, une fiche de personne en aligne une dizaine de médias — la boucle se
 * paierait vite.
 */
final class VisibiliteParLeCercle implements VisibiliteDesSujetsInterface
{
    public function __construct(
        private readonly CercleDeVisibiliteInterface $cercle,
        private readonly ResolutionDuSujetInterface $resolution,
    ) {
    }

    public function regimeDuLecteur(): Regime
    {
        return $this->cercle->regime();
    }

    public function decrire(MediaSubject $kind, int $id): ?SujetMedia
    {
        $resolu = $this->resolution->resoudre($kind, $id);

        if (null === $resolu || !$this->autorise($resolu->personIds)) {
            // « N'existe pas » et « pas le droit » rendent la même chose : l'appelant
            // rendra `404` dans les deux cas, et la réponse n'apprend donc pas à un
            // lecteur hors cercle quelles personnes existent (§ 15.3 du contrat).
            return null;
        }

        return new SujetMedia($kind, $id, $resolu->libelle);
    }

    public function estVisible(MediaSubject $kind, int $id): bool
    {
        $resolu = $this->resolution->resoudre($kind, $id);

        return null !== $resolu && $this->autorise($resolu->personIds);
    }

    public function filtrerVisibles(MediaSubject $kind, array $ids): array
    {
        $uniques = array_values(array_unique($ids));
        // **Une seule résolution** pour tous les sujets : une par sujet coûtait 2,5 s au
        // graphe, qui pose la question pour 2 515 personnes (24/10/2026).
        $engagees = $this->resolution->personnesEngagees($kind, $uniques);
        $parSujet = [];
        $toutes = [];

        foreach ($uniques as $id) {
            $personIds = $engagees[$id] ?? [];

            // Un sujet inconnu, ou qui n'engage personne, n'est pas visible — même
            // raison que dans {@see autorise()} : « rien à protéger » ne vaut pas
            // « ouvert ».
            if ([] === $personIds) {
                continue;
            }

            $parSujet[$id] = $personIds;

            foreach ($personIds as $personId) {
                $toutes[$personId] = $personId;
            }
        }

        if ([] === $toutes) {
            return [];
        }

        // **Un seul appel au cercle**, pour l'union de toutes les personnes engagées.
        $visibles = array_flip($this->cercle->filtrerPersonnes(array_values($toutes)));

        $retenus = [];

        foreach ($parSujet as $id => $personIds) {
            foreach ($personIds as $personId) {
                if (!isset($visibles[$personId])) {
                    // Un seul époux masqué suffit à masquer l'union : la règle est la
                    // même que sur un sujet isolé, et elle doit le rester — deux chemins
                    // qui répondent différemment à la même question, c'est le jour où
                    // la liste laisse passer ce que le détail refuse.
                    continue 2;
                }
            }

            $retenus[] = $id;
        }

        return $retenus;
    }

    /** @param list<int> $personIds */
    private function autorise(array $personIds): bool
    {
        if ([] === $personIds) {
            return false;
        }

        $attendus = array_values(array_unique($personIds));

        return \count($this->cercle->filtrerPersonnes($attendus)) === \count($attendus);
    }
}
