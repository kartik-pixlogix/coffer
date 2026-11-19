/*
 * The in-page checks of the QA sweep (docs/QA.md). Each function is serialised and
 * run inside the page, so it must be self-contained (no imports, no closures).
 */

/**
 * Layout findings for the page, or only inside `rootSelector` (an open dialog/menu).
 * Returns [{ kind, selector, text, detail }].
 */
export function measurePage(options) {
    const opts = Object.assign(
        {
            rootSelector: null,
            occlusion: true,
            scrollSteps: 6,
            skipOverlay: false,
        },
        options || {},
    );
    const vw = document.documentElement.clientWidth;
    const vh = window.innerHeight;
    const findings = [];
    const seen = new Set();
    const EPS = 1.5;

    function selectorOf(el) {
        const parts = [];
        let node = el;

        for (let depth = 0; node && node.nodeType === 1 && depth < 4; depth++) {
            let part = node.tagName.toLowerCase();

            if (node.id && !/^radix-|^:r/.test(node.id)) {
                part += '#' + node.id;
                parts.unshift(part);
                break;
            }

            const classes = Array.from(node.classList)
                .filter(
                    (c) =>
                        !/^(is-|animate-|max-|min-|focus|hover)/.test(c) &&
                        c.length < 32,
                )
                .slice(0, 2);

            if (classes.length) {
                part += '.' + classes.join('.');
            }

            const role = node.getAttribute('role');

            if (role && !classes.length) {
                part += `[role=${role}]`;
            }

            parts.unshift(part);
            node = node.parentElement;
        }

        return parts.join(' > ');
    }

    function textOf(el) {
        return (el.innerText || el.value || el.getAttribute('aria-label') || '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 60);
    }

    function add(kind, el, detail) {
        const selector = selectorOf(el);
        const key = kind + '|' + selector + '|' + textOf(el);

        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        findings.push({ kind, selector, text: textOf(el), detail });
    }

    const styleCache = new Map();
    const cs = (el) => {
        let style = styleCache.get(el);

        if (!style) {
            style = getComputedStyle(el);
            styleCache.set(el, style);
        }

        return style;
    };

    function hiddenBySr(el, rect) {
        const style = cs(el);

        return (
            (rect.width <= 1 && rect.height <= 1) ||
            (style.position === 'absolute' &&
                style.clip &&
                style.clip !== 'auto') ||
            style.clipPath === 'inset(50%)'
        );
    }

    function visible(el) {
        if (!el.isConnected) {
            return false;
        }

        if (el.closest('[hidden], template, [data-qa-ignore]')) {
            return false;
        }

        // A closed <details> hides its content through ::details-content.
        const details = el.closest('details:not([open])');

        if (details && !el.closest('summary')) {
            return false;
        }

        let node = el;

        while (node && node.nodeType === 1) {
            const style = cs(node);

            if (
                style.display === 'none' ||
                style.visibility === 'hidden' ||
                style.contentVisibility === 'hidden' ||
                Number(style.opacity) < 0.05
            ) {
                return false;
            }

            node = node.parentElement;
        }

        const rect = el.getBoundingClientRect();

        // Parked far off screen (honeypots, off-canvas panels).
        if (rect.right < -100 || rect.left > vw + 2000) {
            return false;
        }

        return rect.width > 0 && rect.height > 0 && !hiddenBySr(el, rect);
    }

    /** The nearest ancestor that clips or scrolls horizontally (or vertically with axis 'y'). */
    function clipAncestor(el, axis = 'x') {
        let node = el.parentElement;

        while (
            node &&
            node !== document.body &&
            node !== document.documentElement
        ) {
            const style = cs(node);
            const overflow = axis === 'x' ? style.overflowX : style.overflowY;

            if (overflow !== 'visible') {
                return { node, overflow };
            }

            if (style.position === 'fixed') {
                return null;
            }

            node = node.parentElement;
        }

        return null;
    }

    function ownText(el) {
        let text = '';

        for (const child of el.childNodes) {
            if (child.nodeType === 3) {
                text += child.nodeValue;
            }
        }

        return text.replace(/\s+/g, ' ').trim();
    }

    function textRect(el) {
        let left = Infinity;
        let top = Infinity;
        let right = -Infinity;
        let bottom = -Infinity;

        for (const child of el.childNodes) {
            if (child.nodeType !== 3 || !child.nodeValue.trim()) {
                continue;
            }

            const range = document.createRange();
            range.selectNodeContents(child);

            for (const r of range.getClientRects()) {
                if (r.width < 1 || r.height < 1) {
                    continue;
                }

                left = Math.min(left, r.left);
                top = Math.min(top, r.top);
                right = Math.max(right, r.right);
                bottom = Math.max(bottom, r.bottom);
            }
        }

        return left === Infinity ? null : { left, top, right, bottom };
    }

    /** The part of a text box that can be seen: cut by the element and its clipping ancestors. */
    function clippedRect(el, rect) {
        const out = { ...rect };
        let node = el;

        while (node && node !== document.body && node.nodeType === 1) {
            const style = cs(node);

            if (
                style.overflowX !== 'visible' ||
                style.overflowY !== 'visible'
            ) {
                const r = node.getBoundingClientRect();

                if (style.overflowX !== 'visible') {
                    out.left = Math.max(out.left, r.left);
                    out.right = Math.min(out.right, r.right);
                }

                if (style.overflowY !== 'visible') {
                    out.top = Math.max(out.top, r.top);
                    out.bottom = Math.min(out.bottom, r.bottom);
                }
            }

            if (style.position === 'fixed') {
                break;
            }

            node = node.parentElement;
        }

        return out.right - out.left > 1 && out.bottom - out.top > 1
            ? out
            : null;
    }

    const FLOATING =
        '[data-radix-popper-content-wrapper], .cf-pop, [role="menu"], [role="listbox"], [role="tooltip"]';

    function floating(el) {
        return !!el.closest(FLOATING);
    }

    /** Inside a fixed or sticky box (bars, toasts, FABs): page content may pass under it. */
    function pinned(el) {
        let node = el;

        while (node && node.nodeType === 1) {
            const position = cs(node).position;

            if (position === 'fixed' || position === 'sticky') {
                return true;
            }

            node = node.parentElement;
        }

        return false;
    }

    const roots = opts.rootSelector
        ? Array.from(document.querySelectorAll(opts.rootSelector))
        : [document.body];

    const all = [];

    for (const root of roots) {
        all.push(root, ...root.querySelectorAll('*'));
    }

    const elements = all.filter(
        (el) =>
            ![
                'SCRIPT',
                'STYLE',
                'NOSCRIPT',
                'svg',
                'path',
                'BR',
                'HEAD',
                'META',
                'LINK',
            ].includes(el.tagName) &&
            !(el instanceof SVGElement && el.tagName !== 'svg') &&
            visible(el),
    );

    // 1. The document scrolls sideways.
    if (!opts.rootSelector) {
        const doc = document.documentElement;

        if (doc.scrollWidth > doc.clientWidth + 1) {
            findings.push({
                kind: 'page-hscroll',
                selector: 'html',
                text: '',
                detail: `scrollWidth ${doc.scrollWidth} > clientWidth ${doc.clientWidth}`,
            });
        }
    }

    // 2. Elements past the viewport or past their card, outside an intended scroll area.
    const overflowing = new Set();

    for (const el of elements) {
        const rect = el.getBoundingClientRect();
        const clip = clipAncestor(el, 'x');
        const style = cs(el);

        if (style.position === 'fixed' && opts.skipOverlay) {
            continue;
        }

        if (!clip || clip.overflow === 'visible') {
            if (rect.right > vw + EPS || rect.left < -EPS) {
                if (!(el.parentElement && overflowing.has(el.parentElement))) {
                    add(
                        'off-viewport',
                        el,
                        `left ${Math.round(rect.left)} right ${Math.round(rect.right)} (viewport ${vw})`,
                    );
                }

                overflowing.add(el);
                continue;
            }
        }

        const card = el.parentElement?.closest(
            '.cf-card, .cf-kpi, .cf-modal, .cf-drawer',
        );

        if (card && card !== el && cs(card).overflowX === 'visible') {
            const cardRect = card.getBoundingClientRect();
            const between = clipAncestor(el, 'x');
            const scrollsBetween =
                between && between.node !== card && card.contains(between.node);

            if (
                !scrollsBetween &&
                (rect.right > cardRect.right + EPS ||
                    rect.left < cardRect.left - EPS) &&
                rect.width <= cardRect.width + 200
            ) {
                if (!(el.parentElement && overflowing.has(el.parentElement))) {
                    add(
                        'off-card',
                        el,
                        `right ${Math.round(rect.right)} past card right ${Math.round(cardRect.right)}`,
                    );
                }

                overflowing.add(el);
            }
        }
    }

    // 3. Text cut off: by its own box (overflow hidden, no ellipsis) or by a clipping ancestor.
    const texts = [];
    const canvas = document.createElement('canvas').getContext('2d');

    for (const el of elements) {
        const style = cs(el);

        if (el.tagName === 'SELECT') {
            const option = el.options[el.selectedIndex];

            if (option && option.text.trim()) {
                canvas.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
                const needed = canvas.measureText(option.text).width;
                const room =
                    el.clientWidth -
                    parseFloat(style.paddingLeft) -
                    parseFloat(style.paddingRight);

                if (needed > room + 1) {
                    add(
                        'clipped-text',
                        el,
                        `select shows "${option.text}" (${Math.round(needed)}px) in ${Math.round(room)}px`,
                    );
                }
            }

            continue;
        }

        const own = ownText(el);

        if (!own) {
            continue;
        }

        const rect = textRect(el);

        if (!rect) {
            continue;
        }

        const seenRect = clippedRect(el, rect);

        if (seenRect) {
            texts.push({ el, rect: seenRect, text: own });
        }

        const ellipsis = style.textOverflow === 'ellipsis';
        const clamped =
            style.webkitLineClamp && style.webkitLineClamp !== 'none';

        if (
            !ellipsis &&
            !clamped &&
            (style.overflowX === 'hidden' || style.overflowX === 'clip') &&
            el.scrollWidth > el.clientWidth + 1
        ) {
            add(
                'clipped-text',
                el,
                `scrollWidth ${el.scrollWidth} > ${el.clientWidth}`,
            );
            continue;
        }

        // A clipping (not scrolling) ancestor cuts the text box.
        let node = el.parentElement;

        while (node && node !== document.body) {
            const nodeStyle = cs(node);

            if (nodeStyle.position === 'fixed') {
                break;
            }

            if (
                nodeStyle.overflowX === 'auto' ||
                nodeStyle.overflowX === 'scroll'
            ) {
                break;
            }

            if (
                (nodeStyle.overflowX === 'hidden' ||
                    nodeStyle.overflowX === 'clip') &&
                nodeStyle.textOverflow !== 'ellipsis'
            ) {
                const clipRect = node.getBoundingClientRect();

                if (
                    rect.right > clipRect.right + EPS + 1 ||
                    rect.left < clipRect.left - EPS - 1
                ) {
                    // Ellipsis text inside its own ellipsis box is fine.
                    if (!(ellipsis && node.contains(el))) {
                        add(
                            'clipped-text',
                            el,
                            `text ${Math.round(rect.left)}–${Math.round(rect.right)} clipped by ${selectorOf(node)} (${Math.round(clipRect.left)}–${Math.round(clipRect.right)})`,
                        );
                    }
                }

                break;
            }

            node = node.parentElement;
        }
    }

    // 4. Text boxes that overlap each other.
    function intersects(a, b) {
        const w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
        const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);

        const minH = Math.min(a.bottom - a.top, b.bottom - b.top);
        const minW = Math.min(a.right - a.left, b.right - b.left);

        // Tight display line-heights touch by a few px; that isn't a collision.
        return w > 3 &&
            h > Math.max(4, minH * 0.34) &&
            w > Math.min(8, minW * 0.5)
            ? { w, h }
            : null;
    }

    const textList = texts.filter((t) => !t.el.closest('[role="tooltip"]'));

    for (let i = 0; i < textList.length; i++) {
        for (let j = i + 1; j < textList.length; j++) {
            const a = textList[i];
            const b = textList[j];

            if (a.el.contains(b.el) || b.el.contains(a.el)) {
                continue;
            }

            // Popovers and fixed/sticky bars float over content by design.
            if (
                floating(a.el) !== floating(b.el) ||
                pinned(a.el) !== pinned(b.el)
            ) {
                continue;
            }

            const hit = intersects(a.rect, b.rect);

            if (hit) {
                add(
                    'overlap',
                    a.el,
                    `"${a.text.slice(0, 30)}" overlaps "${b.text.slice(0, 30)}" (${selectorOf(b.el)}) by ${Math.round(hit.w)}×${Math.round(hit.h)}`,
                );
            }
        }
    }

    // 5. Text covered by another box (checked in the viewport only).
    if (opts.occlusion) {
        for (const t of textList) {
            const { rect, el } = t;

            if (
                rect.bottom < 0 ||
                rect.top > vh ||
                rect.right < 0 ||
                rect.left > vw
            ) {
                continue;
            }

            const x = Math.min(
                Math.max((rect.left + rect.right) / 2, 1),
                vw - 1,
            );
            const y = Math.min(
                Math.max((rect.top + rect.bottom) / 2, 1),
                vh - 1,
            );
            const hit = document.elementFromPoint(x, y);

            if (!hit || hit === el || el.contains(hit) || hit.contains(el)) {
                continue;
            }

            // A label's own control, or a sibling inside the same button/link, is fine.
            const control = el.closest(
                'label, button, a, [role="button"], [role="option"], [role="menuitem"]',
            );

            if (control && control.contains(hit)) {
                continue;
            }

            // Page content passes under fixed and sticky bars; only pinned-over-pinned
            // (a toast over the top bar) is a fault.
            if (
                (pinned(hit) && !pinned(el)) ||
                (floating(hit) && !floating(el))
            ) {
                continue;
            }

            add(
                'occluded',
                el,
                `covered by ${selectorOf(hit)} at ${Math.round(x)},${Math.round(y)}`,
            );
        }
    }

    // 6. Popovers and menus that leave the viewport.
    for (const el of document.querySelectorAll(
        '[data-radix-popper-content-wrapper] > *, [role="menu"], [role="listbox"], .cf-popover, [role="tooltip"]',
    )) {
        if (!visible(el)) {
            continue;
        }

        const rect = el.getBoundingClientRect();

        if (
            rect.right > vw + EPS ||
            rect.left < -EPS ||
            rect.bottom > vh + EPS ||
            rect.top < -EPS
        ) {
            add(
                'popover-offscreen',
                el,
                `${Math.round(rect.left)},${Math.round(rect.top)}–${Math.round(rect.right)},${Math.round(rect.bottom)} (viewport ${vw}×${vh})`,
            );
        }

        // Clipped by a scrolling/clipping ancestor (it isn't portalled).
        let node = el.parentElement;

        while (node && node !== document.body) {
            const style = cs(node);

            if (
                style.overflowX !== 'visible' ||
                style.overflowY !== 'visible'
            ) {
                const clipRect = node.getBoundingClientRect();

                if (
                    rect.bottom > clipRect.bottom + EPS ||
                    rect.right > clipRect.right + EPS ||
                    rect.top < clipRect.top - EPS ||
                    rect.left < clipRect.left - EPS
                ) {
                    add(
                        'popover-clipped',
                        el,
                        `clipped by ${selectorOf(node)}`,
                    );
                }

                break;
            }

            node = node.parentElement;
        }
    }

    return findings;
}

