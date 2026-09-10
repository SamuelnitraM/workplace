import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'label', 'preview', 'grid'];

    connect() {
        this.inputTarget.addEventListener('change', (e) => this.handleFileSelect(e));
    }

    handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        // Show preview
        const reader = new FileReader();
        reader.onload = (e) => {
            this.previewTarget.style.backgroundImage = `url(${e.target.result})`;
            this.previewTarget.style.opacity = '1';
        };
        reader.readAsDataURL(file);

        // Automatic upload for better UX
        this.uploadFile();
    }

    async uploadFile() {
        const form = this.element;
        const formData = new FormData(form);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const result = await response.json();

            if (response.ok) {
                this.appendPhoto(result.url, result.filename);
                this.resetForm();
            } else {
                alert(result.error || 'Une erreur est survenue lors de l\'upload');
            }
        } catch (error) {
            console.error('Upload error:', error);
            alert('Erreur réseau lors de l\'upload');
        }
    }

    appendPhoto(url, filename) {
        const grid = document.getElementById('gallery-grid');
        if (!grid) return;

        // Create a new photo element similar to the Twig template
        const photoDiv = document.createElement('div');
        photoDiv.className = 'gallery-photo group relative aspect-square overflow-hidden rounded-lg';
        photoDiv.innerHTML = `
            <button type="button" class="gallery-select absolute left-2 top-2 z-10 hidden h-7 w-7 items-center justify-center rounded-full border-2 border-white/80 bg-gray-900/70 text-white shadow hover:bg-red-600">✓</button>
            <form method="post" action="/profil/gallery/visibility" class="absolute right-2 top-2 z-20 opacity-0 transition-opacity group-hover:opacity-100">
                <input type="hidden" name="_token" value="">
                <button type="submit" class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white/80 bg-gray-900/70 text-white shadow hover:bg-gray-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7Z"/><circle cx="12" cy="12" r="3" stroke-width="2"/></svg>
                </button>
            </form>
            <button type="button" class="h-full w-full cursor-zoom-in" data-gallery-image="${url}">
                <img src="${url}" class="h-full w-full object-cover">
            </button>
        `;
        grid.appendChild(photoDiv);
    }

    resetForm() {
        this.inputTarget.value = '';
        this.previewTarget.style.opacity = '0';
    }
}
