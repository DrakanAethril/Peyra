import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/*
 * What a dossier's open tab hears (design-ux.md § 6.5): the dossier's private Mercure topic,
 * subscribed with the cookie the page set (App\Twig\DossierLiveExtension). A return to a restore
 * point starting sends every tab to the waiting page (§ 4.9, step 3); any other message is handed
 * to the screen as a `peyra:live` event on the document (live_frame_controller.js redraws a frame
 * with it). Without a hub - or with one that stops answering - nothing breaks: the screens read
 * right when reloaded, and the waiting page asks for its state itself.
 */
export default class extends Controller {
    static values = { url: String, waiting: String };

    connect() {
        if (!this.urlValue || typeof EventSource === 'undefined') {
            return;
        }
        this.source = new EventSource(this.urlValue, { withCredentials: true });
        this.source.onmessage = (event) => this.received(event.data);
    }

    disconnect() {
        this.source?.close();
        this.source = null;
    }

    received(raw) {
        let data;
        try {
            data = JSON.parse(raw);
        } catch {
            return;
        }
        const waiting = this.waitingValue;
        if (data.event === 'restoring' && waiting && window.location.pathname !== new URL(waiting, window.location.href).pathname) {
            Turbo.visit(waiting);

            return;
        }
        document.dispatchEvent(new CustomEvent('peyra:live', { detail: data }));
    }
}
