"""ADB UI helper: all taps derive from the current UIAutomator hierarchy."""
import argparse
from pathlib import Path
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

sys.stdout.reconfigure(encoding='utf-8')

adb = r'C:\Users\63054\AppData\Local\Android\Sdk\platform-tools\adb.exe'
p = argparse.ArgumentParser(); p.add_argument('action', choices=['tree','tap','fill','shot','swipe','back'])
p.add_argument('value', nargs='?', default=''); p.add_argument('--serial', default='emulator-5554'); p.add_argument('--index', type=int, default=0)
a = p.parse_args()
def run(*args): return subprocess.check_output([adb,'-s',a.serial,*args])
def tree():
    raw=run('exec-out','uiautomator','dump','/dev/tty').decode('utf-8')
    return ET.fromstring(raw[raw.index('<?xml'):raw.index('</hierarchy>')+12])
if a.action == 'shot':
    target=Path('docs/evidence')/(a.value+'.png');target.parent.mkdir(parents=True,exist_ok=True)
    target.write_bytes(run('exec-out','screencap','-p'));print(target.resolve())
elif a.action == 'back': run('shell','input','keyevent','4')
elif a.action == 'swipe':
    nodes=[n for n in tree().iter('node') if n.get('scrollable')=='true']
    if not nodes: raise SystemExit('No scrollable node')
    bounds=list(map(int,re.findall(r'\d+',nodes[a.index].get('bounds'))));x1,y1,x2,y2=bounds
    x=(x1+x2)//2;top=y1+(y2-y1)//4;bottom=y1+(y2-y1)*3//4
    run('shell','input','swipe',str(x),str(bottom if a.value!='down' else top),str(x),str(top if a.value!='down' else bottom),'350')
else:
    root=tree(); nodes=list(root.iter('node'))
    if a.action=='tree':
        for i,n in enumerate(nodes):
            text=n.get('text') or n.get('content-desc')
            if text or n.get('class')=='android.widget.EditText': print(i,n.get('class').split('.')[-1],repr(text),n.get('bounds'), 'enabled='+n.get('enabled'))
    elif a.action=='tap':
        candidates=[n for n in nodes if a.value in [n.get('text'),n.get('content-desc')]]
        if not candidates: raise SystemExit('Target missing: '+a.value)
        bounds=list(map(int,re.findall(r'\d+',candidates[a.index].get('bounds'))));x1,y1,x2,y2=bounds
        run('shell','input','tap',str((x1+x2)//2),str((y1+y2)//2))
    else:
        fields=[n for n in nodes if n.get('class')=='android.widget.EditText']
        n=fields[a.index];x1,y1,x2,y2=map(int,re.findall(r'\d+',n.get('bounds')))
        run('shell','input','tap',str((x1+x2)//2),str((y1+y2)//2))
        run('shell','input','keyevent','123')
        run('shell','input','keyevent',*(['67'] * (len(n.get('text', '')) + 1)))
        run('shell','input','text',a.value.replace(' ','%s'))
        run('shell','input','keyevent','4')
