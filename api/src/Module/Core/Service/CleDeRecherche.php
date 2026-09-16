<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

/**
 * **La** définition du repliement d'accents du projet — celle qui **écrit**
 * `tree_person.search_key` et celle qui la **cherche**.
 *
 * > **Ce n'est PAS la recherche de rattachement.** Plusieurs commentaires de ce dépôt
 * > l'affirmaient — « les `LIKE 'BOURRACHE%'` de la recherche de rattachement » — et
 * > c'était faux. `POST /api/inscription/rattachement` est servie par
 * > `Membership\Service\RechercheDeRattachement`, qui compare `surname` et `given_names`
 * > **en PHP** sur son propre port ; `Membership\Service\Port\PersonneDeLArbre` ne porte
 * > même pas de `searchKey`. La seule lectrice SQL de cette colonne est la recherche de
 * > l'arbre, `GET /api/arbre/recherche` (§ 5.5 du contrat). La confusion importe : elle a
 * > fait croire que toucher à cette requête touchait au plafond par jeton du § 4.2 et à
 * > son indistinguabilité, qui n'en dépendent en rien.
 *
 * ## Pourquoi elle est dans `Core`
 *
 * Elle a existé en double : dans `Gedcom\Service\NormalisateurNom`, qui compose la
 * colonne à l'import, et dans `Tree\Serializer\NormaliseurDeRequete`, qui normalise le
 * terme de recherche. La règle 3 de l'ADR 0002 interdit à l'un d'importer l'autre, donc
 * les deux tables étaient recopiées.
 *
 * **Le jour où elles auraient divergé d'un seul caractère, la recherche aurait cessé de
 * trouver les noms qui le portent — sans erreur, sans test rouge.** Sur un arbre plein de
 * *Épine*, *Sarriette*, *Génévrolle* et *Chardon*, la panne était garantie, et elle
 * n'aurait pas ressemblé à une panne : juste une personne qu'on ne retrouve plus. Même
 * geste que l'arbitrage **E** pour `UncertainDate`, `DateQualifier`, `Sex` et `Branch` :
 * un type partagé par deux modules appartient au noyau.
 *
 * ## Pourquoi la table est explicite et non `iconv`
 *
 * **N'employez pas `iconv` avec `//TRANSLIT`.** Son résultat dépend de la bibliothèque du
 * système :
 *
 * | Système | `é` devient |
 * |---|---|
 * | glibc — Linux courant | `e` |
 * | libiconv — Windows | `'e` |
 * | musl — **Alpine, l'image Docker du projet** | `?` |
 *
 * C'est exactement le scénario de ce projet : développement sous Windows, exécution dans
 * une image Alpine. La clé écrite à l'import et la clé cherchée à la requête seraient
 * calculées par deux bibliothèques différentes — soit la panne silencieuse que cette
 * classe existe pour supprimer, réintroduite par le moyen choisi pour l'empêcher.
 *
 * Une table explicite est verbeuse. Elle ne dépend de rien, et elle rend le même résultat
 * sur toutes les machines. C'est le bon échange.
 *
 * ## La forme de sortie
 *
 * `[A-Z0-9 ]` et rien d'autre, 200 caractères au plus. C'est une **propriété**, pas une
 * liste d'exemples : `tests/Core/CleDeRechercheTest.php` la vérifie sur un corpus, et
 * c'est elle qui verrait rouge si un `'e` ou un `?` s'invitait.
 */
final class CleDeRecherche
{
    /** La longueur de `tree_person.search_key` (§ 3.1 du modèle). */
    public const int LONGUEUR_MAXIMALE = 200;

    /**
     * Les caractères composés, dépliés **avant** le repliement lettre à lettre : ils ne
     * donnent pas une lettre mais deux, et `strtr` lettre à lettre ne saurait pas le
     * faire.
     */
    private const array LIGATURES = [
        'Æ' => 'AE', 'æ' => 'ae', 'Œ' => 'OE', 'œ' => 'oe', 'ß' => 'ss',
        'Ĳ' => 'IJ', 'ĳ' => 'ij', 'Ð' => 'D', 'ð' => 'd', 'Þ' => 'TH', 'þ' => 'th',
    ];

