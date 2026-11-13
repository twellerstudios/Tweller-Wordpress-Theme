#!/usr/bin/env python3
"""
15-second Christmas music bed for the Scene Tour reel.

An original arrangement, synthesised here from scratch, of the chorus of
"Jingle Bells" (James Lord Pierpont, 1857: public domain). Nothing is
sampled or licensed, so the track is free to use in paid ads.

    python3 music/make-music.py            ->  assets/music/jingle-bells-15s.wav

Glockenspiel melody, plucked bass, soft electric-piano chords, sleigh bells,
and a short room reverb. Needs numpy.
"""
from pathlib import Path
import wave
import numpy as np

SR = 48000
BPM = 128.0
BEAT = 60.0 / BPM
DUR = 15.0
rng = np.random.default_rng(1225)
OUT = Path(__file__).resolve().parent.parent / "assets" / "music" / "jingle-bells-15s.wav"


def hz(note):
    names = {"C": 0, "C#": 1, "D": 2, "D#": 3, "E": 4, "F": 5, "F#": 6, "G": 7, "G#": 8, "A": 9, "A#": 10, "B": 11}
    n, octv = note[:-1], int(note[-1])
    return 440.0 * 2 ** ((names[n] + 12 * (octv + 1) - 69) / 12)


def env(n, a=0.004, d=0.6):
    t = np.arange(n) / SR
    e = np.exp(-t / d)
    att = int(a * SR)
    if att:
        e[:att] *= np.linspace(0, 1, att)
    return e


def glock(f, length):
    """Bell-like tone: a few inharmonic partials, bright attack, long ring."""
    n = int((length + 1.4) * SR)
    t = np.arange(n) / SR
    s = (np.sin(2 * np.pi * f * t) * env(n, 0.002, 0.9)
         + 0.45 * np.sin(2 * np.pi * f * 2.76 * t) * env(n, 0.001, 0.25)
         + 0.20 * np.sin(2 * np.pi * f * 5.40 * t) * env(n, 0.001, 0.08)
         + 0.10 * np.sin(2 * np.pi * f * 4.0 * t) * env(n, 0.001, 0.4))
    return s


def epiano(freqs, length):
    """Soft Rhodes-ish chord: sine + gentle 2nd harmonic, tremolo, slow decay."""
    n = int((length + 0.6) * SR)
    t = np.arange(n) / SR
    out = np.zeros(n)
    for f in freqs:
        out += np.sin(2 * np.pi * f * t + 0.6 * np.sin(2 * np.pi * f * t) * np.exp(-t / 0.3))
    trem = 1 + 0.08 * np.sin(2 * np.pi * 4.5 * t)
    return out * env(n, 0.01, 0.9) * trem / len(freqs)


def pluck(f, length):
    """Karplus–Strong plucked string for the bass."""
    n = int((length + 0.3) * SR)
    period = int(SR / f)
    buf = rng.uniform(-1, 1, period)
    out = np.empty(n)
    for i in range(n):
        out[i] = buf[i % period]
        buf[i % period] = 0.996 * 0.5 * (buf[i % period] + buf[(i + 1) % period])
    return out * env(n, 0.003, 0.7)


def bells(length):
    """Sleigh-bell shake: band-limited noise with a jingle of high partials."""
    n = int(length * SR)
    t = np.arange(n) / SR
    noise = rng.normal(0, 1, n)
    noise = np.diff(np.concatenate([[0], noise]))           # tilt toward highs
    tone = sum(np.sin(2 * np.pi * f * t + rng.uniform(0, 6)) for f in (5200, 6900, 8300, 10400))
    return (0.5 * noise + 0.35 * tone) * env(n, 0.002, 0.045)


def place(track, sig, at, gain):
    i = int(at * SR)
    j = min(len(track), i + len(sig))
    if i < len(track):
        track[i:j] += gain * sig[: j - i]


