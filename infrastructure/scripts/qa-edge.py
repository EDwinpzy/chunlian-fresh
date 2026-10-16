"""Additional real QA race, inventory, cancellation and price snapshot acceptance."""
import concurrent.futures
import datetime
import hashlib
import importlib.util
import json
import mimetypes
from pathlib import Path
import sys
import time
import urllib.error
import urllib.request
import uuid

sys.stdout.reconfigure(encoding='utf-8')
spec = importlib.util.spec_from_file_location('business_qa', Path(__file__).with_name('qa-business.py'))
q = importlib.util.module_from_spec(spec)
spec.loader.exec_module(q)

def upload_qa_image(token):
    """Upload the fixed QA PNG and verify the public URL serves the same bytes."""
    image_path=Path(__file__).resolve().parents[2]/'infrastructure'/'seed'/'qa-payment-code.png'
    boundary='----CodexQA'+uuid.uuid4().hex
    image_bytes=image_path.read_bytes()
    multipart=(
        f'--{boundary}\r\n'
        f'Content-Disposition: form-data; name="file"; filename="{image_path.name}"\r\n'
        f'Content-Type: {mimetypes.guess_type(image_path.name)[0] or "application/octet-stream"}\r\n\r\n'
    ).encode('ascii')+image_bytes+f'\r\n--{boundary}--\r\n'.encode('ascii')
    upload_request=urllib.request.Request(q.BASE+'/api/v1/merchant/uploads',multipart,{
        'Authorization':'Bearer '+token,'Content-Type':'multipart/form-data; boundary='+boundary,
    },method='POST')
    try:
        with urllib.request.urlopen(upload_request,timeout=35) as response:
            upload_response=json.loads(response.read())
    except urllib.error.HTTPError as error:
        upload_response=json.loads(error.read())
    upload_data=q.good('merchant PNG upload',upload_response)
    uploaded_path=str(upload_data.get('path',''))
    q.check('upload returns public goods image path',uploaded_path.startswith('/static/upload/images/goods/'))
    asset_status=0;asset_bytes=b'';asset_type=''
    if uploaded_path.startswith('/static/upload/images/goods/'):
        try:
            with urllib.request.urlopen(q.BASE+uploaded_path,timeout=35) as response:
                asset_status=response.status;asset_bytes=response.read();asset_type=response.headers.get('Content-Type','')
        except urllib.error.HTTPError as error:
            asset_status=error.code;asset_bytes=error.read();asset_type=error.headers.get('Content-Type','')
        except urllib.error.URLError:
            pass
    q.check('uploaded image URL returns HTTP 200',asset_status==200,{'status':asset_status,'path':uploaded_path})
    q.check('uploaded resource is a valid PNG',asset_type.startswith('image/png') and asset_bytes.startswith(b'\x89PNG\r\n\x1a\n') and asset_bytes==image_bytes,{'content_type':asset_type,'bytes':len(asset_bytes)})

