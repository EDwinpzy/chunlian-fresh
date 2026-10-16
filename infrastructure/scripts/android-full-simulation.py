"""Evidence-first native Android QA helper.

All taps are derived from a freshly saved UIAutomator hierarchy. Text entered into
fields is intentionally not written to the action log.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from xml.etree import ElementTree as ET

sys.stdout.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "docs" / "evidence" / "android-full-2026-10-14"
ADB = Path(r"C:\Users\63054\AppData\Local\Android\Sdk\platform-tools\adb.exe")
SERIALS = {
    "customer": os.environ.get("CHUNLIAN_QA_CUSTOMER_SERIAL", "emulator-5554"),
    "merchant": os.environ.get("CHUNLIAN_QA_MERCHANT_SERIAL", "emulator-5556"),
}


def adb(serial: str, *args: str) -> bytes:
    return subprocess.check_output([str(ADB), "-s", serial, *args], stderr=subprocess.STDOUT)


def hierarchy(serial: str, device: str, step: str) -> tuple[ET.Element, Path]:
    raw = adb(serial, "exec-out", "uiautomator", "dump", "/dev/tty").decode("utf-8", "replace")
    start, end = raw.find("<?xml"), raw.find("</hierarchy>")
    if start < 0 or end < 0:
        raise RuntimeError("uiautomator did not return a complete hierarchy")
    xml = raw[start : end + len("</hierarchy>")]
    path = OUT / "trees" / f"{device}-{step}.xml"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(xml, encoding="utf-8")
    return ET.fromstring(xml), path


def record(event: dict) -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    event["at"] = datetime.now(timezone.utc).isoformat()
    with (OUT / "ui-actions.jsonl").open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(event, ensure_ascii=False) + "\n")


def safe_name(value: str) -> str:
    return re.sub(r"[^A-Za-z0-9_.-]+", "-", value).strip("-_") or "step"


def select_device(name: str) -> tuple[str, str]:
    if name not in SERIALS:
        raise SystemExit(f"unknown device: {name}")
    return name, SERIALS[name]


def bounds_center(node: ET.Element) -> tuple[int, int, str]:
    value = node.get("bounds", "")
    nums = [int(n) for n in re.findall(r"\d+", value)]
    if len(nums) != 4:
        raise RuntimeError(f"node has invalid bounds: {value!r}")
    x1, y1, x2, y2 = nums
    return (x1 + x2) // 2, (y1 + y2) // 2, value


def capture(device: str, serial: str, step: str) -> None:
    root, tree_path = hierarchy(serial, device, step)
    image_path = OUT / f"{device}-{step}.png"
    image_path.parent.mkdir(parents=True, exist_ok=True)
    image_path.write_bytes(adb(serial, "exec-out", "screencap", "-p"))
    texts = [
        node.get("text") or node.get("content-desc")
        for node in root.iter("node")
        if node.get("text") or node.get("content-desc")
    ]
    record({"device": device, "action": "capture", "step": step, "tree": str(tree_path.relative_to(ROOT)), "screenshot": str(image_path.relative_to(ROOT)), "visible_text": texts})
    print(f"{image_path.relative_to(ROOT)}\n{tree_path.relative_to(ROOT)}")


parser = argparse.ArgumentParser()
parser.add_argument("action", choices=["capture", "tap", "fill", "back", "swipe"])
parser.add_argument("device", choices=SERIALS)
parser.add_argument("step", help="short evidence/action name")
parser.add_argument("value", nargs="?", default="", help="exact UI text, field content, or swipe direction")
parser.add_argument("--index", type=int, default=0, help="duplicate text node, text field, or scrollable index")
parser.add_argument("--contains", action="store_true", help="match a text/content description substring")
args = parser.parse_args()

device, serial = select_device(args.device)
step = safe_name(args.step)

if args.action == "capture":
    capture(device, serial, step)
elif args.action == "back":
    root, tree_path = hierarchy(serial, device, step)
    adb(serial, "shell", "input", "keyevent", "4")
    record({"device": device, "action": "back", "tree": str(tree_path.relative_to(ROOT))})
else:
    root, tree_path = hierarchy(serial, device, step)
    nodes = list(root.iter("node"))
    if args.action == "tap":
        matcher = (lambda value: args.value in value) if args.contains else (lambda value: value == args.value)
        matches = [node for node in nodes if matcher(node.get("text", "")) or matcher(node.get("content-desc", ""))]
        if args.index >= len(matches):
            raise SystemExit(f"target not found: {args.value!r}; match_count={len(matches)}; tree={tree_path}")
        node = matches[args.index]
        x, y, box = bounds_center(node)
        adb(serial, "shell", "input", "tap", str(x), str(y))
        record({"device": device, "action": "tap", "target": args.value, "index": args.index, "bounds": box, "tree": str(tree_path.relative_to(ROOT))})
        print(f"Tapped {args.value!r} at UI-tree center {x},{y}; {box}")
    elif args.action == "fill":
        fields = [node for node in nodes if node.get("class") == "android.widget.EditText"]
        if args.index >= len(fields):
            raise SystemExit(f"text field index not found: {args.index}; field_count={len(fields)}; tree={tree_path}")
        node = fields[args.index]
        x, y, box = bounds_center(node)
        adb(serial, "shell", "input", "tap", str(x), str(y))
        adb(serial, "shell", "input", "keyevent", "123")
        adb(serial, "shell", "input", "keyevent", *(["67"] * (len(node.get("text", "")) + 1)))
        adb(serial, "shell", "input", "text", args.value.replace(" ", "%s"))
        adb(serial, "shell", "input", "keyevent", "4")
        record({"device": device, "action": "fill", "field_index": args.index, "bounds": box, "tree": str(tree_path.relative_to(ROOT)), "value": "[redacted]"})
        print(f"Filled field {args.index} at {box}; value redacted")
    else:
        scrollables = [node for node in nodes if node.get("scrollable") == "true"]
        if args.index >= len(scrollables):
            raise SystemExit(f"scrollable index not found: {args.index}; scrollable_count={len(scrollables)}; tree={tree_path}")
        x1, y1, x2, y2 = [int(n) for n in re.findall(r"\d+", scrollables[args.index].get("bounds", ""))]
        x, top, bottom = (x1 + x2) // 2, y1 + (y2 - y1) // 4, y1 + (y2 - y1) * 3 // 4
        start, finish = (bottom, top) if args.value != "down" else (top, bottom)
        adb(serial, "shell", "input", "swipe", str(x), str(start), str(x), str(finish), "350")
        record({"device": device, "action": "swipe", "direction": args.value or "up", "index": args.index, "tree": str(tree_path.relative_to(ROOT))})
        print(f"Swiped UI-tree scrollable node {args.index}; direction={args.value or 'up'}")
