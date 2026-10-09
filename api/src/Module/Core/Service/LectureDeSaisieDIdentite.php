<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\ValueObject\IdentiteComparee;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Le corps commun des deux routes de ressemblance — `POST /api/arbre/ressemblances`
 * (§ 5.5 bis) et `POST /api/demandes/brouillons/ressemblances` (§ 6.6 quater) :
 *
 * ```json
 * { "fiche": { "givenNames": "Pyroxène", "surname": "Tilleule", "marriedName": null,
 *              "nickname": null, "sex": "masculin", "deceased": false,
 *              "birth": { "raw": "ABT 1934", "qualifier": "environ", "min": "1934-01-01",
 *                         "max": "1934-12-31", "place": "Sorbière" },
 *              "death": null },
 *   "limite": 5 }
 * ```
 *
 * Lu **une fois, ici**, pour que les deux routes refusent les mêmes saisies avec les mêmes
 * mots : le formulaire « Ajouter une personne » les appelle en parallèle sur la même
 * saisie, et un `422` d'un côté avec un `200` de l'autre serait inexplicable.
 *
 * ## Les clés de `fiche` sont celles d'un brouillon de `creation_personne`
 *
 * Les noms de propriété Doctrine (§ 1.2) : le front envoie la fiche qu'il est en train de
 * composer, sans traduction. **Une clé inconnue est refusée** plutôt qu'ignorée : un
 * `birht` mal orthographié serait sinon une naissance silencieusement non comparée, et
 * l'écran montrerait des ressemblances qu'il croit fondées sur une date qui n'est jamais
 * partie. La note, les liens et les photos du formulaire ne font donc **pas** partie de ce
 * corps.
 *
 * `deceased` est accepté et **sans effet** : une case « décédé » non cochée ne prouve pas
 * qu'une personne vit, et la cocher ne dit rien que la date de décès ne dise mieux.
 *
 * ## Les deux refus de règle
 *
 * - **Un nom ou un nom d'usage d'au moins deux lettres.** Une lettre seule ramènerait un
 *   quart de l'arbre en candidats — même plancher que la recherche (§ 5.5).
 * - **Au moins un prénom** (ou un surnom). La règle exige un prénom en commun : sans
 *   prénom saisi, la réponse serait vide quoi qu'il arrive, et un `200` vide ferait croire
 *   au front que la personne n'existe pas.
 *
 * Toutes les erreurs de la fiche partent **ensemble**, dans un seul `422`, chacune sur son
 * champ (`fiche.surname`, `fiche.birth`…) : c'est un formulaire, et le corriger en trois
 * allers-retours serait trois fois pire.
 *
 * ## `limite` rabote, elle ne refuse pas
 *
 * 5 par défaut, 10 au plus, ramenée au plafond sans erreur — « un plafond qui lève une
 * 422 apprend à l'appelant où est la limite, un plafond qui rabote ne lui apprend rien et
 * sert le même résultat » (§ 1.5).
 */