/** Dialog-specific checks: height cap, sideways scroll, inside the viewport. */
export function measureDialogs() {
    const vw = document.documentElement.clientWidth;
    const vh = window.innerHeight;
    const findings = [];
    const dialogs = Array.from(
        document.querySelectorAll('[role="dialog"], [role="alertdialog"]'),
    ).filter((el) => el.getBoundingClientRect().height > 0);

    for (const dialog of dialogs) {
        const rect = dialog.getBoundingClientRect();
        const name = (
            dialog.querySelector('h2, [id$="title"]')?.textContent ||
            dialog.getAttribute('aria-label') ||
            ''
        )
            .trim()
            .slice(0, 60);

        if (
            rect.top < -1 ||
            rect.bottom > vh + 1 ||
            rect.left < -1 ||
            rect.right > vw + 1
        ) {
            findings.push({
                kind: 'dialog-offscreen',
                selector: dialog.className.toString().slice(0, 60),
                text: name,
                detail: `${Math.round(rect.left)},${Math.round(rect.top)}–${Math.round(rect.right)},${Math.round(rect.bottom)} in ${vw}×${vh}`,
            });
        }

        // Something inside scrolls vertically while the dialog could still grow.
        const scrollers = [dialog, ...dialog.querySelectorAll('*')].filter(
            (el) => {
                const style = getComputedStyle(el);

                return (
                    (style.overflowY === 'auto' ||
                        style.overflowY === 'scroll') &&
                    el.scrollHeight > el.clientHeight + 2 &&
                    el.clientHeight > 80
                );
            },
        );
        const room = vh - 48;

        if (scrollers.length && rect.height < room - 24 && vw > 640) {
            findings.push({
                kind: 'dialog-capped',
                selector: dialog.className.toString().slice(0, 60),
                text: name,
                detail: `dialog ${Math.round(rect.height)}px tall scrolls inside, viewport allows ${room}px`,
            });
        }

        // Sideways scrollbars inside the dialog.
        for (const el of [dialog, ...dialog.querySelectorAll('*')]) {
            const style = getComputedStyle(el);

            if (
                (style.overflowX === 'auto' || style.overflowX === 'scroll') &&
                el.scrollWidth > el.clientWidth + 1 &&
                !el.closest('[data-qa-hscroll-ok]')
            ) {
                findings.push({
                    kind: 'dialog-hscroll',
                    selector:
                        el.className.toString().slice(0, 60) ||
                        el.tagName.toLowerCase(),
                    text: name,
                    detail: `scrollWidth ${el.scrollWidth} > ${el.clientWidth}`,
                });
            }
        }
    }

    return { count: dialogs.length, findings };
}

