#!/usr/bin/env node
/*
 * Coffer QA sweep (docs/QA.md): signs in as each demo role through the real forms,
 * visits every page at the owner's widths in both themes, opens the listed dialogs,
 * and reports layout faults (sideways scroll, overflow, clipped or covered text,
 * popovers off screen, dialogs capped short). Headless Chrome over the DevTools
 * protocol, Node's built-in WebSocket and fetch; no packages.
 *
 *   npm run qa:sweep -- [--base=http://127.0.0.1:8010] [--roles=admin,guest]
 *       [--widths=375,1150] [--themes=light] [--pages=/expenses,/vault] [--quick]
 *       [--no-dialogs] [--no-pages] [--no-shots] [--dialog=Add] [--out=storage/qa/<run>]
 *
 * Exit code 1 when anything not in scripts/qa/allow.json is found.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchChrome, sleep } from './cdp.mjs';
import {
    clickControl,
    measureDialogs,
    measurePage,
    measureShell,
} from './measure.mjs';
import {
    APP_PAGES,
    DIALOGS,
    GUEST_PAGES,
    QUICK_PAGES,
    QUICK_WIDTHS,
    ROLES,
    SHORT_HEIGHT,
    SHORT_WIDTHS,
    THEMES,
    WIDTHS,
} from './routes.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');

function parseArgs(argv) {
    const args = {};

    for (const raw of argv) {
        const match = raw.match(/^--([^=]+)(?:=(.*))?$/);

        if (match) {
            args[match[1]] = match[2] ?? true;
        }
    }

    return args;
}

const args = parseArgs(process.argv.slice(2));
const list = (value) =>
    typeof value === 'string' ? value.split(',').filter(Boolean) : null;
const quick = Boolean(args.quick);
const base = (args.base || 'http://127.0.0.1:8010').replace(/\/$/, '');
const roles =
    list(args.roles) || (quick ? ['admin', 'guest'] : Object.keys(ROLES));
const widths = (list(args.widths) || (quick ? QUICK_WIDTHS : WIDTHS)).map(
    Number,
);
const themes = list(args.themes) || THEMES;
// Git Bash rewrites "/expenses" to "C:/Program Files/Git/expenses"; undo that, and allow "expenses".
const pageFilter = list(args.pages)?.map((p) =>
    (
        '/' +
        p.replace(/^[A-Za-z]:[\\/].*?[\\/]Git(?=[\\/])/, '').replace(/\\/g, '/')
    ).replace(/^\/+/, '/'),
);
const shots = !args['no-shots'];
const runDialogs = !args['no-dialogs'];
const runPages = !args['no-pages'];
const dialogFilter =
    typeof args.dialog === 'string' ? args.dialog.toLowerCase() : null;
const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const out = resolve(root, args.out || join('storage', 'qa', stamp));
const php = process.env.COFFER_PHP || 'php';

mkdirSync(out, { recursive: true });

const allow = JSON.parse(readFileSync(join(here, 'allow.json'), 'utf8')).allow;

function allowed(finding) {
    return allow.find(
        (rule) =>
            rule.kind === finding.kind &&
            (!rule.page || finding.page.startsWith(rule.page)) &&
            (!rule.selector || finding.selector.includes(rule.selector)) &&
            (!rule.text || finding.text.includes(rule.text)) &&
            (!rule.detail || finding.detail.includes(rule.detail)) &&
            (!rule.maxWidth || finding.width <= rule.maxWidth) &&
            (!rule.minWidth || finding.width >= rule.minWidth),
    );
}

const findings = [];
const visits = [];

function record(context, items) {
    for (const item of items) {
        const finding = { ...context, ...item };
        const rule = allowed(finding);

        if (rule) {
            finding.allowed = rule.reason;
        }

        findings.push(finding);
    }
}

function demoCode() {
    for (let attempt = 0; attempt < 3; attempt++) {
        const output = execFileSync(php, ['artisan', 'coffer:demo-2fa'], {
            cwd: root,
            encoding: 'utf8',
        });
        const match = output.match(/(\d{6})\s+\(valid for (\d+)/);

        if (match && Number(match[2]) >= 4) {
            return match[1];
        }

        execFileSync(process.execPath, ['-e', 'setTimeout(()=>{},5000)']);
    }

    throw new Error('Could not read the demo 2FA code.');
}

async function click(page, spec) {
    const point = await page.call(clickControl, spec);

    if (!point) {
        return false;
    }

    await sleep(120);
    const { x, y } = point;

    for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', {
            type,
            x,
            y,
            button: 'left',
            buttons: type === 'mousePressed' ? 1 : 0,
            clickCount: 1,
        });
    }

    return true;
}

async function typeCode(page) {
    const code = demoCode();

    await page.evaluate("document.querySelector('.cf-code-box')?.focus()");

    for (const digit of code) {
        await page.type(digit);
        await sleep(60);
    }
}

async function signIn(page, role) {
    const { email } = ROLES[role];

    await page.setViewport(1440, 900);
    await page.goto(`${base}/login`);

    if (!(await page.waitForSelector('#si-email'))) {
        throw new Error(`No sign-in form at ${base}/login`);
    }

    await page.focus('#si-email');
    await page.type(email);
    await page.focus('#si-pass');
    await page.type('password');
    await click(page, { selector: 'button[type="submit"]' });

    for (let i = 0; i < 40; i++) {
        await sleep(250);
        const url = await page.url();

        if (url.includes('two-factor-challenge')) {
            await page.waitForSelector('.cf-code-box');
            await typeCode(page);

            for (let j = 0; j < 40; j++) {
                await sleep(250);

                if (!(await page.url()).includes('two-factor-challenge')) {
                    break;
                }
            }

            break;
        }

        if (!url.includes('/login')) {
            break;
        }
    }

    const landed = await page.url();

    if (landed.includes('/login') || landed.includes('two-factor')) {
        throw new Error(`Sign-in as ${email} failed (still on ${landed}).`);
    }

    console.log(`  signed in as ${email} → ${landed.replace(base, '')}`);
}

/** Unlock the vault for this session (the lock card asks for the 2FA code), if it is locked. */
async function unlockVault(page) {
    await page.goto(`${base}/vault`, { settle: 900 });

    if (
        await page.evaluate(
            "!!document.querySelector('.vh-lock-card .cf-code-box')",
        )
    ) {
        await typeCode(page);
        await sleep(1500);
        console.log('  vault unlocked');
    }
}

