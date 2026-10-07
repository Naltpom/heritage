"""Une planche de contrôle : quelques images d'un dossier, côte à côte.

    python outils/planche.py sortie/rushes/arbre 8 sortie/planche-arbre.jpg
"""
import glob, sys
from PIL import Image, ImageDraw
dossier, n, sortie = sys.argv[1], int(sys.argv[2]), sys.argv[3]
fs = sorted(glob.glob(dossier + '/*.jpg') + glob.glob(dossier + '/*.png'))
choix = [fs[round(i * (len(fs) - 1) / max(1, n - 1))] for i in range(n)] if len(fs) > n else fs
L = 640
ims = []
for f in choix:
    im = Image.open(f).convert('RGB')
    im = im.resize((L, round(im.height * L / im.width)))
    ImageDraw.Draw(im).text((8, 6), f.rsplit('/', 1)[1], fill=(255, 220, 120))
    ims.append(im)
col = 3
h = ims[0].height
out = Image.new('RGB', (L * col + 4 * (col - 1), (h + 4) * ((len(ims) + col - 1) // col)), (30, 30, 30))
for i, im in enumerate(ims):
    out.paste(im, ((i % col) * (L + 4), (i // col) * (h + 4)))
out.save(sortie, quality=85)
