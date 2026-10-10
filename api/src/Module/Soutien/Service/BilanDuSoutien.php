<?php

declare(strict_types=1);

namespace App\Module\Soutien\Service;

use App\Module\Soutien\Entity\Releve;
use App\Module\Soutien\Repository\ReleveRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * La charge utile de `GET /api/soutien` : le rythme du moment, la jauge, et l'historique par
 * mois et par année.
 *
 * ## Comment un mois se calcule
 *
 * Un relevé dit « le compte reçoit X par semaine » **à partir de ce jour-là**. Chaque jour
 * compte donc pour un septième du dernier relevé connu à cette date — le relevé suivant
 * prend le relais, et un jour sans visite (donc sans relevé) garde le rythme de la veille.
 * La somme des jours d'un mois est ce que le mois a reçu en dons promis ; celle des mois,
 * ce que l'année a reçu. Le mois en cours s'arrête à aujourd'hui (`complet: false`).
 *
 * Avant le premier relevé, on ne sait rien, et on ne remplit rien : l'historique commence le
 * jour où la page a été ouverte pour la première fois avec un pseudo configuré.
 *
 * Les montants sortent en **centimes entiers** ; l'écran les met en forme.
 */
final class BilanDuSoutien
{
    private const string URL_DON = 'https://liberapay.com/%s/donate';

    public function __construct(
        private readonly ReglagesDuSoutien $reglages,
        private readonly ReleveDuJour $releveDuJour,
        private readonly ReleveRepository $releves,
        private readonly ClockInterface $horloge,
    ) {
    }

    /** @return array<string, mixed> */
    public function charge(): array
    {
        $pseudo = $this->reglages->pseudo();
        $objectif = $this->reglages->objectifMensuel();

        if (null === $pseudo) {
            return [
                'configure' => false,
                'objectifMensuel' => $objectif,
            ];
        }

        $this->releveDuJour->assurer();
        $releves = $this->releves->duPseudo($pseudo);
        $dernier = [] === $releves ? null : $releves[array_key_last($releves)];

        [$mois, $annees] = self::historique($releves, $this->horloge->now()->setTime(0, 0));

        return [
            'configure' => true,
            'pseudo' => $pseudo,
            'lien' => \sprintf(self::URL_DON, rawurlencode($pseudo)),
            'objectifMensuel' => $objectif,
            'actuel' => null === $dernier ? null : [
                'parSemaine' => $dernier->getRecuParSemaineCentimes(),
                'parMois' => self::parMois($dernier->getRecuParSemaineCentimes()),
                'devise' => $dernier->getDevise(),
                'donateurs' => $dernier->getDonateurs(),
                'releveLe' => $dernier->getReleveLe()->format(\DateTimeInterface::ATOM),
            ],
            'depuis' => [] === $releves ? null : $releves[0]->getJour()->format('Y-m-d'),
            'mois' => $mois,
            'annees' => $annees,
        ];
    }

    /** Le rythme mensuel d'un montant hebdomadaire : 52 semaines pour 12 mois. */
    public static function parMois(int $centimesParSemaine): int
    {
        return (int) round($centimesParSemaine * 52 / 12);
    }

    /**
     * Les mois puis les années, le plus récent d'abord. Public pour être éprouvé sans base.
     *
     * @param list<Releve> $releves du plus ancien au plus récent
     *
     * @return array{0: list<array{mois: string, centimes: int, complet: bool}>, 1: list<array{annee: int, centimes: int}>}
     */
    public static function historique(array $releves, \DateTimeImmutable $aujourdhui): array
    {
        if ([] === $releves) {
            return [[], []];
        }

        /** @var array<string, float> $parMois */
        $parMois = [];
        $indice = 0;
        $courant = $releves[0];
        $jour = $releves[0]->getJour();

        while ($jour <= $aujourdhui) {
            // Le dernier relevé pris ce jour-là ou avant.
            while (isset($releves[$indice + 1]) && $releves[$indice + 1]->getJour() <= $jour) {
                $courant = $releves[++$indice];
            }

            $cle = $jour->format('Y-m');
            $parMois[$cle] = ($parMois[$cle] ?? 0.0) + $courant->getRecuParSemaineCentimes() / 7;
            $jour = $jour->modify('+1 day');
        }

        $moisCourant = $aujourdhui->format('Y-m');
        $mois = [];
        $annees = [];
        foreach ($parMois as $cle => $centimes) {
            $arrondi = (int) round($centimes);
            $mois[] = ['mois' => $cle, 'centimes' => $arrondi, 'complet' => $cle !== $moisCourant];
            $annee = (int) substr($cle, 0, 4);
            $annees[$annee] = ($annees[$annee] ?? 0) + $arrondi;
        }

        krsort($annees);

        return [
            array_reverse($mois),
            array_map(
                static fn (int $annee, int $centimes): array => ['annee' => $annee, 'centimes' => $centimes],
                array_keys($annees),
                array_values($annees),
            ),
        ];
    }
}
