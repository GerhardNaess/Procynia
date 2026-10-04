/**
 * The «*» after a required field's label, as elsewhere in the app. Screen readers get the field's
 * own aria-required instead, so the star itself is hidden from them.
 */
export default function RequiredMark() {
    return <span aria-hidden="true" className="ml-0.5 text-rose-500">*</span>;
}