async function setTheme(page, theme) {
    await page.evaluate(
        `document.documentElement.setAttribute('data-theme', ${JSON.stringify(theme)}); true`,
    );
    await sleep(120);
}

async function pageInfo(page) {
    return page.evaluate(`(() => {
        let component = null;
        try { component = (history.state && history.state.page && history.state.page.component) || null; } catch (e) {}
        if (!component) {
            const app = document.getElementById('app');
            try { component = app && app.dataset.page ? JSON.parse(app.dataset.page).component : null; } catch (e) {}
        }
        if (!component) {
            const script = document.querySelector('script[data-page]');
            try { component = script ? JSON.parse(script.textContent).component : null; } catch (e) {}
        }
        return { component, url: location.pathname + location.search, app: !!document.querySelector('.cf-shell') };
    })()`);
}

async function scrollThrough(page) {
    const height = await page.evaluate('document.documentElement.scrollHeight');
    const vh = await page.evaluate('innerHeight');

    for (let y = 0, step = 0; y < height && step < 12; y += vh * 0.8, step++) {
        await page.evaluate(`window.scrollTo(0, ${Math.round(y)})`);
        await sleep(220);
    }

    await page.evaluate('window.scrollTo(0, 0)');
    await sleep(500);
}

function shotName(...parts) {
    return (
        parts
            .join('_')
            .replace(/^\/+/, '')
            .replace(/[^a-z0-9_.-]+/gi, '-')
            .replace(/-+/g, '-')
            .slice(0, 120) + '.png'
    );
}

