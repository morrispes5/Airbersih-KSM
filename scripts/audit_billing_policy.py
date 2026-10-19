"""Read-only billing evidence. Outputs aggregates and formula addresses, never identities."""
from collections import Counter
from decimal import Decimal, InvalidOperation
from pathlib import Path
import hashlib
import json
import posixpath
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
REL = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id"


def number(value):
    try:
        return Decimal(value) if value is not None and str(value).strip() else None
    except (InvalidOperation, ValueError):
        return None


def audit():
    source = ROOT / "private/sources/excel-A.xlsx"
    result = {"source": "excel-A", "sha256": hashlib.sha256(source.read_bytes()).hexdigest(), "mode": "read_only_cached_values_and_formulas", "sheets": []}
    with zipfile.ZipFile(source) as archive:
        strings = ["".join(e.itertext()) for e in ET.fromstring(archive.read("xl/sharedStrings.xml"))]
        book = ET.fromstring(archive.read("xl/workbook.xml"))
        relations = {e.attrib["Id"]: e.attrib["Target"] for e in ET.fromstring(archive.read("xl/_rels/workbook.xml.rels"))}
        for sheet in book.find("m:sheets", NS):
            name = sheet.attrib["name"]
            if name not in {"Maret '25", "April '25", "Mei '25"}:
                continue
            target = relations[sheet.attrib[REL]]
            path = target.lstrip("/") if target.startswith("/") else posixpath.normpath("xl/" + target)
            cells, formulas = {}, {}
            for cell in ET.fromstring(archive.read(path)).findall(".//m:sheetData/m:row/m:c", NS):
                ref = cell.attrib["r"]
                value = cell.find("m:v", NS)
                text = value.text if value is not None else None
                if cell.attrib.get("t") == "s" and text is not None:
                    text = strings[int(text)]
                elif cell.attrib.get("t") == "inlineStr":
                    text = "".join(cell.find("m:is", NS).itertext())
                cells[ref] = text
                formula = cell.find("m:f", NS)
                if formula is not None:
                    formulas[ref] = formula.text
            count = 39 if name == "Mei '25" else 38
            fees, zero_fees, errors = Counter(), Counter(), Counter()
            examples = []
            for row in range(6, 6 + count):
                before, after, volume, charge, total = [number(cells.get(f"{c}{row}")) for c in ["G", "H", "I", "J", "L"]]
                raw_fee = cells.get(f"K{row}")
                label = "FREE" if str(raw_fee).strip().upper() == "FREE" else str(number(raw_fee)) if number(raw_fee) is not None else "missing_or_other"
                fees[label] += 1
                fee = Decimal(0) if label == "FREE" else number(raw_fee)
                if volume == 0:
                    zero_fees[label] += 1
                if before is not None and after is not None and volume is not None and volume != after - before:
                    errors["volume_cache_mismatch"] += 1
                if volume is not None and charge is not None and charge != volume * 10000:
                    errors["charge_not_equal_volume_times_10000"] += 1
                if charge is not None and fee is not None and total is not None and total != charge + fee:
                    errors["total_not_equal_charge_plus_admin"] += 1
                if any(v is None for v in [before, after, volume, charge, fee, total]):
                    errors["incomplete_numeric_row"] += 1
                if len(examples) < 3:
                    row_formulas = {f"{c}{row}": formulas[f"{c}{row}"] for c in ["I", "J", "L"] if f"{c}{row}" in formulas and formulas[f"{c}{row}"]}
                    if row_formulas:
                        examples.append(row_formulas)
            result["sheets"].append({"name": name, "range": f"G6:N{5+count}", "rows_examined": count, "fee_values": dict(fees), "admin_on_zero_usage": dict(zero_fees), "numeric_exceptions": dict(errors), "formula_examples": examples, "headers": {ref: cells[ref] for row in [4, 5] for col in ["G", "H", "I", "J", "K", "L", "M", "N"] if (ref := f"{col}{row}") in cells and cells[ref]}})
    result["interpretation"] = {"billing_grain": "monthly invoice inferred from month-specific sheets and invoice reference", "admin_basis": "separate component per invoice, not per cubic metre; not a new fee on payment retry", "historical_admin": "preserve numeric 5000/10000 or explicit FREE; never replace historic values with new tariff", "limits": "cached values and invoice structure are evidence, not a complete statement of current RT policy; unresolved rows remain pending review"}
    (ROOT / "docs/evidence/billing-policy-audit.json").write_text(json.dumps(result, indent=2, ensure_ascii=False), encoding="utf8")
    print(json.dumps(result, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    audit()
