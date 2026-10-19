'use strict';
const initial = document.getElementById('awal');
const latest = document.getElementById('akhir');
const volume = document.getElementById('pemakaian');
const total = document.getElementById('total');
const detail = document.getElementById('rincian');
const error = document.getElementById('kesalahan');
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
function calculate() {
  const a = initial.valueAsNumber;
  const b = latest.valueAsNumber;
  const valid = initial.value !== '' && latest.value !== '' && initial.validity.valid && latest.validity.valid && Number.isFinite(a) && Number.isFinite(b) && b >= a;
  error.hidden = valid;
  if (!valid) {
    volume.textContent = '—';
    total.textContent = '—';
    detail.textContent = '';
    error.textContent = 'Isi angka meter yang valid. Angka terbaru harus sama atau lebih besar dari angka sebelumnya.';
    return;
  }
  const usage = Math.round((b - a) * 1000) / 1000;
  const cost = Math.round(usage * 10000);
  volume.textContent = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(usage) + ' m³';
  total.textContent = rupiah.format(cost + 5000);
  detail.textContent = rupiah.format(cost) + ' pemakaian + Rp5.000 administrasi';
}
initial.addEventListener('input', calculate);
latest.addEventListener('input', calculate);
calculate();
