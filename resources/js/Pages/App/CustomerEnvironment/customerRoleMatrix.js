/**
 * Which roles belong in a domain's permission matrix in Kundemiljø → Tilganger.
 *
 * A role holding only Wiki permissions has nothing to say under Kvalitet, so listing it there as a
 * row of empty checkboxes only makes the page harder to read. This is a presentation filter: the
 * role list below the matrices still shows every role, and the edit dialog still offers both
 * permission groups, so a role is always one checkbox away from entering the other domain.
 *
 * @param {Array<{permission_keys: string[]}>} roles
 * @param {{permissions: Array<{key: string}>}} domain
 */
export function rolesInDomain(roles, domain) {
    const domainKeys = (domain?.permissions ?? []).map((permission) => permission.key);

    return (roles ?? []).filter((role) =>
        (role.permission_keys ?? []).some((key) => domainKeys.includes(key))
    );
}
