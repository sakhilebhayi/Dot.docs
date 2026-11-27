import path from 'node:path';
import { fileURLToPath } from 'node:url';

// package.json is "type": "module": no __dirname.
export const browserRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const repoRoot = path.resolve(browserRoot, '..', '..');
export const dataDir = path.join(browserRoot, '.data');
export const databaseFile = path.join(dataDir, 'e2e.sqlite');
export const port = Number(process.env.E2E_PORT ?? 8091);
export const baseURL = `http://127.0.0.1:${port}`;
export const password = process.env.E2E_PASSWORD ?? 'e2e-local-password';

export const users = {
    owner: 'owner@e2e.test',
    colleague: 'colleague@e2e.test',
    outsider: 'outsider@e2e.test',
};

/** The app's environment for the suite: a scratch SQLite file, local sign-in, no network services. */
export function appEnv(): Record<string, string> {
    return {
        APP_ENV: 'local',
        APP_DEBUG: 'false',
        // A lazy load or a silently dropped attribute fails the run here, as in PHPUnit (R2-8; production only logs).
        MODELS_STRICT: 'true',
        APP_URL: baseURL,
        APP_KEY: process.env.APP_KEY ?? 'base64:ZTJlLWxvY2FsLWtleS1mb3ItYnJvd3Nlci10ZXN0cyE=',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: databaseFile,
        DB_URL: '',
        SESSION_DRIVER: 'file',
        SESSION_SECURE_COOKIE: 'false',
        CACHE_STORE: 'file',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'array',
        BROADCAST_CONNECTION: 'null',
        AI_PROVIDER: 'mock',
        DOTSSO_ENABLED: 'false',
        DOCS_ENTITLEMENT_CHECK: 'false',
        LOG_CHANNEL: 'single',
        E2E_PASSWORD: password,
    };
}

/** Where a person's signed-in session is kept between tests. */
export function stateFile(email: string): string {
    return path.join(dataDir, `state-${email.replace(/[^a-z0-9]/gi, '_')}.json`);
}
