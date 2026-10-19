const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

(async () => {
  const root = path.resolve(__dirname, '../../..');
  const account = await fs.readFile(path.join(root, 'private/operational-account.md'), 'utf8');
  const email = account.match(/^Email: (.+)$/m)[1].trim();
  const password = account.match(/^Password: (.+)$/m)[1].trim();
  const origin = 'http://127.0.0.1:8085';
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  const checks = [];
  const errors = [];
  page.on('pageerror', error => errors.push(error.name));
  const pass = name => { checks.push(name); console.log('PASS ' + name); };
  async function login(remember = false, uppercase = false) {
    await page.getByLabel('Email', { exact: true }).fill(uppercase ? email.toUpperCase() : email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    if (remember) await page.getByRole('checkbox', { name: 'Tetap masuk di perangkat ini' }).check();
    await page.getByRole('button', { name: /Masuk ke ruang pengurus/ }).click();
    await expect(page.getByRole('heading', { name: 'Beranda', exact: true })).toBeVisible();
  }
  try {
    await page.goto(origin + '/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('incorrect-for-this-test');
    await page.getByRole('button', { name: 'Lihat password', exact: true }).click();
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'text');
    await page.getByRole('button', { name: 'Sembunyikan password', exact: true }).click();
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'password');
    pass('Password visibility can be toggled without submitting');
    await page.getByRole('button', { name: /Masuk ke ruang pengurus/ }).click();
    await expect(page.getByRole('alert')).toContainText('Email atau password tidak sesuai.');
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    pass('Incorrect password rejected and cleared');
    await login(true, true);
    pass('Operational admin logs in with uppercase email and remember choice');
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Beranda', exact: true })).toBeVisible();
    pass('Beranda refresh preserves authenticated session');
    const persistentCookies = (await context.cookies()).filter(cookie => cookie.name.startsWith('remember_'));
    expect(persistentCookies).toHaveLength(1);
    expect(persistentCookies[0].httpOnly).toBe(true);
    expect(persistentCookies[0].expires).toBeGreaterThan(Date.now() / 1000);
    const reopened = await browser.newContext();
    await reopened.addCookies(persistentCookies);
    const reopenedPage = await reopened.newPage();
    await reopenedPage.goto(origin + '/app/dashboard');
    await expect(reopenedPage.getByRole('heading', { name: 'Beranda', exact: true })).toBeVisible({ timeout: 30000 });
    await reopened.close();
    pass('Persistent cookie restores login without the original session cookie');
    const restart = spawnSync('powershell.exe', ['-NoProfile', '-File', path.join(root, 'scripts/run-local.ps1')], { cwd: root, encoding: 'utf8', timeout: 60000, windowsHide: true });
    if (restart.status !== 0) throw new Error('Local restart failed');
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Beranda', exact: true })).toBeVisible();
    pass('Application restart preserves account and authenticated session');
    await page.getByRole('button', { name: 'Keluar', exact: true }).click();
    await expect(page.getByRole('button', { name: /Masuk ke ruang pengurus/ })).toBeVisible();
    expect((await context.cookies()).some(cookie => cookie.name.startsWith('remember_'))).toBe(false);
    pass('Logout removes persistent login cookie');
    await page.goto(origin + '/app/dashboard');
    await expect(page).toHaveURL(origin + '/login');
    pass('Logged-out browser cannot access dashboard');
    const expired = await context.request.post(origin + '/login', { form: { _token: 'expired-csrf-test', email, password }, maxRedirects: 0 });
    expect(expired.status()).toBe(302);
    await page.goto(origin + '/login');
    await expect(page.getByRole('alert')).toContainText('Halaman masuk sudah kedaluwarsa.');
    pass('Expired login CSRF returns a fresh form and does not authenticate');
    for (let cycle = 1; cycle <= 3; cycle++) {
      await login();
      pass('Fresh login cycle ' + cycle);
      if (cycle < 3) {
        await page.getByRole('button', { name: 'Keluar', exact: true }).click();
        await expect(page.getByRole('button', { name: /Masuk ke ruang pengurus/ })).toBeVisible();
      }
    }
    await page.screenshot({ path: path.join(root, 'docs/evidence/screenshots/login-recovered-dashboard.png'), fullPage: true });
    const mobileContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobile = await mobileContext.newPage();
    await mobile.goto(origin + '/login');
    expect(await mobile.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await expect(mobile.getByRole('button', { name: 'Lihat password', exact: true })).toBeVisible();
    await mobile.screenshot({ path: path.join(root, 'docs/evidence/screenshots/login-recovered-mobile.png'), fullPage: true });
    await mobileContext.close();
    pass('Mobile login controls visible without horizontal overflow');
    expect(errors).toEqual([]);
    await fs.writeFile(path.join(root, 'docs/evidence/login-recovery.json'), JSON.stringify({ checked_utc: new Date().toISOString(), target: 'operational localhost; existing admin account', result: 'passed', checks, console_errors: errors, mutations: 'Requested admin credential recovery, sessions and remember-token rotation, login/logout and local process restart only; no financial or resident record created' }, null, 2));
  } catch (error) {
    await fs.writeFile(path.join(root, 'private/audit/login-recovery-error.txt'), error.stack || error.message);
    console.error('FAIL login verification (' + error.name + '); details saved privately.');
    process.exitCode = 1;
  } finally { await browser.close(); }
})();
