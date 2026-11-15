import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import { BOOST, boostBundle, paths } from '../../../scripts/coffer-ui-css.mjs';

describe('coffer-ui.css (D22)', () => {
    it('is the design bundle with component rules boosted (run `npm run ui:css`)', () => {
        const bundle = readFileSync(paths.bundle, 'utf8');
        const generated = readFileSync(paths.output, 'utf8');

        expect(generated).toBe(boostBundle(bundle));
    });

    it('leaves resets and tokens alone and boosts components', () => {
        const css = boostBundle(
            ':where(:root){--x:1}.cf button,.cf input{color:inherit}' +
                '.cf-btn--primary{color:red}.a,.b:hover::after{x:y}' +
                '@media (x){.cf-skel{a:b}}@keyframes k{to{opacity:1}}' +
                '.c[data-x="{"]{d:e}',
        );

        expect(css).toContain(':where(:root){--x:1}');
        expect(css).toContain('.cf button,.cf input{color:inherit}');
        expect(css).toContain(`.cf-btn--primary${BOOST}{color:red}`);
        expect(css).toContain(`.a${BOOST},.b:hover${BOOST}::after{x:y}`);
        expect(css).toContain(`@media (x){.cf-skel${BOOST}{a:b}}`);
        expect(css).toContain('@keyframes k{to{opacity:1}}');
        expect(css).toContain(`.c[data-x="{"]${BOOST}{d:e}`);
    });
});
