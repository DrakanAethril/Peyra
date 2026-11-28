/*
 * Redraws a Turbo Frame of the page from the server (design-ux.md § 6.5): when the dossier's live
 * topic announces one of its `events` (dossier_live_controller.js), and every `poll` milliseconds
 * while the server says something is under way (« En préparation… ») - the live update is a
 * comfort, the polling the fallback. Placed inside the frame it redraws, so each answer brings its
 * own values. Never while the focus is inside the frame (§ 6.5: « jamais sous le focus »).
 */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { frame: String, events: Array, poll: Number };

    connect() {
        this.events = this.eventsValue;
        this.heard = (event) => {
            if (this.events.includes(event.detail?.event)) {
                this.reload();
            }
        };
        document.addEventListener('peyra:live', this.heard);
        if (this.pollValue > 0) {
            this.timer = window.setTimeout(() => this.reload(), this.pollValue);
        }
    }

    disconnect() {
        document.removeEventListener('peyra:live', this.heard);
        window.clearTimeout(this.timer);
    }

    reload() {
        const frame = document.getElementById(this.frameValue);
        if (!frame) {
            return;
        }
        if (frame.contains(document.activeElement) && document.activeElement !== document.body) {
            this.timer = window.setTimeout(() => this.reload(), 1000);

            return;
        }
        if (frame.src) {
            frame.reload();
        } else {
            frame.src = window.location.href;
        }
    }
}
