'use strict';

// Compiles the generated framework files with the real frameworks and checks that every class editors can
// output exists. Generate the files first:
//   SWATCH_FRAMEWORK_FIXTURES=tests/frameworks/generated php tests/run.php
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const postcss = require('postcss');

const root = path.resolve(__dirname, '..', '..');
const generated = path.resolve(root, process.env.SWATCH_FRAMEWORK_FIXTURES || 'tests/frameworks/generated');
const failures = [];
let checks = 0;

function check(condition, message) {
    checks++;
    if (!condition) {
        failures.push(message);
    }
}

function file(name) {
    const full = path.join(generated, name);
    if (!fs.existsSync(full)) {
        throw new Error(`${full} is missing. Run tests/run.php with SWATCH_FRAMEWORK_FIXTURES=${path.relative(root, generated)} first.`);
    }
    return full;
}

/** Relative import path from this folder, so Sass treats the generated file as project code rather than a dependency. */
function importPath(full) {
    const relative = path.relative(__dirname, full).split(path.sep).join('/');
    return relative.startsWith('.') ? relative : `./${relative}`;
}

/** Maps each class used alone as a selector to its rules, in source order. */
function parse(css) {
    const rules = new Map();
    let order = 0;
    postcss.parse(css).walkRules((rule) => {
        for (const selector of rule.selectors) {
            const match = /^\.((?:\\.|[\w-])+)$/.exec(selector.trim());
            if (!match) {
                continue;
            }
            const decls = {};
            rule.walkDecls((decl) => {
                (decls[decl.prop] ??= []).push(decl.value + (decl.important ? ' !important' : ''));
            });
            const name = match[1].replace(/\\(.)/g, '$1');
            if (!rules.has(name)) {
                rules.set(name, []);
            }
            rules.get(name).push({ decls, order: order++ });
        }
    });
    return rules;
}

function value(rules, name, prop) {
    return (rules.get(name) ?? []).flatMap((rule) => rule.decls[prop] ?? []).join(' | ');
}

function has(rules, name, prop, expected, label) {
    const actual = value(rules, name, prop);
    check(actual.includes(expected), `${label}: .${name} ${prop} should include "${expected}", got "${actual}"`);
}

function everyClass(label, framework, rules) {
    const classes = JSON.parse(fs.readFileSync(file(`classes-${framework}.json`), 'utf8'));
    const missing = classes.filter((name) => !rules.has(name));
    check(classes.length > 0, `${label}: the class list is empty`);
    check(missing.length === 0, `${label}: ${missing.length} of ${classes.length} classes have no CSS: ${missing.slice(0, 20).join(', ')}`);
    return classes.length;
}

