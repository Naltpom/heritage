"""La bande-son de la vidéo verticale, puis l'assemblage final.

    python mixer.py            # sortie/presentation.muet.mp4    → sortie/heritage-presentation.mp4
    python mixer.py --bd       # sortie/presentation-bd.muet.mp4 → sortie/heritage-presentation-bd.mp4

Trois couches, de la plus présente à la plus discrète :
  1. la voix : la prise ElevenLabs entière (narration/prise/prise.mp3), posée à T0 ;
  2. une musique d'ambiance composée ici (libre de droits) : une nappe chaude et quelques
     notes de piano feutré, sans batterie ; elle descend d'environ 12 dB quand la voix parle ;
  3. des bruitages à peine audibles sur les interactions de l'interface.
"""
import json, os, subprocess, sys
import numpy as np
from scipy.signal import butter, sosfilt, fftconvolve
from scipy.io import wavfile

ICI = os.path.dirname(os.path.abspath(__file__))
SORTIE = os.path.join(ICI, 'sortie')
SR = 48000


def hz(m):
    return 440 * 2 ** ((m - 69) / 12)


def lp(y, fc, o=2):
    return sosfilt(butter(o, fc, 'low', fs=SR, output='sos'), y)


def hp(y, fc, o=2):
    return sosfilt(butter(o, fc, 'high', fs=SR, output='sos'), y)


def poser(piste, s, t, gain=1.0):
    i = int(round(t * SR))
    if i < 0:
        s, i = s[-i:], 0
    j = min(len(piste), i + len(s))
    if j > i:
        piste[i:j] += s[:j - i] * gain


def reverb(y, duree=3.2, humide=0.35, rng=None):
    """Une salle douce : une réponse impulsionnelle de bruit qui décroît, filtrée."""
    n = int(SR * duree)
    tt = np.arange(n) / SR
    ir = rng.standard_normal(n) * np.exp(-tt * 3.0 / duree * 2.3)
    ir = lp(ir, 5000)
    ir /= np.sqrt((ir ** 2).sum())
    w = fftconvolve(y, ir)[:len(y)]
    return y * (1 - humide) + w * humide * 2.2


# -------------------------------------------------------------- la musique
def nappe(f, d, rng):
    """Une note tenue : quelques partiels légèrement désaccordés, attaque et relâche lentes."""
    n = int(SR * d)
    tt = np.arange(n) / SR
    y = np.zeros(n)
    for k, g in [(1, 1.0), (2, 0.35), (3, 0.12), (4, 0.05)]:
        for det in (-0.0025, 0.0025):
            ph = rng.uniform(0, 2 * np.pi)
            y += g * np.sin(2 * np.pi * f * k * (1 + det) * tt + ph + 0.002 * np.sin(2 * np.pi * 0.2 * tt))
    env = np.minimum(1, tt / 1.6) * np.clip((d - tt) / 2.2, 0, 1)
    return y * env


def piano(f, d=4.0):
    """Un piano feutré : partiels légèrement inharmoniques, attaque douce, longue extinction."""
    n = int(SR * d)
    tt = np.arange(n) / SR
    y = np.zeros(n)
    for k, g in [(1, 1.0), (2, 0.42), (3, 0.16), (4, 0.08), (5, 0.03)]:
        fk = f * k * np.sqrt(1 + 0.0004 * k * k)
        y += g * np.sin(2 * np.pi * fk * tt) * np.exp(-tt * (0.9 + 0.55 * k))
    att = np.minimum(1, tt / 0.012)
    return lp(y * att, 2600)


