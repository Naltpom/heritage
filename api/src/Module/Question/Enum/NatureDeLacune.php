<?php

declare(strict_types=1);

namespace App\Module\Question\Enum;

/**
 * Les trous de l'arbre qu'un membre de la famille peut combler — la liste **close**
 * sur laquelle la détection travaille (ADR 0008, § 4).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  C'EST LE CODE QUI TROUVE LA LACUNE. LE MODÈLE DE LANGAGE NE FAIT QUE LA METTRE
 *  EN FRANÇAIS. Une nature absente de cette liste n'est pas une question, quel que
 *  soit ce qu'un modèle aurait à en dire.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * C'est ce découpage qui rend le repli possible : {@see self::gabarit()} donne une
 * phrase à toute lacune, sans aucun appel sortant. Gemini coupé, la page sert les
 * mêmes questions, moins bien écrites — « une page vide parce qu'un tiers ne répond
 * pas est un défaut de conception, pas un incident » (ADR 0008).
 *
 * ── Ce qui entre dans cette liste, et ce qui n'y entre pas ───────────────────────
 *
 * Une nature est admise si, et seulement si, ses trois conditions sont réunies :
 *
 * 1. **elle se détecte par une requête**, sans jugement — une colonne vide, une
 *    filiation absente, un compte de médias à zéro ;
 * 2. **un membre de la famille peut y répondre de mémoire** ; « sur quelle source
 *    repose cette fiche ? » est une question de généalogiste, pas de petite-fille ;
 * 3. **la réponse a une route d'écriture qui existe déjà** (ADR 0004) — voir
 *    {@see self::champ()}.
 *
 * ── Pourquoi presque toutes les natures rendent `null` en {@see self::champ()} ───
 *
 * Parce qu'une réponse en texte libre n'est pas une valeur validée, et que la forcer
 * dans un champ serait l'aplatissement que le § 9.1 du modèle de données interdit.
 *
 * Le cas le plus net est celui des dates. « vers 1923 », « juste avant la guerre »,
 * « l'été 38 » : un bloc date porte **cinq valeurs solidaires** (`raw`, `qualifier`,
 * `min`, `max`, `place`), et les deviner depuis une phrase, c'est fabriquer une date
 * ferme à partir d'une fourchette — exactement le bogue qui faisait naître une aïeule
 * après ses propres enfants. Les lieux ont le même défaut : `place` vit **dans** le
 * bloc date, il n'est pas un champ à part.
 *
 * La réponse devient donc une **note** sur la fiche, que l'administrateur lit avant de
 * saisir la valeur. Ce n'est pas un pis-aller : c'est le mécanisme même de l'ADR 0004,
 * « quelqu'un qui regarde un diff et demande sur quoi tu t'appuies ? ». Ce que la page
 * apporte, c'est le renseignement ; la mise en forme reste au validateur.
 *
 * Seules les deux natures dont la réponse **est** littéralement la valeur du champ —
 * un prénom, un nom — prennent la route `modification_personne`.
 */
enum NatureDeLacune: string
{
    /** `tree_person.given_names` vide. Sept fiches de l'arbre réel sont dans ce cas. */
    case PrenomInconnu = 'prenom_inconnu';

    /** `tree_person.surname` vide. */
    case NomInconnu = 'nom_inconnu';

    /** Aucune filiation maternelle : ni `tree_child` vers une famille, ni `wife` sur elle. */
    case MereInconnue = 'mere_inconnue';

    /** Aucune filiation paternelle. */
    case PereInconnu = 'pere_inconnu';

    /** Le bloc naissance ne porte **aucune borne** : ni `min`, ni `max`. */
    case DateDeNaissanceInconnue = 'date_naissance_inconnue';

    /**
     * La naissance est datée mais `birth_place` est vide.
     *
     * L'ordre importe : on ne demande pas le lieu d'une naissance qu'on ne sait pas
     * dater. Demander deux choses à la fois sur la même fiche produit deux questions
     * dont la seconde est absurde, et un membre qui lit une question absurde cesse de
     * lire les suivantes.
     */
    case LieuDeNaissanceInconnu = 'lieu_naissance_inconnu';

    /** La personne est décédée et le bloc décès ne porte aucune borne. */
    case DateDeDecesInconnue = 'date_deces_inconnue';

    /** Le décès est daté mais `death_place` est vide. */
    case LieuDeDecesInconnu = 'lieu_deces_inconnu';

    /**
     * Aucun média ne vise cette fiche.
     *
     * La seule nature dont la réponse attendue est une **image** et non une phrase.
     * Elle prend malgré tout la route de la note quand on lui répond en texte (« j'en
     * ai une dans l'album de ma mère ») : c'est un renseignement, et un renseignement
     * ne se jette pas parce qu'il n'avait pas la forme attendue.
     */
    case AucunePhoto = 'aucune_photo';

