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

        // Text from utterances the engine has committed to. Interim results are
        // kept apart because they are rewritten on every event — appending them
        // is how you end up with "a blue a blue vase a blue vase".
        this.finalText = '';
        this.interimText = '';

        this.onUpdate = null;
        this.onError = null;
        this.onEnd = null;
    }

    static isSupported() {
        return typeof window !== 'undefined'
            && (window.SpeechRecognition || window.webkitSpeechRecognition) !== undefined;
    }

    get text() {
        return (this.finalText + ' ' + this.interimText).replace(/\s+/g, ' ').trim();
    }

    start() {
        if (this.isListening) return;

        const Impl = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Impl) throw new Error('This browser has no speech recognition.');

        const recognition = new Impl();
        recognition.lang = this.lang;
        recognition.continuous = true;      // don't stop at the first pause
        recognition.interimResults = true;  // the whole point: show it as they speak

        recognition.onresult = (event) => {
            let interim = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                const result = event.results[i];
                if (result.isFinal) {
                    this.finalText += (this.finalText ? ' ' : '') + result[0].transcript.trim();
                } else {
                    interim += result[0].transcript;
                }
            }
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
        // Fold any half-spoken words in, so stopping mid-sentence doesn't lose them.
        if (this.interimText) {
            this.finalText += (this.finalText ? ' ' : '') + this.interimText;
            this.interimText = '';
        }
        this.recognition.stop();

        return this.text;
    }

    reset() {
        this.finalText = '';
        this.interimText = '';
        this.onUpdate?.('', '');
    }
}
