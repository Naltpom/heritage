<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Enum\Regime;

/**
 * Le contrat **unique** de « ce lecteur a-t-il le droit de voir cette personne ».
 *
 * `Media`, `Tree`, `Timeline` et `Proposal` en dépendent ; `Membership` l'implémente.
 * Il vit dans `Core` et non dans chaque module parce que quatre interfaces déclarées
 * séparément, ce sont quatre définitions de « visible » qui divergeront — et **une règle
 * de confidentialité ne se trompe qu'une fois pour fuir**. L'ADR 0002 interdit les
 * *relations Doctrine* inter-modules et prescrit un noyau partagé **minimal** : un
 * contrat d'interface est exactement ce qu'un tel noyau a vocation à porter. Aucune
 * entité ne franchit la frontière.
 *
 * ## Le sujet est un identifiant, pas une entité
 *
 * `int $personId`, et jamais `Tree\Person`. Ce n'est pas une commodité : `Core` est
 * référencé par les six autres modules et n'en référence aucun (§ 2.6 du modèle de
 * données). Un type-hint vers une entité de `Tree` inverserait la dépendance et
 * rouvrirait la frontière que l'ADR 0002 ferme. Les modules qui ont besoin d'un
 * vocabulaire plus riche — « ce média porte sur une union », « cette citation étaye une
 * fiche » — le traduisent **chez eux**, dans leur propre adaptateur, et n'envoient ici
 * que des identifiants de personnes.
 *
 * ## Le régime est dans la signature, pas dans un commentaire
 *
 * `regime()` rend une valeur de {@see Regime}, qui en compte **deux** et ne porte
 * **aucune profondeur**. C'est délibéré et c'est la moitié du contrat : un compte en
 * attente de validation relève d'un régime distinct, pas d'un cercle de profondeur
 * nulle. Un `N = 0` reste un cercle — par la formule de l'ADR 0003, `A(u) = { u }`, puis
 * la passe des conjoints y ajoute le conjoint, soit exactement les deux fiches vivantes
 * qu'on veut fermer. Comme aucune méthode d'ici n'accepte ni ne rend de profondeur,
 * « cercle avec N = 0 » n'est pas exprimable dans ce contrat.
 *
 * ## Le lecteur est implicite, et c'est un choix
 *
 * Aucune méthode ne prend le lecteur en paramètre : l'implémentation le résout
 * elle-même, session comprise. Le motif est la bascule « voir comme un membre »
 * (§ 10.4 du contrat d'API), qui remplace le cercle de l'administrateur par celui d'un
 * membre choisi : un lecteur passé par chaque appelant est un lecteur qu'un appelant
 * finira par construire sans la bascule, et « une bascule qu'on oublie de rejouer sur un
 * appel ne vérifie plus rien ». Les appelants qui ont besoin du régime le **lisent** ici
 * plutôt que de le recalculer, pour la même raison.
 *
 * Corollaire de l'ADR 0003 qu'aucune implémentation n'a le droit d'assouplir : **le
 * normaliseur n'a pas de mode « sans filtre »**. Un administrateur passe par un cercle
 * complet, jamais par un `if ($estAdmin) { return true; }`.
 *
 * ## Les trois règles que toute implémentation doit respecter
 *
 * 1. Une personne **décédée** est un fait historique : visible de tout compte connecté,
 *    sans restriction (§ 6.2 du dossier de mission).
 * 2. Une personne **vivante** est visible de tout lecteur en régime {@see Regime::Cercle}
 *    — membre validé, administrateur —, **sauf** si sa fiche est à confidentialité
 *    forcée (`force_privacy`) : celle-là n'est visible que dans le cercle du lecteur
 *    (révision du 2026-10-01 de l'ADR 0003 ; jusque-là, tout vivant était soumis au
 *    cercle). **Jamais** en régime {@see Regime::Aucun} — y compris la personne à
 *    laquelle ce lecteur prétend se rattacher. Confirmer une date de naissance n'est pas
 *    la lire, et le pré-lien d'invitation n'ouvre aucun accès (§ 12.1 du contrat d'API).
 * 3. **Un décès inconnu vaut vivant, donc masqué.** *« On ne sait pas si elle est morte »
 *    n'est pas une autorisation de publier ses dates.*
 *
 * Et une quatrième, qui porte sur la forme des refus : ces méthodes ne distinguent pas
 * « n'existe pas » de « pas le droit ». Les deux rendent la même chose, et l'appelant
 * rend `404` dans les deux cas (§ 15.3 du contrat d'API) — sans quoi la réponse dit à un
 * lecteur hors cercle quelles personnes existent.
 *
 * ## Le défaut
 *
 * Tant qu'aucun adaptateur n'est branché, {@see CercleFerme} répond « non » à tout.
 * Lire son en-tête avant d'être tenté de l'« arranger » : c'est une règle permanente,
 * pas un bouchon.
 */
