const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const script = path.resolve(__dirname, '../../src/assetbundles/dist/swatches.js');
const fixture = path.join(__dirname, 'fixture.html');

test.beforeEach(async ({ page }) => {
    await page.setContent(fs.readFileSync(fixture, 'utf8'));
    await page.addScriptTag({ path: require.resolve('jquery') });
    await page.addScriptTag({ path: script });
    await page.evaluate(() => {
        window.swatchEvents = [];
        jQuery(document).on('input change', 'input[type="hidden"]', function (event) {
            window.swatchEvents.push({ type: event.type, name: this.name });
        });
    });
});

test('selects independently and emits Craft dirty-state events', async ({ page }) => {
    const first = page.locator('#site-default');
    const second = page.locator('#site-omeo');
    await first.getByRole('button', { name: 'Primary', exact: true }).click();
    await second.getByRole('button', { name: 'Primary', exact: true }).click();
    const a = JSON.parse(await first.locator('input').inputValue());
    const b = JSON.parse(await second.locator('input').inputValue());
    expect(a.color[0].color).toBe('#ef730b');
    expect(b.color[0].color).toBe('#4a665a');
    await second.getByRole('button', { name: 'Orange', exact: true }).click();
    await expect(second.locator('[aria-pressed="true"]')).toHaveCount(1);
    expect(JSON.parse(await first.locator('input').inputValue())).toEqual(a);
    expect(await page.evaluate(() => window.swatchEvents.map(event => event.type)))
        .toEqual(['input', 'change', 'input', 'change', 'input', 'change']);
});

test('supports toggle, clear, keyboard selection and unavailable warnings', async ({ page }) => {
    const field = page.locator('#site-omeo');
    await expect(field.locator('[data-unavailable]')).toHaveCount(1);
    const option = field.getByRole('button', { name: 'Orange', exact: true });
    await option.focus();
    await page.keyboard.press('Space');
    await expect(option).toHaveAttribute('aria-pressed', 'true');
    await expect(field.locator('[data-unavailable]')).toHaveCount(0);
    await option.click();
    await expect(field.locator('input')).toHaveValue('');
    await option.click();
    await field.getByRole('button', { name: 'Clear selection' }).click();
    await expect(field.locator('input')).toHaveValue('');
    await expect(field.locator('[aria-pressed="true"]')).toHaveCount(0);
});

test('works after Hyper inserts and replaces markup without initialization', async ({ page }) => {
    await page.evaluate(() => {
        const clone = document.querySelector('#site-omeo').cloneNode(true);
        clone.id = 'hyper-new-link';
        clone.querySelector('input').name = 'fields[links][new1][fields][colour]';
        document.body.append(clone);
    });
    const field = page.locator('#hyper-new-link');
    await field.getByRole('button', { name: 'Orange', exact: true }).click();
    expect(JSON.parse(await field.locator('input').inputValue()).handle).toBe('orange');
    await field.evaluate(element => { element.outerHTML = element.outerHTML; });
    await field.getByRole('button', { name: 'Primary', exact: true }).click();
    expect(JSON.parse(await field.locator('input').inputValue()).color[0].color).toBe('#4a665a');
    await expect(page.locator('#site-omeo input')).not.toHaveValue(await field.locator('input').inputValue());
});

test('loading assets twice does not double-bind or toggle a selection off', async ({ page }) => {
    await page.addScriptTag({ path: script });
    const field = page.locator('#site-default');
    await field.getByRole('button', { name: 'Primary', exact: true }).click();
    await expect(field.locator('[data-swatch]')).toHaveAttribute('aria-pressed', 'true');
    expect(await page.evaluate(() => window.swatchEvents.length)).toBe(2);
    await field.locator('[data-shade]').selectOption('200');
    expect(await page.evaluate(() => window.swatchEvents.length)).toBe(4);
});

test('chooses a shade and opacity for the selected colour', async ({ page }) => {
    const field = page.locator('#site-default');
    const shade = field.locator('[data-shade]');
    const opacity = field.locator('[data-opacity]');
    const preview = field.locator('[data-preview] > span');
    await expect(shade).toBeDisabled();
    await expect(opacity).toBeDisabled();
    await field.getByRole('button', { name: 'Primary', exact: true }).click();
    await expect(shade).toBeEnabled();
    await expect(opacity).toBeEnabled();
    await shade.selectOption('100');
    await opacity.selectOption('50');
    expect(JSON.parse(await field.locator('input').inputValue())).toMatchObject({ handle: 'primary', shade: '100', opacity: 50 });
    await expect(preview).toHaveCSS('background-color', 'rgb(252, 227, 206)');
    await expect(preview).toHaveCSS('opacity', '0.5');
    expect(await page.evaluate(() => window.swatchEvents.map(event => event.type)))
        .toEqual(['input', 'change', 'input', 'change', 'input', 'change']);
    await shade.selectOption('');
    expect(JSON.parse(await field.locator('input').inputValue())).toMatchObject({ shade: null, opacity: 50 });
    await expect(preview).toHaveCSS('background-color', 'rgb(239, 115, 11)');
    await field.getByRole('button', { name: 'Clear selection' }).click();
    await expect(field.locator('input')).toHaveValue('');
    await expect(shade).toBeDisabled();
    await expect(opacity).toBeDisabled();
    await expect(opacity).toHaveValue('');
    await expect(preview).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
});

