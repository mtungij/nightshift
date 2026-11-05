"""Validate real Chrome thermal-ticket PDFs with Poppler (pdfinfo/pdftotext)."""
import json
import re
import subprocess
import sys
from pathlib import Path
from xml.etree import ElementTree as ET

folder = Path(sys.argv[1])
paper_mm = int(sys.argv[2])
assert paper_mm in (58, 80)
results = []
long_name = 'Serengeti Lite - Long narrow-printer product name ' + 'ExtraLongProductName' * 4
for pdf in sorted(folder.glob('*.pdf')):
    info = subprocess.check_output(['pdfinfo', str(pdf)], text=True)
    pages = int(re.search(r'Pages:\s+(\d+)', info).group(1))
    width = float(re.search(r'Page size:\s+([\d.]+)', info).group(1))
    assert pages == 2, (pdf.name, pages)
    assert abs(width * 25.4 / 72 - paper_mm) < .2, (pdf.name, width)
    text = subprocess.check_output(['pdftotext', '-raw', str(pdf), '-'], text=True)
    contents = [part for part in text.split('\f') if part.strip()]
    assert len(contents) == 2, pdf.name
    sw = '-sw' in pdf.name
    waiter = 'NAKALA YA MHUDUMU' if sw else 'WAITER COPY'
    cashier = 'NAKALA YA KESHIA' if sw else 'CASHIER COPY'
    assert waiter in contents[0] and cashier not in contents[0], pdf.name
    assert cashier in contents[1] and waiter not in contents[1], pdf.name
    assert ('HAIJALIPWA' if sw else 'UNPAID') in contents[0], pdf.name
    assert 'outstanding' not in contents[1] and 'Salio Linalodaiwa' not in contents[1], pdf.name
    assert 'Reprint sales tickets' not in text and 'Chapisha tena tiketi za mauzo' not in text, pdf.name
    for content in contents:
        assert ''.join(long_name.split()) in ''.join(content.split()), (pdf.name, 'product name incomplete')
        assert ('JUMLA' if sw else 'TOTAL') in content, pdf.name
    if pdf.name.startswith('reprint') or pdf.name.startswith('stress'):
        assert all(('IMECHAPISHWA TENA' if sw else 'REPRINT') in page for page in contents), pdf.name
    if pdf.name.startswith('stress'):
        assert '9,999,999,999.99' in contents[0], pdf.name
        assert all(q in text for q in ['4 × Piece', '1.5 × Piece', '0.75 × Piece']), pdf.name
    bbox = ET.fromstring(subprocess.check_output(['pdftotext', '-bbox', str(pdf), '-']))
    words = 0
    for page in bbox.findall('.//{*}page'):
        w, h = float(page.attrib['width']), float(page.attrib['height'])
        inset = 5 * 72 / 25.4 if paper_mm == 58 else 4 * 72 / 25.4
        for word in page.findall('.//{*}word'):
            words += 1
            bounds = {k: float(v) for k, v in word.attrib.items()}
            assert bounds['xMin'] >= inset - 1 and bounds['xMax'] <= w - inset + 1 and bounds['yMin'] >= 0 and bounds['yMax'] <= h + .1, (pdf.name, word.text, bounds)
    results.append({'file': pdf.name, 'pages': pages, 'width_mm': round(width * 25.4 / 72, 2), 'words_inside_printable_area': words, 'result': 'PASS'})
assert len(results) == (11 if paper_mm == 58 else 9), len(results)
report = {'result': 'PASS', 'paper_width_mm': paper_mm, 'pdfs': results, 'total_pages': sum(p['pages'] for p in results)}
(folder / 'print-validation.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report, indent=2))
