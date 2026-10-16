"""Generate matching complete SVG and Android vector UI icons (24-unit canvas)."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
# Complete paths inside the viewport; identical geometry in both clients.
SHAPES = {
    'home': [('M3,10 L12,3 L21,10 L19,10 L19,21 L5,21 L5,10 Z', 'main'), ('M10,21 L10,15 Q12,12 14,15 L14,21 Z', 'accent')],
    'category': [('M3,3 L10,3 L10,10 L3,10 Z M14,3 L21,3 L21,10 L14,10 Z M3,14 L10,14 L10,21 L3,21 Z', 'main'), ('M14,14 L21,14 L21,21 L14,21 Z', 'accent')],
    'cart': [('M2,3 L5,3 L8,16 L19,16 L22,6 L7,6 L7.6,9 L18.2,9 L16.8,13 L10.3,13 L7,3 Z', 'main'), ('M8,20 a2,2 0,1 0,4,0 a2,2 0,1 0,-4,0 M16,20 a2,2 0,1 0,4,0 a2,2 0,1 0,-4,0', 'accent')],
    'profile': [('M8,7 a4,4 0,1 0,8,0 a4,4 0,1 0,-8,0 M4,21 L4,18 C4,10 20,10 20,18 L20,21 Z', 'main')],
    'search': [('M3,10 a7,7 0,1 0,14,0 a7,7 0,1 0,-14,0 M6,10 a4,4 0,1 1,8,0 a4,4 0,1 1,-8,0', 'main'), ('M14,16 L16,14 L22,20 L20,22 Z', 'accent')],
    'product_detail': [('M4,2 L16,2 L20,6 L20,22 L4,22 Z', 'main'), ('M7,7 L14,7 L14,9 L7,9 Z M7,12 L17,12 L17,14 L7,14 Z M7,17 L13,17 L13,19 L7,19 Z', 'white')],
    'refund': [('M3,10 C3,0 20,0 21,10 L23,10 L19,15 L15,10 L18,10 C17,4 7,4 6,10 Z M21,14 C21,24 4,24 3,14 L1,14 L5,9 L9,14 L6,14 C7,20 17,20 18,14 Z', 'accent'), ('M9,8 L12,11 L15,8 L16,9 L13,12 L15,12 L15,14 L13,14 L13,15 L15,15 L15,17 L13,17 L13,19 L11,19 L11,17 L9,17 L9,15 L11,15 L11,14 L9,14 L9,12 L11,12 L8,9 Z', 'main')],
}

def output(name, shapes, active=True):
    colors = {'main': '#16834A' if active else '#84959F', 'accent': '#F59A23' if active else '#84959F', 'white': '#FFFFFF'}
    svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' + ''.join(f'<path fill="{colors[c]}" fill-rule="evenodd" d="{p}"/>' for p,c in shapes) + '</svg>\n'
    xml = '<vector xmlns:android="http://schemas.android.com/apk/res/android" android:width="24dp" android:height="24dp" android:viewportWidth="24" android:viewportHeight="24">' + ''.join(f'<path android:fillColor="{colors[c]}" android:fillType="evenOdd" android:pathData="{p}"/>' for p,c in shapes) + '</vector>\n'
    tab = name in ('home','category','cart','profile','search')
    suffix = 'active' if active else 'normal'
    web = ROOT / 'apps/customer-web/public/assets' / ('tabbar' if tab else 'common') / (f'{name}-{suffix}.svg' if tab else f'{name}.svg')
    android_name = f'customer_tab_{name}_{suffix}' if tab else f'customer_common_{name}'
    web.write_text(svg, encoding='utf-8')
    web.with_suffix('.png').unlink(missing_ok=True)
    res = ROOT / 'apps/customer-android/app/src/main/res/drawable-nodpi'
    (res / f'{android_name}.png').unlink(missing_ok=True)
    (res / f'{android_name}.xml').write_text(xml, encoding='utf-8')
    if name == 'home':
        merchant = ROOT / 'apps/merchant-android/app/src/main/res/drawable-nodpi'
        (merchant / f'brand_dashboard_{suffix}.png').unlink(missing_ok=True)
        (merchant / f'brand_dashboard_{suffix}.xml').write_text(xml, encoding='utf-8')

for name, shapes in SHAPES.items():
    output(name, shapes)
    if name in ('home','category','cart','profile','search'):
        output(name, shapes, False)
print('Generated 12 complete matching UI icons for web and Android.')
