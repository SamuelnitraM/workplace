/*
 * Sons de notification SYNTHÉTISÉS (Web Audio) : aucun fichier audio, des sons propres au site.
 * Les clés correspondent à App\Notification\NotificationSound (paramètres du profil).
 *
 *  auspex   Double écho de scanner (contact radar)
 *  forge    Coup de marteau sur l'enclume (partiels métalliques inharmoniques)
 *  des      Trois dés qui roulent et s'arrêtent sur la table
 *  cor      Appel bref de cor de guerre
 *  cristal  Tintement cristallin scintillant (arpège aigu légèrement désaccordé)
 *  servo    Bips mécaniques de servo-crâne
 *
 * Politique des navigateurs : un son ne peut être joué qu'après une première interaction avec la page
 * (clic, touche). Le contexte audio est donc « déverrouillé » au premier geste de l'utilisateur.
 */

const MASTER_VOLUME = 0.5;
/** Durée pendant laquelle un seul son peut être joué, tous onglets confondus (rafales, onglets multiples). */
const QUIET_PERIOD_MS = 1200;

let context = null;
let master = null;

function audio() {
    if (context) return context;
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return null;
    context = new AudioContextClass();
    // Compresseur : volume homogène d'un son à l'autre, sans saturation
    const compressor = context.createDynamicsCompressor();
    compressor.threshold.value = -18;
    compressor.ratio.value = 4;
    master = context.createGain();
    master.gain.value = MASTER_VOLUME;
    master.connect(compressor).connect(context.destination);
    return context;
}

function unlock() {
    const ctx = audio();
    if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => {});
}
['pointerdown', 'keydown', 'touchstart'].forEach(type => document.addEventListener(type, unlock, { passive: true }));

// ─── Briques de synthèse ─────────────────────────────────
function tone(ctx, { type = 'sine', freq, start, duration, gain = 0.4, attack = 0.005, endFreq = null, detune = 0, destination = master }) {
    const osc = ctx.createOscillator();
    const amp = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, start);
    if (endFreq) osc.frequency.exponentialRampToValueAtTime(endFreq, start + duration);
    osc.detune.value = detune;
    amp.gain.setValueAtTime(0.0001, start);
    amp.gain.exponentialRampToValueAtTime(gain, start + attack);
    amp.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    osc.connect(amp).connect(destination);
    osc.start(start);
    osc.stop(start + duration + 0.05);
}

function noise(ctx, { start, duration, gain = 0.3, filter = 'bandpass', freq = 3000, q = 1 }) {
    const length = Math.ceil(ctx.sampleRate * duration);
    const buffer = ctx.createBuffer(1, length, ctx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < length; i++) data[i] = Math.random() * 2 - 1;
    const source = ctx.createBufferSource();
    source.buffer = buffer;
    const biquad = ctx.createBiquadFilter();
    biquad.type = filter;
    biquad.frequency.value = freq;
    biquad.Q.value = q;
    const amp = ctx.createGain();
    amp.gain.setValueAtTime(gain, start);
    amp.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    source.connect(biquad).connect(amp).connect(master);
    source.start(start);
}

