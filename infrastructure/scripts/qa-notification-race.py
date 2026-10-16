"""Isolated MySQL/API acceptance for notification read markers and commit races.

Run on the QA host from the project root. This script refuses to run unless
both the HTTP marker and MySQL database identify the isolated QA environment.
It removes only the synthetic outbox rows it creates.
"""
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid

DB_NAME = 'chunlian_qa_20261013'
BASE = os.environ.get('CHUNLIAN_QA_URL', 'http://127.0.0.1:18096').rstrip('/')
report = []


def req(method, path, body=None, token=None):
    headers = {'Content-Type': 'application/json', 'X-Client': 'customer-web'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    data = json.dumps(body, ensure_ascii=False).encode('utf-8') if body is not None else None
    request = urllib.request.Request(BASE + path, data, headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=35) as response:
            return json.loads(response.read())
    except urllib.error.HTTPError as error:
        try:
            return json.loads(error.read())
        finally:
            error.close()


def api(method, path, body=None, token=None):
    return req(method, '/api/v1/' + path, body, token)


def check(label, ok, detail=None):
    report.append({
        'name': label,
        'status': 'passed' if ok else 'failed',
        'passed': bool(ok),
        'detail': detail if not ok else None,
    })
    print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    if not ok:
        print(json.dumps(detail, ensure_ascii=False), flush=True)


def skip(label, detail):
    report.append({'name': label, 'status': 'skipped', 'passed': None, 'detail': detail})
    print('SKIP ' + label, flush=True)
    print(json.dumps(detail, ensure_ascii=False), flush=True)


def good(label, response):
    check(label, response.get('code') == 'OK', response)
    if response.get('code') != 'OK':
        raise RuntimeError(label)
    return response.get('data')


def mysql(sql, timeout=15):
    result = subprocess.run(
        ['mysql', '--batch', '--skip-column-names', '--database=' + DB_NAME, '--execute=' + sql],
        capture_output=True, text=True, encoding='utf-8', timeout=timeout,
    )
    if result.returncode != 0:
        raise RuntimeError('QA MySQL command failed: ' + result.stderr[-1000:])
    return result.stdout.strip()


def mysql_session():
    return subprocess.Popen(
        ['mysql', '--batch', '--skip-column-names', '--unbuffered', '--database=' + DB_NAME],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        text=True, encoding='utf-8', bufsize=1,
    )


def begin_uncommitted_event(marker):
    process = mysql_session()
    payload = json.dumps({'qa_marker': marker, 'role': 'lower-id-uncommitted'}, separators=(',', ':'))
    process.stdin.write('START TRANSACTION;\n')
    process.stdin.write(
        "INSERT INTO veg_outbox_event "
        "(event_type, entity_id, payload, status, retry_count, sent_at, created_at, updated_at) "
        "VALUES ('ORDER_CREATED', NULL, '" + payload + "', 'SENT', 0, NOW(), NOW(), NOW());\n"
    )
    process.stdin.write('SELECT LAST_INSERT_ID();\n')
    process.stdin.flush()
    value = process.stdout.readline().strip()
    if not value.isdigit():
        raise RuntimeError('QA MySQL did not return the uncommitted event id')
    return process, value


def insert_committed_event(marker, role):
    payload = json.dumps({'qa_marker': marker, 'role': role}, separators=(',', ':'))
    sql = (
        "INSERT INTO veg_outbox_event "
        "(event_type, entity_id, payload, status, retry_count, sent_at, created_at, updated_at) "
        "VALUES ('ORDER_CREATED', NULL, '" + payload + "', 'SENT', 0, NOW(), NOW(), NOW()); "
        'SELECT LAST_INSERT_ID()'
    )
    value = mysql(sql)
    if not value.isdigit():
        raise RuntimeError('QA MySQL did not return the committed event id')
    return value


def finish_session(process, statement):
    if process is None or process.poll() is not None:
        return
    process.communicate(input=statement + ';\n', timeout=15)
    if process.returncode != 0:
        raise RuntimeError('QA MySQL transaction did not finish successfully')


def main():
    marker_data = req('GET', '/__qa_environment')
    check('notification race refuses non-isolated HTTP target', marker_data == {
        'environment': 'isolated-staging', 'database': DB_NAME,
    }, marker_data)
    if marker_data != {'environment': 'isolated-staging', 'database': DB_NAME}:
        raise SystemExit('Refusing a non-isolated QA target')

    actual_db = mysql('SELECT DATABASE()')
    check('notification race connects only to isolated QA database', actual_db == DB_NAME, actual_db)
    if actual_db != DB_NAME:
        raise SystemExit('Refusing a non-isolated MySQL database')

    merchant = good('notification race merchant login', api(
        'POST', 'merchant/auth/login',
        {'username': 'qa_admin', 'password': 'QaMerchant2026!', 'device_id': 'qa-notification-race'},
    ))['token']
    marker = 'qa-notification-race-' + uuid.uuid4().hex
    created_ids = []
    transaction_a = None
    a_committed = False
    try:
        transaction_a, event_a = begin_uncommitted_event(marker)
        created_ids.append(event_a)
        event_b = insert_committed_event(marker, 'higher-id-committed')
        created_ids.append(event_b)
        check('lower event id remains uncommitted while higher id commits', int(event_a) < int(event_b))

        started = time.monotonic()
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(api, 'POST', 'merchant/notifications/read', {}, merchant)
            try:
                mark_response = future.result(timeout=8)
                check('mark all read returns while lower-id event is uncommitted', True,
                        {'elapsed_seconds': round(time.monotonic() - started, 3)})
            except concurrent.futures.TimeoutError:
                check('mark all read returns while lower-id event is uncommitted', False,
                        {'waited_seconds': round(time.monotonic() - started, 3)})
                finish_session(transaction_a, 'COMMIT')
                a_committed = True
                mark_response = future.result(timeout=35)
            good('mark all read API response', mark_response)

        if not a_committed:
            finish_session(transaction_a, 'COMMIT')
            a_committed = True

        count_window_start = int(mysql('SELECT COALESCE(MAX(id), 0) FROM veg_outbox_event'))
        long_page = good('notifications list after lower-id commit', api(
            'GET', 'merchant/notifications?limit=200', token=merchant,
        ))
        rows = {str(row['id']): row for row in long_page['list']}
        check('late-committed lower-id event remains unread',
                event_a in rows and rows[event_a]['is_unread'] is True,
                {'event_id': event_a, 'found': event_a in rows,
                 'is_unread': rows.get(event_a, {}).get('is_unread')})
        check('higher-id event committed before mark is read',
                event_b in rows and rows[event_b]['is_unread'] is False,
                {'event_id': event_b, 'found': event_b in rows,
                 'is_unread': rows.get(event_b, {}).get('is_unread')})

        compact_page = good('compact notifications count', api(
            'GET', 'merchant/notifications?limit=1', token=merchant,
        ))
        count_window_end = int(mysql('SELECT COALESCE(MAX(id), 0) FROM veg_outbox_event'))
        count_detail = {
            'limit_1': compact_page['unread_count'],
            'limit_200': long_page['unread_count'],
            'event_id_before': count_window_start,
            'event_id_after': count_window_end,
        }
        if count_window_start == count_window_end:
            check('unread count is independent of list limit',
                    compact_page['unread_count'] == long_page['unread_count']
                    and compact_page['unread_count'] >= 1,
                    count_detail)
        else:
            skip('unread count is independent of list limit', {
                **count_detail,
                'reason': 'An unrelated QA business event committed during the two API reads.',
            })

        good('mark late event read', api('POST', 'merchant/notifications/read', {}, merchant))
        after_read = good('notifications after second read', api(
            'GET', 'merchant/notifications?limit=200', token=merchant,
        ))
        after_read_rows = {str(row['id']): row for row in after_read['list']}
        check('second mark all read marks late event as read',
                event_a in after_read_rows and after_read_rows[event_a]['is_unread'] is False,
                {'event_id': event_a, 'found': event_a in after_read_rows,
                 'is_unread': after_read_rows.get(event_a, {}).get('is_unread'),
                 'global_unread_count': after_read['unread_count']})
        check('second mark all read keeps earlier event read',
                event_b in after_read_rows and after_read_rows[event_b]['is_unread'] is False,
                {'event_id': event_b, 'found': event_b in after_read_rows,
                 'is_unread': after_read_rows.get(event_b, {}).get('is_unread')})

        event_c = insert_committed_event(marker, 'new-event-after-read')
        created_ids.append(event_c)
        new_page = good('notifications after a new committed event', api(
            'GET', 'merchant/notifications?limit=200', token=merchant,
        ))
        new_row = next((row for row in new_page['list'] if str(row['id']) == event_c), None)
        check('new event after mark all read is unread',
                new_row is not None and new_row['is_unread'] is True and new_page['unread_count'] >= 1,
                {'event_id': event_c, 'found': new_row is not None,
                 'is_unread': None if new_row is None else new_row['is_unread'],
                 'unread_count': new_page['unread_count']})
    except Exception as error:
        check('notification race suite completion', False, str(error))
    finally:
        if transaction_a is not None and not a_committed:
            try:
                finish_session(transaction_a, 'ROLLBACK')
            except Exception as error:
                check('rollback unfinished QA transaction', False, str(error))
        if created_ids:
            safe_ids = [event_id for event_id in created_ids if event_id.isdigit()]
            if safe_ids:
                id_list = ','.join(safe_ids)
                mysql('DELETE FROM veg_notification_seen WHERE event_id IN (' + id_list + ')')
                mysql('DELETE FROM veg_outbox_event WHERE id IN (' + id_list + ')')
                check('synthetic outbox events and seen markers cleaned',
                        mysql('SELECT COUNT(*) FROM veg_outbox_event WHERE id IN (' + id_list + ')') == '0'
                        and mysql('SELECT COUNT(*) FROM veg_notification_seen WHERE event_id IN (' + id_list + ')') == '0')

    passed = sum(test['status'] == 'passed' for test in report)
    failed = sum(test['status'] == 'failed' for test in report)
    skipped = sum(test['status'] == 'skipped' for test in report)
    print(json.dumps({
        'target': BASE,
        'database': actual_db,
        'script_sha256': __import__('hashlib').sha256(Path(__file__).read_bytes()).hexdigest(),
        'passed': passed,
        'failed': failed,
        'skipped': skipped,
        'total': len(report),
        'tests': report,
    }, ensure_ascii=False, indent=2))
    return 0 if failed == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
