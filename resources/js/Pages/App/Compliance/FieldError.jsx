import { ERROR } from '../../../Support/formStyles';
import { fieldErrorId } from './complianceA11y';

/**
 * A field's error, under the id fieldStateProps puts in the field's aria-describedby — so it is read
 * with the field, not only seen beside it (3.3.1, A11Y-013). `field` is the control's id. Nothing
 * when there is no error.
 */
export default function FieldError({ field, message, className = ERROR }) {
    if (! message) {
        return null;
    }

    return <p id={fieldErrorId(field)} className={className}>{message}</p>;
}