test('replaces unavailable saved modifiers', async ({ page }) => {
    const field = page.locator('#site-limited');
    const shade = field.locator('[data-shade]');
    const opacity = field.locator('[data-opacity]');
    await expect(field.locator('[data-unavailable-modifier]')).toHaveCount(2);
    await expect(shade).toHaveValue('950');
    await expect(opacity).toHaveValue('33');
    await field.getByRole('button', { name: 'Orange', exact: true }).click();
    await expect(shade).toHaveValue('');
    await expect(shade.locator('option[value="950"]')).toHaveCount(0);
    await expect(field.locator('[data-unavailable-modifier="shade"]')).toHaveCount(0);
    await expect(field.locator('[data-unavailable-modifier="opacity"]')).toHaveCount(1);
    await opacity.selectOption('50');
    await expect(opacity.locator('option[value="33"]')).toHaveCount(0);
    await expect(field.locator('[data-unavailable-modifier]')).toHaveCount(0);
    expect(JSON.parse(await field.locator('input').inputValue())).toMatchObject({ handle: 'orange', shade: null, opacity: 50 });
});

test('zero opacity is saved as transparent, not as a blank selection', async ({ page }) => {
    const field = page.locator('#site-default');
    await field.getByRole('button', { name: 'Primary', exact: true }).click();
    await field.locator('[data-opacity]').selectOption('0');
    expect(JSON.parse(await field.locator('input').inputValue())).toMatchObject({ handle: 'primary', opacity: 0 });
    await expect(field.locator('[data-preview] > span')).toHaveCSS('opacity', '0');
    await field.locator('[data-shade]').selectOption('200');
    expect(JSON.parse(await field.locator('input').inputValue())).toMatchObject({ shade: '200', opacity: 0 });
    await field.locator('[data-opacity]').selectOption('');
    expect(JSON.parse(await field.locator('input').inputValue()).opacity).toBeNull();
    await expect(field.locator('[data-preview] > span')).toHaveCSS('opacity', '1');
});

test('disables shades for gradients but keeps opacity', async ({ page }) => {
    const field = page.locator('#site-gradient');
    const shade = field.locator('[data-shade]');
    await field.getByRole('button', { name: 'Navy', exact: true }).click();
    await shade.selectOption('900');
    await field.getByRole('button', { name: 'Rainbow', exact: true }).click();
    await expect(shade).toHaveValue('');
    await expect(shade).toBeEnabled();
    expect(await shade.locator('option:not([value=""])').evaluateAll(options => options.every(option => option.disabled))).toBe(true);
    await field.locator('[data-opacity]').selectOption('50');
    expect(JSON.parse(await field.locator('input').inputValue())).toMatchObject({ handle: 'rainbow', shade: null, opacity: 50 });
    await expect(field.locator('[data-preview] > span')).toHaveCSS('background-image', /linear-gradient/);
    await field.getByRole('button', { name: 'Navy', exact: true }).click();
    await expect(shade.locator('option[value="900"]')).toBeEnabled();
});

test('modifiers work in Hyper clones without changing the original field', async ({ page }) => {
    await page.evaluate(() => {
        const clone = document.querySelector('#site-default').cloneNode(true);
        clone.id = 'hyper-clone';
        clone.querySelector('input').name = 'fields[links][new1][fields][colour]';
        document.body.append(clone);
    });
    const field = page.locator('#hyper-clone');
    await field.getByRole('button', { name: 'Primary', exact: true }).click();
    await field.locator('[data-shade]').selectOption('200');
    expect(JSON.parse(await field.locator('input').inputValue()).shade).toBe('200');
    await expect(page.locator('#site-default input')).toHaveValue('');
    await expect(page.locator('#site-default [data-shade]')).toBeDisabled();
});

test('disabled revision/static fields cannot change', async ({ page }) => {
    const field = page.locator('#site-default');
    await field.locator('button').evaluateAll(buttons => buttons.forEach(button => { button.disabled = true; }));
    await field.getByRole('button', { name: 'Primary', exact: true }).dispatchEvent('click');
    await expect(field.locator('input')).toHaveValue('');
    expect(await page.evaluate(() => window.swatchEvents.length)).toBe(0);
});