async function sweepPages(page, role) {
    const source = role === 'guest' ? GUEST_PAGES : APP_PAGES;
    const pages = source.filter((path) =>
        pageFilter
            ? pageFilter.some((p) => path === p || path.startsWith(p + '?'))
            : quick
              ? QUICK_PAGES.includes(path)
              : true,
    );
    const viewports = [
        ...widths.map((w) => [w, 800]),
        ...SHORT_WIDTHS.filter((w) => !quick && widths.includes(w)).map((w) => [
            w,
            SHORT_HEIGHT,
        ]),
    ];

    for (const path of pages) {
        await page.setViewport(viewports[0][0], viewports[0][1]);
        await page.goto(base + path, { settle: 1600 });
        const info = await pageInfo(page);
        visits.push({ role, path, ...info });
        console.log(
            `  ${path} → ${info.component ?? '?'}${info.url !== path ? ` (${info.url})` : ''}`,
        );
        let first = true;

        for (const [width, height] of viewports) {
            await page.setViewport(width, height);
            await sleep(350);

            if (first || width < 768) {
                await scrollThrough(page);
            }

            first = false;

            for (const theme of themes) {
                await setTheme(page, theme);
                const context = {
                    role,
                    page: path,
                    component: info.component,
                    width,
                    height,
                    theme,
                    scenario: null,
                };
                const items = await page.call(measurePage, {});
                const shell = info.app ? await page.call(measureShell) : [];
                record(context, [...items, ...shell]);

                if (shots) {
                    await page.screenshot(
                        join(
                            out,
                            shotName(role, path, `${width}x${height}`, theme),
                        ),
                    );
                    await page.setViewport(width, height);
                }
            }
        }
    }
}

async function sweepDialogs(page, role) {
    const scenarios = DIALOGS.filter(
        (d) =>
            d.roles.includes(role) &&
            (!dialogFilter || d.name.toLowerCase().includes(dialogFilter)) &&
            (!pageFilter || pageFilter.includes(d.route)),
    );
    const sizes = quick
        ? QUICK_WIDTHS.map((w) => [w, 800])
        : [
              [375, 800],
              [768, 900],
              [1150, SHORT_HEIGHT],
              [1440, 900],
          ].filter(([w]) => widths.includes(w) || args.widths === undefined);

    for (const scenario of scenarios) {
        for (const [width, height] of sizes) {
            if (
                (scenario.minWidth && width < scenario.minWidth) ||
                (scenario.maxWidth && width > scenario.maxWidth)
            ) {
                continue;
            }

            await page.setViewport(width, height);
            await page.goto(base + scenario.route, { settle: 900 });
            let opened = true;

            for (const step of scenario.steps) {
                if (step.click) {
                    opened = await click(page, step.click);

                    if (!opened) {
                        break;
                    }

                    await sleep(450);
                } else if (step.wait) {
                    opened = await page.waitForSelector(step.wait, 5000);

                    if (!opened) {
                        break;
                    }

                    await sleep(350);
                } else if (step.code) {
                    await typeCode(page);
                    await sleep(800);
                } else if (step.pause) {
                    await sleep(step.pause);
                }
            }

            const context = {
                role,
                page: scenario.route,
                scenario: scenario.name,
                width,
                height,
                theme: themes[0],
            };

            if (!opened) {
                if (!scenario.optional) {
                    record(context, [
                        {
                            kind: 'scenario-failed',
                            selector: '',
                            text: scenario.name,
                            detail: 'opener not found or nothing opened',
                        },
                    ]);
                }

                console.log(
                    `  ✗ ${scenario.name} @${width}x${height}: did not open`,
                );
                continue;
            }

            await sleep(500);

            for (const theme of themes) {
                await setTheme(page, theme);
                const ctx = { ...context, theme };
                const rootSelector =
                    scenario.root ||
                    '[role="dialog"], [role="alertdialog"], [role="menu"], [data-radix-popper-content-wrapper]';
                const items = await page.call(measurePage, {
                    rootSelector,
                    occlusion: true,
                });
                const dialogs = await page.call(measureDialogs);
                record(ctx, [...items, ...dialogs.findings]);

                if (shots) {
                    await page.screenshot(
                        join(
                            out,
                            shotName(
                                role,
                                'dialog',
                                scenario.name,
                                `${width}x${height}`,
                                theme,
                            ),
                        ),
                        { full: false },
                    );
                }
            }

            console.log(`  ✓ ${scenario.name} @${width}x${height}`);
        }
    }
}

