"""Bande-son et assemblage : musique composée ici (donc libre de droits),
bruitages synthétisés, voix ; la musique s'efface sous la voix.

    python mixer.py bienvenue

Lit   sortie/<id>.muet.mp4, sortie/<id>.piste.json, sortie/<id>.jpg, voix/<id>/<n>.mp3
Écrit ../app/public/videos/<id>.mp4, <id>.jpg (affiche), <id>.vtt (sous-titres)
"""
import json, os, subprocess, sys
import numpy as np
from scipy.signal import butter, sosfilt
from scipy.io import wavfile

ICI = os.path.dirname(os.path.abspath(__file__))
PUBLIC = os.path.join(ICI, '..', 'app', 'public', 'videos')
SR = 48000

# Une couleur musicale par vidéo : tonalité (demi-tons au-dessus de do), tempo,
# pulsation (0 = sans batterie, pour les tutoriels où la voix compte d'abord).
AMBIANCES = {
    'bienvenue': dict(ton=0, bpm=100, pulse=0.6, mineur=False),
    'lire-l-arbre': dict(ton=2, bpm=96, pulse=0.35, mineur=False),
    'filtres-de-l-arbre': dict(ton=5, bpm=96, pulse=0.35, mineur=False),
    'proposer-une-correction': dict(ton=7, bpm=96, pulse=0.35, mineur=False),
    'sources-et-actes': dict(ton=4, bpm=92, pulse=0.25, mineur=True),
    'notifications-et-calendrier': dict(ton=9, bpm=100, pulse=0.35, mineur=False),
    'carte-et-chronologie': dict(ton=3, bpm=92, pulse=0.3, mineur=False),
}


def hz(m):
    return 440 * 2 ** ((m - 69) / 12)


def lp(y, fc, ordre=2):
    return sosfilt(butter(ordre, fc, 'low', fs=SR, output='sos'), y)


def hp(y, fc, ordre=2):
    return sosfilt(butter(ordre, fc, 'high', fs=SR, output='sos'), y)


def poser(piste, s, t, gain=1.0):
    i = int(t * SR)
    if i < 0:
        s, i = s[-i:], 0
    j = min(len(piste), i + len(s))
    if j > i:
        piste[i:j] += s[:j - i] * gain


def note(f, d, forme='saw', att=0.01, rel=0.3):
    t = np.arange(int(SR * d)) / SR
    if forme == 'saw':
        y = sum(np.sin(2 * np.pi * f * k * t * (1 + 0.002 * (k % 3 - 1))) / k for k in range(1, 7))
    else:
        y = np.sin(2 * np.pi * f * t) + 0.3 * np.sin(4 * np.pi * f * t) * np.exp(-t * 6)
    env = np.minimum(1, t / att) * np.clip((d - t) / rel, 0, 1)
    return y * env


