/**
 * The «*» after a required field's label, as elsewhere in the app (FormField draws the same one).
 * Screen readers get the field's own aria-required instead, so the star itself is hidden from them;
 * every form that shows it also says once what it means, with RequiredFieldsNote (3.3.2, A11Y-121).
 * rose-700, like FormField's star and the error text: rose-500 was under 4.5:1 on white.
 */
export default function RequiredMark() {
    return <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>;
}