function report() {
    const open = findings.filter((f) => !f.allowed);
    const allowedOnes = findings.filter((f) => f.allowed);
    writeFileSync(
        join(out, 'report.json'),
        JSON.stringify(
            { base, roles, widths, themes, visits, findings },
            null,
            2,
        ),
    );

    // Group the same fault across widths/themes/roles.
    const groups = new Map();

    for (const f of findings) {
        const key = [
            f.allowed ? 'allowed' : 'open',
            f.scenario || f.page,
            f.kind,
            f.selector,
            f.text,
        ].join('|');
        const group = groups.get(key) || {
            ...f,
            where: new Set(),
            roles: new Set(),
        };
        group.where.add(
            `${f.width}${f.height !== 800 ? 'x' + f.height : ''}${f.theme === 'dark' ? 'd' : ''}`,
        );
        group.roles.add(f.role);
        groups.set(key, group);
    }

    const lines = [
        `# QA sweep ${stamp}`,
        '',
        `Base ${base} · roles ${roles.join(', ')} · widths ${widths.join(', ')} · themes ${themes.join(', ')}`,
        '',
        `**${open.length} open findings** (${[...groups.values()].filter((g) => !g.allowed).length} distinct), ${allowedOnes.length} allowed.`,
        '',
    ];
    const byKind = {};

    for (const f of open) {
        byKind[f.kind] = (byKind[f.kind] || 0) + 1;
    }

    lines.push(
        '| Kind | Count |',
        '|---|---|',
        ...Object.entries(byKind).map(([k, n]) => `| ${k} | ${n} |`),
        '',
    );

    for (const section of ['open', 'allowed']) {
        const rows = [...groups.values()].filter(
            (g) => (section === 'open') === !g.allowed,
        );

        if (!rows.length) {
            continue;
        }

        lines.push(
            `## ${section === 'open' ? 'Open' : 'Allowed (scripts/qa/allow.json)'}`,
            '',
        );
        rows.sort(
            (a, b) =>
                (a.scenario || a.page).localeCompare(b.scenario || b.page) ||
                a.kind.localeCompare(b.kind),
        );

        for (const g of rows) {
            lines.push(
                `- **${g.scenario ? 'dialog: ' + g.scenario : g.page}** · ${g.kind} · \`${g.selector}\`${g.text ? ` "${g.text}"` : ''} · ${g.detail} · at ${[...g.where].join(' ')} · ${[...g.roles].join(', ')}${g.allowed ? ` · _${g.allowed}_` : ''}`,
            );
        }

        lines.push('');
    }

    writeFileSync(join(out, 'report.md'), lines.join('\n'));
    console.log(
        `\n${open.length} open findings, ${allowedOnes.length} allowed. Report: ${join(out, 'report.md')}`,
    );

    return open.length;
}

async function main() {
    const browser = await launchChrome({
        port: Number(args.port || 9333),
        headless: !args.headed,
    });
    let failed = false;

    try {
        for (const role of roles) {
            console.log(`\n${ROLES[role].label}`);
            const page = await browser.newPage();

            try {
                if (role !== 'guest') {
                    await signIn(page, role);
                    await unlockVault(page);
                }

                if (runPages) {
                    await sweepPages(page, role);
                }

                if (runDialogs && role !== 'guest') {
                    await sweepDialogs(page, role);
                }

                if (page.consoleErrors.length) {
                    record(
                        {
                            role,
                            page: '(console)',
                            width: 0,
                            height: 0,
                            theme: '',
                            scenario: null,
                        },
                        page.consoleErrors.slice(0, 20).map((e) => ({
                            kind: 'js-error',
                            selector: '',
                            text: '',
                            detail: String(e).slice(0, 200),
                        })),
                    );
                }
            } catch (error) {
                failed = true;
                console.error(`  ${role}: ${error.message}`);
                record(
                    {
                        role,
                        page: '(run)',
                        width: 0,
                        height: 0,
                        theme: '',
                        scenario: null,
                    },
                    [
                        {
                            kind: 'run-error',
                            selector: '',
                            text: '',
                            detail: error.message,
                        },
                    ],
                );
            }

            await browser
                .send('Target.closeTarget', { targetId: page.targetId })
                .catch(() => {});
        }
    } finally {
        await browser.close();
    }

    const open = report();
    process.exit(open > 0 || failed ? 1 : 0);
}

main().catch((error) => {
    console.error(error);
    process.exit(2);
});
