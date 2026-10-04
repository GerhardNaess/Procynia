/**
 * The people the form may offer as owner of a risk in the given area.
 *
 * The backend sends each candidate with the areas — among those the acting user may choose — in
 * which that person can read risks. An owner who cannot open the risk they own is not an owner, so
 * the list follows the chosen area. The server checks the same thing again on save.
 *
 * @param {Array<{id: number, name: string, area_ids: number[]}>} options
 * @param {number|string|null} areaId
 */
export function ownersForArea(options, areaId) {
    const id = Number(areaId);

    if (! id) {
        return [];
    }

    return (options ?? []).filter((option) => (option.area_ids ?? []).includes(id));
}
