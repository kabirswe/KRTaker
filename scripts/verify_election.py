#!/usr/bin/env python3
"""V2.31.7 live round-trip: create election → add candidates → open → vote → close → verify roles."""
import json, sys, time
sys.path.insert(0, '/root/KRTaker/scripts')
from krt_api_login import api_login, api

# read owner creds from the known creds file (avoids inline-secret redaction)
def owner_creds():
    for line in open('/root/krtaker-deploy/user_creds.txt', encoding='utf-8'):
        parts = line.split()
        if len(parts) >= 2 and parts[0] == 'owner':
            return parts[1].strip(), parts[2].strip()
    raise RuntimeError('owner creds not found')

_em, _pw = owner_creds()
TOKEN = api_login(_em, _pw).get('token')
print('TOKEN OK')

def call(action, payload=None):
    return api(TOKEN, action, payload)

# 0. cleanup any leftover test elections (draft/open from prior runs) — leave closed ones
r = call('app-samity', {'action': 'election-list'})
for x in (r.get('elections') or []):
    if x['status'] != 'closed':
        call('app-samity', {'action': 'election-delete', 'id': x['id']})
        print('cleaned leftover:', x['id'], x['status'])

# 1. list members
r = call('app-samity', {'action': 'member-list'})
members = r.get('members', [])
print('members:', len(members), [m['name'] for m in members[:8]])
if len(members) < 3:
    print('NEED MORE MEMBERS'); sys.exit(1)

ids = [m['id'] for m in members]

# 2. create election
r = call('app-samity', {'action': 'election-create', 'title': '2026 Annual Committee Election (test)',
                        'positions': [{'name': 'Chairman', 'seats': 1}, {'name': 'Secretary', 'seats': 1}, {'name': 'Treasurer', 'seats': 1}, {'name': 'Member', 'seats': 3}],
                        'starts_at': '2026-06-12', 'ends_at': '2026-06-30'})
eid = r.get('id')
print('election created:', eid, json.dumps(r)[:120])
if not eid:
    print('CREATE FAILED', json.dumps(r)[:300]); sys.exit(1)

def add_cand(member, position, manifesto=''):
    r = call('app-samity', {'action': 'candidate-add', 'election': eid, 'member': member, 'position': position, 'manifesto': manifesto})
    print(f'candidate {member} -> {position}:', json.dumps(r)[:120])
    return r.get('id')

c0 = add_cand(ids[0], 'Chairman', 'Let us build a better society')
c1 = add_cand(ids[1], 'Chairman', 'Transparency first')
c2 = add_cand(ids[2], 'Secretary', 'Accountability')
c3 = add_cand(ids[3 % len(ids)], 'Treasurer', 'Clean books')
c4 = add_cand(ids[4 % len(ids)], 'Member', '')
c5 = add_cand(ids[5 % len(ids)], 'Member', '')
c6 = add_cand(ids[6 % len(ids)], 'Member', '')

# duplicate candidate (same member, same election) should fail
r = call('app-samity', {'action': 'candidate-add', 'election': eid, 'member': ids[0], 'position': 'Chairman'})
print('dup candidate (expect fail):', json.dumps(r)[:120])
assert not r.get('ok'), 'DUP SHOULD FAIL'

# 3. open
r = call('app-samity', {'action': 'election-open', 'id': eid})
print('open:', json.dumps(r)[:120])

# 4. vote — get candidate ids per position from election-list
r = call('app-samity', {'action': 'election-list'})
e = next((x for x in r['elections'] if x['id'] == eid), None)
if not e:
    print('ELECTION NOT IN LIST'); sys.exit(1)
bypos = {}
for c in e['candidates']:
    bypos.setdefault(c['position'], []).append(c)
for pos, cs in bypos.items():
    r = call('app-samity', {'action': 'vote', 'election': eid, 'position': pos, 'candidate': cs[0]['id']})
    print(f'vote {pos} -> {cs[0]["member_name"]}:', json.dumps(r)[:100])

# re-vote same position (conflict-upsert) — should still be ok
pos0 = list(bypos.keys())[0]
cs0 = bypos[pos0]
if len(cs0) > 1:
    r = call('app-samity', {'action': 'vote', 'election': eid, 'position': pos0, 'candidate': cs0[1]['id']})
    print(f're-vote {pos0}:', json.dumps(r)[:100])

# invalid candidate vote should fail
r = call('app-samity', {'action': 'vote', 'election': eid, 'position': 'Chairman', 'candidate': 'SCD-999'})
print('invalid candidate vote (expect fail):', json.dumps(r)[:100])
assert not r.get('ok'), 'INVALID VOTE SHOULD FAIL'

# 5. close
r = call('app-samity', {'action': 'election-close', 'id': eid})
print('close winners:', json.dumps(r.get('winners'))[:400])
assert r.get('ok'), 'CLOSE FAILED'

# 6. verify final
r = call('app-samity', {'action': 'election-list'})
e = next((x for x in r['elections'] if x['id'] == eid), None)
print('final status:', e['status'], '| total_ballots:', e['total_ballots'], '| my votes:', e['my_votes'])
for pos, cs in bypos.items():
    print(pos, [(c['member_name'], c['votes']) for c in sorted(cs, key=lambda x: -x['votes'])])

# 7. verify committee roles were auto-assigned
r = call('app-samity', {'action': 'member-list'})
print('committee roles now:')
for m in r['members']:
    if m['role'] not in ('Member', ''):
        print('  ', m['name'], '=>', m['role'])
print('ROUND-TRIP DONE')