/** Shell checks for app pages: rail and top bar stay in view when the page scrolls. */
export function measureShell() {
    const findings = [];
    const rail = document.querySelector('.app-rail');
    const topbar = document.querySelector('.cf-topbar');
    const doc = document.documentElement;

    if (!rail || !topbar || doc.scrollHeight <= window.innerHeight + 200) {
        return findings;
    }

    const railShown = getComputedStyle(rail).display !== 'none';

    if (!railShown) {
        return findings;
    }

    window.scrollTo(0, 400);
    const railRect = rail.getBoundingClientRect();
    const topRect = topbar.getBoundingClientRect();

    if (railRect.bottom < 40) {
        findings.push({
            kind: 'shell-scrolls-away',
            selector: '.app-rail',
            text: '',
            detail: `rail bottom ${Math.round(railRect.bottom)} after scrolling 400px`,
        });
    }

    if (topRect.bottom < 10) {
        findings.push({
            kind: 'shell-scrolls-away',
            selector: '.cf-topbar',
            text: '',
            detail: `top bar bottom ${Math.round(topRect.bottom)} after scrolling 400px`,
        });
    }

    window.scrollTo(0, 0);

    return findings;
}

/** Visible buttons/links (for writing dialog scenarios): [{ text, tag }]. */
export function listControls() {
    return Array.from(
        document.querySelectorAll('button, a[href], [role="button"], summary'),
    )
        .filter((el) => el.getBoundingClientRect().width > 0)
        .map((el) => ({
            tag: el.tagName.toLowerCase(),
            text: (el.innerText || el.getAttribute('aria-label') || '')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 50),
        }))
        .filter((c) => c.text);
}

