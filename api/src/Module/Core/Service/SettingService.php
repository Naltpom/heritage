<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lecture et écriture de `core_setting` — les réglages qu'un administrateur change
 * **sans redéploiement** (§ 2.2 du modèle, § 10.2 du contrat).
 *
 * Le plus important d'entre eux est `cercle.generations`, le `N` du cercle de visibilité.
 * L'ADR 0003 explique pourquoi il n'est pas une constante : « un chiffre qu'on découvre
 * faux et qu'on ne peut changer qu'en redéployant sera changé en dur dans le code par le
 * premier qui passe, sans trace et sans discussion ».
 *
 * **Toute la table est chargée d'un coup** et gardée en mémoire pour la durée de la
 * requête : une vingtaine de lignes, et une lecture de réglage se produit dans presque
 * tous les chemins de code. Pas de cache persistant — il faudrait l'invalider, et une
 * invalidation oubliée sur `cercle.generations` est une fuite, pas un ralentissement.
 *
 * Le service **caste à la lecture** selon `valueType` et **refuse à l'écriture** une
 * valeur qui ne caste pas, ou qui sort de `[minValue, maxValue]`. La colonne est un
 * `TEXT` ; le contrat, lui, est typé.
 */
final class SettingService
{
    public const string CLE_CERCLE_GENERATIONS = 'cercle.generations';

    /**
     * Repli si la ligne manque — base non migrée, restauration partielle. Il vaut le
     * défaut de la migration (§ 2.2 du modèle) : une lecture de réglage ne doit jamais
     * faire tomber une requête, et surtout pas en ouvrant le cercle.
     */
    public const int DEFAUT_CERCLE_GENERATIONS = 3;

    /** @var array<string, Setting>|null Toute la table, pour la durée de la requête. */
    private ?array $parCle = null;

    public function __construct(
        private readonly SettingRepository $depot,
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
    ) {
    }

    /** Le `N` de la formule de l'ADR 0003. */
    public function generationsDuCercle(): int
    {
        return $this->entier(self::CLE_CERCLE_GENERATIONS, self::DEFAUT_CERCLE_GENERATIONS);
    }

    public function entier(string $cle, int $defaut): int
    {
        $reglage = $this->trouver($cle);

        if (null === $reglage || SettingType::Entier !== $reglage->getValueType()) {
            return $defaut;
        }

        $valeur = filter_var($reglage->getValue(), \FILTER_VALIDATE_INT);

        return false === $valeur ? $defaut : $valeur;
    }

    public function texte(string $cle, string $defaut): string
    {
        return $this->trouver($cle)?->getValue() ?? $defaut;
    }