    /**
     * Le champ de la **surface éditable** (`Proposal\Service\SurfaceEditable`) qu'une
     * réponse en texte libre alimente directement, ou `null` pour passer par une note.
     *
     * La chaîne traverse la frontière de module telle quelle : `SurfaceEditable` et
     * `ValueKind` appartiennent à `Proposal`, que `Question` n'a pas le droit de nommer
     * (règle 3 de l'ADR 0002). C'est l'adaptateur, **chez `Proposal`**, qui traduit ce
     * nom de champ en `ValueKind` — et qui lève si le nom n'est pas dans la surface.
     *
     * Les deux seules valeurs possibles aujourd'hui sont `givenNames` et `surname` :
     * ce sont les deux champs dont la réponse d'un membre **est** la valeur, sans
     * interprétation. Voir l'en-tête pour le motif complet.
     */
    public function champ(): ?string
    {
        return match ($this) {
            self::PrenomInconnu => 'givenNames',
            self::NomInconnu => 'surname',
            self::MereInconnue,
            self::PereInconnu,
            self::DateDeNaissanceInconnue,
            self::LieuDeNaissanceInconnu,
            self::DateDeDecesInconnue,
            self::LieuDeDecesInconnu,
            self::AucunePhoto => null,
        };
    }

    /**
     * La phrase du repli, `%s` portant le nom d'affichage de la personne.
     *
     * **C'est elle qui garantit qu'aucune lacune ne reste sans question.** Elle est
     * écrite pour être lue par quelqu'un qui ne connaît ni l'arbre ni le vocabulaire
     * de la généalogie : on dit « où est née » et pas « le lieu de l'événement
     * `BIRT` », et l'on tutoie la mémoire plutôt que l'état civil — *« vous
     * souvenez-vous »* obtient une réponse là où *« indiquez »* n'en obtient pas.
     *
     * Le vouvoiement est celui du reste de l'interface. Le point d'interrogation n'est
     * pas décoratif : ces phrases se retrouvent telles quelles sous un bouton « Je ne
     * sais pas », et une question qui n'en est pas une rend ce bouton incompréhensible.
     */
    public function gabarit(): string
    {
        return match ($this) {
            self::PrenomInconnu => 'Nous ne connaissons que le nom de famille de %s. Vous souvenez-vous de son prénom ?',
            self::NomInconnu => 'Nous ne connaissons que le prénom de %s. Vous souvenez-vous de son nom de famille ?',
            self::MereInconnue => 'Nous ne savons pas qui était la mère de %s. Vous en souvenez-vous ?',
            self::PereInconnu => 'Nous ne savons pas qui était le père de %s. Vous en souvenez-vous ?',
            self::DateDeNaissanceInconnue => 'Nous ne savons pas quand %s est né·e. Même une année approximative nous aiderait.',
            self::LieuDeNaissanceInconnu => 'Savez-vous où %s est né·e ?',
            self::DateDeDecesInconnue => 'Nous ne savons pas quand %s est décédé·e. Même une année approximative nous aiderait.',
            self::LieuDeDecesInconnu => 'Savez-vous où %s est décédé·e ?',
            self::AucunePhoto => 'Nous n\'avons aucune photo de %s. En auriez-vous une ?',
        };
    }

    /**
     * L'**ordre de présentation**, du plus facile à répondre au plus exigeant.
     *
     * Une page qui s'ouvre sur « quand votre arrière-grand-père est-il né ? » se
     * referme ; une page qui s'ouvre sur « auriez-vous une photo de votre grand-mère ? »
     * obtient une première contribution, et la première contribution est celle qui
     * décide s'il y en aura une seconde (ADR 0004, § caducité).
     *
     * Le rang le plus bas passe en premier.
     */
    public function rang(): int
    {
        return match ($this) {
            self::AucunePhoto => 1,
            self::PrenomInconnu => 2,
            self::NomInconnu => 3,
            self::MereInconnue => 4,
            self::PereInconnu => 5,
            self::LieuDeDecesInconnu => 6,
            self::DateDeDecesInconnue => 7,
            self::LieuDeNaissanceInconnu => 8,
            self::DateDeNaissanceInconnue => 9,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PrenomInconnu => 'Prénom inconnu',
            self::NomInconnu => 'Nom inconnu',
            self::MereInconnue => 'Mère inconnue',
            self::PereInconnu => 'Père inconnu',
            self::DateDeNaissanceInconnue => 'Date de naissance inconnue',
            self::LieuDeNaissanceInconnu => 'Lieu de naissance inconnu',
            self::DateDeDecesInconnue => 'Date de décès inconnue',
            self::LieuDeDecesInconnu => 'Lieu de décès inconnu',
            self::AucunePhoto => 'Aucune photo',
        };
    }
}
