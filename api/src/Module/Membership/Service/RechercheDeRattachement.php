<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;

/**
 * La recherche de rattachement — **le principal risque du produit**, et l'endroit où il
 * est fermé.
 *
 * Sans garde-fous, un lien d'invitation devient un oracle permettant d'énumérer les
 * personnes vivantes de l'arbre (§ 6.1 du dossier, ADR 0003). Ce service porte les
 * garde-fous qui tiennent à la *recherche* ; le plafond horaire et l'indistinguabilité de
 * son dépassement sont tenus par {@see ServiceDInscription}, parce qu'ils portent sur la
 * *réponse*, pas sur le calcul.
 *
 * ## Ce que ce service rend, exhaustivement
 *
 * Une liste de {@see PersonneDeLArbre}, dont l'appelant ne sortira que `ref` opaque et
 * `libelleMinimal()`. Jamais `personId`, jamais le nom d'affichage complet, jamais les
 * prénoms entiers, jamais la date exacte, ni lieu, ni sexe, ni génération, ni branche, ni
 * parenté, ni portrait — **ni le nombre total de correspondances trouvées avant
 * plafonnement**. Ce dernier point est le moins intuitif et le plus important : « ni un
 * décompte, ni un *et 7 autres* — ce serait un oracle de cardinalité, qui permet de tester
 * une hypothèse sans même lire un nom » (§ 4.2).
 *
 * D'où la forme de {@see self::chercher()} : elle **tronque avant de rendre**, et ne rend
 * jamais le total. L'appelant n'a donc pas le moyen de le divulguer.
 *
 * ## Les personnes décédées ne sont PAS cherchables — depuis le 1er octobre 2026
 *
 * Elles l'ont été, au nom d'un « oracle inverse » : les exclure faisait dire à « votre
 * recherche ne rend rien » qu'une personne était décédée plutôt qu'inexistante. **Cet
 * argument ne tient plus**, et la raison est écrite dans l'ADR 0013 : tout porteur du lien
 * peut désormais créer un compte en attente sans prouver quoi que ce soit (« Je ne me trouve
 * pas »), et un compte en attente **voit tous les défunts** — c'est la conséquence
 * mécanique d'un cercle vide (ADR 0003, `MemberStatus`). L'état civil des morts n'est donc
 * plus un secret que ce formulaire aurait à garder ; l'oracle protégeait une information
 * qu'on obtient, deux écrans plus loin, en toutes lettres.
 *
 * Ce qui reste vrai, en revanche, et qui décide : **le rattachement d'un compte à une
 * personne décédée n'a pas de sens fonctionnel.** Le proposer, c'était laisser un
 * candidat se reconnaître dans son arrière-grand-père homonyme et créer un compte ancré
 * sur un mort. Les deux modes les écartent — la recherche comme la confirmation d'un
 * pré-lien, qui rend alors `candidats: []` et consomme sa tentative comme une date fausse.
 *
 * La propriété « vivante » est celle que calcule `Tree` (§ 9.2 du modèle), portée par
 * {@see PersonneDeLArbre::$vivante} : une personne sans décès connu et née il y a moins
 * de cent ans est vivante, et reste donc proposée. Le cercle de visibilité, lui, ne
 * s'applique pas ici : personne n'est encore connecté.
 *
 * ## La date, et pourquoi elle se compare sur l'étiquette compacte
 *
 * L'arbre ne porte pas de date ferme : `ABT 1934`, `BET 1901 AND 1909`, `22 MAY 1949`. La
 * comparaison se fait donc **à la granularité dont la fiche dispose**, et jamais en
 * aplatissant une fourchette en date ferme (§ 4 du dossier). Une fiche sans aucune date de
 * naissance **ne peut être confirmée par personne** : c'est le sens strict de « preuve de
 * connaissance », et un candidat dans ce cas passe par la validation d'un administrateur
 * plutôt que par une porte qui s'ouvrirait sur rien.
 */
