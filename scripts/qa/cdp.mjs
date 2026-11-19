/*
 * A tiny Chrome DevTools Protocol client for the QA sweep (docs/QA.md): launches
 * headless Chrome, talks to it over Node's built-in WebSocket, and gives each role
 * its own browser context (separate cookies). No packages.
 */
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CHROME_PATHS = [
    process.env.CHROME_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
].filter(Boolean);

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function findChrome() {
    const { existsSync } = await import('node:fs');

    const found = CHROME_PATHS.find((path) => existsSync(path));

    if (!found) {
        throw new Error('Chrome not found: set CHROME_PATH.');
    }

    return found;
}

export async function launchChrome({ port = 9333, headless = true } = {}) {
    const binary = await findChrome();
    const profile = mkdtempSync(join(tmpdir(), 'coffer-qa-'));
    const args = [
        `--remote-debugging-port=${port}`,
        `--user-data-dir=${profile}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        '--disable-background-networking',
        '--disable-sync',
        '--hide-scrollbars=false',
        '--force-color-profile=srgb',
        '--renderer-process-limit=2',
        ...(headless ? ['--headless=new'] : []),
        'about:blank',
    ];
    const child = spawn(binary, args, { stdio: 'ignore' });

    let wsUrl;

    for (let attempt = 0; attempt < 60 && !wsUrl; attempt++) {
        await sleep(250);

        try {
            const response = await fetch(
                `http://127.0.0.1:${port}/json/version`,
            );
            wsUrl = (await response.json()).webSocketDebuggerUrl;
        } catch {
            // Not up yet.
        }
    }

    if (!wsUrl) {
        child.kill();
        throw new Error('Chrome did not open its debugging port.');
    }

    const browser = await Browser.connect(wsUrl);

    browser.onClose = () => {
        child.kill();

        try {
            rmSync(profile, { recursive: true, force: true });
        } catch {
            // Chrome may still hold files for a moment; the OS cleans tmp.
        }
    };

    return browser;
}

class Browser {
    constructor(socket) {
        this.socket = socket;
        this.nextId = 1;
        this.pending = new Map();
        this.listeners = new Set();
        socket.addEventListener('message', (event) => this.receive(event.data));
    }

    static connect(url) {
        return new Promise((resolve, reject) => {
            const socket = new WebSocket(url);
            socket.addEventListener('open', () => resolve(new Browser(socket)));
            socket.addEventListener('error', () =>
                reject(new Error('Could not connect to Chrome.')),
            );
        });
    }

    receive(raw) {
        const message = JSON.parse(String(raw));

        if (message.id && this.pending.has(message.id)) {
            const { resolve, reject, method } = this.pending.get(message.id);
            this.pending.delete(message.id);

            if (message.error) {
                reject(new Error(`${method}: ${message.error.message}`));
            } else {
                resolve(message.result);
            }

            return;
        }

        for (const listener of this.listeners) {
            listener(message);
        }
    }

    send(method, params = {}, sessionId) {
        const id = this.nextId++;

        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject, method });
            this.socket.send(
                JSON.stringify({
                    id,
                    method,
                    params,
                    ...(sessionId ? { sessionId } : {}),
                }),
            );
        });
    }

    async newPage() {
        const { browserContextId } = await this.send(
            'Target.createBrowserContext',
            {
                disposeOnDetach: true,
            },
        );
        const { targetId } = await this.send('Target.createTarget', {
            url: 'about:blank',
            browserContextId,
        });
        const { sessionId } = await this.send('Target.attachToTarget', {
            targetId,
            flatten: true,
        });
        const page = new Page(this, sessionId, targetId, browserContextId);
        await page.init();

        return page;
    }

    async close() {
        try {
            await this.send('Browser.close');
        } catch {
            // Already gone.
        }

        this.socket.close();
        this.onClose?.();
    }
}

export class Page {
    constructor(browser, sessionId, targetId, contextId) {
        this.browser = browser;
        this.sessionId = sessionId;
        this.targetId = targetId;
        this.contextId = contextId;
        this.waiters = new Set();
        this.consoleErrors = [];
        browser.listeners.add((message) => {
            if (message.sessionId !== sessionId) {
                return;
            }

            if (message.method === 'Runtime.exceptionThrown') {
                this.consoleErrors.push(
                    message.params.exceptionDetails?.exception?.description ??
                        message.params.exceptionDetails?.text,
                );
            }

            for (const waiter of this.waiters) {
                waiter(message);
            }
        });
    }

    send(method, params) {
        return this.browser.send(method, params, this.sessionId);
    }

    async init() {
        await this.send('Page.enable');
        await this.send('Runtime.enable');
    }

    waitFor(method, timeout = 15000) {
        return new Promise((resolve) => {
            const timer = setTimeout(() => {
                this.waiters.delete(waiter);
                resolve(null);
            }, timeout);
            const waiter = (message) => {
                if (message.method === method) {
                    clearTimeout(timer);
                    this.waiters.delete(waiter);
                    resolve(message.params);
                }
            };
            this.waiters.add(waiter);
        });
    }

    async goto(url, { settle = 600 } = {}) {
        const loaded = this.waitFor('Page.loadEventFired', 30000);
        await this.send('Page.navigate', { url });
        await loaded;
        await this.evaluate(
            'document.fonts ? document.fonts.ready.then(() => true) : true',
        );
        await sleep(settle);
    }

    /** Evaluate an expression (or `(${fn})(args)` string) and return its value. */
    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        });

        if (result.exceptionDetails) {
            throw new Error(
                result.exceptionDetails.exception?.description ??
                    result.exceptionDetails.text,
            );
        }

        return result.result.value;
    }

    call(fn, ...args) {
        return this.evaluate(`(${fn.toString()})(...${JSON.stringify(args)})`);
    }

    async url() {
        return this.evaluate('location.href');
    }

    async waitForSelector(selector, timeout = 8000) {
        const started = Date.now();

        while (Date.now() - started < timeout) {
            const found = await this.evaluate(
                `!!document.querySelector(${JSON.stringify(selector)})`,
            );

            if (found) {
                return true;
            }

            await sleep(150);
        }

        return false;
    }

    async setViewport(width, height) {
        await this.send('Emulation.setDeviceMetricsOverride', {
            width,
            height,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        await this.send('Emulation.setTouchEmulationEnabled', {
            enabled: width < 768,
        });
    }

    async focus(selector) {
        await this.evaluate(
            `document.querySelector(${JSON.stringify(selector)})?.focus()`,
        );
    }

    async type(text) {
        await this.send('Input.insertText', { text });
    }

    async screenshot(path, { full = true } = {}) {
        const { writeFileSync } = await import('node:fs');
        let clip;

        if (full) {
            const size = await this.evaluate(
                '({ w: document.documentElement.clientWidth, h: Math.min(document.documentElement.scrollHeight, 12000) })',
            );
            clip = { x: 0, y: 0, width: size.w, height: size.h, scale: 1 };
        }

        const { data } = await this.send('Page.captureScreenshot', {
            format: 'png',
            ...(clip ? { clip, captureBeyondViewport: true } : {}),
        });
        writeFileSync(path, Buffer.from(data, 'base64'));
    }
}
