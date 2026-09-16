<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

/**
 * Les quatre réglages d'administration dont `Membership` dépend (§ 2.2 du modèle).
 *
 * Une interface, et non une classe concrète, pour une raison qui s'est déjà payée
 * ailleurs : ces quatre valeurs conditionnent **les garde-fous les plus sensibles du
 * produit**, et un test qui doit semer `core_setting` pour vérifier qu'un plafond tient
 * est un test qu'on n'écrit pas. Ici, on passe un double en une ligne.
 *
 * **Aucune de ces valeurs n'est une constante**, et ce n'est pas une élégance : le § 6.2
 * du dossier l'exige nommément pour `N` — « le commanditaire a formulé ce chiffre avec
 * hésitation : il doit pouvoir le changer sans redéploiement ». L'ADR 0003 explique ce
 * qu'on évite : « un chiffre qu'on découvre faux et qu'on ne peut changer qu'en
 * redéployant sera changé en dur dans le code par le premier qui passe, sans trace et
 * sans discussion ».
 */
interface ReglagesDeMembership
{
    /**
     * `cercle.generations` — le `N` de la formule de l'ADR 0003. Défaut 3, borné à `[1, 6]`.
     *
     * **La borne haute n'est pas décorative** : à `N = 5` le cercle maximal couvre
     * 132 personnes sur 274, soit la moitié de l'arbre (§ 9.3). Au-delà de 6, la règle de
     * confidentialité ne protège plus personne.
     */
    public function generationsDuCercle(): int;

    /** `invitation.ttl_jours` — durée de validité d'un lien. Défaut 7, borné à `[1, 30]`. */
    public function ttlDInvitationEnJours(): int;

    /**
     * `invitation.tentatives_par_heure` — **le garde-fou anti-oracle du § 6.1 du dossier**.
     * Défaut 5, borné à `[1, 20]`.
     */
    public function tentativesParHeure(): int;

    /**
     * `invitation.candidats_max` — au plus 3 candidats renvoyés par la recherche de
     * rattachement. Défaut 3, borné à `[1, 5]`.
     */
    public function candidatsMax(): int;
}
