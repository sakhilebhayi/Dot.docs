import './bootstrap';
import './shell';
import './editor/index';
import _ from 'lodash';
import { initOfflineSupport } from './offline';

window._ = _;

// Register service worker + global online/offline state
initOfflineSupport(
    () => window.dispatchEvent(new CustomEvent('app-online')),
    () => window.dispatchEvent(new CustomEvent('app-offline'))
);

/**
 * Voice Typing component (Web Speech API).
 * Injects recognised speech directly into the active TipTap editor.
 * Registered via alpine:init so Livewire's bundled Alpine is used.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('voiceTyping', () => ({
        supported: false,
        listening: false,
        recognition: null,

        init() {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            this.supported = !!SpeechRecognition;
            if (!this.supported) return;

            this.recognition = new SpeechRecognition();
            this.recognition.continuous = true;
            this.recognition.interimResults = false;
            this.recognition.lang = document.documentElement.lang || 'en-US';

            this.recognition.onresult = (event) => {
                const transcript = Array.from(event.results)
                    .slice(event.resultIndex)
                    .filter(r => r.isFinal)
                    .map(r => r[0].transcript)
                    .join(' ');

                if (transcript.trim()) {
                    // Find the nearest TipTap editor and insert text
                    const editorEl = document.querySelector('.ProseMirror');
                    if (editorEl) {
                        // Dispatch to the editor Alpine component to insert text
                        window.dispatchEvent(new CustomEvent('voice-transcript', {
                            detail: { text: transcript.trim() }
                        }));
                    }
                }
            };

            this.recognition.onerror = () => { this.listening = false; };
            this.recognition.onend = () => { this.listening = false; };
        },

        toggle() {
            if (!this.supported) return;
            if (this.listening) {
                this.recognition.stop();
                this.listening = false;
            } else {
                this.recognition.start();
                this.listening = true;
            }
        }
    }));

    /**
     * The comment composer's @-mention input (resources/views/livewire/
     * documents/comment-thread.blade.php). Registered HERE, not in that
     * view's own @push('scripts') block, because the comment thread only
     * ever renders after the writer opens it - $commentSidebarOpen defaults
     * to false (App\Livewire\Documents\Editor), so the component mounts for
     * the first time through a Livewire AJAX update, never the initial
     * full-page load. @push/@stack are resolved once, at Blade's initial
     * full-page compile, so a <script> pushed from a component that only
     * ever arrives via a later Livewire morph never reaches the page at all
     * - confirmed live: window.Alpine's registry has no 'mentionInput' entry
     * and the rendered HTML has no trace of the script, every time, in every
     * real usage path. That left `x-data="mentionInput(...)"` throwing
     * "mentionInput is not defined" the instant anyone opened comments,
     * taking x-model/@input down with it (see comment-thread.blade.php's
     * own docblock for the full mechanism). voiceTyping above never hit
     * this because app.js loads once, up front, on every page - the fix is
     * to register every Alpine component that a Livewire subcomponent's
     * x-data might reference the same way, regardless of when that
     * subcomponent itself first mounts.
     */
    Alpine.data('mentionInput', (valueEntangle, onSearch) => ({
        value: valueEntangle,
        handleInput(e) {
            const val = e.target.value;
            const match = val.match(/@(\w*)$/);
            if (match) {
                onSearch(match[1]);
            } else {
                onSearch('');
            }
        },
        insertMention(name) {
            this.value = this.value.replace(/@\w*$/, '@' + name + ' ');
            onSearch('');
        }
    }));
});
