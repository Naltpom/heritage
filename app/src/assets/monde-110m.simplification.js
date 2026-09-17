// Allège ne_110m_admin_0_countries.geojson : ne garde que le nom français et
// l'ISO2, arrondit à 2 décimales (~1,1 km à l'équateur, invisible au zoom monde)
// et supprime les points devenus consécutivement identiques — un arrondi qui
// laisse deux points confondus produit un anneau dégénéré que Leaflet dessine
// en pointe.
const fs = require('fs')
const r = (n) => Math.round(n * 100) / 100

function anneau(points) {
  const sortie = []
  for (const [x, y] of points) {
    const p = [r(x), r(y)]
    const d = sortie[sortie.length - 1]
    if (d === undefined || d[0] !== p[0] || d[1] !== p[1]) sortie.push(p)
  }
  // un anneau doit rester fermé et compter au moins 4 points
  if (sortie.length > 0) {
    const [a, b] = [sortie[0], sortie[sortie.length - 1]]
    if (a[0] !== b[0] || a[1] !== b[1]) sortie.push([a[0], a[1]])
  }
  return sortie.length >= 4 ? sortie : null
}

function polygone(anneaux) {
  const g = anneaux.map(anneau).filter((a) => a !== null)
  return g.length > 0 ? g : null
}

const src = JSON.parse(fs.readFileSync('ne110m.geojson', 'utf8'))
const features = []
for (const f of src.features) {
  const g = f.geometry
  let geometry = null
  if (g.type === 'Polygon') {
    const p = polygone(g.coordinates)
    if (p !== null) geometry = { type: 'Polygon', coordinates: p }
  } else if (g.type === 'MultiPolygon') {
    const m = g.coordinates.map(polygone).filter((p) => p !== null)
    if (m.length > 0) geometry = { type: 'MultiPolygon', coordinates: m }
  }
  if (geometry === null) continue
  features.push({
    type: 'Feature',
    properties: { nom: f.properties.NAME_FR ?? f.properties.NAME, iso: f.properties.ISO_A2 },
    geometry,
  })
}
fs.writeFileSync('monde-110m.geo.json', JSON.stringify({ type: 'FeatureCollection', features }))
console.log('features conservées :', features.length, '/', src.features.length)