def musique(duree, reperes, rng):
    n = int(SR * duree)
    # ré majeur, quatre accords lents qui reviennent : I – vi – IV – V(sus) ; un accord toutes les ~4,4 s
    accords = [[50, 57, 62, 64, 66], [47, 54, 59, 62, 66], [43, 50, 55, 59, 62], [45, 52, 57, 59, 64]]
    mesure = 4.4
    pad, pno, basse = np.zeros(n), np.zeros(n), np.zeros(n)
    r = np.random.default_rng(11)
    for m in range(int(duree / mesure) + 2):
        acc = accords[m % 4]
        t0 = m * mesure
        for a in acc[1:]:
            poser(pad, nappe(hz(a), mesure + 2.6, r), t0, 0.10)
        poser(basse, nappe(hz(acc[0] - 12), mesure + 2.0, r), t0, 0.22)
        # le piano : quelques notes seulement, jamais sur la voix d'une façon qui accroche
        motif = [acc[2] + 12, acc[3] + 12, acc[4] + 12, acc[3] + 12] if m % 2 == 0 else [acc[4] + 12, acc[2] + 12]
        for i, note in enumerate(motif):
            tn = t0 + 0.2 + i * (mesure / len(motif)) + r.uniform(-0.04, 0.04)
            poser(pno, piano(hz(note), 4.5), tn, 0.16 * r.uniform(0.75, 1.0))
    tt = np.arange(n) / SR
    pad = lp(pad, 1400)
    # la musique respire avec le récit : discrète au début, s'ouvre sur le logo final
    courbe = 0.55 + 0.45 * np.clip((tt - reperes['heritage'] + 3.0) / 3.0, 0, 1)
    courbe *= np.clip(tt / 2.5, 0, 1) * np.clip((duree - tt) / 2.6, 0, 1)
    y = (pad + basse + pno * np.clip((tt - 1.0) / 2, 0, 1)) * courbe
    y = reverb(y, 3.4, 0.4, rng)
    return y / np.abs(y).max()


# -------------------------------------------------------------- les bruitages
def bruitages(sons, n, rng):
    sfx = np.zeros(n)

    def souffle(d, f0, f1):  # un souffle d'air filtré, pour les grands mouvements de caméra
        m = int(SR * d)
        b = rng.standard_normal(m + 4000)
        out = np.zeros(m)
        for k in range(0, m, 480):
            fc = f0 * (f1 / f0) ** (k / m)
            out[k:k + 480] = lp(b[k:k + 2480], fc)[2000:2000 + len(out[k:k + 480])]
        tt = np.arange(m) / SR
        return out * np.sin(np.pi * tt / d) ** 2

    def tic():  # un toucher d'interface : un « tic » très court, boisé
        tt = np.arange(int(SR * 0.06)) / SR
        return np.sin(2 * np.pi * 1900 * tt) * np.exp(-tt * 110) * 0.6 + hp(rng.standard_normal(len(tt)), 4000) * np.exp(-tt * 300) * 0.15

    def touche():  # une touche de clavier
        tt = np.arange(int(SR * 0.035)) / SR
        return hp(rng.standard_normal(len(tt)), 2200) * np.exp(-tt * 220) * 0.5 + np.sin(2 * np.pi * 420 * tt) * np.exp(-tt * 160) * 0.2

    def clochette(m, d=2.2):
        tt = np.arange(int(SR * d)) / SR
        f = hz(m)
        return (np.sin(2 * np.pi * f * tt) + 0.25 * np.sin(2 * np.pi * f * 2.0 * tt) * np.exp(-tt * 4)) * np.exp(-tt * 2.4) * np.minimum(1, tt / 0.004)

    def papier(d=0.5):  # une feuille qu'on pose
        m = int(SR * d)
        tt = np.arange(m) / SR
        b = hp(lp(rng.standard_normal(m), 6000), 900)
        mod = np.abs(np.sin(2 * np.pi * 7 * tt + rng.uniform(0, 3))) ** 3
        return b * mod * np.exp(-tt * 6) * 0.6

    def pose():  # une pastille qui apparaît : une goutte grave et ronde
        tt = np.arange(int(SR * 0.18)) / SR
        return np.sin(2 * np.pi * (520 + 260 * np.exp(-tt * 40)) * tt) * np.exp(-tt * 26) * 0.5

    def decale(s, retard, d):
        y = np.zeros(int(SR * d))
        poser(y, s, retard)
        return y

    BANQUE = {
        'souffle': lambda: souffle(1.4, 300, 2600) * 0.5,
        'recul': lambda: souffle(2.2, 2400, 220) * 0.55,
        'tic': tic,
        'touche': lambda: touche() * 0.55,
        'pose': pose,
        'papier': papier,
        'lien': lambda: clochette(86, 1.2) * 0.12,
        'verifie': lambda: (clochette(81) + decale(clochette(88), 0.11, 2.2)) * 0.22,
        'note': lambda: clochette(79, 1.6) * 0.15,
        'declic': lambda: hp(rng.standard_normal(int(SR * 0.03)), 1500) * np.exp(-np.arange(int(SR * 0.03)) / SR * 140) * 0.5,
        'final': lambda: (clochette(74, 4) + clochette(81, 4) * 0.6 + decale(clochette(86, 3.6), 0.18, 4)) * 0.2,
    }
    for s in sons:
        poser(sfx, BANQUE[s['type']](), s['t'], s.get('gain', 1))
    return reverb(sfx, 1.4, 0.25, rng)