def main():
    if q.req('GET', '/__qa_environment') != {'environment': 'isolated-staging', 'database': 'chunlian_qa_20261013'}:
        raise SystemExit('Refusing unmarked environment')
    m = q.good('edge merchant login', q.api('POST', 'merchant/auth/login', {'username':'qa_admin','password':'QaMerchant2026!','device_id':'qa-edge'}))['token']
    c = q.good('edge customer register', q.api('POST', 'customer/auth/register', {'phone':'137'+str(time.time_ns()%100000000).zfill(8),'password':'QaEdge2026!','nickname':'QA Edge'}))['token']
    upload_qa_image(m)
    cat = q.good('edge categories', q.api('GET', 'customer/catalog/categories'))[0]['id']
    pairs = []
    for name, price, inventory in [('QA Edge A',300,50),('QA Edge B',1000,50),('QA Last Stock',200,1)]:
        created = q.good('edge goods create', q.api('POST','merchant/goods',{'title':name,'price_fen':price,'inventory':inventory,'category_id':cat,'spec_text':'QA only','is_shelves':True},m,q.key()))
        gid = str(created.get('goods_id') or created.get('id'))
        g=q.good('edge goods detail',q.api('GET','customer/catalog/goods/'+gid))
        pairs.append((gid,str(g['skus'][0]['sku_id'])))
    ga,sa=pairs[0];gb,sb=pairs[1];gr,sr=pairs[2]
    q.check('SKU update rejects legacy POST method',q.api('POST',f'merchant/goods/{ga}/sku/{sa}',{'price_fen':400},m)['code']=='VALIDATION_FAILED')
    def stock(gid): return q.good('inventory check',q.api('GET','customer/catalog/goods/'+gid))['skus'][0]['inventory']
    def cancel(o): return q.good('edge cancel',q.api('POST','customer/orders/'+o['id']+'/cancel',{'version':o['version']},c,q.key()))
    def pay(o):
        q.good('edge verify prepayment',q.action(m,o,'qr-receipt',amount_fen=o['receivable_fen']))
        return q.detail(c,o['id'])
    def refund(o,amount):
        q.good('edge verify refund',q.action(m,o,'manual-refund',amount_fen=amount))
        return q.detail(c,o['id'])
    def merchant_detail(oid): return q.good('merchant order detail',q.api('GET','merchant/orders/'+oid,token=m))
    def merchant_list_order(oid):
        result=q.good('merchant order list',q.api('GET','merchant/orders?page=1&page_size=100',token=m))
        return next((row for row in result['list'] if str(row['id'])==str(oid)),None)

    # Last inventory can be bought once despite two independent UUIDs.
    payload={'items':[{'sku_id':sr,'qty':1}], 'contact':{'name':'QA Race','phone':'13900139000','address':'QA Race Road'},'payment_method':'COD','delivery_mode':'ASAP'}
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results=list(pool.map(lambda _:q.api('POST','customer/orders',payload,c,q.key()),range(2)))
    q.check('last-unit race has one winner and one stock refusal', sorted(r['code'] for r in results)==['OK','OUT_OF_STOCK'],results)
    q.check('last-unit race stock never negative',stock(gr)==0)
    winner=next(r['data']['order'] for r in results if r['code']=='OK')
    cancel(winner);q.check('last-unit cancel restores one',stock(gr)==1)

    # Initial receipt has no effect from anonymous/customer calls.
    unpaid=q.order(c,sa,2,'OFFLINE_QR')
    q.check('unpaid QR dispatch rejected',q.action(m,unpaid,'deliver')['code']!='OK')
    q.check('customer cannot verify receipt',q.action(c,unpaid,'qr-receipt',amount_fen=600)['code']=='FORBIDDEN')
    q.check('missing receipt key rejected',q.api('POST','merchant/orders/'+unpaid['id']+'/qr-receipt',{'version':unpaid['version'],'amount_fen':600},m)['code']!='OK')
    unpaid=cancel(unpaid)
    q.check('cancelled QR order rejects later receipt confirmation',q.action(m,unpaid,'qr-receipt',amount_fen=600)['code']!='OK')
    unpaid=q.detail(c,unpaid['id'])
    q.check('rejected receipt leaves cancelled order unpaid',unpaid['fulfillment']=='CANCELLED' and unpaid['payment_state']=='UNPAID' and unpaid['paid_fen']==0)
    paid=pay(q.order(c,sa,2,'OFFLINE_QR'));cancel(paid);cancelled=q.detail(c,paid['id'])
    q.check('paid cancellation occupies manual refund',cancelled['fulfillment']=='CANCELLED' and cancelled['refunding_fen']==600 and cancelled['payment_state']=='REFUNDING')
    q.check('cancel refund rejects over-refund',q.action(m,cancelled,'manual-refund',amount_fen=601)['code']!='OK')
    cancelled=refund(cancelled,600)
    q.check('paid cancellation fully refunded',cancelled['payment_state']=='REFUNDED' and cancelled['refunded_fen']==600 and cancelled['refunding_fen']==0)
    q.check('new-key repeat refund rejected',q.action(m,cancelled,'manual-refund',amount_fen=600)['code']!='OK')

    # An applying revision plus cancellation creates two disjoint refund obligations.
    both=pay(q.order(c,sa,4,'OFFLINE_QR'))
    q.good('pending reduction refund',q.revise(c,both,[{'sku_id':sa,'qty':2}]))
    both=q.detail(c,both['id']);cancel(both);both=q.detail(c,both['id'])
    q.check('two pending refunds exactly equal original receipt',both['refunding_fen']==1200)
    refund_detail=merchant_detail(both['id'])['pending_manual_refunds']
    pending_refund_ids={str(r['id']) for r in refund_detail}
    refund_list_order=merchant_list_order(both['id'])
    q.check('merchant detail exposes both pending refund obligations',len(refund_detail)==2 and sorted(r['amount_fen'] for r in refund_detail)==[600,600] and sum(r['amount_fen'] for r in refund_detail)==both['refunding_fen'])
    q.check('merchant order list exposes both pending refunds',refund_list_order is not None and len(refund_list_order['pending_manual_refunds'])==2 and sum(r['amount_fen'] for r in refund_list_order['pending_manual_refunds'])==1200 and {str(r['id']) for r in refund_list_order['pending_manual_refunds']}==pending_refund_ids)
    both=refund(both,600)
    q.check('first refund keeps remaining refund pending',both['payment_state']=='REFUNDING' and both['refunding_fen']==600 and both['refunded_fen']==600)
    refund_detail=merchant_detail(both['id'])['pending_manual_refunds']
    refund_list_order=merchant_list_order(both['id'])
    q.check('first verification removes only its refund obligation',len(refund_detail)==1 and refund_detail[0]['amount_fen']==600 and str(refund_detail[0]['id']) in pending_refund_ids and len({str(r['id']) for r in refund_detail})==1 and refund_list_order is not None and refund_list_order['pending_manual_refunds']==refund_detail and sum(r['amount_fen'] for r in refund_list_order['pending_manual_refunds'])==600)
    both=refund(both,600)
    q.check('both refunds settle without over-refund',both['payment_state']=='REFUNDED' and both['refunded_fen']==1200 and both['refunding_fen']==0)
    q.check('fully verified refunds clear merchant detail and list',merchant_detail(both['id'])['pending_manual_refunds']==[] and merchant_list_order(both['id']) is not None and merchant_list_order(both['id'])['pending_manual_refunds']==[])

    # Dashboard counts are order counts, while payment figures follow the signed ledger contract.
    dashboard_before=q.good('dashboard metrics baseline',q.api('GET','merchant/dashboard',token=m))
    metric_order=pay(q.order(c,sa,2,'OFFLINE_QR'))
    q.good('dashboard refund revision',q.revise(c,metric_order,[{'sku_id':sa,'qty':1}]))
    metric_order=q.detail(c,metric_order['id'])
    dashboard_pending_refund=q.good('dashboard with pending refund',q.api('GET','merchant/dashboard',token=m))
    q.check('refund revision is not counted as pending top-up',dashboard_pending_refund['pending_topup_count']==dashboard_before['pending_topup_count'])
    q.check('pending refund dashboard count counts the order once',dashboard_pending_refund['pending_refund_count']==dashboard_before['pending_refund_count']+1)
    q.check('dashboard QR receipts exclude pending refunds',dashboard_pending_refund['today_offline_qr_received_fen']==dashboard_before['today_offline_qr_received_fen']+600 and dashboard_pending_refund['today_refund_fen']==dashboard_before['today_refund_fen'])
    q.check('C-07 pending manual refund blocks dispatch',q.action(m,metric_order,'deliver')['code']=='TOPUP_REQUIRED')
    blocked=q.detail(c,metric_order['id'])
    q.check('C-07 refused dispatch preserves pending refund order',blocked['version']==metric_order['version'] and blocked['fulfillment']=='PENDING' and blocked['payment_state']==metric_order['payment_state'] and blocked['refunding_fen']==metric_order['refunding_fen'])
    pending_ledger=q.good('pending refund ledger',q.api('GET','merchant/ledger?order_id='+metric_order['id'],token=m))
    q.check('C-07 pending refund does not reduce actual net receipts',pending_ledger['summary']['net_fen']==600 and pending_ledger['summary']['in_flight_count']==1)
    metric_order=refund(metric_order,300)
    dashboard_refund_settled=q.good('dashboard after refund settles',q.api('GET','merchant/dashboard',token=m))
    q.check('settled refund leaves no pending dashboard count',dashboard_refund_settled['pending_refund_count']==dashboard_before['pending_refund_count'] and dashboard_refund_settled['pending_topup_count']==dashboard_before['pending_topup_count'])
    q.check('dashboard preserves QR receipt and signed refund totals',dashboard_refund_settled['today_offline_qr_received_fen']==dashboard_before['today_offline_qr_received_fen']+600 and dashboard_refund_settled['today_refund_fen']==dashboard_before['today_refund_fen']-300)

    # Pending top-up only reserves positive deltas; cancelling restores old and extra quantities.
    start_a,start_b=stock(ga),stock(gb)
    pending=pay(q.order(c,sa,4,'OFFLINE_QR'))
    q.good('mixed SKU top-up',q.revise(c,pending,[{'sku_id':sa,'qty':1},{'sku_id':sb,'qty':2}]))
    pending=q.detail(c,pending['id'])
    q.check('pending decrease not released early',stock(ga)==start_a-4 and stock(gb)==start_b-2)
    q.check('C-05 pending top-up blocks dispatch',q.action(m,pending,'deliver')['code']=='TOPUP_REQUIRED')
    blocked=q.detail(c,pending['id'])
    q.check('C-05 refused dispatch preserves top-up order',blocked['version']==pending['version'] and blocked['fulfillment']=='PENDING' and blocked['revision_state']=='PENDING_TOPUP' and blocked['lines']==pending['lines'])
    q.check('legacy blind commit blocked',q.action(m,pending,'revision/commit-topup')['code']!='OK')
    cancel(pending);pending=q.detail(c,pending['id'])
    q.check('pending top-up cancel restores every reserved unit',stock(ga)==start_a and stock(gb)==start_b)
    refund(pending,1200)

    pending=pay(q.order(c,sa,4,'OFFLINE_QR'))
    q.good('mixed top-up for commit',q.revise(c,pending,[{'sku_id':sa,'qty':1},{'sku_id':sb,'qty':2}]))
    pending=q.detail(c,pending['id'])
    q.good('mixed top-up collect',q.action(m,pending,'qr-receipt',amount_fen=1100))
    pending=q.detail(c,pending['id'])
    q.check('top-up commit releases negative SKU once',stock(ga)==start_a-1 and stock(gb)==start_b-2 and pending['total_fen']==2300 and pending['paid_fen']==2300)
    cancel(pending);pending=q.detail(c,pending['id']);refund(pending,2300)
    q.check('committed mixed cancellation restores all stock',stock(ga)==start_a and stock(gb)==start_b)

    # A dispatch/revision race cannot apply two actions against one version.
    o=q.order(c,sa,1)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        f1=pool.submit(q.action,m,o,'deliver');f2=pool.submit(q.revise,c,o,[{'sku_id':sa,'qty':2}])
        race_results=[f1.result(),f2.result()]
    q.check('dispatch vs revision produces one commit',sum(r['code']=='OK' for r in race_results)==1,race_results)
    after=q.detail(c,o['id']);q.check('dispatch/revision race advances version once',after['version']==o['version']+1)
    race_state_ok=False
    race_cleanup_detail=None
    if after['fulfillment']=='PENDING':
        cleanup_result=q.api('POST','customer/orders/'+o['id']+'/cancel',{'version':after['version']},c,q.key())
        race_cleanup_detail=q.api('GET','customer/orders/'+o['id'],token=c)
        race_state_ok=(cleanup_result.get('code')=='OK' and race_cleanup_detail.get('code')=='OK'
                       and race_cleanup_detail.get('data',{}).get('fulfillment')=='CANCELLED')
    elif after['fulfillment']=='DELIVERING':
        race_state_ok=True
    q.check('race winner leaves a valid dispatch or cancellation state',race_state_ok,
            {'fulfillment':after.get('fulfillment'),'cleanup_code':None if race_cleanup_detail is None else cleanup_result.get('code'),
             'final_fulfillment':None if race_cleanup_detail is None or race_cleanup_detail.get('code')!='OK' else race_cleanup_detail.get('data',{}).get('fulfillment')})

    # C-02: cancellation and a paid-order reduction contend for one version.
    initial_stock=stock(ga)
    both=pay(q.order(c,sa,4,'OFFLINE_QR'))
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        f1=pool.submit(q.revise,c,both,[{'sku_id':sa,'qty':2}])
        f2=pool.submit(q.api,'POST','customer/orders/'+both['id']+'/cancel',{'version':both['version']},c,q.key())
        outcomes=[f1.result(),f2.result()]
    q.check('C-02 revision/cancel race commits exactly once',sum(r['code']=='OK' for r in outcomes)==1 and all(r['code'] in ('OK','ORDER_VERSION_CONFLICT') for r in outcomes),outcomes)
    after=q.detail(c,both['id'])
    q.check('C-02 race advances the order version once',after['version']==both['version']+1)
    if after['fulfillment']!='CANCELLED':
        cancel(after)
        after=q.detail(c,after['id'])
    q.check('C-02 final cancellation reserves exactly the original paid amount',after['fulfillment']=='CANCELLED' and after['refunding_fen']==1200 and after['refunded_fen']==0)
    obligations=merchant_detail(after['id'])['pending_manual_refunds']
    q.check('C-02 refund obligations total original receipt without overlap',sum(r['amount_fen'] for r in obligations)==1200)
    for obligation in obligations:
        after=refund(after,obligation['amount_fen'])
    q.check('C-02 race refunds fully without double refund',after['refunded_fen']==1200 and after['refunding_fen']==0 and after['payment_state']=='REFUNDED')
    q.check('C-02 cancellation releases each inventory unit once',stock(ga)==initial_stock)

    # C-03: even distinct concurrent receipt keys cannot double-collect a QR order.
    receipt=q.order(c,sa,1,'OFFLINE_QR')
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        receipts=list(pool.map(lambda _:q.action(m,receipt,'qr-receipt',amount_fen=300),range(2)))
    q.check('C-03 concurrent QR receipt has one winner',sum(r['code']=='OK' for r in receipts)==1 and all(r['code'] in ('OK','ORDER_VERSION_CONFLICT') for r in receipts),receipts)
    receipt=q.detail(c,receipt['id'])
    ledger=q.good('C-03 concurrent receipt ledger',q.api('GET','merchant/ledger?order_id='+receipt['id'],token=m))
    q.check('C-03 exactly one successful receipt ledger entry',len(ledger['list'])==1 and ledger['list'][0]['kind']=='OFFLINE_RECEIPT' and ledger['list'][0]['status']=='SUCCESS' and ledger['summary']['net_fen']==300 and receipt['paid_fen']==300)
    cancel(receipt)
    refund(q.detail(c,receipt['id']),300)

    # C-04: concurrent delivery confirmations and COD receipts commit once.
    cod=q.order(c,sa,1)
    q.good('C-04 dispatch COD',q.action(m,cod,'deliver'))
    cod=q.detail(c,cod['id'])
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        delivered=list(pool.map(lambda _:q.action(m,cod,'confirm-delivery',collected_on_delivery=False),range(2)))
    q.check('C-04 concurrent delivery confirms once',sum(r['code']=='OK' for r in delivered)==1 and all(r['code'] in ('OK','ORDER_VERSION_CONFLICT') for r in delivered),delivered)
    delivered_order=q.detail(c,cod['id'])
    q.check('C-04 delivery leaves one version and unpaid receivable',delivered_order['version']==cod['version']+1 and delivered_order['fulfillment']=='DELIVERED' and delivered_order['payment_state']=='UNPAID' and delivered_order['receivable_fen']==300)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        collected=list(pool.map(lambda _:q.action(m,delivered_order,'cod-receipt',collected_fen=300,collection_method='CASH'),range(2)))
    q.check('C-04 concurrent COD receipts commit once',sum(r['code']=='OK' for r in collected)==1 and all(r['code'] in ('OK','ORDER_VERSION_CONFLICT') for r in collected),collected)
    cod=q.detail(c,cod['id'])
    ledger=q.good('C-04 COD ledger',q.api('GET','merchant/ledger?order_id='+cod['id'],token=m))
    q.check('C-04 COD paid once with one successful ledger entry',cod['paid_fen']==300 and cod['payment_state']=='PAID' and cod['version']==delivered_order['version']+1 and len(ledger['list'])==1 and ledger['list'][0]['kind']=='COD_RECEIPT' and ledger['summary']['net_fen']==300)

    # C-06: a rejected mixed-SKU revision preserves both stock and the active order.
    unchanged=q.order(c,sa,1)
    stock_a,stock_last=stock(ga),stock(gr)
    rejected=q.revise(c,unchanged,[{'sku_id':sa,'qty':3},{'sku_id':sr,'qty':2}])
    q.check('C-06 mixed revision with insufficient stock is rejected',rejected['code']=='OUT_OF_STOCK',rejected)
    after=q.detail(c,unchanged['id'])
    q.check('C-06 failed revision leaves version lines and total unchanged',after['version']==unchanged['version'] and after['lines']==unchanged['lines'] and after['total_fen']==unchanged['total_fen'])
    q.check('C-06 failed revision keeps all SKU inventory unchanged',stock(ga)==stock_a and stock(gr)==stock_last)
    cancel(after)

    # Price rises across multiple edits do not overwrite original bought quantities.
    snapshot=q.order(c,sa,2)
    q.good('raise price',q.api('PATCH',f'merchant/goods/{ga}/sku/{sa}',{'price_fen':400},m))
    q.good('first price split',q.revise(c,snapshot,[{'sku_id':sa,'qty':3}]))
    snapshot=q.detail(c,snapshot['id']);q.check('first split keeps 2x300 plus 1x400',snapshot['total_fen']==1000)
    history=q.good('split price revision history',q.api('GET','merchant/orders/'+snapshot['id']+'/revisions',token=m))
    q.check('split price history sums SKU quantities',history['list'][-1]['summary']==f'{sa} ×2 → ×3',history['list'][-1]['summary'])
    q.good('second increment preserves split',q.revise(c,snapshot,[{'sku_id':sa,'qty':4}]))
    snapshot=q.detail(c,snapshot['id'])
    price_qty={}
    for line in snapshot['lines']:price_qty[line['unit_fen']]=price_qty.get(line['unit_fen'],0)+line['qty']
    q.check('second split remains 2x300 plus 2x400',snapshot['total_fen']==1400 and price_qty=={300:2,400:2},snapshot['lines'])
    q.good('archive historical product',q.api('DELETE','merchant/goods/'+ga,token=m))
    q.check('archive hides product',q.api('GET','customer/catalog/goods/'+ga)['code']!='OK')
    q.good('reduce archived snapshot',q.revise(c,snapshot,[{'sku_id':sa,'qty':1}]))
    snapshot=q.detail(c,snapshot['id']);q.check('archived snapshot can decrease without repricing',snapshot['total_fen']==300)
    cancel(snapshot)
    q.check('foreign payment method WECHAT unsupported',q.api('POST','customer/orders',{**payload,'items':[{'sku_id':sb,'qty':1}],'payment_method':'WECHAT'},c,q.key())['code']!='OK')

if __name__=='__main__':
    try:main()
    except Exception as e:q.check('edge suite completion',False,str(e))
    Path('docs/evidence/qa-edge.json').write_text(json.dumps({'target':q.BASE,'timestamp':datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'script_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        'business_helper_sha256':hashlib.sha256(Path(__file__).with_name('qa-business.py').read_bytes()).hexdigest(),
        'tests':q.report},ensure_ascii=False,indent=2),encoding='utf-8')
    passed=sum(r['passed'] for r in q.report);print(f'{passed}/{len(q.report)} passed')
    raise SystemExit(0 if passed==len(q.report) else 1)
