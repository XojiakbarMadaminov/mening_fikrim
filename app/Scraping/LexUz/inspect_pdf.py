"""Optional bounded layout diagnostics. No admission decision, network or persistent raw files."""
import hashlib
import json
import sys

import pdfplumber

output = {"engine": "pdfplumber", "version": pdfplumber.__version__, "pages": [], "document_certified": False}
with pdfplumber.open(sys.argv[1]) as pdf:
    if not 0 < len(pdf.pages) <= 250:
        raise ValueError("PDF diagnostic page budget exceeded")
    for number, page in enumerate(pdf.pages, 1):
        trials = []
        for tolerance in (3, 8):
            tables = []
            settings = {"vertical_strategy": "lines", "horizontal_strategy": "lines", "snap_tolerance": tolerance, "join_tolerance": tolerance}
            for table in page.find_tables(settings):
                cells = table.extract()
                if sum(map(len, cells)) > 35000:
                    raise ValueError("PDF diagnostic table cell budget exceeded")
                tables.append({"bbox": list(table.bbox), "rows": len(cells), "columns": max(map(len, cells), default=0), "cells": cells})
            trials.append({"tolerance": tolerance, "tables": tables})
        signatures = [hashlib.sha256(json.dumps(t["tables"], ensure_ascii=False, sort_keys=True).encode()).hexdigest() for t in trials]
        output["pages"].append({"page": number, "image_count": len(page.images), "trials": trials,
                                "settings_disagree": signatures[0] != signatures[1], "table_relationships_verified": False})
        page.close()
json.dump(output, sys.stdout, ensure_ascii=False)
