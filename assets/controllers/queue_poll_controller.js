import { Controller } from '@hotwired/stimulus';

// Turbo has no polling attribute: reload the frame from its src, morphing because of refresh="morph" (DQ-6).
export default class extends Controller {
    static values = { interval: { type: Number, default: 5000 } };

    connect() {
        this.timer = setInterval(() => this.poll(), this.intervalValue);
        this.onVisible = () => this.poll();
        document.addEventListener('visibilitychange', this.onVisible);
    }

    disconnect() {
        clearInterval(this.timer);
        document.removeEventListener('visibilitychange', this.onVisible);
    }

    poll() {
        // Skip while the phone is locked or the tab is hidden, and never stack polls: Turbo sets busy while a load or form submit runs.
        if (document.hidden || this.element.hasAttribute('busy')) {
            return;
        }
        this.element.reload();
    }
}
