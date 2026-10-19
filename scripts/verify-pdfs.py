from pathlib import Path
import json, datetime
from pypdf import PdfReader
root = Path(__file__).resolve().parents[1]
checks = []
cases = [
 ('operational-invoice-test.pdf', ['Lima ratus ribu Rupiah', 'FREE / Rp0', 'Rp500.000', '2026-10-01', '2026-10-16']),
 ('operational-invoice-browser.pdf', ['Tiga ratus lima ribu Rupiah', 'Rp305.000', 'Rp5.000']),
 ('operational-report-live.pdf', ['Laporan kas', 'Saldo awal', 'Saldo akhir', 'Piutang'])
]
for filename, expected in cases:
 reader = PdfReader(root/'private/audit'/filename)
 text = ' '.join(' '.join(page.extract_text() or '' for page in reader.pages).split())
 assert len(reader.pages) == 1, f'{filename}: expected one A4 page'
 page = reader.pages[0]
 assert abs(float(page.mediabox.width)-595.28)<1 and abs(float(page.mediabox.height)-841.89)<1, 'Paper must be A4'
 assert not any(term in text.lower() for term in ['simulasi','dummy','sim/']), 'Operational document label mismatch'
 for value in expected:
  assert value in text, f'{filename}: expected field missing'
 checks.append({'document_kind': 'cash_report' if 'report' in filename else 'invoice', 'fixture_case': filename.removesuffix('.pdf').removeprefix('operational-'), 'a4_one_page': True, 'required_fields': True, 'no_simulation_label': True, 'terbilang_matches_total': 'invoice' in filename})
result = {'checked_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'checks': checks, 'private_pdfs_only': True, 'result':'passed'}
(root/'docs/evidence/operational-pdf.json').write_text(json.dumps(result,indent=2),encoding='utf8')
print(json.dumps(result,indent=2))