    /**
     * Les deux chaînes se lisent **en parallèle**, caractère par caractère : la n-ième
     * lettre de `ACCENTUES` se replie sur la n-ième de `REPLIES`. Toute modification
     * touche les deux, et l'assertion de `tableDeRepliement()` refuse un déséquilibre.
     */
    private const string ACCENTUES = 'ÀÁÂÃÄÅàáâãäåÈÉÊËèéêëÌÍÎÏìíîïÒÓÔÕÖØòóôõöøÙÚÛÜùúûüÝŸýÿÇçÑñŠšŽžĀāĒēĪīŌōŪūŁłĆćŚśŹźŻż';

    private const string REPLIES = 'AAAAAAaaaaaaEEEEeeeeIIIIiiiiOOOOOOooooooUUUUuuuuYYyyCcNnSsZzAaEeIiOoUuLlCcSsZzZz';

    /** @var array<string, string>|null mémorisée : la clé est calculée 274 fois à l'import */
    private static ?array $table = null;

    /**
     * Le repliement lui-même : sans accents, en majuscules, ponctuation réduite à des
     * espaces simples.
     *
     * **Le même appel des deux côtés**, à l'écriture comme à la lecture — c'est toute la
     * raison d'être de cette classe. `cleDeNom()` n'ajoute que l'ordre des morceaux.
     */
    public function replier(string $texte): string
    {
        $deplie = strtr($texte, self::LIGATURES);
        $replie = strtr($deplie, self::tableDeRepliement());
        $majuscules = mb_strtoupper($replie, 'UTF-8');
        $nettoye = (string) preg_replace('/[^A-Z0-9]+/', ' ', $majuscules);

        return mb_substr(trim($nettoye), 0, self::LONGUEUR_MAXIMALE, 'UTF-8');
    }

