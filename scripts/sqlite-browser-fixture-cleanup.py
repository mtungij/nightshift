"""Preview a scoped rollback of the two unintended SQLite browser fixtures.

Requires explicit user approval before --apply. The default edits a backup copy.
The SHA-256 guard refuses to modify a source changed since the plan was prepared.
"""
import hashlib
import json
import sqlite3
import sys
from pathlib import Path

plan = json.loads(Path('/tmp/nightshift-sqlite-browser-cleanup-plan.json').read_text())
source = Path(plan['source'])
apply = '--apply' in sys.argv
if hashlib.sha256(source.read_bytes()).hexdigest() != plan['expected_sha256']:
    raise SystemExit('Source changed since preparation; review a new cleanup plan.')
target = source if apply else Path(plan['preview_copy'])
if not apply:
    original = sqlite3.connect(f'file:{source}?mode=ro', uri=True)
    preview = sqlite3.connect(target)
    original.backup(preview)
    preview.close()
    original.close()
c = sqlite3.connect(target)
c.execute('PRAGMA foreign_keys=ON')
with c:
    triggers = c.execute("SELECT name, sql FROM sqlite_master WHERE type='trigger'").fetchall()
    for name, sql in triggers:
        c.execute('DROP TRIGGER "'+name.replace('"','""')+'"')
    assert c.execute('SELECT invoice_number FROM orders WHERE id=7').fetchone()[0] == 'INV-000007'
    assert c.execute('SELECT invoice_number FROM orders WHERE id=8').fetchone()[0] == 'INV-000008'
    previous = c.execute("SELECT balance_after, created_at FROM stock_movements WHERE company_id=1 AND stock_location_id=2 AND product_id=5 AND id<68 ORDER BY id DESC LIMIT 1").fetchone()
    assert previous[0] == 1
    assert c.execute('SELECT quantity FROM stocks WHERE company_id=1 AND stock_location_id=2 AND product_id=5').fetchone()[0] == 0
    c.execute('UPDATE stocks SET quantity=?, updated_at=? WHERE company_id=1 AND stock_location_id=2 AND product_id=5', previous)
    for table, ids in plan['delete_rows'].items():
        placeholders = ','.join('?' for _ in ids)
        count = c.execute(f'DELETE FROM "{table}" WHERE id IN ({placeholders})', ids).rowcount
        assert count == len(ids), (table, count)
        c.execute('UPDATE sqlite_sequence SET seq=seq-? WHERE name=?', (len(ids), table))
    for doc, value in plan['restore_document_sequences'].items():
        c.execute('UPDATE document_sequences SET value=? WHERE company_id=1 AND type=?', (value, doc))
    c.execute("UPDATE sqlite_sequence SET seq=seq-5 WHERE name='document_sequences'")
    for name, sql in triggers:
        c.execute(sql)
    assert not c.execute('PRAGMA foreign_key_check').fetchall()
    assert c.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
    assert c.execute('SELECT MAX(id) FROM orders').fetchone()[0] == 6
    assert c.execute('SELECT COUNT(*) FROM payments').fetchone()[0] == 1
print(json.dumps({'result':'PASS','mode':'APPLIED' if apply else 'PREVIEW ONLY','target':str(target),'removed_records':sum(map(len, plan['delete_rows'].values())),'foreign_key_check':'PASS','integrity_check':'PASS'}))
c.close()
