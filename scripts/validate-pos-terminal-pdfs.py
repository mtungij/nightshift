"""Validate the native PDFs produced by browser-pos-terminal-smoke.mjs.

Usage: python3 scripts/validate-pos-terminal-pdfs.py [80MM_DIRECTORY 58MM_DIRECTORY]
Requires Poppler's pdfinfo and pdftotext.
"""

import json
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def output(*command):
    return subprocess.check_output(command, text=True)


results = []
directories = sys.argv[1:] or [
    "/tmp/nightshift-terminal-80mm",
    "/tmp/nightshift-terminal-58mm",
]
assert len(directories) == 2, "Provide 80mm and 58mm output directories."
for directory, width in zip(directories, (80, 58)):
    files = sorted(Path(directory).glob("*.pdf"))
    assert len(files) == 4, f"Expected desktop/mobile × EN/SW PDFs in {directory}"
    for pdf in files:
        info = output("pdfinfo", str(pdf))
        size = re.search(r"Page size:\s+([\d.]+) x ([\d.]+)", info)
        assert size and abs(float(size[1]) * 25.4 / 72 - width) < 0.5, pdf
        text = output("pdftotext", "-layout", str(pdf), "-")
        compact = re.sub(r"\s", "", text)
        swahili = "-sw-" in pdf.name
        note = "MtejakaribunaTV" if swahili else "CustomerneartheTV"
        assert compact.count(note) == 2, f"{pdf}: note on both copies"
        assert compact.count("MainCounterPOS") == 2, f"{pdf}: terminal attribution"
        labels = (
            ["NAKALAYAMHUDUMU", "NAKALAYAKESHIA"]
            if swahili
            else ["WAITERCOPY", "CASHIERCOPY"]
        )
        assert all(label in compact for label in labels), f"{pdf}: copy labels"
        waiter = "JamesMushi" if swahili else "AshaMwakalinga"
        assert waiter in compact, f"{pdf}: selected waiter"
        root = ET.fromstring(output("pdftotext", "-bbox", str(pdf), "-"))
        for page in root.findall(".//{*}page"):
            for word in page.findall(".//{*}word"):
                assert (
                    float(word.attrib["xMin"]) >= -0.5
                    and float(word.attrib["xMax"]) <= float(page.attrib["width"]) + 0.5
                ), f"{pdf}: clipped text {word.text}"
        results.append({
            "file": str(pdf),
            "pages": int(re.search(r"Pages:\s+(\d+)", info)[1]),
            "result": "PASS",
        })
print(json.dumps({"result": "PASS", "pdfs": results}, indent=2))