// ─── Les sons ────────────────────────────────────────────
const SOUNDS = {
    auspex(ctx, t) {
        // Ping de sonar : attaque nette, léger glissement vers le grave, puis deux échos
        [[0, 0.5], [0.22, 0.28], [0.44, 0.12]].forEach(([delay, gain]) => {
            tone(ctx, { freq: 1480, endFreq: 1380, start: t + delay, duration: 0.32, gain, attack: 0.004 });
            tone(ctx, { freq: 2960, start: t + delay, duration: 0.08, gain: gain * 0.25, attack: 0.002 });
        });
    },

    forge(ctx, t) {
        // Enclume : transitoire de frappe + partiels inharmoniques qui décroissent à des vitesses différentes
        noise(ctx, { start: t, duration: 0.04, gain: 0.5, filter: 'highpass', freq: 2500 });
        [[1, 1.4, 0.35], [2.76, 0.9, 0.22], [5.4, 0.5, 0.14], [8.93, 0.25, 0.08]].forEach(([ratio, duration, gain]) => {
            tone(ctx, { freq: 620 * ratio, start: t, duration, gain, attack: 0.002 });
        });
    },

    des(ctx, t) {
        // Rebonds de plus en plus rapprochés et faibles, puis immobilisation
        const hits = [0, 0.11, 0.2, 0.27, 0.32, 0.355, 0.38];
        hits.forEach((delay, i) => {
            const gain = 0.55 * (1 - i / (hits.length + 1));
            noise(ctx, { start: t + delay, duration: 0.025, gain, freq: 2200 + Math.random() * 1800, q: 4 });
            tone(ctx, { type: 'triangle', freq: 190 + Math.random() * 60, start: t + delay, duration: 0.05, gain: gain * 0.5, attack: 0.002 });
        });
    },

    cor(ctx, t) {
        // Deux cuivres à la quinte, filtre qui s'ouvre puis se referme (souffle du cor)
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.Q.value = 2;
        filter.frequency.setValueAtTime(350, t);
        filter.frequency.exponentialRampToValueAtTime(1600, t + 0.18);
        filter.frequency.exponentialRampToValueAtTime(500, t + 0.85);
        filter.connect(master);
        [[98, 0.32], [147, 0.2], [196, 0.1]].forEach(([freq, gain]) => {
            const osc = ctx.createOscillator();
            const amp = ctx.createGain();
            const vibrato = ctx.createOscillator();
            const depth = ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(freq * 0.94, t);
            osc.frequency.exponentialRampToValueAtTime(freq, t + 0.12);
            vibrato.frequency.value = 5.5;
            depth.gain.value = freq * 0.006;
            vibrato.connect(depth).connect(osc.frequency);
            amp.gain.setValueAtTime(0.0001, t);
            amp.gain.exponentialRampToValueAtTime(gain, t + 0.1);
            amp.gain.setValueAtTime(gain, t + 0.55);
            amp.gain.exponentialRampToValueAtTime(0.0001, t + 0.9);
            osc.connect(amp).connect(filter);
            osc.start(t);
            vibrato.start(t);
            osc.stop(t + 0.95);
            vibrato.stop(t + 0.95);
        });
    },

    cristal(ctx, t) {
        // Arpège do-mi-sol aigu, chaque note doublée d'une voix désaccordée (scintillement)
        [2093, 2637, 3136].forEach((freq, i) => {
            const start = t + i * 0.075;
            tone(ctx, { freq, start, duration: 1.1 - i * 0.15, gain: 0.22, attack: 0.003 });
            tone(ctx, { freq, start, duration: 1.0 - i * 0.15, gain: 0.12, attack: 0.003, detune: 14 });
            tone(ctx, { freq: freq * 2.01, start, duration: 0.25, gain: 0.05, attack: 0.002 });
        });
    },

    servo(ctx, t) {
        // Trois bips carrés filtrés, puis un petit « chirp » descendant
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 3200;
        filter.connect(master);
        [[1760, 0], [2349, 0.075], [1976, 0.15]].forEach(([freq, delay]) => {
            tone(ctx, { type: 'square', freq, start: t + delay, duration: 0.05, gain: 0.12, attack: 0.002, destination: filter });
        });
        tone(ctx, { freq: 1400, endFreq: 520, start: t + 0.25, duration: 0.12, gain: 0.3, attack: 0.003 });
    },
};

export const SOUND_NAMES = Object.keys(SOUNDS);

/** Joue un son immédiatement (aperçu dans les paramètres : l'appel suit un clic, le contexte est donc actif). */
export function playSound(name) {
    const ctx = audio();
    const sound = SOUNDS[name];
    if (!ctx || !sound) return false;
    if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    sound(ctx, ctx.currentTime + 0.02);
    return true;
}

/**
 * Son de notification choisi par le membre (<meta name="hf-notification-sound">), joué une seule fois
 * même si plusieurs onglets reçoivent l'évènement, et pas plus d'un son par période de calme.
 */
export function playNotificationSound() {
    const name = document.querySelector('meta[name="hf-notification-sound"]')?.content || 'none';
    const ctx = audio();
    // Pas de son choisi, ou contexte audio pas encore autorisé dans cet onglet (aucune interaction) : on laisse
    // un autre onglet (déjà autorisé) le jouer
    if (!SOUNDS[name] || !ctx || ctx.state !== 'running') return;

    const play = () => sound(name);
    if (navigator.locks?.request) {
        navigator.locks.request('hf-notification-sound', { ifAvailable: true }, lock => {
            if (!lock) return null; // un autre onglet vient de jouer le son
            play();
            return new Promise(resolve => setTimeout(resolve, QUIET_PERIOD_MS));
        }).catch(() => {});
    } else {
        play();
    }
}

let lastPlayed = 0;
function sound(name) {
    const now = Date.now();
    if (now - lastPlayed < QUIET_PERIOD_MS) return;
    lastPlayed = now;
    playSound(name);
}
