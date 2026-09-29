/*
 * Network helpers shared by the controllers.
 *
 * latestRequest(): only the last request of a series is kept; starting a new one aborts the previous one, so a slow
 * stale response never overwrites a newer one.
 *
 *   this.previewRequest = latestRequest();
 *   fetch(url, { signal: this.previewRequest.next() }).then(…).catch(error => { if (isAbortError(error)) return; … });
 *   this.previewRequest.abort(); // in disconnect()
 */
export function latestRequest() {
    let controller = null;
    return {
        next() {
            controller?.abort();
            controller = new AbortController();
            return controller.signal;
        },
        abort() {
            controller?.abort();
            controller = null;
        },
    };
}

export function isAbortError(error) {
    return error?.name === 'AbortError';
}