def musique(duree, ton, bpm, pulse, mineur, rng):
    n = int(SR * duree)
    temps = 60 / bpm
    # I – V – vi – IV (ou i – VI – III – VII en mineur), deux mesures par accord
    if mineur:
        accords = [[52, 55, 59], [48, 52, 55], [55, 59, 62], [50, 54, 57]]
    else:
        accords = [[48, 52, 55], [55, 59, 62], [57, 60, 64], [53, 57, 60]]
    accords = [[m + ton for m in a] for a in accords]
    nappe, arp, basse, kick, hh = (np.zeros(n) for _ in range(5))
    mesure = 4 * temps
    for m in range(int(duree / mesure) + 1):
        acc = accords[(m // 2) % 4]
        t0 = m * mesure
        if m % 2 == 0:
            for a in acc:
                poser(nappe, note(hz(a), 2 * mesure + 0.8, att=0.7, rel=1.4), t0, 0.12)
            poser(basse, note(hz(acc[0] - 12), 2 * mesure, 'sin', att=0.05, rel=0.5), t0, 0.4)
        for c in range(8):
            a = (acc + [acc[0] + 12])[[0, 1, 2, 3, 2, 1, 2, 3][c]] + 12
            d = 0.4
            poser(arp, note(hz(a), d, 'sin', att=0.004, rel=0.35) * np.exp(-np.arange(int(SR * d)) / SR * 8), t0 + c * temps / 2, 0.14)
        for b in range(4):
            tb = t0 + b * temps
            tt = np.arange(int(SR * 0.25)) / SR
            poser(kick, np.sin(2 * np.pi * (48 + 70 * np.exp(-tt * 30)) * tt) * np.exp(-tt * 14), tb, 0.5)
            poser(hh, rng.standard_normal(int(SR * .05)) * np.exp(-np.arange(int(SR * .05)) / SR * 90), tb + temps / 2, 0.05)
    t = np.arange(n) / SR
    rythme = pulse * np.clip((t - 2.5) / 1.0, 0, 1) * np.clip((duree - 3.0 - t) / 0.5, 0, 1)
    y = lp(nappe, 1800) + arp * np.clip((t - 1.0) / 2, 0, 1) + basse + (kick + hp(hh, 7000)) * rythme
    y *= np.clip(t / 0.8, 0, 1) * np.clip((duree - t) / 2.0, 0, 1)
    return y / np.abs(y).max()


def bruitages(sons, n, rng):
    sfx = np.zeros(n)

    def souffle(d, f0, f1):
        m = int(SR * d)
        b = rng.standard_normal(m + 4000)
        out = np.zeros(m)
        for k in range(0, m, 480):
            fc = f0 * (f1 / f0) ** (k / m)
            # le filtre est amorcé sur 2 000 échantillons pour ne pas claquer à chaque bloc
            out[k:k + 480] = lp(b[k:k + 2480], fc)[2000:2000 + len(out[k:k + 480])]
        tt = np.arange(m) / SR
        return out * np.sin(np.pi * tt / d) ** 2

    def coup(f, d=0.5):
        tt = np.arange(int(SR * d)) / SR
        return np.sin(2 * np.pi * (f + 90 * np.exp(-tt * 25)) * tt) * np.exp(-tt * 9) + lp(rng.standard_normal(len(tt)), 1500) * np.exp(-tt * 40) * 0.6

    def clochette(m, d=1.2):
        tt = np.arange(int(SR * d)) / SR
        f = hz(m)
        return (np.sin(2 * np.pi * f * tt) + 0.4 * np.sin(2 * np.pi * f * 2.76 * tt) * np.exp(-tt * 5)) * np.exp(-tt * 3.5)

    def tape():  # un toucher : un petit « tok » boisé
        tt = np.arange(int(SR * 0.08)) / SR
        return np.sin(2 * np.pi * 1400 * tt * (1 - tt * 3)) * np.exp(-tt * 70) + hp(rng.standard_normal(len(tt)), 3000) * np.exp(-tt * 200) * 0.3

    def touche():  # une touche de clavier, très discrète
        tt = np.arange(int(SR * 0.03)) / SR
        return hp(rng.standard_normal(len(tt)), 2500) * np.exp(-tt * 260)

    def pop():
        tt = np.arange(int(SR * 0.15)) / SR
        return np.sin(2 * np.pi * (500 + 700 * np.exp(-tt * 40)) * tt) * np.exp(-tt * 28)

    def clic():
        tt = np.arange(int(SR * 0.12)) / SR
        return coup(180, 0.25) * 0.6 + np.pad(hp(rng.standard_normal(len(tt)), 2000) * np.exp(-tt * 120), (0, int(SR * 0.25) - len(tt)))

    def decale(son, retard, duree):  # un son retardé, ramené à une durée donnée
        y = np.zeros(int(SR * duree))
        poser(y, son, retard)
        return y

    BANQUE = {
        'eclosion': lambda: np.concatenate([souffle(0.7, 300, 4000) * 0.8, np.zeros(int(SR * 0.5))]) + clochette(84, 1.2) * 0.25,
        'souffle': lambda: souffle(0.55, 400, 5000),
        'recul': lambda: souffle(0.6, 5000, 400),
        'tape': lambda: tape() * 0.8,
        'touche': lambda: touche() * 0.5,
        'pop': lambda: pop() * 0.5,
        'clic': lambda: clic() * 0.7,
        'tampon': lambda: coup(110, 0.4) * 0.9,
        'impact': lambda: coup(70, 0.6),
        'note1': lambda: clochette(76) * 0.35, 'note2': lambda: clochette(79) * 0.35,
        'note3': lambda: clochette(83) * 0.35, 'note4': lambda: clochette(88) * 0.35,
        'notif': lambda: (clochette(88, 0.8) + decale(clochette(93, 0.6), 0.09, 0.8)) * 0.3,
        'succes': lambda: (clochette(81, 1.0) + decale(clochette(88, 0.9), 0.1, 1.0)) * 0.3,
        'final': lambda: (clochette(69) + clochette(76) * 0.7 + clochette(81) * 0.5) * 0.4,
    }
    for s in sons:
        poser(sfx, BANQUE[s['type']](), s['t'], s.get('gain', 1))
    return sfx


def vtt(piste):
    def hms(t):
        return f'{int(t // 3600):02d}:{int(t % 3600 // 60):02d}:{t % 60:06.3f}'
    lignes = ['WEBVTT', '']
    ph = piste['phrases']
    for i, p in enumerate(ph):
        debut = p['mots'][0]['debut'] - 0.1
        fin = min(p['fin'] + 0.6, ph[i + 1]['mots'][0]['debut'] - 0.15) if i + 1 < len(ph) else p['fin'] + 1.0
        lignes += [str(i + 1), f'{hms(max(0, debut))} --> {hms(fin)}', p['texte'], '']
    return '\n'.join(lignes)


def main(vid):
    rng = np.random.default_rng(7)
    piste = json.load(open(os.path.join(ICI, 'sortie', f'{vid}.piste.json'), encoding='utf-8'))
    duree = piste['duree']
    n = int(SR * duree)
    amb = AMBIANCES[vid]
    mus = musique(duree, amb['ton'], amb['bpm'], amb['pulse'], amb['mineur'], rng)
    sfx = bruitages(piste['sons'], n, rng)
    voix = np.zeros(n)
    for i, p in enumerate(piste['phrases']):
        brut = subprocess.run(['ffmpeg', '-v', 'error', '-i', os.path.join(ICI, 'voix', vid, f'{i}.mp3'), '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'],
                              capture_output=True, check=True).stdout
        poser(voix, np.frombuffer(brut, np.float32).astype(float), p['debut'])
    voix /= np.abs(voix).max()
    # la musique descend d'environ 10 dB quand la voix parle
    env = np.convolve(np.abs(voix), np.ones(int(SR * 0.05)) / int(SR * 0.05), 'same')
    parle = np.convolve((env > 0.02).astype(float), np.ones(int(SR * 0.3)) / int(SR * 0.3), 'same').clip(0, 1)
    mus *= 1 - 0.68 * parle
    g = mus * 0.36
    gauche, droite = g * 0.94 + lp(g, 400) * 0.06, g
    stereo = np.stack([gauche, droite], 1) + np.stack([voix, voix], 1) * 0.85 + np.stack([sfx, sfx], 1) * 0.7
    stereo /= np.abs(stereo).max() / 0.89
    bande = os.path.join(ICI, 'sortie', f'{vid}.wav')
    wavfile.write(bande, SR, (stereo * 32767).astype(np.int16))
    os.makedirs(PUBLIC, exist_ok=True)
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', os.path.join(ICI, 'sortie', f'{vid}.muet.mp4'), '-i', bande,
                    '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11', '-ar', '48000',
                    '-c:a', 'aac', '-b:a', '160k', '-shortest', '-movflags', '+faststart', os.path.join(PUBLIC, f'{vid}.mp4')], check=True)
    # l'affiche : un JPEG léger, à la largeur d'une carte
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', os.path.join(ICI, 'sortie', f'{vid}.jpg'), '-vf', 'scale=540:-2', '-q:v', '4',
                    os.path.join(PUBLIC, f'{vid}.jpg')], check=True)
    open(os.path.join(PUBLIC, f'{vid}.vtt'), 'w', encoding='utf-8', newline='\n').write(vtt(piste))
    taille = os.path.getsize(os.path.join(PUBLIC, f'{vid}.mp4')) / 1e6
    print(f'{vid} : {duree:.1f} s, {taille:.1f} Mo')


if __name__ == '__main__':
    for v in sys.argv[1:]:
        main(v)
