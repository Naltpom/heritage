<?php

declare(strict_types=1);

namespace App\Module\Soutien\Entity;

use App\Module\Soutien\Repository\ReleveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce que Liberapay annonçait **un jour donné** pour le compte de soutien du site.
 *
 * Liberapay ne publie que l'état **présent** (`/<pseudo>/public.json`) : ce que le compte
 * reçoit par semaine et combien de donateurs le soutiennent. Il ne publie aucun historique.
 * Pour tracer « combien par mois, combien par an », il faut donc garder nous-mêmes une
 * photographie par jour — c'est cette table, et rien d'autre.
 *
 * ## Une ligne par jour et par pseudo
 *
 * La contrainte d'unicité `(pseudo, jour)` rend le relevé idempotent : deux lectures le
 * même jour n'en font qu'une. Le pseudo est gardé sur chaque ligne parce qu'il est un
 * réglage d'administration : le jour où il change, l'historique de l'ancien compte ne doit
 * pas se mélanger à celui du nouveau.
 *
 * ## Des centimes, pas des flottants
 *
 * Liberapay rend des montants en texte décimal (`"829.48"`). On les garde en **centimes
 * entiers** : un `float` additionné sur un an finit par afficher « 44,999999 € ».
 *
 * ## Ce que ces chiffres sont, et ne sont pas
 *
 * Ce sont des **dons promis** — le rythme en cours —, pas de l'argent encaissé : Liberapay
 * fait payer les donateurs à l'avance (souvent plusieurs semaines d'un coup) puis reverse
 * chaque semaine. Le détail des versements n'est visible que dans l'espace Liberapay du
 * propriétaire. L'écran le dit en toutes lettres.
 */
#[ORM\Entity(repositoryClass: ReleveRepository::class)]
#[ORM\Table(name: 'sou_releve')]
#[ORM\UniqueConstraint(name: 'uniq_sou_releve_pseudo_jour', columns: ['pseudo', 'jour'])]
class Releve
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Le pseudo Liberapay relevé, tel que le réglage le portait ce jour-là. */
    #[ORM\Column(length: 32)]
    private string $pseudo;

    /** Le jour du relevé, sans heure. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $jour;

    /** Ce que le compte reçoit **par semaine**, en centimes — l'unité de Liberapay. */
    #[ORM\Column]
    private int $recuParSemaineCentimes;

    /** Code ISO 4217 (`EUR`). Un compte Liberapay peut recevoir dans une autre devise. */
    #[ORM\Column(length: 3)]
    private string $devise;

    /** Le nombre de donateurs, secrets compris : Liberapay les compte sans les nommer. */
    #[ORM\Column]
    private int $donateurs;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $releveLe;

    public function __construct(
        string $pseudo,
        \DateTimeImmutable $jour,
        int $recuParSemaineCentimes,
        string $devise,
        int $donateurs,
        \DateTimeImmutable $releveLe,
    ) {
        $this->pseudo = $pseudo;
        $this->jour = $jour->setTime(0, 0);
        $this->recuParSemaineCentimes = $recuParSemaineCentimes;
        $this->devise = $devise;
        $this->donateurs = $donateurs;
        $this->releveLe = $releveLe;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPseudo(): string
    {
        return $this->pseudo;
    }

    public function getJour(): \DateTimeImmutable
    {
        return $this->jour;
    }

    public function getRecuParSemaineCentimes(): int
    {
        return $this->recuParSemaineCentimes;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function getDonateurs(): int
    {
        return $this->donateurs;
    }

    public function getReleveLe(): \DateTimeImmutable
    {
        return $this->releveLe;
    }
}
