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

            // Inside a visually hidden box (cf-sr tables behind charts).
            if (
                node !== el &&
                (node.classList.contains('cf-sr') ||
                    node.classList.contains('sr-only') ||
                    hiddenBySr(node, node.getBoundingClientRect()))
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

    /** Each line box of the element's own text. */
    function lineRects(el) {
        const out = [];

        for (const child of el.childNodes) {
            if (child.nodeType !== 3 || !child.nodeValue.trim()) {
                continue;
            }

            const range = document.createRange();
            range.selectNodeContents(child);

            for (const r of range.getClientRects()) {
                if (r.width >= 1 && r.height >= 1) {
                    out.push({
                        left: r.left,
                        top: r.top,
                        right: r.right,
                        bottom: r.bottom,
                    });
                }
            }
        }

        return out;
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

    function stickyOf(el) {
        let node = el;

        while (node && node.nodeType === 1) {
            if (cs(node).position === 'sticky') {
                return node;
            }

            node = node.parentElement;
        }

        return null;
    }

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

    /** Inside a fixed box (the top bar, the phone tab bar, toasts, dialogs). */
    function fixedOf(el) {
        for (
            let node = el;
            node && node.nodeType === 1;
            node = node.parentElement
        ) {
            if (cs(node).position === 'fixed') {
                return node;
            }
        }

        return null;
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
            const lines = lineRects(el)
                .map((r) => clippedRect(el, r))
                .filter(Boolean);
            texts.push({ el, rect: seenRect, lines, text: own });
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
                pinned(a.el) !== pinned(b.el) ||
                stickyOf(a.el) !== stickyOf(b.el)
            ) {
                continue;
            }

            let hit = null;

            if (intersects(a.rect, b.rect)) {
                for (const ra of a.lines) {
                    for (const rb of b.lines) {
                        hit = hit || intersects(ra, rb);
                    }
                }
            }

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
            // (a toast over the top bar) is a fault. A pinned table column is still
            // page content: it passes under the fixed bars too.
            if (
                (pinned(hit) && !pinned(el)) ||
                (fixedOf(hit) && !fixedOf(el)) ||
                (floating(hit) && !floating(el))
            ) {
                continue;
            }

            // Content scrolling under a sticky header or footer in its own scroller
            // (a dialog's pinned head and foot) is the design.
            const sticky = stickyOf(hit);

            if (sticky && !sticky.contains(el)) {
                continue;
            }

            add(
                'occluded',
                el,
                `covered by ${selectorOf(hit)} at ${Math.round(x)},${Math.round(y)}`,
            );
        }
    }

    // 7. Content under a sticky lead column while its scroller is at rest
    // (scrollLeft 0): nothing should sit under the pinned column before scrolling.
    for (const sticky of elements) {
        const style = cs(sticky);

        if (style.position !== 'sticky' || style.left === 'auto') {
            continue;
        }

        const scroller = clipAncestor(sticky, 'x');

        if (
            !scroller ||
            !['auto', 'scroll'].includes(scroller.overflow) ||
            scroller.node.scrollWidth <= scroller.node.clientWidth + 1 ||
            Math.abs(scroller.node.scrollLeft) > 0.5
        ) {
            continue;
        }

        const pin = sticky.getBoundingClientRect();

        for (const t of textList) {
            if (
                sticky.contains(t.el) ||
                t.el.contains(sticky) ||
                !scroller.node.contains(t.el) ||
                stickyOf(t.el) !== null
            ) {
                continue;
            }

            const hit = intersects(t.rect, {
                left: pin.left,
                top: pin.top,
                right: pin.right,
                bottom: pin.bottom,
            });

            if (hit) {
                add(
                    'under-sticky',
                    t.el,
                    `"${t.text.slice(0, 30)}" sits under the pinned ${selectorOf(sticky)} at rest by ${Math.round(hit.w)}×${Math.round(hit.h)}`,
                );
            }
        }
    }

    // 8. A one-row strip that scrolls sideways with a scrollbar track (D127): tab and
    // chip rows hide it. Headless phones use overlay scrollbars, so a strip that
    // doesn't set scrollbar-width: none counts too (desktop browsers draw a track).
    for (const el of elements) {
        const style = cs(el);

        if (
            !['auto', 'scroll'].includes(style.overflowX) ||
            el.scrollWidth <= el.clientWidth + 1 ||
            el.clientHeight > 96
        ) {
            continue;
        }

        const track =
            el.offsetHeight -
            el.clientHeight -
            parseFloat(style.borderTopWidth) -
            parseFloat(style.borderBottomWidth);

        if (track > 0.5 || style.scrollbarWidth !== 'none') {
            add(
                'visible-scrollbar',
                el,
                track > 0.5
                    ? `a ${Math.round(track)}px scrollbar track under a one-row strip`
                    : `one-row strip scrolls sideways without scrollbar-width: none`,
            );
        }
    }

    // 9. Segmented controls and tabs whose labels can't be seen: zero-sized, or cut
    // to a sliver by a clipping parent that shrank below them (scrolling past them
    // sideways or vertically is fine).
    for (const el of all.filter((node) =>
        node.matches?.('.cf-seg-btn, .cf-tab, [role="tab"]'),
    )) {
        const group = el.closest('.cf-seg, .cf-tabs, [role="tablist"]') || el;

        if (!visible(group)) {
            continue;
        }

        const rect = el.getBoundingClientRect();
        // The smallest clipping parent: a strip or control squashed below its label.
        let room = Infinity;

        for (
            let node = el.parentElement;
            node && node !== document.body;
            node = node.parentElement
        ) {
            const style = cs(node);

            if (style.overflowY !== 'visible') {
                room = Math.min(room, node.getBoundingClientRect().height);
            }

            if (style.position === 'fixed') {
                break;
            }
        }

        const label = textOf(el);
        const seenHeight = Math.min(rect.height, room);

        if (
            rect.width < 4 ||
            rect.height < 4 ||
            !label ||
            seenHeight < rect.height * 0.6
        ) {
            add(
                'empty-control',
                el,
                `label "${label}" shows ${Math.round(seenHeight)} of ${Math.round(rect.height)}px (${Math.round(rect.width)}px wide)`,
            );
        }
    }

    // 10. App KPI tiles whose figure is smaller than the tile's label, or under 20 px.
    for (const tile of elements.filter(
        (node) =>
            node.classList.contains('cf-kpi') &&
            !node.classList.contains('is-loading'),
    )) {
        const label = tile.querySelector('.cf-kpi-title');
        const figureBox = tile.querySelector('.cf-kpi-value') || tile;
        const figures = [
            ...figureBox.querySelectorAll(
                '.t-money-lg, .t-money, .t-figure-hero',
            ),
        ].filter((node) => visible(node));

        // A bare figure (text straight in the value row) counts as the figure.
        if (
            figures.length === 0 &&
            figureBox !== tile &&
            figureBox.textContent.trim()
        ) {
            figures.push(figureBox);
        }

        if (!label || figures.length === 0) {
            continue;
        }

        const labelSize = parseFloat(cs(label).fontSize);
        const figureSize = Math.max(
            ...figures.map((node) => parseFloat(cs(node).fontSize)),
        );

        if (figureSize < labelSize || figureSize < 20) {
            add(
                'small-kpi',
                tile,
                `figure ${figureSize}px, label ${labelSize}px`,
            );
        }
    }

    // 11. A table scrolled sideways keeps each row's lead cell (its label) in view.
    for (const wrap of elements.filter(
        (node) =>
            node.classList.contains('cf-table-wrap') && node.scrollLeft > 0.5,
    )) {
        const edge = wrap.getBoundingClientRect().left;

        for (const row of wrap.querySelectorAll(
            ':scope > .cf-table > .cf-tr:not(.cf-thead), :scope > .cf-table > [role="rowgroup"] > .cf-tr',
        )) {
            const lead = row.firstElementChild;
            const left = lead ? lead.getBoundingClientRect().left : 0;

            if (lead && textOf(lead) && left < edge - 1) {
                add(
                    'lead-scrolled-away',
                    lead,
                    `the row's label scrolls out of view (${Math.round(left)} < ${Math.round(edge)})`,
                );
                break;
            }
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
        // Only modal dialogs and drawers should grow; popovers keep their own height.
        const modal =
            dialog.getAttribute('aria-modal') === 'true' ||
            dialog.matches('.cf-modal, .cf-drawer, [role="alertdialog"]');

        if (modal && scrollers.length && rect.height < room - 24 && vw > 640) {
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
                // One-row strips that scroll sideways by design (D127).
                !el.closest('[data-qa-hscroll-ok], .cf-tabs, .cf-scroll-row')
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
