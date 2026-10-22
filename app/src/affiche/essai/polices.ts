/**
 * Les polices de l'affiche lues **sur le disque**, pour les tests : les mêmes fichiers que
 * ceux que le navigateur télécharge (`../polices/`), donc les mêmes mesures.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { CLES_DE_POLICE, installerPolices, MetriquesDePolice } from '../polices'

export function cheminDePolice(cle: string): string {
  return fileURLToPath(new URL(`../polices/${cle}.ttf`, import.meta.url))
}

export function installerLesPolicesDuDisque(): void {
  installerPolices(new Map(CLES_DE_POLICE.map((cle) => [cle, MetriquesDePolice.depuis(readFileSync(cheminDePolice(cle)))])))
}
