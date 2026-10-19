"""Read-only OOXML audit. Never emits customer cell contents or modifies workbooks."""
from pathlib import Path
from collections import Counter
import hashlib
import json
import posixpath
import re
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
REL = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
months = r"Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember|January|February|March|May|June|July|August|October|December"
period_re = re.compile(r"\b(?:" + months + r")\s*[-/]?\s*20\d{2}\b", re.I)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def audit(path):
    with zipfile.ZipFile(path) as z:
        strings = []
        if "xl/sharedStrings.xml" in z.namelist():
            strings = ["".join(e.itertext()) for e in ET.fromstring(z.read("xl/sharedStrings.xml"))]
        wb = ET.fromstring(z.read("xl/workbook.xml"))
        rels = {e.attrib["Id"]: e.attrib["Target"] for e in ET.fromstring(z.read("xl/_rels/workbook.xml.rels"))}
        result = {"alias": path.stem, "bytes": path.stat().st_size, "sha256": digest(path.read_bytes()), "sheets": []}
        for sheet in wb.find("m:sheets", NS):
            target = rels[sheet.attrib["{" + REL + "}id"]]
            target = target.lstrip("/") if target.startswith("/") else posixpath.normpath("xl/" + target)
            raw = z.read(target)
            tree = ET.fromstring(raw)
            cells = tree.findall(".//m:sheetData/m:row/m:c", NS)
            formulas, errors, periods = [], [], set()
            populated = 0
            for cell in cells:
                val = cell.find("m:v", NS)
                form = cell.find("m:f", NS)
                text = val.text if val is not None and val.text else ""
                if cell.attrib.get("t") == "s" and text:
                    text = strings[int(text)]
                elif cell.attrib.get("t") == "inlineStr":
                    text = "".join(cell.find("m:is", NS).itertext())
                if text or form is not None:
                    populated += 1
                if form is not None:
                    formulas.append({"cell": cell.attrib["r"], "formula": form.text, "cached": val.text if val is not None else None})
                if cell.attrib.get("t") == "e":
                    errors.append({"cell": cell.attrib["r"], "error": text})
                periods.update(period_re.findall(text))
            dimension = tree.find("m:dimension", NS)
            result["sheets"].append({"name": sheet.attrib["name"], "state": sheet.attrib.get("state", "visible"), "path": target,
                "dimension": dimension.attrib.get("ref") if dimension is not None else None,
                "populated_cells": populated, "formula_count": len(formulas), "cached_errors": errors,
                "period_labels": sorted(periods), "drawing_refs": [e.attrib for e in tree.findall("m:drawing", NS)],
                "xml_sha256": digest(raw)})
        media = [i for i in z.infolist() if i.filename.startswith("xl/media/") and not i.is_dir()]
        hashes = Counter(digest(z.read(i.filename)) for i in media)
        result["media"] = {"count": len(media), "bytes": sum(i.file_size for i in media), "unique_sha256_count": len(hashes),
            "extensions": dict(Counter(Path(i.filename).suffix for i in media)),
            "largest": [{"path": i.filename, "bytes": i.file_size} for i in sorted(media, key=lambda i: i.file_size, reverse=True)[:5]]}
        result["external_link_parts"] = [n for n in z.namelist() if n.startswith("xl/externalLinks/")]
        result["macros"] = any(n.endswith("vbaProject.bin") for n in z.namelist())
        return result

results = [audit(p) for p in sorted((ROOT / "private/sources").glob("*.xlsx"))]
destination = ROOT / "docs/evidence/source-audit.json"
destination.parent.mkdir(parents=True, exist_ok=True)
destination.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
for r in results:
    print(json.dumps(r, ensure_ascii=True))
