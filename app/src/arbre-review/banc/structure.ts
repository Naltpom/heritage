/**
 * La lecture d'un fichier de STRUCTURE de l'arbre réel, pour le banc de `/arbre-review`
 * (`revue.banc.ts`) — jamais pour l'application, et le fichier lui-même n'entre jamais
 * dans le dépôt (ADR 0006). Il ne porte ni nom ni date complète : une ligne par personne
 * (`P|id|sexe|génération|branche|année|décédé|longueur du nom|longueur de la date de
 * naissance|longueur de la date de décès`), par famille (`F|id|mari|femme|divorcé`) et
 * par filiation (`C|famille|enfant|rang`). Les noms deviennent « Personne N<id> » ; la
 * largeur des fiches se recalcule comme `MesureDeFiche::largeurAffichee`.
 */
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, Sexe } from '@/lib/api'

export function lireStructure(texte: string): Graphe {
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const sexes: Record<string, Sexe> = { m: 'masculin', f: 'feminin', i: 'inconnu' }
  let ego = ''
  for (const ligne of texte.split(/\r?\n/)) {
    const c = ligne.split('|')
    if (c[0] === 'P') {
      const [, id, sexe, generation, branche, annee, decede, nom, nais, deces] = c
      const an = annee ? Number(annee) : null
      const vivant = decede === '0' && (an !== null ? an > 1916 : Number(generation) >= -2)
      // La largeur affichée, comme `MesureDeFiche::largeurAffichee` : 215 pour une personne
      // vivante ; sinon le nom ou la ligne de dates, et la photo.
      const lnais = Number(nais)
      const ldeces = Number(deces)
      const dates = lnais && ldeces ? lnais + 3 + ldeces : lnais ? lnais + 3 : ldeces ? ldeces + 2 : 1
      const w = vivant ? 215 : Math.max(104, Math.floor(Math.max(Number(nom) * 6.6, dates * 5.8) + 28)) + 48
      const p: NoeudPersonne = {
        id: `p${id}`,
        type: 'personne',
        personId: Number(id),
        x: 0,
        y: 0,
        w,
        h: 56,
        displayName: `Personne ${id}`,
        etiquetteNom: `Personne N${id}`,
        sex: sexes[sexe!] ?? 'inconnu',
        generation: generation === '' ? 0 : Number(generation),
        branch: (branche || null) as unknown as Branche,
        vivant,
        visibilite: 'complete',
        ...(an !== null ? { etiquetteDates: `${an} – ` } : {}),
      }
      noeuds.push(p)
      if (!ego) ego = p.id
    } else if (c[0] === 'F') {
      const [, id, mari, femme, divorce] = c
      const f: NoeudFamille = { id: `f${id}`, type: 'famille', familyId: Number(id), x: 0, y: 0, w: 10, h: 10, divorced: divorce === '1' }
      noeuds.push(f)
      if (mari) aretes.push({ de: `p${mari}`, vers: f.id, genre: 'union' })
      if (femme) aretes.push({ de: `p${femme}`, vers: f.id, genre: 'union' })
    } else if (c[0] === 'C') {
      const [, famille, enfant] = c
      aretes.push({ de: `f${famille}`, vers: `p${enfant}`, genre: 'filiation' })
    }
  }
  return {
    empreinte: '0'.repeat(64),
    ego,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
}

