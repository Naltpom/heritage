// Charge narration/voix.js + timeline.js dans Node (pour le tournage des rushes et les contrôles).
const vm = require('node:vm'), fs = require('node:fs'), path = require('node:path')
module.exports = function chargerTimeline() {
  const racine = path.join(__dirname, '..')
  const ctx = { window: {}, console }
  vm.createContext(ctx)
  vm.runInContext(fs.readFileSync(path.join(racine, 'narration', 'voix.js'), 'utf8'), ctx)
  return vm.runInContext(fs.readFileSync(path.join(racine, 'timeline.js'), 'utf8') + '\n;({ LIGNES, PAUSES, R, SCENE, DUREE, instant })', ctx)
}