# Chorus in G major (8 bars). (note, beats)
MELODY = [
    ("B4", 1), ("B4", 1), ("B4", 2),
    ("B4", 1), ("B4", 1), ("B4", 2),
    ("B4", 1), ("D5", 1), ("G4", 1.5), ("A4", 0.5),
    ("B4", 4),
    ("C5", 1), ("C5", 1), ("C5", 1.5), ("C5", 0.5),
    ("C5", 1), ("B4", 1), ("B4", 1), ("B4", 0.5), ("B4", 0.5),
    ("D5", 1), ("D5", 1), ("C5", 1), ("A4", 1),
    ("G4", 4),
]
CHORDS = [  # one per bar
    ["G3", "B3", "D4"], ["G3", "B3", "D4"], ["C4", "E4", "G3"], ["G3", "B3", "D4"],
    ["C4", "E4", "G3"], ["G3", "B3", "D4"], ["D4", "F#4", "C4"], ["G3", "B3", "D4"],
]
BASS = ["G2", "G2", "C3", "G2", "C3", "G2", "D2", "G2"]
FIFTH = ["D3", "D3", "G2", "D3", "G2", "D3", "A2", "D3"]

START = 0.12  # a breath before the first note
N = int(DUR * SR) + SR
mel, chd, bas, bel = (np.zeros(N) for _ in range(4))

t = START
for note, beats in MELODY:
    place(mel, glock(hz(note), beats * BEAT), t, 0.55)
    place(mel, glock(hz(note) * 2, beats * BEAT), t, 0.08)   # octave sparkle
    t += beats * BEAT

for bar in range(8):
    b0 = START + bar * 4 * BEAT
    last = bar == 7
    # bass: root on 1, fifth on 3 (whole note on the last bar)
    place(bas, pluck(hz(BASS[bar]), (4 if last else 2) * BEAT), b0, 0.55)
    if not last:
        place(bas, pluck(hz(FIFTH[bar]), 2 * BEAT), b0 + 2 * BEAT, 0.45)
    # chords on the off-beats (2 and 4), sustained on the last bar
    freqs = [hz(n) for n in CHORDS[bar]]
    if last:
        place(chd, epiano(freqs, 4 * BEAT), b0, 0.45)
    else:
        for k in (1, 3):
            place(chd, epiano(freqs, BEAT * 0.9), b0 + k * BEAT, 0.38)
    # sleigh bells on every eighth, accented on the beat
    for e in range(8 if not last else 2):
        acc = 1.0 if e % 2 == 0 else 0.55
        place(bel, bells(0.16), b0 + e * BEAT / 2, 0.22 * acc)

# A last shimmer of bells over the final chord
for k in range(6):
    place(bel, bells(0.3), START + 28 * BEAT + k * 0.11, 0.12 * (1 - k / 7))

mix = mel * 0.9 + chd * 0.8 + bas * 0.9 + bel * 0.8

# Room reverb: convolve with a decaying noise impulse (FFT).
ir_len = int(1.6 * SR)
ir = rng.normal(0, 1, ir_len) * np.exp(-np.arange(ir_len) / SR / 0.45)
ir[0] = 0
ir /= np.sqrt(np.sum(ir ** 2))
size = 1 << int(np.ceil(np.log2(len(mix) + ir_len)))
wet = np.fft.irfft(np.fft.rfft(mix, size) * np.fft.rfft(ir, size), size)[: len(mix)]
out = mix + 0.22 * wet

# Stereo: bells and sparkle a little wider.
left = out + 0.06 * np.roll(bel, 240)
right = out + 0.06 * np.roll(bel, -240)
stereo = np.stack([left, right], axis=1)[: int(DUR * SR)]

# Fade out the last 0.5 s, normalise to -1 dBFS peak (loudness is set by ffmpeg later).
fade = int(0.5 * SR)
stereo[-fade:] *= np.linspace(1, 0, fade)[:, None]
stereo /= np.max(np.abs(stereo)) / 0.89

OUT.parent.mkdir(parents=True, exist_ok=True)
with wave.open(str(OUT), "wb") as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes((stereo * 32767).astype("<i2").tobytes())
print(OUT, f"{DUR:.1f}s")
