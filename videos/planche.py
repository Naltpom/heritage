import sys, glob
from PIL import Image
vid = sys.argv[1]
fs = sorted(glob.glob(f'sortie/apercu/{vid}_*.png'), key=lambda f: float(f.rsplit('_', 1)[1][:-4]))
ims = [Image.open(f).resize((270, 480)) for f in fs]
par = 7
lig = (len(ims) + par - 1) // par
out = Image.new('RGB', (276 * min(par, len(ims)), 486 * lig), (40, 40, 40))
for i, im in enumerate(ims): out.paste(im, ((i % par) * 276, (i // par) * 486))
out.save(f'sortie/planche_{vid}.jpg', quality=85)
print([f.rsplit('_', 1)[1][:-4] for f in fs])
