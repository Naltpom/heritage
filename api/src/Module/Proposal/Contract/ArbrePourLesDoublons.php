<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Ce que `Proposal` demande à l'arbre pour signaler un doublon **avant** le dépôt d'une
 * création (`POST /api/demandes/doublons-probables`, § 6.3 ter du contrat d'API). `Tree`
 * l'implémente (`Tree\Service\VueDeLArbrePourLesDoublons`).
 *
 * ── Ce contrat décide de la visibilité, et c'est voulu ──────────────────────────
 *
 * À la différence de `Demarche\Contract\PortArbrePourDemarches`, qui ne rend que des
 * noms déjà autorisés par l'appelant, celui-ci rend des **fiches** — noms, dates, lieux,
 * proches — trouvées par une recherche. Il doit donc filtrer exactement comme la
 * recherche du § 5.5, et le seul endroit où ce filtre existe déjà, avec la réciprocité
 * des vivants branchée, est le cercle de `Tree` (`Tree\Serializer\CercleDuLecteur`).
 * Recomposer le même filtre ici, avec le contrat du noyau, en ferait deux — et le
 * second serait celui qu'on oublierait de corriger (ADR 0003, « le filtrage est dans la
 * sérialisation »).
 *
 * L'implémentation s'engage donc, pour le **lecteur courant** (bascule « voir comme un
 * membre » comprise) :
 *
 * - une personne que le cercle ne montre pas — fiche confidentielle hors du cercle —
 *   est **absente**, pas réduite : ni résultat, ni compte, ni indice (ADR 0003, « la
 *   recherche ne renvoie jamais une personne vivante hors cercle ») ;
 * - la naissance d'un vivant passe par la réciprocité (ADR 0016) : la date servie **et
 *   la date comparée** sont la date réduite. Comparer sur la vraie date ferait du
 *   rapprochement un oracle — « il me signale un doublon quand je tape 1985 » ;
 * - un proche (parent, conjoint, enfant) que le cercle ne montre pas reste cité, sous
 *   le masque `Personne vivante`, comme sur la fiche d'un proche.
 */
interface ArbrePourLesDoublons
{
    /**
     * Les personnes de l'arbre **visibles du lecteur courant** dont la clé de recherche
     * contient chaque mot de `$terme` (même règle que `GET /api/arbre/recherche`).
     *
     * Aucun tri par ressemblance ici : c'est `Proposal` qui compare
     * ({@see \App\Module\Proposal\Service\RapprochementDeDoublons}). Le plafond porte sur
     * le jeu **déjà filtré**, comme à la recherche.
     *
     * @return list<PersonneCandidate>
     */
    public function candidats(string $terme, int $limite): array;
}