final readonly class LectureDeSaisieDIdentite
{
    public const int LIMITE_PAR_DEFAUT = 5;
    public const int LIMITE_MAXIMALE = 10;

    /** Le plancher du nom, en lettres — celui de la recherche (§ 5.5). */
    public const int LETTRES_MINIMALES_DU_NOM = 2;

    /** Les clés de `fiche`, et elles seules. */
    private const array CLES = ['givenNames', 'surname', 'marriedName', 'nickname', 'sex', 'deceased', 'birth', 'death'];

    public function __construct(
        private LectureDeBlocDate $dates = new LectureDeBlocDate(),
        private ProximiteDeNoms $noms = new ProximiteDeNoms(),
    ) {
    }

    /**
     * La saisie, prête à comparer — ou un `422` qui dit tout ce qui ne va pas.
     *
     * @param array<array-key, mixed> $corps le corps JSON décodé
     */
    public function identite(array $corps): IdentiteComparee
    {
        $fiche = $corps['fiche'] ?? null;

        if (!\is_array($fiche)) {
            throw new ValidationException(['fiche' => 'La fiche à comparer est un objet : { givenNames, surname, marriedName, nickname, sex, deceased, birth, death }.']);
        }

        $erreurs = [];

        $inconnues = array_diff(array_keys($fiche), self::CLES);

        if ([] !== $inconnues) {
            $erreurs['fiche'] = \sprintf('Clés inattendues dans la fiche : %s.', implode(', ', array_map(strval(...), $inconnues)));
        }

        $prenoms = $this->texte($fiche, 'givenNames', $erreurs);
        $nom = $this->texte($fiche, 'surname', $erreurs);
        $nomDUsage = $this->texte($fiche, 'marriedName', $erreurs);
        $surnom = $this->texte($fiche, 'nickname', $erreurs);

        $sexe = Sex::Inconnu;
        $brut = $fiche['sex'] ?? null;

        if (null !== $brut) {
            $lu = \is_string($brut) ? Sex::tryFrom($brut) : null;

            if (null === $lu) {
                $erreurs['fiche.sex'] = 'Le sexe vaut « masculin », « feminin » ou « inconnu ».';
            } else {
                $sexe = $lu;
            }
        }

        if (null !== ($fiche['deceased'] ?? null) && !\is_bool($fiche['deceased'])) {
            $erreurs['fiche.deceased'] = 'Cette valeur doit être true, false ou null.';
        }

        $naissance = $this->date($fiche, 'birth', $erreurs);
        $deces = $this->date($fiche, 'death', $erreurs);

        // La règle ne parle que si le type a passé : un `surname` qui n'est pas une chaîne a
        // déjà son message, et lui en ajouter un second sur le même champ l'écraserait.
        if (!isset($erreurs['fiche.surname']) && !isset($erreurs['fiche.marriedName'])
            && !$this->nomSuffisant($nom) && !$this->nomSuffisant($nomDUsage)) {
            $erreurs['fiche.surname'] = \sprintf('Le nom ou le nom d\'usage doit compter au moins %d lettres pour chercher une ressemblance.', self::LETTRES_MINIMALES_DU_NOM);
        }

        if (!isset($erreurs['fiche.givenNames']) && !isset($erreurs['fiche.nickname'])
            && [] === $this->noms->jetons($prenoms ?? '') && [] === $this->noms->jetons($surnom ?? '')) {
            $erreurs['fiche.givenNames'] = 'Au moins un prénom est nécessaire pour chercher une ressemblance.';
        }

        if ([] !== $erreurs) {
            throw new ValidationException($erreurs);
        }

        return new IdentiteComparee(
            $prenoms ?? '',
            $nom ?? '',
            $nomDUsage,
            $surnom,
            $sexe,
            $naissance,
            $deces,
        );
    }

    /** Le plafond : 5 par défaut, 10 au plus, **ramené** plutôt que refusé (§ 1.5). */
    public function limite(mixed $demandee): int
    {
        if (null === $demandee || '' === $demandee || \is_bool($demandee)) {
            return self::LIMITE_PAR_DEFAUT;
        }

        $valeur = filter_var($demandee, \FILTER_VALIDATE_INT);

        if (false === $valeur || $valeur < 1) {
            return self::LIMITE_PAR_DEFAUT;
        }

        return min($valeur, self::LIMITE_MAXIMALE);
    }

    /**
     * @param array<array-key, mixed> $fiche
     * @param array<string, string>   $erreurs
     */
    private function texte(array $fiche, string $cle, array &$erreurs): ?string
    {
        $valeur = $fiche[$cle] ?? null;

        if (null === $valeur || \is_string($valeur)) {
            return $valeur;
        }

        $erreurs['fiche.'.$cle] = 'Cette valeur doit être une chaîne ou null.';

        return null;
    }

    /**
     * @param array<array-key, mixed> $fiche
     * @param array<string, string>   $erreurs
     */
    private function date(array $fiche, string $cle, array &$erreurs): ?UncertainDate
    {
        try {
            return $this->dates->depuisValeur($fiche[$cle] ?? null, 'fiche.'.$cle);
        } catch (ValidationException $erreur) {
            foreach ($erreur->champs() ?? [] as $champ => $message) {
                $erreurs[(string) $champ] = (string) $message;
            }

            return null;
        }
    }

    private function nomSuffisant(?string $nom): bool
    {
        return preg_match_all('/[A-Z]/', $this->noms->normaliser($nom ?? '')) >= self::LETTRES_MINIMALES_DU_NOM;
    }
}
