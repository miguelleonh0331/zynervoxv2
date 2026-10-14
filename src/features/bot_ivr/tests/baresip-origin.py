"""No SSH, database, SIP calls or root access; exercises the actual broker."""
import importlib.util
import json
import pathlib
import sys
import tempfile
import time
import types
import unittest

if sys.platform == 'win32':
    sys.modules['fcntl'] = types.SimpleNamespace(flock=lambda *args: None, LOCK_EX=2)
spec = importlib.util.spec_from_file_location('broker', sys.argv.pop(1))
broker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(broker)
CALL = 'zv2-' + 'a' * 24
RUN = 'b' * 32


class BrokerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.b = broker.Broker.__new__(broker.Broker)
        self.b.root = pathlib.Path(self.tmp.name)
        self.b.cfg = {'enabled': True}
        self.b.annexes = ['4002', '4003']
        self.b.channels = lambda: ''
        self.b.registered = lambda: {'4002', '4003'}
        self.queries = []
        self.original_sql = broker.sql
        broker.sql = self.sql
        self.addCleanup(setattr, broker, 'sql', self.original_sql)

    def sql(self, query):
        self.queries.append(query)
        if 'SELECT run_id' in query:
            return RUN + '\n'
        if 'SELECT annex FROM' in query:
            return '4003\n'
        return ''

    def lease(self, **updates):
        row = dict(call_id=CALL, run_id=RUN, annex='4003', state='dialing', phone='983202698',
                   list_id=1, lead_id=2, caller_id='', created=time.time(), bound=False, released=False)
        row.update(updates)
        self.b.save(row)
        return row

    def test_no_collision_with_legacy_reservations(self):
        result = self.b.reserve(dict(call_id=CALL, run_id=RUN))
        self.assertTrue(result['available'])
        update = next(x for x in self.queries if x.startswith('UPDATE'))
        self.assertIn("reserved_campaign_id IS NULL AND status='free'", update)
        self.assertIn(broker.OWNER, update)
        self.assertEqual(self.b.load(CALL)['annex'], '4003')

    def test_no_registered_annex_means_no_claim(self):
        self.b.registered = lambda: set()
        self.assertFalse(self.b.reserve(dict(call_id=CALL, run_id=RUN))['available'])
        self.assertFalse(any(x.startswith('UPDATE') for x in self.queries))

    def test_release_is_owner_and_token_scoped(self):
        lease = self.lease(state='reserved')
        self.b.dispatch(dict(action='release', call_id=CALL))
        self.assertTrue(self.b.load(CALL)['released'])
        query = self.queries[-1]
        self.assertIn('reserved_campaign_id=' + broker.OWNER, query)
        self.assertIn('locked_by=' + str(broker.lock_id(CALL)), query)
        self.assertIn("annex='4003'", query)

    def test_one_use_bind_and_annex_identity(self):
        self.lease()
        request = dict(action='bind', call_id=CALL, phone='983202698', channel='SIP/4002-000abc', unique_id='123.45')
        with self.assertRaises(ValueError):
            self.b.dispatch(request)
        request['channel'] = 'SIP/4003-000abc'
        self.assertTrue(self.b.dispatch(request)['bound'])
        with self.assertRaises(ValueError):
            self.b.dispatch(request)

    def test_ambiguous_origin_cannot_be_cancelled_or_reused(self):
        self.lease(state='quarantine')
        with self.assertRaises(ValueError):
            self.b.dispatch(dict(action='release', call_id=CALL))
        self.b.sweep('')
        self.assertFalse(self.b.load(CALL)['released'])

    def test_expired_lease_waits_for_pbx_absence(self):
        self.lease(state='quarantine', created=time.time() - 120)
        self.b.sweep('SIP/4003-000abc!test_cortar!7307!1\n')
        self.assertFalse(self.b.load(CALL)['released'])
        self.b.sweep('')
        self.assertTrue(self.b.load(CALL)['released'])
        self.assertEqual(self.b.load(CALL)['state'], 'failed')

    def test_token_rejects_paths_shell_and_sql(self):
        for value in ['../a', CALL + "';select", CALL + '\n', 123]:
            with self.assertRaises(ValueError):
                broker.token(value)

    def test_duplicate_dial_rejected(self):
        self.lease()
        with self.assertRaises(ValueError):
            self.b.dispatch(dict(action='dial', call_id=CALL))


unittest.main()
