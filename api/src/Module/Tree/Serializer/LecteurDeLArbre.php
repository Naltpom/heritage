<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\Regime;

/**
 * Qui lit l'arbre, et à quel titre. Objet valeur, construit une fois par requête par
 * {@see FabriqueDuLecteur}.
 *
 * Il existe pour la même raison que `Proposal\Service\Lecteur` : aucun normaliseur de ce
 * module ne reçoit jamais un jeton de sécurité ni une `Request`. Un service qui prend un
 * `LecteurDeLArbre` se teste en une ligne, sans pare-feu, sans session, sans base — et
 * c'est **ce qui rend écrivables** les tests de la règle la plus sensible du produit.
 *
 * ## Les deux champs qu'on oublierait volontiers
 *
 * **`regime`** porte la distinction `ATTENTE` / `MEMBRE` du § 1.6 du contrat. Ce n'est
 * pas un rôle et ce n'est pas un cercle de taille nulle : un compte `en_attente` n'a
 * **pas de cercle**, il ne voit aucune personne vivante, y compris celle à laquelle il
 * prétend se rattacher (§ 12.1, ADR 0003). La nuance vit dans le type pour qu'elle ne
 * disparaisse pas au premier remaniement.
 *
 * **`membreEmprunteId`** porte la bascule « voir comme un membre » (§ 10.4). Elle ne
 * retire aucun droit d'écriture ici — `Tree` ne fait que lire —, mais elle change
 * **trois** choses, et oublier la deuxième vide la bascule de son sens :
 *
 * 1. le cercle appliqué est celui du membre emprunté — résolu par le contrat du noyau,
 *    qui lit la bascule lui-même ;
 * 2. **l'`ETag` du graphe change avec le membre emprunté** ({@see EtagDuGraphe}). Sans
 *    cela, l'administrateur revalide, reçoit `304`, et regarde son propre graphe non
 *    filtré sous l'identité qu'il croit tester — l'outil de vérification donne une
 *    fausse assurance, ce qui est pire que pas d'outil du tout. **Il en change par
 *    conséquence et non par exception** : l'`ETag` hache le *cercle effectif* (§ 5.2), et
 *    le cercle effectif sous la bascule est celui du point 1. Aucune ligne du calcul de
 *    l'`ETag` ne nomme la bascule, et c'est ce qui la rend impossible à perdre — *une
 *    exception s'oublie au premier remaniement, une conséquence non* ;
 * 3. le calque « en attente » suit le membre emprunté, pas l'administrateur.
 *
 * ## Deux identifiants qu'il ne faut surtout pas confondre
 *
 * `membreEmprunteId` est un **`mbr_member.id`** : c'est ce que `Core` range en session, et
 * c'est ce que le cercle sait interpréter. `membreEmprunteUserId` est le **`core_user.id`**
 * du même membre — une autre séquence, un autre espace de noms. Seul le second sert à
 * comparer une identité à `prp_change_request.author_user_id`, donc à décider du calque ;
 * et `Tree` n'a aucun moyen de le déduire du premier sans franchir la frontière de
 * `Membership`. Tant qu'il n'est pas fourni, {@see self::identiteEffective()} rend `null`
 * sous la bascule — c'est-à-dire **aucun calque**, plutôt que celui de l'administrateur,
 * qui serait exactement ce que la règle 3 du § 10.4 interdit.
 */