/**
 * Find the first visible control matching { text, selector, within, nth, exact }.
 * Scrolls it into view and returns its centre (the runner clicks with real mouse events,
 * which Radix triggers need), or false.
 */
export function clickControl(spec) {
    const scope = spec.within ? document.querySelector(spec.within) : document;

    if (!scope) {
        return false;
    }

    const candidates = Array.from(
        scope.querySelectorAll(
            spec.selector ||
                'button, a[href], [role="button"], [role="menuitem"], [role="tab"], [role="combobox"], summary, label',
        ),
    ).filter((el) => {
        const rect = el.getBoundingClientRect();

        if (rect.width === 0 || rect.height === 0) {
            return false;
        }

        if (!spec.text) {
            return true;
        }

        const label = (
            el.innerText ||
            el.getAttribute('aria-label') ||
            el.title ||
            ''
        )
            .replace(/\s+/g, ' ')
            .trim();

        if (spec.text instanceof Object && spec.text.re) {
            return new RegExp(spec.text.re, 'i').test(label);
        }

        return spec.exact
            ? label === spec.text
            : label.toLowerCase().includes(String(spec.text).toLowerCase());
    });
    const el = candidates[spec.nth || 0];

    if (!el) {
        return false;
    }

    el.scrollIntoView({ block: 'center', inline: 'center' });
    const rect = el.getBoundingClientRect();

    return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
}
