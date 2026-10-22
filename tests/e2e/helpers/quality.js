import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import { REPO_ROOT, artisanCommand } from './environment.js';

const execAsync = promisify(exec);

/**
 * Whether the server under test can interpret a process description with AI — the same rule the
 * Flyt tab reads (ProcessFlowInterpretationAiClient::isAvailable()).
 *
 * The isolated E2E environment has no OpenAI key, deliberately (docs/testing/e2e-environment.md):
 * a test run must not spend provider calls or send text to a provider. A spec that needs the real
 * server-side interpretation skips with this as its reason, rather than failing on a missing key or
 * pretending by stubbing the very thing it defends.
 */
export async function serverHasFlowAi() {
    const { stdout } = await execAsync(
        artisanCommand('tinker --execute="echo json_encode(\\App\\Services\\Ai\\Quality\\ProcessFlowInterpretationAiClient::isAvailable());"'),
        { cwd: REPO_ROOT },
    );

    return stdout.trim().endsWith('true');
}

export const NO_FLOW_AI = 'AI-tolkning er ikke tilgjengelig i dette miljøet (ingen OpenAI-nøkkel, se docs/testing/e2e-environment.md).';