final readonly class LecteurDeLArbre
{
    /**
     * @param int      $userId               ⇢ inter-module (`Core\User.id`)
     * @param string   $libelle              nom d'affichage du compte, jamais celui de la personne réclamée
     * @param bool     $administrateur       `ROLE_ADMIN`. **N'ouvre à lui seul aucune porte** : un
     *                                       administrateur passe par un cercle complet, jamais par un
     *                                       `if ($estAdmin) { return $tout; }` que l'ADR 0003 interdit
     * @param Regime   $regime               `Aucun` pour un compte `en_attente`, `Cercle` pour un compte actif
     * @param int|null $membreEmprunteId     le `mbr_member.id` emprunté par la bascule, `null` hors bascule
     * @param int|null $membreEmprunteUserId le `core_user.id` du même membre, quand un appelant sait le
     *                                       résoudre — `null` sinon, et le calque se referme
     */
    public function __construct(
        public int $userId,
        public string $libelle,
        public bool $administrateur,
        public Regime $regime = Regime::Aucun,
        public ?int $membreEmprunteId = null,
        public ?int $membreEmprunteUserId = null,
    ) {
    }

    /**
     * L'identité **de compte** à comparer à l'auteur d'une demande.
     *
     * `null` sous la bascule tant que le compte du membre emprunté n'est pas résolu :
     * servir le calque de l'administrateur à cet instant montrerait un écran que le
     * membre emprunté ne verra jamais, c'est-à-dire l'inverse de ce que la bascule existe
     * pour vérifier.
     */
    public function identiteEffective(): ?int
    {
        if (!$this->sousBascule()) {
            return $this->userId;
        }

        return $this->membreEmprunteUserId;
    }

    public function sousBascule(): bool
    {
        return null !== $this->membreEmprunteId;
    }

    /**
     * Le régime **effectif**. Sous la bascule, l'administrateur lit comme un membre
     * actif : la bascule remplace un cercle par un autre, elle n'en supprime jamais un.
     */
    public function regimeEffectif(): Regime
    {
        return $this->sousBascule() ? Regime::Cercle : $this->regime;
    }

    /** Un compte `en_attente` : personnes décédées uniquement, aucune personne vivante. */
    public function enRegimeDAttente(): bool
    {
        return Regime::Aucun === $this->regimeEffectif();
    }

    /**
     * Voit-il le calque de **toutes** les demandes, ou seulement des siennes ?
     *
     * Sous la bascule, un administrateur voit ce que verrait le membre emprunté — donc
     * plus le calque global (§ 10.4, règle 3).
     */
    public function voitToutesLesDemandes(): bool
    {
        return $this->administrateur && !$this->sousBascule();
    }

    /**
     * De quoi mémoriser un calcul par lecteur **pour la durée d'une requête**, bascule
     * comprise : deux membres empruntés successivement ne partagent pas leur cercle.
     */
    public function cle(): string
    {
        return $this->userId.':'.($this->membreEmprunteId ?? '');
    }

    /**
     * Le même lecteur, dans le vocabulaire du contrat du noyau
     * {@see \App\Module\Core\Contract\CalqueEnAttenteInterface}.
     *
     * ## Le point délicat : la bascule dont on ne connaît que le membre
     *
     * `Core\Contract\Lecteur` exprime la bascule par un **identifiant de compte**
     * (`membreEmprunteUserId`), et `Core` ne range en session qu'un `mbr_member.id`. Tant
     * que personne ne résout l'un en l'autre, laisser `membreEmprunteUserId` à `null`
     * ferait rendre `true` à `voitToutesLesDemandes()` — l'administrateur recevrait
     * **son propre calque complet** sous l'identité qu'il croit tester, c'est-à-dire
     * exactement ce que la règle 3 du § 10.4 interdit.
     *
     * On ferme donc, en empruntant au contrat sa propre façon de dire « ce lecteur ne
     * reçoit aucun calque » : le régime d'attente. Ce n'est pas un détournement, c'est la
     * seule valeur du contrat qui porte ce sens, et elle le porte exactement. Le jour où
     * la bascule transportera aussi le compte, la condition disparaît d'elle-même.
     */
    public function pourLeCalque(): Lecteur
    {
        $bascule = $this->sousBascule();
        $inconnu = $bascule && null === $this->membreEmprunteUserId;

        return new Lecteur(
            $this->userId,
            $this->libelle,
            $this->administrateur,
            $this->enRegimeDAttente() || $inconnu,
            $this->membreEmprunteUserId,
        );
    }
}