async function tailwind4() {
    const tailwind = require('@tailwindcss/postcss');
    const input = `@import "tailwindcss" source(none);\n@import "${importPath(file('tailwind4.css'))}";\n`;
    const result = await postcss([tailwind({ base: __dirname, optimize: false })])
        .process(input, { from: path.join(__dirname, 'tailwind4-input.css') });
    const rules = parse(result.css);
    const count = everyClass('Tailwind 4', 'tailwind4', rules);
    check(result.css.includes('--color-brand-orange-50: #fff7ed;'), 'Tailwind 4: exact shades are theme variables');
    check(result.css.includes('--color-blue: #0d6efd;'), 'Tailwind 4: theme => false colours define their base colour');
    check(/--color-blue-500: oklch\(/.test(result.css), 'Tailwind 4: theme => false colours keep Tailwind’s own shades');
    has(rules, 'bg-brand-orange-50', 'background-color', 'var(--color-brand-orange-50)', 'Tailwind 4');
    has(rules, 'bg-blue', 'background-color', 'var(--color-blue)', 'Tailwind 4');
    has(rules, 'bg-blue-975', 'background-color', 'var(--color-blue-975)', 'Tailwind 4');
    has(rules, 'bg-accent/50', 'background-color', '50%', 'Tailwind 4');
    has(rules, 'text-brand-orange-975/33', 'color', '33%', 'Tailwind 4');
    has(rules, 'opacity-33', 'opacity', '33%', 'Tailwind 4');
    has(rules, 'bg-brand-orange/0', 'background-color', '0%', 'Tailwind 4');
    has(rules, 'opacity-0', 'opacity', '0%', 'Tailwind 4');
    check(!rules.has('bg-rainbow'), 'Tailwind 4: gradients have no classes');
    return count;
}

async function tailwind3() {
    const tailwind = require('tailwindcss3');
    const preset = require(file('tailwind3.cjs'));
    const config = { presets: [preset], content: [{ raw: '' }], corePlugins: { preflight: false } };
    const result = await postcss([tailwind(config)]).process('@tailwind utilities;', { from: undefined });
    const rules = parse(result.css);
    const count = everyClass('Tailwind 3', 'tailwind3', rules);
    has(rules, 'bg-brand-orange-50', 'background-color', 'rgb(255 247 237', 'Tailwind 3');
    has(rules, 'bg-blue', 'background-color', 'rgb(13 110 253', 'Tailwind 3');
    has(rules, 'bg-blue-500', 'background-color', 'rgb(59 130 246', 'Tailwind 3 (Tailwind’s own blue-500)');
    has(rules, 'bg-blue-975', 'background-color', 'rgb(1 6 13', 'Tailwind 3');
    has(rules, 'bg-accent/50', 'background-color', 'rgb(102 51 153 / 0.5)', 'Tailwind 3 (named colour with opacity)');
    has(rules, 'text-brand-orange-975/33', 'color', '/ 0.33)', 'Tailwind 3');
    has(rules, 'opacity-33', 'opacity', '0.33', 'Tailwind 3');
    has(rules, 'bg-brand-orange/0', 'background-color', '/ 0)', 'Tailwind 3');
    has(rules, 'opacity-0', 'opacity', '0', 'Tailwind 3');
    return count;
}

function bootstrap(label, entry) {
    const sass = require('sass');
    const generatedUrl = pathToFileURL(file('bootstrap5.scss')).href;
    const warnings = [];
    const result = sass.compileString(entry.replace('GENERATED', importPath(file('bootstrap5.scss'))), {
        url: pathToFileURL(path.join(__dirname, 'bootstrap-input.scss')),
        loadPaths: [path.join(root, 'node_modules')],
        quietDeps: true,
        logger: {
            warn(message, { span }) {
                if (span?.url?.href === generatedUrl) {
                    warnings.push(`line ${span.start.line + 1}: ${message.split('\n')[0]}`);
                }
            },
        },
    });
    check(warnings.length === 0, `Bootstrap (${label}): the generated file causes Sass warnings: ${warnings.slice(0, 5).join('; ')}`);
    const rules = parse(result.css);
    const count = everyClass(`Bootstrap (${label})`, 'bootstrap5', rules);
    const name = `Bootstrap (${label})`;
    has(rules, 'bg-brand-green-100', 'background-color', 'rgb(219 224 222 / var(--bs-bg-opacity, 1)) !important', name);
    has(rules, 'bg-brand-green-100', '--bs-bg-opacity', '1', name);
    has(rules, 'bg-accent', 'background-color', 'color-mix(in srgb, rebeccapurple calc(var(--bs-bg-opacity, 1) * 100%), transparent)', name);
    has(rules, 'text-bg-brand-green', 'color', '#ffffff !important', name);
    has(rules, 'text-bg-brand-green', 'background-color', 'rgb(74 102 90 / var(--bs-bg-opacity, 1))', name);
    has(rules, 'bg-opacity-5', '--bs-bg-opacity', '0.05', name);
    has(rules, 'border-opacity-15', '--bs-border-opacity', '0.15', name);
    has(rules, 'text-opacity-15', '--bs-text-opacity', '0.15', name);
    has(rules, 'opacity-5', 'opacity', '0.05', name);
    has(rules, 'bg-opacity-0', '--bs-bg-opacity', '0', name);
    has(rules, 'opacity-0', 'opacity', '0', name);
    has(rules, 'bg-primary', 'background-color', 'var(--bs-primary-rgb)', `${name} (native theme colour)`);
    has(rules, 'bg-primary-subtle', 'background-color', 'var(--bs-primary-bg-subtle)', `${name} (native subtle class)`);
    check((rules.get('bg-primary-subtle') ?? []).length === 1, `${name}: native subtle classes are not redefined`);
    check((rules.get('bg-primary') ?? []).length === 1, `${name}: native theme colour classes are not redefined`);
    has(rules, 'bg-primary-50', 'background-color', 'rgb(231 241 255 / var(--bs-bg-opacity, 1))', name);
    const last = (className) => Math.max(...(rules.get(className) ?? [{ order: -1 }]).map((rule) => rule.order));
    check(last('bg-opacity-50') > last('bg-brand-green'), `${name}: opacity utilities follow colour utilities so they win`);
    check(!rules.has('bg-rainbow'), `${name}: gradients have no classes`);
    return count;
}

/**
 * Out of the box: a site's normal Bootstrap build, with its colours in $theme-colors and no generated file,
 * must already contain the classes the plugin outputs.
 */
function bootstrapStock() {
    const sass = require('sass');
    const result = sass.compileString([
        '@import "bootstrap/scss/functions";',
        '@import "bootstrap/scss/variables";',
        '$theme-colors: map-merge($theme-colors, ("brand-green": #4a665a, "accent": rebeccapurple));',
        '@import "bootstrap/scss/bootstrap";',
    ].join('\n'), {
        url: pathToFileURL(path.join(__dirname, 'bootstrap-stock.scss')),
        loadPaths: [path.join(root, 'node_modules')],
        quietDeps: true,
        silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls'],
        logger: { warn() {} },
    });
    const rules = parse(result.css);
    const classes = JSON.parse(fs.readFileSync(file('classes-bootstrap5-stock.json'), 'utf8'));
    // Stock Bootstrap has bg- and border-opacity-10 but no text-opacity-10 or opacity-10.
    const knownGaps = ['text-opacity-10', 'opacity-10'];
    const missing = classes.filter((name) => !rules.has(name) && !knownGaps.includes(name));
    check(classes.length > 0, 'Bootstrap (stock): the class list is empty');
    check(missing.length === 0, `Bootstrap (stock): ${missing.length} of ${classes.length} classes are not in a normal Bootstrap build: ${missing.slice(0, 20).join(', ')}`);
    check(!classes.some((name) => /-(?:\d+|subtle|emphasis)$/.test(name) && !/opacity-\d+$/.test(name)), 'Bootstrap (stock): no shade classes are output');
    check(['bg-brand-green', 'text-bg-accent', 'border-brand-green', 'bg-opacity-10', 'bg-opacity-75'].every((name) => classes.includes(name)), 'Bootstrap (stock): colour and opacity classes are output');
    has(rules, 'bg-brand-green', 'background-color', 'rgba(var(--bs-brand-green-rgb), var(--bs-bg-opacity)) !important', 'Bootstrap (stock)');
    has(rules, 'bg-opacity-10', '--bs-bg-opacity', '0.1', 'Bootstrap (stock)');
    return classes.length;
}

function pluginCss() {
    const css = fs.readFileSync(file('plugin.css'), 'utf8');
    const rules = parse(css);
    const count = everyClass('Plugin CSS', 'plugin', rules);
    has(rules, 'mcs-bg-rainbow', 'background-image', 'linear-gradient(to bottom right,rgb(255 0 0 / var(--mcs-bg-opacity, 1))', 'Plugin CSS');
    has(rules, 'mcs-bg-opacity-50', '--mcs-bg-opacity', '0.5', 'Plugin CSS');
    has(rules, 'mcs-text-bg-brand-orange-900', 'color', '#ffffff', 'Plugin CSS');
    check(css.includes('@property --mcs-bg-opacity'), 'Plugin CSS: opacity properties are registered');
    return count;
}

(async () => {
    const results = {
        'Tailwind CSS 4': await tailwind4(),
        'Tailwind CSS 3': await tailwind3(),
        // Bootstrap's documented "Option B" partial imports, with the generated file after the utilities map.
        'Bootstrap 5.3 (utilities API)': bootstrap('utilities API', [
            'functions', 'variables', 'variables-dark', 'maps', 'mixins', 'root', 'utilities', 'GENERATED',
            'reboot', 'type', 'images', 'containers', 'grid', 'helpers', 'utilities/api',
        ].map((part) => `@import "${part === 'GENERATED' ? part : `bootstrap/scss/${part}`}";`).join('\n')),
        'Bootstrap 5.3 (full import)': bootstrap('full import', '@import "GENERATED";\n@import "bootstrap/scss/bootstrap";'),
        'Bootstrap 5.3 (stock build, no generated file)': bootstrapStock(),
        'Plugin CSS': pluginCss(),
    };
    for (const [label, count] of Object.entries(results)) {
        console.log(`${label}: ${count} classes compiled`);
    }
    if (failures.length > 0) {
        console.error(`FAIL: ${failures.length} of ${checks} checks\n- ${failures.join('\n- ')}`);
        process.exit(1);
    }
    console.log(`PASS: ${checks} framework checks`);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