def main(bd):
    rng = np.random.default_rng(7)
    piste = json.load(open(os.path.join(SORTIE, 'presentation.piste.json'), encoding='utf-8'))
    duree = piste['duree']
    n = int(SR * duree)
    voix = np.zeros(n)
    brut = subprocess.run(['ffmpeg', '-v', 'error', '-i', os.path.join(ICI, 'narration', 'prise', 'prise.mp3'), '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'], capture_output=True, check=True).stdout
    poser(voix, np.frombuffer(brut, np.float32).astype(float), piste['t0'])
    voix /= np.abs(voix).max()
    # une très légère compression douce et un filtre de présence : une voix proche, pas radiophonique
    voix = hp(voix, 70)
    mus = musique(duree, piste['reperes'], rng)
    sfx = bruitages(piste['sons'], n, rng)
    # la musique s'efface sous la voix (≈ -12 dB), avec des transitions lentes
    env = np.convolve(np.abs(voix), np.ones(int(SR * 0.05)) / int(SR * 0.05), 'same')
    parle = np.convolve((env > 0.015).astype(float), np.ones(int(SR * 0.6)) / int(SR * 0.6), 'same').clip(0, 1)
    mus *= 1 - 0.75 * parle
    g = mus * 0.30
    gauche = g * 0.92 + lp(g, 500) * 0.08
    droite = np.concatenate([np.zeros(int(SR * 0.012)), g[:-int(SR * 0.012)]])  # un peu de largeur
    stereo = np.stack([gauche, droite], 1) + np.stack([voix, voix], 1) * 0.9 + np.stack([sfx, sfx], 1) * 0.5
    stereo /= np.abs(stereo).max() / 0.9
    nom = 'heritage-verticale-bd' if bd else 'heritage-verticale'
    bande = os.path.join(SORTIE, f'{nom}.wav')
    wavfile.write(bande, SR, (stereo * 32767).astype(np.int16))
    video = os.path.join(SORTIE, 'presentation-bd.muet.mp4' if bd else 'presentation.muet.mp4')
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', video, '-i', bande, '-map', '0:v', '-map', '1:a', '-c:v', 'copy',
                    '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11', '-ar', '48000', '-c:a', 'aac', '-b:a', '192k',
                    '-shortest', '-movflags', '+faststart', os.path.join(SORTIE, f'{nom}.mp4')], check=True)
    taille = os.path.getsize(os.path.join(SORTIE, f'{nom}.mp4')) / 1e6
    print(f'sortie/{nom}.mp4 : {duree:.1f} s, {taille:.1f} Mo')


if __name__ == '__main__':
    main('--bd' in sys.argv)
