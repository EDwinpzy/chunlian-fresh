"""Real HTTP business acceptance. Refuses any target without the isolated marker.

Synthetic accounts/orders are retained in the QA database for audit inspection.
No live customer credentials, payment provider calls, or production writes.
"""
import concurrent.futures
import datetime
import hashlib
import json
import os
from pathlib import Path
import time
import urllib.error
import urllib.request
import uuid

BASE = os.environ.get('CHUNLIAN_QA_URL', 'http://127.0.0.1:18096').rstrip('/')
report = []
STORE_TOKEN = None
STORE_SNAPSHOT = None

def req(method, path, body=None, token=None, key=None):
    headers = {'Content-Type': 'application/json', 'X-Client': 'customer-web'}
    if token: headers['Authorization'] = 'Bearer ' + token
    if key: headers['Idempotency-Key'] = key
    data = json.dumps(body, ensure_ascii=False).encode() if body is not None else None
    try:
        with urllib.request.urlopen(urllib.request.Request(BASE + path, data, headers, method=method), timeout=35) as r:
            raw = r.read()
            try: return json.loads(raw)
            except ValueError:
                Path('docs/evidence/qa-http-error.html').write_bytes(raw)
                raise RuntimeError('Non-JSON response for ' + path + ' (saved qa-http-error.html)')
    except urllib.error.HTTPError as e:
        try: return json.loads(e.read())
        finally: e.close()

def api(method, path, body=None, token=None, key=None):
    return req(method, '/api/v1/' + path, body, token, key)

def check(label, ok, detail=None):
    report.append({'name': label, 'passed': bool(ok), 'detail': detail if not ok else None})
    print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    if not ok: print(json.dumps(detail, ensure_ascii=False), flush=True)

def good(label, response):
    check(label, response.get('code') == 'OK', response)
    if response.get('code') != 'OK': raise RuntimeError(label)
    return response.get('data')

def key(): return str(uuid.uuid4())

def restore_store_settings():
    """Restore the QA store configuration even if a test fails mid-run."""
    if STORE_TOKEN is None or STORE_SNAPSHOT is None:
        return
    fields = ('shop_name', 'notice', 'phone', 'payment_qr_url', 'delivery_fee_fen', 'is_open', 'min_lead_minutes')
    current = api('GET', 'merchant/store', token=STORE_TOKEN)
    if current.get('code') != 'OK' or not isinstance(current.get('data'), dict):
        check('read store settings for restoration', False, {'code': current.get('code')})
        return
    changes = {name: STORE_SNAPSHOT[name] for name in fields if current['data'].get(name) != STORE_SNAPSHOT.get(name)}
    if changes:
        restored = api('PATCH', 'merchant/store', changes, STORE_TOKEN)
        check('restore original shop settings', restored.get('code') == 'OK', {'code': restored.get('code'), 'changed_fields': list(changes)})
    after = api('GET', 'merchant/store', token=STORE_TOKEN)
    values_match = after.get('code') == 'OK' and isinstance(after.get('data'), dict) and all(
        after['data'].get(name) == STORE_SNAPSHOT.get(name) for name in fields
    )
    check('original shop settings and QR path restored', values_match,
          {'changed_fields': [name for name in fields if isinstance(after.get('data'), dict) and after['data'].get(name) != STORE_SNAPSHOT.get(name)]})

def order(c, sku, qty=2, method='COD', **extra):
    body = {'items': [{'sku_id': sku, 'qty': qty}], 'contact': {'name': 'QA Customer', 'phone': '13900139000', 'address': 'QA Street 123'},
            'delivery_mode': 'ASAP', 'payment_method': method, 'client_type': 'android', **extra}
    return good('create ' + method, api('POST', 'customer/orders', body, c, key()))['order']

def detail(c, oid): return good('read customer order', api('GET', 'customer/orders/' + oid, token=c))

def action(m, o, endpoint, **extra):
    return api('POST', 'merchant/orders/' + o['id'] + '/' + endpoint, {'version': o['version'], **extra}, m, key())

def revise(c, o, items, idem=None):
    return api('POST', 'customer/orders/' + o['id'] + '/revision', {'client_version': o['version'], 'items': items}, c, idem or key())

