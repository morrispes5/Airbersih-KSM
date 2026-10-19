const { chromium, expect: baseExpect } = require('@playwright/test');
const expect = baseExpect.configure({ timeout: 25000 });
const fs = require('node:fs/promises');
const path = require('node:path');

(async () => {
  const root = path.resolve(__dirname, '../../..');
  const base = 'http://127.0.0.1:8086';
  const fixture = JSON.parse(await fs.readFile(path.join(root, 'private/runtime/operational-e2e-account.json'), 'utf8'));
  const photo = path.join(root, 'private/audit/dummy-meter.png');
  const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const checks = [];
  const errors = [];
  page.on('pageerror', e => errors.push(e.name));
  const check = async (name, fn) => { await fn(); checks.push({ name, result: 'passed' }); console.log('PASS operational flow: ' + name); };
  const go = async route => { expect((await page.goto(base + route)).status()).toBe(200); };
  const formWith = name => page.locator('form').filter({ has: page.getByRole('button', { name, exact: true }) });
  const attach = async input => { await input.setInputFiles(photo); await expect(page.getByTestId('photo-ready')).toBeVisible(); };
  let connectionId, invoiceId, readingPhoto;
  try {
    await check('Masuk mode operasional pada database uji', async () => {
      await go('/login');
      await page.getByLabel('Email', { exact: true }).fill('acceptance-admin@example.test');
      await page.getByLabel('Password', { exact: true }).fill(fixture.password);
      await page.getByRole('button', { name: /Masuk ke/ }).click();
      await page.waitForURL('**/app/dashboard');
      await expect(page.getByTestId('operational-preparation')).toContainText('0 dari 2 tempat penyimpanan uang');
      await expect(page.locator('main')).toContainText('Belum dikonfirmasi');
    });
    await check('Pelanggan dan sambungan dengan baseline kosong', async () => {
      await go('/app/customers');
      const customer = formWith('Simpan pelanggan');
      await customer.getByLabel('Nama pelanggan').fill('Nama Uji Penerimaan');
      await customer.getByLabel('Alamat pelanggan').fill('Alamat uji penerimaan terisolasi');
      await customer.getByRole('button', { name: 'Simpan pelanggan', exact: true }).click();
      await expect(customer.getByLabel('Nama pelanggan')).toHaveValue('');
      const form = formWith('Simpan sambungan');
      await form.getByLabel('Pelanggan', { exact: true }).selectOption({ label: (await form.getByLabel('Pelanggan', { exact: true }).locator('option').filter({ hasText: 'Nama Uji Penerimaan' }).innerText()) });
      await form.getByLabel('Alamat sambungan').fill('Alamat uji penerimaan terisolasi');
      await form.getByLabel('Petugas', { exact: true }).selectOption({ label: 'Acceptance officer' });
      await form.getByLabel('Nomor meter yang terpasang').fill('ACCEPTANCE-METER');
      await expect(form.getByLabel('Angka awal meter')).toHaveValue('');
      await form.getByRole('button', { name: 'Simpan sambungan', exact: true }).click();
      await expect(form.getByLabel('Nomor meter yang terpasang')).toHaveValue('');
      await go('/app/meters');
      const select = formWith('Simpan catatan meter').getByLabel('Rumah / sambungan air');
      connectionId = await select.locator('option').filter({ hasText: 'Nama Uji Penerimaan' }).getAttribute('value');
    });
    await check('Bacaan tanpa baseline ditahan', async () => {
      const form = formWith('Simpan catatan meter');
      await form.getByLabel('Rumah / sambungan air').selectOption(connectionId);
      await form.getByLabel('Angka meter saat ini').fill('30');
      await form.getByLabel('Tanggal pemeriksaan', { exact: true }).fill(fixture.date);
      await attach(form.getByLabel('Foto meter', { exact: true }));
      await form.getByRole('button', { name: 'Simpan catatan meter', exact: true }).click();
      await expect(page.getByRole('alert')).toContainText('Angka awal meter belum dikonfirmasi');
    });
    await check('Saldo awal nol dikonfirmasi pada kedua akun', async () => {
      await go('/app/finance');
      const form = formWith('Simpan transaksi');
      for (const label of ['Kas Uji Penerimaan', 'Bank Uji Penerimaan']) {
        await form.getByLabel('Jenis', { exact: true }).selectOption('opening');
        await form.getByLabel('Kas / rekening', { exact: true }).selectOption({ label });
        await form.getByLabel('Nominal rupiah').fill('0');
        await form.getByLabel('Tanggal', { exact: true }).fill(fixture.date);
        await form.getByLabel('Keterangan').fill('Saldo nol untuk pengujian terisolasi');
        await form.getByRole('button', { name: 'Simpan transaksi', exact: true }).click();
        await expect(form.getByLabel('Kas / rekening', { exact: true })).toHaveValue('');
      }
      await go('/app/dashboard');
      await expect(page.getByTestId('operational-preparation')).toContainText('2 dari 2 tempat penyimpanan uang');
    });
    await check('Laporan sebelum saldo awal tidak mengarang nol', async () => {
      await go('/app/reports');
      const [year, month] = fixture.period.split('-').map(Number);
      const previousPeriod = new Date(Date.UTC(year, month - 2, 1)).toISOString().slice(0, 7);
      await page.getByLabel('Bulan yang ingin dilihat').fill(previousPeriod);
      await expect(page.locator('.cash-grid strong')).toHaveText(['Belum dikonfirmasi', 'Belum dikonfirmasi']);
    });
    await check('Baseline nol bertanggal dengan foto cloud', async () => {
      await go('/app/meters');
      const form = formWith('Simpan angka meter pertama');
      await form.getByLabel('Rumah / sambungan air').selectOption(connectionId);
      await form.getByLabel('Angka yang terlihat pada meter').fill('0');
      await form.getByLabel('Tanggal melihat meter').fill(fixture.date);
      await form.getByLabel('Catatan pemeriksaan').fill('Pemeriksaan baseline fixture pengujian terisolasi');
      await attach(form.getByLabel('Foto angka meter pertama'));
      await form.getByRole('button', { name: 'Simpan angka meter pertama', exact: true }).click();
      await expect(page.getByRole('status').filter({ hasText: 'Angka meter pertama sudah disimpan' })).toBeVisible();
    });
    await check('Pembacaan 30 m3 dan review', async () => {
      const form = formWith('Simpan catatan meter');
      await form.getByLabel('Rumah / sambungan air').selectOption(connectionId);
      await form.getByLabel('Tanggal pemeriksaan', { exact: true }).fill(fixture.date);
      await form.getByLabel('Angka meter saat ini').fill('30');
      await attach(form.getByLabel('Foto meter', { exact: true }));
      await form.getByRole('button', { name: 'Simpan catatan meter', exact: true }).click();
      await expect(form.getByLabel('Angka meter saat ini')).toHaveValue('');
      page.once('dialog', d => d.accept());
      await page.getByRole('button', { name: 'Angka sudah benar', exact: true }).click();
      await expect(page.getByRole('status').filter({ hasText: 'Catatan meter sudah diperiksa' })).toBeVisible();
      readingPhoto = await page.getByRole('link', { name: /Lihat foto/ }).getAttribute('href');
    });
    await check('Jatuh tempo eksplisit, admin sekali dan nomor BB', async () => {
      await go('/app/invoices');
      await page.getByLabel('Rumah / sambungan air').selectOption(connectionId);
      await page.getByRole('button', { name: 'Buat rancangan tagihan', exact: true }).click();
      await expect(page.getByRole('alert')).toBeVisible();
      await page.getByLabel('Batas tanggal pembayaran').fill(fixture.due_date);
      await page.getByRole('button', { name: 'Buat rancangan tagihan', exact: true }).click();
      const row = page.locator('tr').filter({ hasText: 'Nama Uji Penerimaan' });
      await expect(row).toContainText('Rp305.000');
      const href = await row.getByRole('link', { name: 'Lihat tagihan' }).getAttribute('href');
      invoiceId = href.match(/invoices\/(\d+)/)[1];
      page.once('dialog', d => d.accept());
      await row.getByRole('button', { name: 'Terbitkan tagihan', exact: true }).click();
      await expect(row).toContainText('BB/');
    });
    await check('Unduhan PDF operasional terotorisasi', async () => {
      const response = await page.request.get(base + '/documents/invoices/' + invoiceId);
      expect(response.status()).toBe(200);
      expect((await response.body()).subarray(0, 5).toString()).toBe('%PDF-');
      await fs.writeFile(path.join(root, 'private/audit/operational-invoice-browser.pdf'), await response.body());
    });
    await check('Pelunasan kurang ditolak dan penuh dibukukan', async () => {
      await go('/app/payments');
      await page.getByLabel('Tagihan yang belum dibayar').selectOption(invoiceId);
      await expect(page.getByLabel('Nominal rupiah')).toHaveValue('305000');
      await page.getByLabel('Uang diterima di').selectOption({ label: 'Kas Uji Penerimaan (Uang tunai)' });
      await page.getByLabel('Nominal rupiah').fill('5000');
      await page.getByRole('button', { name: 'Catat pembayaran', exact: true }).click();
      await expect(page.getByRole('alert')).toContainText('pelunasan penuh');
      await page.getByLabel('Nominal rupiah').fill('305000');
      await page.getByRole('button', { name: 'Catat pembayaran', exact: true }).click();
      await expect(page.getByLabel('Tagihan yang belum dibayar')).toHaveValue('');
      await expect(page.locator('table')).toContainText('Sudah diterima');
    });
    await check('Kas, pendapatan dan piutang direkonsiliasi', async () => {
      await go('/app/reports');
      await expect(page.locator('main')).toContainText('Rp305.000');
      await expect(page.locator('main')).toContainText('Tagihan yang belum dibayar Rp0');
      for (const format of ['csv', 'pdf']) expect((await page.request.get(base + '/documents/report?period=' + fixture.period + '&format=' + format)).status()).toBe(200);
    });
    await check('Akses petugas sesuai penugasan dan halaman keuangan ditolak', async () => {
      await page.getByRole('button', { name: 'Keluar', exact: true }).click();
      await page.getByLabel('Email', { exact: true }).fill('acceptance-officer@example.test');
      await page.getByLabel('Password', { exact: true }).fill(fixture.password);
      await page.getByRole('button', { name: /Masuk ke/ }).click();
      await page.waitForURL('**/app/dashboard');
      expect((await page.request.get(readingPhoto)).status()).toBe(200);
      expect((await page.goto(base + '/app/finance')).status()).toBe(403);
      expect((await page.request.get(base + '/documents/invoices/' + invoiceId)).status()).toBe(403);
    });
    expect(errors).toEqual([]);
    await fs.writeFile(path.join(root, 'docs/evidence/operational-flow.json'), JSON.stringify({ checked_utc: new Date().toISOString(), base, database: 'airbersih_test', air_mode: 'operational', cloud_bucket_kind: 'isolated test', synthetic_fixture_only: true, checks, console_errors: errors, result: 'passed' }, null, 2));
  } catch (e) {
    await page.screenshot({ path: path.join(root, 'private/audit/operational-flow-failure.png'), fullPage: true }).catch(() => {});
    await fs.writeFile(path.join(root, 'private/audit/operational-flow-error.txt'), e.stack || e.message).catch(() => {});
    console.error('FAIL operational browser flow (' + e.name + '); diagnostic saved privately.');
    process.exitCode = 1;
  } finally { await browser.close(); }
})();
