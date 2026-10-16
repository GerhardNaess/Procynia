/**
 * The «*» after a required field's label. Screen readers get the field's own aria-required (or the
 * radiogroup's) instead, so the star is hidden from them. rose-700, like FormField's: the star is
 * text that carries meaning, and rose-500 is under 4.5:1 on white (1.4.3).
 */
export default function RequiredMark() {
    return <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>;
}