    /**
     * La clé d'une personne : `surname || ' ' || given_names || ' ' || nickname`, repliée,
     * **sans mot répété**.
     *
     * ── Le nom d'abord ──────────────────────────────────────────────────────────────
     *
     * Ce n'est pas esthétique : `idx_tree_person_search` est un index de **préfixe**
     * (`varchar_pattern_ops`), et c'est le patronyme qui doit l'attaquer. Un ordre
     * inverse le rendrait inutilisable, et deux tests le verrouillent
     * (`CleDeRechercheTest::testLeNomPasseAvantLesPrenoms()`,
     * `NormalisateurNomTest::testLaCleDeRechercheMetLeNomEnTete()`).
     *
     * ── Le surnom, et pourquoi il vient EN DERNIER ──────────────────────────────────
     *
     * `display_name` est composé `nickname ?? given_names`, puis `surname` : le surnom y
     * **remplace** les prénoms en tête. Une fiche affichée « Cobalt Sureau » dont la clé
     * ne portait que `SUREAU OPALE` était donc **introuvable sous le nom qu'elle
     * affiche** — mesuré sur la base de travail : 12 fiches portent un surnom, **3**
     * l'avaient hors de leur clé (les 9 autres y échappaient par accident, leur surnom
     * figurant déjà parmi leurs prénoms).
     *
     * Il est ajouté **à la fin** et non en tête, pour ne pas déloger le patronyme du
     * préfixe indexé — le corriger en tête aurait réparé la recherche par surnom en
     * cassant la recherche par patronyme, qui est le cas courant.
     *
     * ── Pourquoi on déduplique les mots ─────────────────────────────────────────────
     *
     * Parce que le surnom est très souvent l'un des prénoms. Sans déduplication, la clé
     * de ces 9 fiches gagnerait un mot déjà présent : aucune recherche n'en trouverait
     * davantage, et **12 lignes seraient réécrites au lieu de 3**. Ce n'est pas une
     * économie d'octets : `Person` porte un `#[ORM\Version]`, et toute ligne réécrite
     * périme les demandes en attente qui la visaient (§ 9.6). On n'écrit que ce qui
     * change — même règle qu'à {@see \App\Module\Tree\Service\RecalculDesColonnesDerivees}.
     *
     * Mesuré sur les 279 fiches : **0** clé actuelle porte déjà un mot en double, la
     * déduplication ne déplace donc rien d'autre que les 3 lignes visées.
     *
     * ── Le nom d'usage, et pourquoi il y entre désormais ────────────────────────────
     *
     * Dans un arbre familial français, c'est très souvent sous ce nom qu'on cherche une
     * femme mariée. 97 fiches en portent un, **96** l'avaient hors de leur clé : elles
     * étaient introuvables sous le nom que leur famille emploie. C'est le même défaut que
     * celui du surnom ci-dessus, à trente fois l'échelle — et il s'est refermé au moment
     * où l'écran a retenu « Nom d'usage » comme terme unique de ce champ : le mot qu'on
     * affiche doit être un mot sous lequel on peut chercher.
     *
     * Il est ajouté **en complément et jamais en remplacement**, et **en queue** comme le
     * surnom, pour deux raisons distinctes :
     *
     * - une personne doit rester trouvable par son **nom de naissance**, qui est ce que
     *   portent les actes — c'est lui qui reste en tête de la clé ;
     * - `idx_tree_person_search` est un index de **préfixe**, et déloger le patronyme
     *   casserait la recherche par patronyme, qui est le cas courant.
     *
     * ── Ce que cet élargissement n'ouvre PAS ────────────────────────────────────────
     *
     * **Il ne touche pas au rattachement d'un compte, ni au plafond du § 4.2.** Cette
     * colonne n'a qu'une lectrice SQL, `Tree\Serializer\LectureDeLArbre` — voir l'en-tête
     * de cette classe, qui porte le démenti au complet. `POST /api/inscription/
     * rattachement` compare `surname` et `given_names` **en PHP** sur son propre port, et
     * `Membership\Service\Port\PersonneDeLArbre` ne porte ni `searchKey` ni
     * `marriedName` : `VueDeLArbrePourMembership` lui passe `getSurname()` seul. Un nom
     * d'usage dans cette clé-ci n'est donc atteignable que par `GET /api/arbre/recherche`,
     * qui exige `ROLE_USER`.
     *
     * Et pour un lecteur connecté, **le cercle décide toujours seul** : cette clé nourrit
     * un jeu de *candidats* que `NormaliseurDeRecherche` filtre ensuite personne par
     * personne (ADR 0003). Élargir la clé fait donc atteindre par un mot de plus des gens
     * **déjà visibles** ; cela ne rend visible personne de nouveau. Mesuré sur la base de
     * travail : 29 des 97 sont vivantes, et aucune vivante hors cercle n'est devenue
     * trouvable.
     */
    public function cleDeNom(
        string $prenoms,
        string $nom,
        ?string $surnom = null,
        ?string $nomDUsage = null,
    ): string {
        $replie = $this->replier(trim($nom.' '.$prenoms.' '.($surnom ?? '').' '.($nomDUsage ?? '')));

        if ('' === $replie) {
            return '';
        }

        // `array_unique` conserve la **première** occurrence, donc l'ordre « patronyme,
        // prénoms, surnom » survit à la déduplication.
        return implode(' ', array_unique(explode(' ', $replie)));
    }

    /** @return array<string, string> */
    private static function tableDeRepliement(): array
    {
        if (null !== self::$table) {
            return self::$table;
        }

        $accentues = mb_str_split(self::ACCENTUES, 1, 'UTF-8');
        $replies = mb_str_split(self::REPLIES, 1, 'UTF-8');
        \assert(\count($accentues) === \count($replies), 'Les deux tables de repliement doivent avoir la même longueur.');

        return self::$table = array_combine($accentues, $replies);
    }
}