final readonly class RechercheDeRattachement
{
    public function __construct(private NormalisationDeNom $noms)
    {
    }

    /**
     * La recherche floue du mode « recherche » — au plus `$max` candidats.
     *
     * Les trois critères sont **conjonctifs** : nom proche, un prénom en commun, et date
     * compatible. Les relâcher élargirait mécaniquement ce qu'un porteur de jeton peut
     * faire correspondre, donc ce qu'il peut apprendre — et la seule chose qu'il a le
     * droit d'apprendre, c'est qu'il s'est reconnu lui-même.
     *
     * @return list<PersonneDeLArbre>
     */
    public function chercher(VueDeLArbre $arbre, CriteresDeRattachement $criteres, int $max): array
    {
        $nom = $this->noms->normaliser($criteres->nom);
        $prenoms = $this->noms->jetons($criteres->prenoms);

        $trouves = [];

        foreach ($arbre->personnes as $personne) {
            // Un défunt n'est pas un titulaire de compte possible. Voir l'en-tête : l'argument
            // de l'oracle inverse est tombé avec l'inscription sans fiche.
            if (!$personne->vivante) {
                continue;
            }

            if (!$this->noms->proche($nom, $this->noms->normaliser($personne->nom))) {
                continue;
            }

            if (!$this->noms->unPrenomEnCommun($prenoms, $this->noms->jetons($personne->prenoms))) {
                continue;
            }

            if (!$this->correspondALaDate($personne, $criteres)) {
                continue;
            }

            $trouves[] = $personne;
        }

        // Tri par identifiant : un ordre **stable**, et surtout un ordre qui n'encode
        // aucun score. Un classement par pertinence dirait au porteur du jeton lequel de
        // ses essais approche le but, ce qui est exactement l'information qu'on lui refuse.
        usort($trouves, static fn (PersonneDeLArbre $a, PersonneDeLArbre $b): int => $a->id <=> $b->id);

        // Tronqué **ici**, avant de rendre : l'appelant ne voit jamais combien il y en
        // avait. Voir l'en-tête — l'oracle de cardinalité.
        return \array_slice($trouves, 0, max(0, $max));
    }

    /**
     * Le mode « confirmation » : la personne est déjà désignée, il n'y a qu'une date à
     * confirmer.
     *
     * **Le nom et les prénoms ne sont pas vérifiés**, alors que le formulaire les demande.
     * Ce n'est pas un oubli : les deux modes demandent les mêmes trois champs parce qu'« un
     * formulaire qui ne demanderait que la date en mode confirmation dirait au porteur
     * *nous savons déjà ton nom* » (§ 4.1). La preuve de connaissance, elle, est la date —
     * et exiger en plus l'orthographe exacte d'un nom de jeune fille refuserait la bonne
     * personne sans jamais lui dire pourquoi, puisqu'aucun message ne le lui apprendrait.
     *
     * @return list<PersonneDeLArbre> zéro ou **un** candidat : « au plus 1 en mode confirmation »
     */
    public function confirmer(VueDeLArbre $arbre, int $personId, CriteresDeRattachement $criteres): array
    {
        $personne = $arbre->personne($personId);

        // Une personne pré-liée décédée — une erreur de l'administrateur, ou un décès saisi
        // depuis l'envoi du lien — ne se confirme pas : même réponse qu'une date fausse.
        if (null === $personne || !$personne->vivante || !$this->correspondALaDate($personne, $criteres)) {
            return [];
        }

        return [$personne];
    }

    /**
     * La date soumise est-elle compatible avec celle de la fiche ?
     *
     * L'étiquette compacte du § 5.2 du contrat prend six formes, et chacune porte une
     * granularité différente :
     *
     * | Étiquette    | Ce qu'on exige de la date soumise |
     * |--------------|-----------------------------------|
     * | `22.5.1949`   | le jour, le mois et l'année       |
     * | `5/1949`     | le mois et l'année                |
     * | `1949`       | l'année                           |
     * | `~1934`      | l'année                           |
     * | `1901-1909`  | une année **dans** l'intervalle   |
     * | `<1920`, `>1920` | l'année de la borne           |
     * | *(vide)*     | **rien ne correspond**            |
     *
     * Le dernier cas est le seul qui soit une décision plutôt qu'une lecture : une fiche
     * sans date de naissance ne peut pas servir de preuve de connaissance. Refuser est
     * conservateur — le candidat passe par un administrateur — là où accepter ouvrirait un
     * rattachement sur la seule foi d'un nom.
     */
    private function correspondALaDate(PersonneDeLArbre $personne, CriteresDeRattachement $criteres): bool
    {
        $etiquette = trim($personne->etiquetteNaissance);

        if ('' === $etiquette) {
            return false;
        }

        if (1 === preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $etiquette, $m)) {
            return $criteres->naissance->format('j.n.Y') === \sprintf('%d.%d.%s', (int) $m[1], (int) $m[2], $m[3]);
        }

        if (1 === preg_match('/^(\d{1,2})\/(\d{4})$/', $etiquette, $m)) {
            return $criteres->naissance->format('n.Y') === \sprintf('%d.%s', (int) $m[1], $m[2]);
        }

        // Toutes les autres formes se ramènent à un intervalle d'années : un millésime
        // unique en est un intervalle de longueur nulle. On lit les millésimes présents
        // dans l'étiquette plutôt que d'énumérer les préfixes `~`, `<` et `>`, qui sont
        // une affaire de rendu et pourraient changer sans que la règle change.
        if (0 === preg_match_all('/\d{4}/', $etiquette, $millesimes)) {
            return false;
        }

        $annees = array_map('intval', $millesimes[0]);
        $annee = $criteres->annee();

        return $annee >= min($annees) && $annee <= max($annees);
    }
}