interface CercleDeVisibiliteInterface
{
    /**
     * **Le seul nom qu'une personne vivante hors cercle expose** — révision du 2026-10-01
     * de l'ADR 0003.
     *
     * *Seconde révision du même jour* : un membre validé voit désormais tous les vivants,
     * et ce masque ne couvre plus, pour lui, que les **fiches confidentielles** hors de son
     * cercle. Il reste aussi le nom que rend tout lecteur du régime `Aucun`. La mécanique
     * ci-dessous est inchangée ; c'est la population qu'elle couvre qui a rétréci.
     *
     * Jusque-là, la liste blanche laissait sortir son **nom d'affichage**. Décision du
     * commanditaire : une personne vivante hors du cercle du lecteur n'expose plus son
     * nom. Partout où une telle personne est sérialisée — nœud du graphe, fiche réduite,
     * référence dans la fiche d'un proche, époux et enfants d'une famille, personne citée
     * par l'assistant —, `displayName` (et `etiquetteNom` sur un nœud) valent cette
     * constante, **et le vrai nom ne quitte jamais le serveur**.
     *
     * Une constante et non un « libellé par module » : deux orthographes du même masque
     * se distingueraient à l'écran, et la seconde finirait par porter une initiale.
     */
    public const string NOM_MASQUE = 'Personne vivante';

    /**
     * Le régime de lecture du lecteur courant.
     *
     * `Regime::Aucun` — personnes décédées uniquement, aucune personne vivante.
     * `Regime::Cercle` — la formule du § 6.2 du dossier s'applique.
     */
    public function regime(): Regime;

    /**
     * Le lecteur courant a-t-il le droit de voir les **données protégées** de cette
     * personne — dates, lieux, sources, citations, médias, portrait ?
     *
     * Pour un lecteur en régime `Cercle`, `false` ne se rend plus que pour une fiche
     * confidentielle hors de son cercle (révision du 2026-10-01).
     *
     * `false` ne veut pas dire « le nœud disparaît » : hors cercle, une personne vivante
     * expose encore son identifiant, son sexe, sa génération, sa branche, le booléen
     * `vivant` et sa position dans le graphe (ADR 0003) — **mais plus son nom** : son
     * `displayName` vaut {@see NOM_MASQUE} (révision du 2026-10-01). C'est la liste
     * blanche du § 11.1 du contrat, et c'est à l'appelant de l'appliquer.
     */
    public function voitPersonne(int $personId): bool;

    /**
     * La même question pour plusieurs personnes d'un coup.
     *
     * Elle existe pour une raison mesurable : l'arbre compte 274 personnes et le graphe
     * les sérialise toutes à chaque requête. Une boucle sur `voitPersonne()` y
     * ferait 274 résolutions de cercle là où une seule suffit. Un appelant qui sérialise
     * une liste passe par ici.
     *
     * @param list<int> $personIds
     *
     * @return list<int> ceux que le lecteur voit, dans l'ordre reçu, doublons retirés
     */
    public function filtrerPersonnes(array $personIds): array;
}
