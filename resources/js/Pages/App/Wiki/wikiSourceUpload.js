/**
 * Client-side pre-check for a Wiki source document, mirroring WikiSourceController::store().
 *
 * THE SERVER STAYS AUTHORITATIVE. These rules exist so that picking a 300 MB video does not spend a
 * minute uploading before the server rejects it — not so the server can trust the browser. The
 * request is validated there exactly as before ('mimes:pdf,docx', 'max:20480'), and a file that
 * slips past this check is still refused.
 *
 * Kept deliberately narrow: extension and size, the two things a browser can know for certain
 * before reading the file. MIME sniffing in the browser is unreliable and would risk rejecting a
 * document the server would have accepted, which is the one failure mode a pre-check must not have.
 */

/** Laravel's 'max:20480' is 20480 KB, and Laravel counts a KB as 1024 bytes. */
export const MAX_SOURCE_FILE_BYTES = 20480 * 1024;

export const ALLOWED_SOURCE_EXTENSIONS = ['pdf', 'docx'];

function extensionOf(name) {
    const dot = String(name ?? '').lastIndexOf('.');

    return dot === -1 ? '' : String(name).slice(dot + 1).toLocaleLowerCase();
}

/**
 * @returns {?string} the error to show, or null when the file may be uploaded.
 */
export function validateSourceFile(file, tw = {}) {
    if (!file) {
        return null;
    }

    if (! ALLOWED_SOURCE_EXTENSIONS.includes(extensionOf(file.name))) {
        return tw.sources_error_file_type ?? 'Filen må være PDF eller DOCX.';
    }

    if (Number(file.size) > MAX_SOURCE_FILE_BYTES) {
        return tw.sources_error_file_size ?? 'Filen er større enn 20 MB.';
    }

    return null;
}