def main():
    global STORE_TOKEN, STORE_SNAPSHOT
    marker = req('GET', '/__qa_environment')
    if marker != {'environment': 'isolated-staging', 'database': 'chunlian_qa_20261013'}:
        raise SystemExit('Refusing unmarked environment')
    m = good('merchant login', api('POST', 'merchant/auth/login', {'username': 'qa_admin', 'password': 'QaMerchant2026!', 'device_id': 'qa-business'}))['token']
    STORE_TOKEN = m
    STORE_SNAPSHOT = good('snapshot original store settings', api('GET', 'merchant/store', token=m))
    check('shop is open before business regression', STORE_SNAPSHOT['is_open'] is True)
    check('bad merchant login rejected', api('POST', 'merchant/auth/login', {'username': 'qa_admin', 'password': 'wrong'})['code'] == 'FORBIDDEN')
    check('merchant anonymous rejected', api('GET', 'merchant/orders')['code'] == 'FORBIDDEN')
    phone = '139' + str(time.time_ns() % 100000000).zfill(8)
    creds = {'phone': phone, 'password': 'QaCustomer2026!', 'nickname': 'QA Customer'}
    c = good('register customer', api('POST', 'customer/auth/register', creds))['token']
    check('duplicate registration rejected', api('POST', 'customer/auth/register', creds)['code'] != 'OK')
    check('invalid phone rejected', api('POST', 'customer/auth/register', {**creds, 'phone': '123'})['code'] != 'OK')
    check('bad customer login rejected', api('POST', 'customer/auth/login', {**creds, 'password': 'wrong'})['code'] != 'OK')
    c2 = good('second independent session', api('POST', 'customer/auth/login', creds))['token']
    good('first session remains valid', api('GET', 'customer/auth/me', token=c))
    good('update nickname', api('PATCH', 'customer/auth/profile', {'nickname': 'QA Updated'}, c))
    good('logout second session', api('POST', 'customer/auth/logout', {}, c2))
    check('revoked session rejected', api('GET', 'customer/auth/me', token=c2)['code'] != 'OK')
    other = good('other customer', api('POST', 'customer/auth/register', {**creds, 'phone': '138' + str(time.time_ns() % 100000000).zfill(8)}))['token']
    address = {'name': 'QA User', 'phone': '13900139000', 'address': 'QA Address 123', 'is_default': True}
    a = good('address create', api('POST', 'customer/addresses', address, c))
    addresses = good('address list', api('GET', 'customer/addresses', token=c))
    aid = addresses[0]['id']
    good('address edit', api('POST', 'customer/addresses', {**address, 'id': aid, 'address': 'QA Edited 456'}, c))
    good('address default', api('POST', 'customer/addresses/' + aid + '/default', {}, c))
    check('foreign address edit rejected', api('POST', 'customer/addresses', {**address, 'id': aid}, other)['code'] != 'OK')
    check('foreign address delete rejected', api('DELETE', 'customer/addresses/' + aid, token=other)['code'] != 'OK')
    cats = good('six fresh categories', api('GET', 'customer/catalog/categories'))
    check('category count six', len(cats) == 6)
    goods = []
    for index, (n, price) in enumerate([('QA business A', 350), ('QA business B', 480)]):
        goods_payload = {'title': n, 'price_fen': price, 'inventory': 80, 'inventory_unit': '份', 'spec_text': 'QA 500g', 'category_id': cats[0]['id'], 'is_shelves': True}
        goods_key = key()
        if index == 0:
            check('merchant goods creation requires an idempotency key', api('POST', 'merchant/goods', goods_payload, m)['code'] != 'OK')
            check('merchant goods creation rejects a non-v4 key', api('POST', 'merchant/goods', goods_payload, m, 'not-a-uuid')['code'] != 'OK')
        created = good('merchant goods create', api('POST', 'merchant/goods', goods_payload, m, goods_key))
        gid = str(created.get('goods_id') or created.get('id'))
        if index == 0:
            replay = good('merchant goods idempotent replay', api('POST', 'merchant/goods', goods_payload, m, goods_key))
            replay_id = str(replay.get('goods_id') or replay.get('id'))
            check('same-key goods replay returns the original product', replay_id == gid, {'first_id': gid, 'replay_id': replay_id})
            conflict = api('POST', 'merchant/goods', {**goods_payload, 'title': n + ' conflict'}, m, goods_key)
            check('same key with different goods body conflicts', conflict['code'] == 'IDEMPOTENCY_CONFLICT', conflict)
        g = good('customer product detail', api('GET', 'customer/catalog/goods/' + gid))
        goods.append((gid, str(g['skus'][0]['sku_id'] if 'sku_id' in g['skus'][0] else g['skus'][0]['id'])))
    ga, sa = goods[0]; gb, sb = goods[1]
    race_payload = {'items': [{'sku_id': sa, 'qty': 1}], 'contact': {'name': 'QA Concurrent', 'phone': '13900139000', 'address': 'QA Race 1'},
                    'delivery_mode': 'ASAP', 'payment_method': 'COD', 'client_type': 'android'}
    race_key = key()
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        race_responses = list(pool.map(lambda _: api('POST', 'customer/orders', race_payload, c, race_key), range(2)))
    race_ok = [r for r in race_responses if r.get('code') == 'OK']
    race_codes = [r.get('code') for r in race_responses]
    if not race_ok:
        # The second concurrent request can observe the winner's pending lease;
        # retrying the same key after it settles must return the winner's result.
        for _ in range(20):
            replay = api('POST', 'customer/orders', race_payload, c, race_key)
            if replay.get('code') == 'OK':
                race_ok = [replay]
                break
            time.sleep(.1)
    race_order_ids = {str(r.get('data', {}).get('order', {}).get('id', '')) for r in race_ok}
    settled_replay = api('POST', 'customer/orders', race_payload, c, race_key)
    settled_id = str(settled_replay.get('data', {}).get('order', {}).get('id', ''))
    check('same-key concurrent create never duplicates', len(race_order_ids) == 1 and '' not in race_order_ids
          and settled_replay.get('code') == 'OK' and settled_id in race_order_ids
          and all(code in ('OK', 'IDEMPOTENCY_IN_PROGRESS') for code in race_codes),
          {'codes': race_codes, 'order_ids': list(race_order_ids)})
    check('empty preview rejected', api('POST', 'customer/orders/preview', {'items': []}, c)['code'] != 'OK')
    check('stock preview rejected', api('POST', 'customer/orders/preview', {'items': [{'sku_id': sa, 'qty': 99}]}, c)['code'] == 'OUT_OF_STOCK')
    check('past appointment rejected', api('POST', 'customer/orders/preview', {'items': [{'sku_id': sa, 'qty': 1}], 'delivery_mode': 'SCHEDULED', 'expected_at': '2020-01-01T12:00:00+08:00'}, c)['code'] == 'INVALID_EXPECTED_TIME')
    tomorrow = (datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))) + datetime.timedelta(days=1)).isoformat()
    o = order(c, sa, delivery_mode='SCHEDULED', expected_at=tomorrow, total_fen=1)
    check('server ignores spoofed money', o['total_fen'] == 700)
    check('appointment retained', o['delivery_mode'] == 'SCHEDULED' and bool(o['expected_at']))
    merchant_detail = good('authorized merchant reads delivery contact', api('GET', 'merchant/orders/' + o['id'], token=m))
    check('merchant detail includes dialable contact phone', merchant_detail['contact'].get('phone') == '13900139000')
    merchant_list = good('merchant list contact privacy', api('GET', 'merchant/orders', token=m))
    check('merchant list never exposes full contact phone', bool(merchant_list.get('list')) and all('phone' not in item.get('contact', {}) for item in merchant_list['list']))
    check('anonymous merchant detail contact protected', api('GET', 'merchant/orders/' + o['id'])['code'] == 'FORBIDDEN')
    check('foreign order read rejected', api('GET', 'customer/orders/' + o['id'], token=other)['code'] != 'OK')
    check('foreign cancel rejected', api('POST', 'customer/orders/' + o['id'] + '/cancel', {'version': o['version']}, other, key())['code'] != 'OK')
    info = {'version': o['version'], 'contact': {**address, 'address': 'QA latest address'}, 'delivery_mode': 'ASAP', 'expected_at': None, 'remark': 'QA note'}
    updated = good('order info edit', api('PATCH', 'customer/orders/' + o['id'] + '/info', info, c))
    check('latest address and mode', updated['contact']['address'] == 'QA latest address' and updated['delivery_mode'] == 'ASAP' and updated['expected_at'] is None)
    check('stale info version rejected', api('PATCH', 'customer/orders/' + o['id'] + '/info', info, c)['code'] == 'ORDER_VERSION_CONFLICT')
    o = detail(c, o['id'])

    # Offline QR collection is only recorded after merchant verification. Initial
    # receipt, top-up, and manual refund all share the same order ledger.
    qr = detail(c, order(c, sa, 1, method='OFFLINE_QR')['id'])
    wrong_receipt = action(m, qr, 'qr-receipt', amount_fen=qr['receivable_fen'] + 1)
    check('QR initial receipt rejects wrong amount', wrong_receipt['code'] == 'VALIDATION_FAILED')
    qr_key = key(); qr_body = {'version': qr['version'], 'amount_fen': qr['receivable_fen']}
    qr_paid = good('merchant verifies QR prepayment', api('POST', 'merchant/orders/' + qr['id'] + '/qr-receipt', qr_body, m, qr_key))
    qr_replay = good('QR prepayment idempotent replay', api('POST', 'merchant/orders/' + qr['id'] + '/qr-receipt', qr_body, m, qr_key))
    check('QR receipt replay is stable', qr_replay == qr_paid)
    qr = detail(c, qr['id'])
    check('QR prepayment updates paid order', qr['payment_state'] == 'PAID' and qr['paid_fen'] == qr['total_fen'])

    before_pending_lines = qr['lines']
    qr_topup = good('paid QR order creates pending top-up', revise(c, qr, [{'sku_id': sa, 'qty': 2}]))
    qr_pending = detail(c, qr['id'])
    check('pending QR top-up keeps old active lines', qr_pending['revision_state'] == 'PENDING_TOPUP'
          and qr_pending['lines'] == before_pending_lines)
    bad_topup = action(m, qr_pending, 'qr-receipt', amount_fen=qr_topup['delta_fen'] + 1)
    check('QR top-up rejects wrong amount', bad_topup['code'] == 'VALIDATION_FAILED')
    qr_pending_key = key()
    qr_topup_receipt = good('merchant verifies QR top-up', api('POST', 'merchant/orders/' + qr['id'] + '/qr-receipt',
        {'version': qr_pending['version'], 'amount_fen': qr_topup['delta_fen']}, m, qr_pending_key))
    qr = detail(c, qr['id'])
    check('verified top-up commits new lines once', qr_topup_receipt['revision_state'] == 'COMMITTED'
          and qr['revision_state'] == 'COMMITTED' and sum(x['qty'] for x in qr['lines']) == 2
          and qr['paid_fen'] == qr['total_fen'])

    qr_refund = good('paid QR decrease enters manual refund', revise(c, qr, [{'sku_id': sa, 'qty': 1}]))
    qr_applying = detail(c, qr['id'])
    check('QR refund reserves refund amount', qr_applying['revision_state'] == 'APPLYING'
          and qr_applying['refunding_fen'] == abs(qr_refund['delta_fen']))
    wrong_refund = action(m, qr_applying, 'manual-refund', amount_fen=abs(qr_refund['delta_fen']) + 1)
    check('manual refund rejects wrong amount', wrong_refund['code'] == 'VALIDATION_FAILED')
    refund_key = key(); refund_body = {'version': qr_applying['version'], 'amount_fen': abs(qr_refund['delta_fen'])}
    refund_done = good('merchant confirms actual manual refund', api('POST', 'merchant/orders/' + qr['id'] + '/manual-refund', refund_body, m, refund_key))
    refund_replay = good('manual refund idempotent replay', api('POST', 'merchant/orders/' + qr['id'] + '/manual-refund', refund_body, m, refund_key))
    refund_ledger = good('read manual refund business timestamp', api('GET', 'merchant/ledger?order_id=' + qr['id'], token=m))
    refund_entries = [entry for entry in refund_ledger['list'] if entry['kind'] == 'OFFLINE_REFUND' and entry['status'] == 'SUCCESS']
    china_zone = datetime.timezone(datetime.timedelta(hours=8))
    check('manual refund timestamp matches China business time', bool(refund_entries) and all(
        abs((datetime.datetime.now(china_zone) - datetime.datetime.fromisoformat(entry['occurred_at']).replace(tzinfo=china_zone)).total_seconds()) < 90
        for entry in refund_entries))
    qr = detail(c, qr['id'])
    check('manual refund settles once', refund_done == refund_replay and qr['revision_state'] == 'COMMITTED'
          and qr['payment_state'] == 'PARTIAL_REFUNDED' and qr['refunded_fen'] == abs(qr_refund['delta_fen'])
          and qr['refunding_fen'] == 0)
    qr_ledger_before = refund_ledger['total']
    good('dispatch settled prepaid QR order', action(m, qr, 'deliver'))
    qr = detail(c, qr['id'])
    qr_delivery = api('POST', 'merchant/orders/' + qr['id'] + '/confirm-delivery',
                      {'version': qr['version'], 'collected_on_delivery': False}, m, key())
    good('deliver prepaid QR without recording another receipt', qr_delivery)
    check('prepaid delivery message says money settled', qr_delivery.get('message') == '已送达（货款已结清）')
    qr = detail(c, qr['id'])
    check('prepaid delivered balance stays zero', qr['fulfillment'] == 'DELIVERED' and qr['receivable_fen'] == 0)
    qr_ledger_after = good('prepaid delivery ledger unchanged', api('GET', 'merchant/ledger?order_id=' + qr['id'], token=m))
    check('prepaid delivery does not duplicate money entry', qr_ledger_after['total'] == qr_ledger_before)

    idem = key(); items = [{'sku_id': sa, 'qty': 3}, {'sku_id': sb, 'qty': 1}]
    sub = good('revision add new product', revise(c, o, items, idem))
    replay = good('revision same key replay', revise(c, o, items, idem))
    check('revision replay same result', replay == sub)
    check('revision key conflict', revise(c, o, [{'sku_id': sa, 'qty': 1}], idem)['code'] == 'IDEMPOTENCY_CONFLICT')
    o = detail(c, o['id']); check('COD increase immediate and totals', o['total_fen'] == 1530)
    good('COD decrease no refund', revise(c, o, [{'sku_id': sa, 'qty': 1}]))
    o = detail(c, o['id']); check('COD decrease committed', o['revision_state'] == 'COMMITTED' and o['refunding_fen'] == 0)
    good('dispatch COD', action(m, o, 'deliver')); o = detail(c, o['id'])
    check('edit after dispatch rejected', revise(c, o, [{'sku_id': sa, 'qty': 2}])['code'] != 'OK')
    check('cancel after dispatch rejected', api('POST', 'customer/orders/' + o['id'] + '/cancel', {'version': o['version']}, c, key())['code'] != 'OK')
    good('deliver without collection', action(m, o, 'confirm-delivery', collected_on_delivery=False)); o = detail(c, o['id'])
    check('delivered unpaid independent', o['fulfillment'] == 'DELIVERED' and o['payment_state'] == 'UNPAID' and o['paid_fen'] == 0)
    good('customer receives unpaid COD', api('POST', 'customer/orders/' + o['id'] + '/receive', {'version': o['version']}, c, key())); o = detail(c, o['id'])
    recv = good('receivables after customer receipt', api('GET', 'merchant/receivables', token=m))
    check('finished unpaid remains receivable', any(str(x['order_id']) == o['id'] for x in recv))
    check('wrong collection amount rejected', action(m, o, 'cod-receipt', collected_fen=1, collection_method='CASH')['code'] != 'OK')
    good('collect after customer receipt', action(m, o, 'cod-receipt', collected_fen=o['receivable_fen'], collection_method='OFFLINE_QR')); o = detail(c, o['id'])
    check('finished and paid independent', o['fulfillment'] == 'FINISHED' and o['payment_state'] == 'PAID')
    check('duplicate collection rejected', action(m, o, 'cod-receipt', collected_fen=350, collection_method='CASH')['code'] != 'OK')
    good('ledger query', api('GET', 'merchant/ledger?order_id=' + o['id'], token=m))
    good('revision history', api('GET', 'merchant/orders/' + o['id'] + '/revisions', token=m))
    notifications = good('merchant notifications', api('GET', 'merchant/notifications?limit=50', token=m))
    compact_notifications = good('compact merchant notifications', api('GET', 'merchant/notifications?limit=1', token=m))
    check('notification unread count ignores list limit', notifications['unread_count'] == compact_notifications['unread_count'] and notifications['unread_count'] > 1)
    good('mark notifications read', api('POST', 'merchant/notifications/read', {}, m))
    check('mark all read clears global count', good('notifications after read', api('GET', 'merchant/notifications?limit=1', token=m))['unread_count'] == 0)
    stats = good('merchant stats', api('GET', 'merchant/stats', token=m))
    business_today = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()
    check('default stats ends on China business date', stats['to'] == business_today.isoformat()
          and stats['from'] == (business_today - datetime.timedelta(days=6)).isoformat())
    today_stats = good('single China business day stats', api('GET', f'merchant/stats?from={business_today}&to={business_today}', token=m))
    dashboard = good('dashboard business-day totals', api('GET', 'merchant/dashboard', token=m))
    check('single day stats range is honored', today_stats['from'] == str(business_today) and today_stats['to'] == str(business_today))
    check('today stats and dashboard use same funds and order date',
          today_stats['received_total_fen'] == dashboard['today_received_fen']
          and today_stats['received_offline_qr_fen'] == dashboard['today_offline_qr_received_fen']
          and today_stats['received_cod_fen'] == dashboard['today_cod_received_fen']
          and today_stats['refunded_fen'] == dashboard['today_refund_fen']
          and today_stats['order_count'] == dashboard['today_order_count'])
    good('daily settlement', api('GET', 'merchant/settlement/daily', token=m))
    cancel = order(c, sa, 1)
    check('new order creates unread notification after read', good('notifications after new order', api('GET', 'merchant/notifications?limit=1', token=m))['unread_count'] > 0)
    before_stock = good('stock before cancellation', api('GET', 'customer/catalog/goods/' + ga))['skus'][0]['inventory']
    ck = key(); body = {'version': cancel['version'], 'reason': 'QA cancellation'}
    first = good('cancel pending COD', api('POST', 'customer/orders/' + cancel['id'] + '/cancel', body, c, ck))
    replay = good('cancel replay', api('POST', 'customer/orders/' + cancel['id'] + '/cancel', body, c, ck))
    after_stock = good('stock after cancellation', api('GET', 'customer/catalog/goods/' + ga))['skus'][0]['inventory']
    check('cancel stock returned once', after_stock == before_stock + 1 and replay == first)
    good('pause shop', api('PATCH', 'merchant/store', {'is_open': False}, m))
    check('pause rejects new purchase', api('POST', 'customer/orders', {'items': [{'sku_id': sa, 'qty': 1}], 'contact': address, 'payment_method': 'COD'}, c, key())['code'] == 'SHOP_CLOSED')
    good('existing order readable paused', api('GET', 'customer/orders/' + o['id'], token=c))
    good('resume shop', api('PATCH', 'merchant/store', {'is_open': True}, m))
    good('price inventory update', api('PATCH', f'merchant/goods/{ga}/sku/{sa}', {'price_fen': 600, 'inventory': 40}, m))
    check('old snapshot unchanged', detail(c, o['id'])['total_fen'] == 350)
    good('goods edit', api('PATCH', 'merchant/goods/' + ga, {'title': 'QA edited product'}, m))
    good('goods off shelf', api('POST', 'merchant/goods/' + ga + '/shelves', {'is_shelves': False}, m))
    check('off shelf prevents purchase', api('POST', 'customer/orders/preview', {'items': [{'sku_id': sa, 'qty': 1}]}, c)['code'] != 'OK')
    good('on shelf again', api('POST', 'merchant/goods/' + ga + '/shelves', {'is_shelves': True}, m))
    good('delete own address', api('DELETE', 'customer/addresses/' + aid, token=c))

if __name__ == '__main__':
    try: main()
    except Exception as e: check('suite completion', False, str(e))
    finally: restore_store_settings()
    out = Path('docs/evidence/qa-business.json'); out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps({'target': BASE, 'timestamp': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                              'script_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
                              'tests': report}, ensure_ascii=False, indent=2), encoding='utf-8')
    passed = sum(x['passed'] for x in report)
    print(f'{passed}/{len(report)} passed')
    raise SystemExit(0 if passed == len(report) else 1)
