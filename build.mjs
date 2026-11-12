/**
 * Builds the frontend assets from resources/ into assets/dist/:
 *   resources/JavaScript/CbLoader.js -> assets/dist/JavaScript/CbLoader.js (one file)
 *   resources/Scss/Styles.scss       -> assets/dist/Css/ConsentBanner.css
 *
 * Kirby serves every plugin asset under its own media hash. A file therefore
 * cannot reference its neighbours by a relative URL: the JavaScript is bundled
 * into one file and images of the stylesheet are inlined as data URIs.
 *
 *   npm run build      production (minified)
 *   npm run build:dev  development (readable, with console output)
 */
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as esbuild from 'esbuild';
import * as sass from 'sass';
import postcss from 'postcss';
import autoprefixer from 'autoprefixer';

const root = path.dirname(fileURLToPath(import.meta.url));
const source = path.join(root, 'resources');
const target = path.join(root, 'assets', 'dist');
const dev = process.argv.includes('--dev');

async function buildScript() {
    await esbuild.build({
        entryPoints: [path.join(source, 'JavaScript', 'CbLoader.js')],
        outfile: path.join(target, 'JavaScript', 'CbLoader.js'),
        bundle: true,
        format: 'iife',
        target: ['es2019'],
        minify: !dev,
        sourcemap: dev ? 'inline' : false,
        legalComments: 'none',
        define: { 'process.env.NODE_ENV': JSON.stringify(dev ? 'development' : 'production') },
        drop: dev ? [] : ['console'],
        logLevel: 'info',
    });
}

/**
 * url('../Image/name.svg') -> data URI, so the stylesheet needs no second file.
 */
async function inlineImages(css) {
    const pattern = /url\((['"]?)\.\.\/Image\/([\w.-]+\.svg)\1\)/g;
    const names = [...new Set([...css.matchAll(pattern)].map(match => match[2]))];
    const images = {};
    for (const name of names) {
        const svg = (await readFile(path.join(source, 'Image', name), 'utf8')).trim();
        images[name] = 'data:image/svg+xml,' + encodeURIComponent(svg).replace(/'/g, '%27');
    }
    return css.replace(pattern, (_, quote, name) => `url("${images[name]}")`);
}

async function buildStyle() {
    const compiled = sass.compile(path.join(source, 'Scss', 'Styles.scss'), {
        style: dev ? 'expanded' : 'compressed',
        quietDeps: true,
        silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'slash-div'],
    });
    const prefixed = await postcss([autoprefixer]).process(compiled.css, { from: undefined });
    const file = path.join(target, 'Css', 'ConsentBanner.css');
    await mkdir(path.dirname(file), { recursive: true });
    await writeFile(file, await inlineImages(prefixed.css));
    console.log(`  ${path.relative(root, file)}`);
}

await Promise.all([buildScript(), buildStyle()]);
