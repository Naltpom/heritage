<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\ValueKind;

/**
 * La liste, **close**, des champs qu'une demande a le droit de viser.
 *
 * C'est la table de correspondance explicite exigée par le § 9.4 du modèle de données
 * et le § 6.2 du contrat d'API : « `field` est borné par une table de correspondance
 * explicite côté service, **jamais résolu par réflexion**. »
 *
 * **Pourquoi jamais par réflexion.** Un `field` vient d'une requête HTTP. Le résoudre
 * en `set`.ucfirst($field) laisserait atteindre n'importe quel mutateur de l'entité —
 * `setVersion()`, `setSearchKey()`, `setBranch()` — c'est-à-dire une vulnérabilité
 * d'affectation de masse, sur les colonnes précisément dérivées dont dépendent le
 * placement et le cercle de visibilité. Un champ absent de cette table n'est pas
 * modifiable par une demande, et la demande **n'est pas créée** : `422`.
 *
 * ── Ce qui est exclu, et pourquoi ────────────────────────────────────────────────
 *
 * Ni `branch`, ni `generation`, ni `rank`, ni `bestQuay`, ni `citationCount`, ni
 * `displayName`, ni `searchKey`, ni `version` : ce sont des **colonnes dérivées**,
 * écrites par un service de `Tree` après toute écriture structurante (§ 3.1 du
 * modèle). Une demande qui les vise est un malentendu, pas une contribution.
 *
 * ── La correspondance avec `Tree` ────────────────────────────────────────────────
 *
 * Cette table dit **ce qui est proposable** ; la table `field → callable` du service de
 * `Tree` dit **comment l'appliquer**. Les deux doivent couvrir exactement le même
 * ensemble — un champ proposable que `Tree` ne sait pas appliquer produirait une
 * demande qu'on peut déposer et pas valider, ce qui est la pire des deux moitiés. Un
 * test croisé le vérifiera dès que `Tree` aura livré son implémentation de
 * {@see Port\PortArbre}.
 */
final class SurfaceEditable
{
    /**
     * La surface d'une personne, **exhaustive** au § 6.2 du contrat d'API.
     *
     * `birth` et `death` sont des **blocs** : un op, cinq valeurs solidaires. Le
     * `field` est le nom du bloc, jamais `birthMin`.
     */
    private const array PERSONNE = [
        'givenNames' => ValueKind::Texte,
        'surname' => ValueKind::Texte,
        'marriedName' => ValueKind::Texte,
        'nickname' => ValueKind::Texte,
        'sex' => ValueKind::Enum,
        'deceased' => ValueKind::Booleen,
        'birth' => ValueKind::DateIncertaine,
        'death' => ValueKind::DateIncertaine,
        'forcePrivacy' => ValueKind::Booleen,
        'privacyNote' => ValueKind::Texte,
    ];

    /**
     * La surface d'une union : les dates, la nature (mariés ou non) et le fait de la
     * rupture.
     *
     * Ni `husband`, ni `wife` : changer la composition d'un couple n'est pas une
     * modification de champ, c'est un lien — donc une demande `ajout_lien`, qui passe
     * par `prp_draft_link` et par le résolveur de familles (§ 9.5).
     */
    private const array FAMILLE = [
        'marriage' => ValueKind::DateIncertaine,
        'married' => ValueKind::Booleen,
        'divorced' => ValueKind::Booleen,
        'divorce' => ValueKind::DateIncertaine,
    ];

    /**
     * Les booléens **tri-états** : `null` y est une valeur — « on ne sait pas » —, et non
     * un oubli.
     *
     * `married` est le seul (ADR 0020). Les autres booléens de la surface (`deceased`,
     * `forcePrivacy`, `divorced`) sont `NOT NULL` en base : y accepter `null` le ferait
     * écrire `false` par le `(bool)` de `Tree`, c'est-à-dire affirmer « vivant » ou « pas
     * de rupture » à la place de qui n'a rien dit. La liste est donc fermée, par champ, et
     * le `ValueKind` reste `booleen` : la forme JSON est la même, seul le domaine s'élargit.
     *
     * @var array<string, list<string>>
     */
    private const array NUL_ADMIS = [
        SubjectKind::Famille->value => ['married'],
    ];

