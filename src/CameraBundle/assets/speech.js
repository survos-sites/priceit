/**
 * Live dictation via the browser's own SpeechRecognition.
 *
 * Deliberately not Whisper, not MediaRecorder, not an upload: the note is only
 * ever wanted as text, so the audio never has to exist. The browser does the
 * recognition and we keep the string.
 *
 * The trade this makes, stated plainly because the capture app is otherwise
 * offline-first: SpeechRecognition needs a live connection (Chrome ships the
 * audio to Google) and Firefox does not implement it at all. So this is an
 * enhancement, never the only way in — `isSupported()` is the gate, and the
 * caller keeps a typed fallback for everyone else.
 */
export class Speech {
    constructor({ lang = 'en-US' } = {}) {
        this.lang = lang;
        this.recognition = null;
        this.isListening = false;

        // Nothing is appended per event. Each event carries the session's whole result list, so
        // the session's text is rebuilt from it every time; appending is how notes came out
        // doubled. committedText holds finished sessions (Chrome ends one after each pause and
        // we restart), sessionFinal and interimText the current one.
        this.committedText = '';
        this.sessionFinal = '';
        this.interimText = '';
        // Results from before a reset() that the running session will keep re-sending.
        this.skipResults = 0;
        this.resultCount = 0;

        this.onUpdate = null;
        this.onError = null;
        this.onEnd = null;
    }

    static isSupported() {
        return typeof window !== 'undefined'
            && (window.SpeechRecognition || window.webkitSpeechRecognition) !== undefined;
    }

    get text() {
        return [this.committedText, this.sessionFinal, this.interimText].join(' ').replace(/\s+/g, ' ').trim();
    }

    /** Fold the finished session into committed text; the next session starts from nothing. */
    _commitSession() {
        this.committedText = this.text;
        this.sessionFinal = '';
        this.interimText = '';
        this.skipResults = 0;
        this.resultCount = 0;
    }

    start() {
        if (this.isListening) return;

        const Impl = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Impl) throw new Error('This browser has no speech recognition.');

        const recognition = new Impl();
        recognition.lang = this.lang;
        // Android Chrome's continuous mode re-sends earlier phrases inside later results, which
        // no bookkeeping here can undo. One phrase per session avoids it, and onend below
        // already restarts after every pause, so dictation still runs on.
        recognition.continuous = !/Android/i.test(navigator.userAgent);
        recognition.interimResults = true;  // the whole point: show it as they speak

        recognition.onresult = (event) => {
            let final = '';
            let interim = '';
            for (let i = this.skipResults; i < event.results.length; i++) {
                const result = event.results[i];
                if (result.isFinal) {
                    final += ' ' + result[0].transcript;
                } else {
                    interim += ' ' + result[0].transcript;
                }
            }
            this.resultCount = event.results.length;
            this.sessionFinal = final.trim();
            this.interimText = interim.trim();
            this.onUpdate?.(this.text, this.interimText);
        };

        recognition.onerror = (event) => {
            // 'no-speech' and 'aborted' are ordinary: someone paused, or stopped
            // on purpose. Reporting them as failures trains people to ignore the
            // message that matters ('not-allowed' — the mic was denied).
            if (event.error === 'no-speech' || event.error === 'aborted') return;
            this.onError?.(event.error);
        };

        recognition.onend = () => {
            this._commitSession();
            // Chrome ends the session on its own after a stretch of silence. If
            // the user hasn't asked to stop, restart — otherwise dictation dies
            // mid-thought while the button still says "listening".
            if (this.isListening) {
                try {
                    recognition.start();
                    return;
                } catch {
                    // Fall through to a real stop if it refuses to restart.
                }
            }
            this.isListening = false;
            this.onEnd?.(this.text);
        };

        this.recognition = recognition;
        this.isListening = true;
        recognition.start();
    }

    stop() {
        if (!this.recognition) return this.text;

        this.isListening = false;
        // Half-spoken words stay in interimText rather than being copied into the final text:
        // Chrome sends them once more as a final result after stop(), and copying them first
        // is what doubled the end of a note. If that final result never comes, onend
        // commits the interim words, so stopping mid-sentence still keeps them.
        this.recognition.stop();

        return this.text;
    }

    reset() {
        this.committedText = '';
        this.sessionFinal = '';
        this.interimText = '';
        // A running session keeps re-sending everything it has heard; skip what came before.
        this.skipResults = this.resultCount;
        // No onUpdate here. The caller is either typing, where an update would overwrite the
        // keystroke with the old text, or clearing the note, which it does itself.
    }
}