    public function booleen(string $cle, bool $defaut): bool
    {
        $reglage = $this->trouver($cle);

        if (null === $reglage || SettingType::Booleen !== $reglage->getValueType()) {
            return $defaut;
        }

        return filter_var($reglage->getValue(), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $defaut;
    }

    /**
     * @param array<mixed> $defaut
     *
     * @return array<mixed>
     */
    public function json(string $cle, array $defaut): array
    {
        $reglage = $this->trouver($cle);

        if (null === $reglage || SettingType::Json !== $reglage->getValueType()) {
            return $defaut;
        }

        $decode = json_decode($reglage->getValue(), true);

        return \is_array($decode) ? $decode : $defaut;
    }

    /** @return list<Setting> */
    public function tous(): array
    {
        return array_values($this->charger());
    }

    public function trouver(string $cle): ?Setting
    {
        return $this->charger()[$cle] ?? null;
    }

    /**
     * La charge utile d'un réglage, `value` **castée** selon `valueType` (§ 10.2) : un
     * `entier` sort en nombre JSON, jamais en texte. Sinon chaque écran refait le cast à
     * sa façon, et l'un d'eux le fera mal.
     *
     * @return array<string, mixed>
     */
    public function normaliser(Setting $reglage): array
    {
        return [
            'key' => $reglage->getSettingKey(),
            'value' => $this->valeurCastee($reglage),
            'valueType' => $reglage->getValueType()->value,
            'label' => $reglage->getLabel(),
            'description' => $reglage->getDescription(),
            'minValue' => $reglage->getMinValue(),
            'maxValue' => $reglage->getMaxValue(),
            'updatedByEmail' => $reglage->getUpdatedByEmail(),
            'updatedAt' => $reglage->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    public function valeurCastee(Setting $reglage): mixed
    {
        $brut = $reglage->getValue();

        return match ($reglage->getValueType()) {
            SettingType::Entier => (int) $brut,
            SettingType::Booleen => filter_var($brut, \FILTER_VALIDATE_BOOL),
            SettingType::Json => json_decode($brut, true),
            SettingType::Texte => $brut,
        };
    }

    /**
     * `PATCH /api/administration/reglages/{cle}`.
     *
     * Trois refus, dans cet ordre : clé inconnue → `404` (on n'apprend pas à l'appelant
     * quelles clés existent) ; valeur qui ne caste pas → `422 donnees_invalides` ; valeur
     * hors bornes → `422 valeur_hors_bornes`, **avec les bornes dans `details`**. « Au-delà
     * de 6 le cercle couvre l'arbre entier et la règle ne protège plus personne » : la
     * borne n'est pas décorative.
     *
     * @throws NotFoundException   clé inconnue
     * @throws ValidationException valeur invalide ou hors bornes
     */
    public function modifier(string $cle, mixed $valeur, ?User $auteur = null): Setting
    {
        $reglage = $this->trouver($cle)
            ?? throw new NotFoundException('Ce réglage n’existe pas.');

        $ancienne = $this->valeurCastee($reglage);
        $texte = $this->serialiser($reglage, $valeur);

        $reglage->setValue($texte)->setUpdatedBy($auteur?->getId(), $auteur?->getEmail());
        $this->em->flush();

        $this->audit->enregistrer(
            AuditAction::ReglageModifie,
            'Core\\Setting',
            (string) $reglage->getId(),
            $reglage->getSettingKey(),
            [$reglage->getSettingKey() => ['avant' => $ancienne, 'apres' => $this->valeurCastee($reglage)]],
            $auteur,
        );

        return $reglage;
    }

    /**
     * Valide puis rend la forme texte à stocker. Le cast est **strict** : « 4 » est un
     * entier, « 4.5 » et « quatre » n'en sont pas.
     */
    private function serialiser(Setting $reglage, mixed $valeur): string
    {
        return match ($reglage->getValueType()) {
            SettingType::Entier => (string) $this->entierDansLesBornes($reglage, $valeur),
            SettingType::Booleen => $this->booleenValide($valeur) ? 'true' : 'false',
            SettingType::Texte => \is_string($valeur)
                ? $valeur
                : throw new ValidationException(['value' => 'Une chaîne de caractères est attendue.']),
            SettingType::Json => $this->jsonValide($valeur),
        };
    }

    private function entierDansLesBornes(Setting $reglage, mixed $valeur): int
    {
        // `is_bool` d'abord : `filter_var(true, FILTER_VALIDATE_INT)` rend 1, et
        // `{"value": true}` sur un réglage entier est une erreur d'appelant, pas un 1.
        $entier = \is_bool($valeur) ? false : (\is_int($valeur) ? $valeur : filter_var($valeur, \FILTER_VALIDATE_INT));

        if (false === $entier) {
            throw new ValidationException(['value' => 'Un nombre entier est attendu.']);
        }

        $min = $reglage->getMinValue();
        $max = $reglage->getMaxValue();

        if ((null !== $min && $entier < $min) || (null !== $max && $entier > $max)) {
            throw $this->horsBornes($min, $max);
        }

        return $entier;
    }

    /** Les bornes voyagent dans `details` : le front les affiche, il ne les devine pas. */
    private function horsBornes(?int $min, ?int $max): ValidationException
    {
        $attendu = \sprintf('Valeur attendue entre %s et %s.', $min ?? '−∞', $max ?? '+∞');

        return new ValidationException(
            ['value' => $attendu],
            'La valeur est hors des bornes autorisées pour ce réglage.',
            'valeur_hors_bornes',
            ['minValue' => $min, 'maxValue' => $max],
        );
    }

    private function booleenValide(mixed $valeur): bool
    {
        if (!\is_bool($valeur)) {
            throw new ValidationException(['value' => 'Un booléen est attendu (true ou false).']);
        }

        return $valeur;
    }

    private function jsonValide(mixed $valeur): string
    {
        if (!\is_array($valeur)) {
            throw new ValidationException(['value' => 'Une structure JSON est attendue.']);
        }

        return json_encode($valeur, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, Setting> */
    private function charger(): array
    {
        if (null !== $this->parCle) {
            return $this->parCle;
        }

        $parCle = [];
        foreach ($this->depot->findAll() as $reglage) {
            $parCle[$reglage->getSettingKey()] = $reglage;
        }
        // Ordre stable de la liste non paginée du § 10.2 : sans tri, il dépend de
        // l'ordre physique des lignes et l'écran d'administration se réordonne tout seul.
        ksort($parCle);

        return $this->parCle = $parCle;
    }
}
