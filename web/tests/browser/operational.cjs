const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs/promises');
const path = require('node:path');
const { createHash } = require('node:crypto');

(async () => {
  const root = path.resolve(__dirname, '../../..');
  const account = await fs.readFile(path.join(root, 'private/operational-account.md'), 'utf8');
  const withBackup = process.argv.includes('--backup');
  const state = JSON.parse(await fs.readFile(path.join(root, 'docs/evidence/operational-state.json'), 'utf8'));
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  const [y, m, d] = today.split('-').map(Number);
  const lowerDay = Math.min(d, new Date(Date.UTC(y - 1, m, 0)).getUTCDate());
  const lower = (y - 1) + '-' + String(m).padStart(2, '0') + '-' + String(lowerDay).padStart(2, '0');
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = [];
  page.on('pageerror', e => errors.push(e.name));
  try {
    await page.goto('http://127.0.0.1:8085/login');
    await page.getByLabel('Email', { exact: true }).fill(account.match(/Email: (.+)/)[1].trim());
    await page.getByLabel('Password', { exact: true }).fill(account.match(/Password: (.+)/)[1].trim());
    await page.getByRole('button', { name: /Masuk ke/ }).click();
    await page.waitForURL('**/app/dashboard');
    await expect(page.locator('main')).toContainText('Mulai dari sini');
    await expect(page.getByTestId('operational-preparation')).toBeVisible();
    const pages = [];
    let referencePath = null;
    for (const slug of ['dashboard', 'customers', 'meters', 'invoices', 'payments', 'finance', 'reports', 'imports', 'settings', 'audit']) {
      const response = await page.goto('http://127.0.0.1:8085/app/' + slug);
      expect(response.status()).toBe(200);
      const body = await page.locator('body').innerText();
      expect(/simulasi|dummy|example\.test|SIM\//i.test(body)).toBe(false);
      expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
      if (slug === 'imports') { await page.getByTestId('source-review').locator('summary').first().click(); referencePath = await page.getByRole('link', { name: /Lihat contoh tagihan lama/ }).getAttribute('href'); }
      pages.push({ page: slug, status: 200, example_labels: false, overflow: false });
      console.log('PASS operational ' + slug);
    }
    const sources = JSON.parse(await fs.readFile(path.join(root, 'docs/evidence/operational-sources.json'), 'utf8'));
    const source = sources.find(s => s.alias === 'excel-B');
    const download = await page.request.get('http://127.0.0.1:8085/documents/sources/' + source.batch);
    expect(download.status()).toBe(200);
    expect(createHash('sha256').update(await download.body()).digest('hex')).toBe(source.hash);
    expect(download.headers()['cache-control']).toContain('no-store');
    const reference = await page.request.get(referencePath);
    expect(reference.status()).toBe(200);
    expect(reference.headers()['content-type']).toContain('image/png');
    expect(createHash('sha256').update(await reference.body()).digest('hex')).toBe(createHash('sha256').update(await fs.readFile(path.join(root, 'private/sources/invoice-reference-2025-05.png'))).digest('hex'));
    const exports = [];
    for (const format of ['csv', 'pdf']) {
      const response = await page.request.get('http://127.0.0.1:8085/documents/report?period=' + today.slice(0, 7) + '&format=' + format);
      expect(response.status()).toBe(200);
      expect(response.headers()['content-type']).toContain(format === 'pdf' ? 'application/pdf' : 'text/csv');
      if (format === 'pdf') await fs.writeFile(path.join(root, 'private/audit/operational-report-live.pdf'), await response.body());
      exports.push({ format, status: 200 });
    }
    const guest = await browser.newContext();
    try {
      for (const url of ['http://127.0.0.1:8085/documents/sources/' + source.batch, referencePath, 'http://127.0.0.1:8085/documents/report?period=' + today.slice(0, 7)]) {
        const denied = await guest.request.get(url, { maxRedirects: 0 });
        expect(denied.status()).toBe(302);
      }
    } finally { await guest.close(); }
    await page.goto('http://127.0.0.1:8085/app/dashboard');
    if (state.unconfirmed_opening_accounts > 0) await expect(page.locator('main')).toContainText('Belum dikonfirmasi');
    await page.getByTestId('operational-preparation').screenshot({ path: path.join(root, 'docs/evidence/screenshots/operational-preparation.png') });
    // Keep public evidence free of names and resident records when these scripts are reused.
    await page.getByTestId('operational-preparation').screenshot({ path: path.join(root, 'docs/evidence/screenshots/operational-dashboard.png') });
    await page.setViewportSize({ width: 360, height: 800 });
    for (const { page: slug } of pages) {
      await page.goto('http://127.0.0.1:8085/app/' + slug);
      expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    }
    await page.goto('http://127.0.0.1:8085/app/dashboard');
    await page.getByRole('button', { name: 'Buka atau tutup navigasi' }).click();
    await expect(page.getByRole('navigation', { name: 'Navigasi utama' })).toBeVisible();
    await page.getByRole('button', { name: 'Tutup navigasi', exact: true }).click();
    await expect(page.getByRole('navigation', { name: 'Navigasi utama' })).not.toBeVisible();
    await page.getByRole('button', { name: 'Buka atau tutup navigasi' }).click();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('navigation', { name: 'Navigasi utama' })).not.toBeVisible();
    await page.getByTestId('operational-preparation').screenshot({ path: path.join(root, 'docs/evidence/screenshots/operational-mobile.png') });
    let backup = 'not requested';
    if (withBackup) {
      await page.setViewportSize({ width: 1440, height: 1000 });
      await page.goto('http://127.0.0.1:8085/app/settings');
      const before = Number(await page.getByTestId('recovery-status').getAttribute('data-copy-id'));
      const button = page.getByRole('button', { name: 'Buat salinan cadangan', exact: true });
      await expect(button).toBeEnabled();
      page.once('dialog', d => d.accept());
      await button.click();
      await expect(page.getByRole('status').filter({ hasText: 'Backup masuk antrean' })).toBeVisible();
      await expect(async () => {
        await page.reload();
        expect(Number(await page.getByTestId('recovery-status').getAttribute('data-copy-id'))).toBeGreaterThan(before);
        await expect(page.getByRole('button', { name: 'Buat salinan cadangan', exact: true })).toBeEnabled();
        await expect(page.getByTestId('recovery-status')).not.toContainText('Salinan terakhir belum berhasil dibuat');
      }).toPass({ timeout: 240000, intervals: [3000, 5000, 10000] });
      await page.getByTestId('recovery-status').screenshot({ path: path.join(root, 'docs/evidence/screenshots/operational-recovery-status.png') });
      backup = 'UI queue completed; new independent encrypted Aiven copy visible';
      console.log('PASS operational backup UI completed');
    }
    expect(errors).toEqual([]);
    await fs.writeFile(path.join(root, 'docs/evidence/operational-ui.json'), JSON.stringify({ checked_utc: new Date().toISOString(), checked_wib_date: today, history_window: [lower, today], pages, mobile_pages_checked: pages.length, mobile_overflow: false, mobile_navigation: 'passed', console_errors: errors, authenticated_source_download: 'original hash identical', authenticated_reference_image: 'original hash identical', guest_document_access: 'source, image and report redirected to login', exports, backup, mutations: 'login and requested encrypted recovery backup only; no operational business transaction created' }, null, 2));
  } catch (e) {
    await page.screenshot({ path: path.join(root, 'private/audit/operational-browser-failure.png'), fullPage: true }).catch(() => {});
    await fs.writeFile(path.join(root, 'private/audit/operational-browser-error.txt'), e.stack || e.message).catch(() => {});
    console.error('FAIL operational UI (' + e.name + '); detailed diagnostic saved privately.');
    process.exitCode = 1;
  } finally { await browser.close(); }
})();
