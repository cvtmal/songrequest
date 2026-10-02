import { Controller } from '@hotwired/stimulus';

// Without JS the page still shows the code large, and the browser's own print works.
export default class extends Controller {
    static targets = ['sheet'];

    fullscreen() {
        this.sheetTarget.requestFullscreen?.();
    }

    print() {
        window.print();
    }
}