    /**
     * La surface d'une citation : « page, `QUAY`, transcription » (§ 5.7 du modèle).
     *
     * Ni `source`, ni `person`, ni `family` : rattacher une citation ailleurs n'est pas
     * une correction de citation, c'est un lien.
     *
     * ── `quay` est un `Entier`, et ce fut longtemps un `Enum` qui ne marchait pas ──────
     *
     * Le champ était **inatteignable par tous les chemins**, par deux causes distinctes
     * qui avaient la même racine : `ValueKind::Enum` est documenté « scalaire JSON
     * **string** » et passe par `NormaliseurDeValeur::texte()`, alors que
     * `CitationQuality` est la seule énumération du projet adossée à `int` — l'exception
     * assumée du § 2.7 — et que le port rend donc un entier.
     *
     * Les deux symptômes étaient assez différents pour que personne ne rapproche les
     * moitiés :
     *
     * - poster `"quay": 2` passait le dépôt, et c'est la **validation** qui levait un
     *   `422 « Cette valeur doit être une chaîne ou null »` **dans sa transaction** ;
     * - poster `"quay": "2"` s'écrivait — mais la valeur courante était l'entier `2` et
     *   l'`old_value` la chaîne `"2"`. La comparaison de caducité est un `!==`, donc
     *   `2 !== "2"` était vrai, et la demande mourait **caduque, systématiquement**.
     *
     * `Entier` aligne tout : `versPhp()` rend un `int`, `Tree::qualite()` accepte déjà
     * `string|int` et borne à 0-3, `EtatCible::valeurs` rend déjà un `int`, et la
     * comparaison devient entier contre entier.
     *
     * **Aucune op à migrer** : mesuré en base avant d'écrire cette ligne, `prp_change_op`
     * ne porte **aucune** op visant `quay`, sous aucun `value_kind` — et n'en porte
     * d'ailleurs aucune en `enum` tout court. Le champ n'a jamais été atteint, ce qui est
     * la confirmation empirique de son inatteignabilité plutôt qu'une chance.
     *
     * *(`ValueKind::Enum` reste dans l'énumération, et reste juste : il décrit une
     * énumération adossée à `string`. C'est `quay` qui n'en était pas une.)*
     */
    private const array CITATION = [
        'page' => ValueKind::Texte,
        'quay' => ValueKind::Entier,
        'dataText' => ValueKind::Texte,
        'url' => ValueKind::Texte,
    ];

    /**
     * Les libellés français du diff : « **Naissance** : 1987 → entre 1901 et 1909 ».
     *
     * Ils vivent ici plutôt que dans le sérialiseur parce qu'ils sont indissociables de
     * la liste : ajouter un champ éditable sans son libellé produirait une ligne de
     * diff intitulée par un nom de propriété Doctrine, en anglais, dans une interface
     * en français.
     */
    private const array LIBELLES = [
        'givenNames' => 'Prénoms',
        'surname' => 'Nom',
        'marriedName' => 'Nom d\'usage',
        'nickname' => 'Surnom',
        'sex' => 'Sexe',
        'deceased' => 'Personne décédée',
        'birth' => 'Naissance',
        'death' => 'Décès',
        'forcePrivacy' => 'Confidentialité forcée',
        'privacyNote' => 'Motif de confidentialité',
        'marriage' => 'Mariage',
        'married' => 'Mariés',
        // « Divorce prononcé » mentait sur les unions libres : `divorced` couvre toute
        // rupture (ADR 0020). Le diff d'administration dit « Divorcés » ou « Séparés »
        // quand il connaît la nature de l'union ({@see libelleSelonLaNature()}).
        'divorced' => 'Union rompue',
        'divorce' => 'Rupture',
        'page' => 'Page',
        'quay' => 'Qualité de la source',
        'dataText' => 'Transcription',
        'url' => 'Lien',
        // Pas un champ éditable par op — une note de fiche proposée se complète sur place,
        // ou par la fusion d'un complément (ADR 0017), et la notification qui le raconte
        // la nomme avec les autres champs.
        'note' => 'Note',
    ];

    /**
     * Les champs proposables pour une portée donnée.
     *
     * `media` rend `[]` : dans cette livraison, aucun champ de média n'est modifiable
     * par un op de champ. Un média se joint (`ajout_media`, un op de nature
     * `ajout_media` dont le `field` est `null`), il ne se réécrit pas.
     *
     * @return array<string, ValueKind>
     */
    public function champs(SubjectKind $kind): array
    {
        return match ($kind) {
            SubjectKind::Personne => self::PERSONNE,
            SubjectKind::Famille => self::FAMILLE,
            SubjectKind::Citation => self::CITATION,
            SubjectKind::Media, SubjectKind::Note, SubjectKind::Fait => [],
        };
    }

    public function accepte(SubjectKind $kind, string $field): bool
    {
        return \array_key_exists($field, $this->champs($kind));
    }

    /** Le `ValueKind` qu'un champ impose — le client ne choisit pas le sien. */
    public function natureDe(SubjectKind $kind, string $field): ?ValueKind
    {
        return $this->champs($kind)[$field] ?? null;
    }

    /**
     * `null` est-il une valeur de ce champ ? Vrai pour les seuls booléens tri-états
     * ({@see NUL_ADMIS}) — et pour `Texte` et `Entier`, qui l'admettaient déjà par nature.
     */
    public function admetNul(SubjectKind $kind, string $field): bool
    {
        return \in_array($field, self::NUL_ADMIS[$kind->value] ?? [], true);
    }

    /** Le libellé français d'un champ ; à défaut, le nom de propriété, pour ne jamais rendre une chaîne vide. */
    public function libelle(string $field): string
    {
        return self::LIBELLES[$field] ?? $field;
    }

    /**
     * Le libellé d'un champ de rupture **selon la nature de l'union** (ADR 0020) :
     * « Divorcés » et « Divorce » après un mariage, « Séparés » et « Séparation » sinon —
     * union libre ou nature inconnue, parce qu'on ne divorce que d'un mariage et qu'on
     * n'affirme pas un mariage qu'on ignore.
     *
     * `$marie` est la nature **après** la demande : celle que la demande pose quand elle
     * la change, celle de la fiche sinon. Les autres champs gardent leur libellé.
     */
    public function libelleSelonLaNature(string $field, ?bool $marie): string
    {
        return match ($field) {
            'divorced' => true === $marie ? 'Divorcés' : 'Séparés',
            'divorce' => true === $marie ? 'Divorce' : 'Séparation',
            default => $this->libelle($field),
        };
    }
}
