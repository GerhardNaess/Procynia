const STORAGE_KEY = 'procynia.ai.workspace';

function readWorkspaceState() {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const rawState = window.localStorage.getItem(STORAGE_KEY);

        if (!rawState) {
            return {};
        }

        const parsedState = JSON.parse(rawState);

        return parsedState && typeof parsedState === 'object' && !Array.isArray(parsedState)
            ? parsedState
            : {};
    } catch {
        return {};
    }
}

function writeWorkspaceState(nextState) {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(nextState));
    } catch {
        // Ignore storage failures and keep the current page state in memory only.
    }
}

function normalizeId(value) {
    const normalizedValue = String(value ?? '').trim();

    return normalizedValue === '' ? null : normalizedValue;
}

export function readLastAiCaseId() {
    return normalizeId(readWorkspaceState().lastCaseId);
}

export function writeLastAiCaseId(caseId) {
    const normalizedCaseId = normalizeId(caseId);

    if (normalizedCaseId === null) {
        return;
    }

    const state = readWorkspaceState();

    writeWorkspaceState({
        ...state,
        lastCaseId: normalizedCaseId,
    });
}

export function readRememberedAiRequirementId(caseId) {
    const normalizedCaseId = normalizeId(caseId);

    if (normalizedCaseId === null) {
        return null;
    }

    const state = readWorkspaceState();
    const activeRequirementIds = state.activeRequirementIds;

    if (!activeRequirementIds || typeof activeRequirementIds !== 'object' || Array.isArray(activeRequirementIds)) {
        return null;
    }

    return normalizeId(activeRequirementIds[normalizedCaseId]);
}

export function writeRememberedAiRequirementId(caseId, requirementId) {
    const normalizedCaseId = normalizeId(caseId);

    if (normalizedCaseId === null) {
        return;
    }

    const state = readWorkspaceState();
    const activeRequirementIds = state.activeRequirementIds && typeof state.activeRequirementIds === 'object' && !Array.isArray(state.activeRequirementIds)
        ? { ...state.activeRequirementIds }
        : {};
    const normalizedRequirementId = normalizeId(requirementId);

    if (normalizedRequirementId === null) {
        delete activeRequirementIds[normalizedCaseId];
    } else {
        activeRequirementIds[normalizedCaseId] = normalizedRequirementId;
    }

    writeWorkspaceState({
        ...state,
        activeRequirementIds,
    });
}
