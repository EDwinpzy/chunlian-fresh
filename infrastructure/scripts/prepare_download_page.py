"""Render the first-install page and campaign image into the Android release folder."""
import html
import json
from pathlib import Path
import re
import shutil


PACKAGES = {
    "com.chunlian.customer": ("customer-release.apk", "{{CUSTOMER_VERSION}}"),
    "com.chunlian.merchant": ("merchant-release.apk", "{{MERCHANT_VERSION}}"),
}
PAGE_ASSETS = {
    "customer-app-icon.png": "apps/customer-android/app/src/main/res/mipmap-nodpi/ic_launcher.png",
    "merchant-app-icon.png": "apps/merchant-android/app/src/main/res/mipmap-nodpi/ic_launcher.png",
    "customer-category.png": "apps/customer-web/public/assets/icons/customer/category.png",
    "customer-orders.png": "apps/customer-web/public/assets/icons/customer/orders.png",
    "merchant-products.png": "apps/merchant-android/app/src/main/res/drawable-nodpi/merchant_product.png",
}
NOTES_TOKEN = "{{RELEASE_NOTES}}"
VERSION_PATTERN = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,79}")


def render_download_page(root: Path, manifest: dict) -> tuple[Path, Path]:
    packages = manifest.get("packages")
    if not isinstance(packages, list):
        raise ValueError("Android release manifest packages must be a list")

    by_application_id = {}
    for package in packages:
        if not isinstance(package, dict):
            raise ValueError("Android release package entries must be objects")
        application_id = package.get("application_id")
        expected = PACKAGES.get(application_id)
        if expected is None or application_id in by_application_id:
            raise ValueError("Android release manifest has an unexpected or duplicate application ID")
        filename, _ = expected
        version = package.get("version_name")
        if package.get("file") != filename or not isinstance(version, str) or VERSION_PATTERN.fullmatch(version) is None:
            raise ValueError(f"Invalid release metadata for {application_id}")
        notes = package.get("release_notes", "")
        if not isinstance(notes, str):
            raise ValueError(f"Invalid release notes for {application_id}")
        by_application_id[application_id] = package

    if set(by_application_id) != set(PACKAGES):
        raise ValueError("Android release manifest must contain both customer and merchant apps")

    notes_values = {package.get("release_notes", "").strip() for package in by_application_id.values()}
    notes = "；".join(value for value in sorted(notes_values) if value) or "顾客版与店主版已更新，欢迎下载体验。"
    template_path = root / "infrastructure/download-page/index.template.html"
    source_preview = root / "infrastructure/download-page/index.html"
    image_path = root / "infrastructure/download-page/assets/spring-fresh-campaign.jpg"
    missing_assets = [str(root / path) for path in PAGE_ASSETS.values() if not (root / path).is_file()]
    if not template_path.is_file() or not image_path.is_file() or missing_assets:
        missing = [str(path) for path in (template_path, image_path) if not path.is_file()] + missing_assets
        raise FileNotFoundError("Download page template or image assets are missing: " + ", ".join(missing))

    page = template_path.read_text(encoding="utf-8")
    substitutions = {
        "{{CUSTOMER_VERSION}}": by_application_id["com.chunlian.customer"]["version_name"],
        "{{MERCHANT_VERSION}}": by_application_id["com.chunlian.merchant"]["version_name"],
        NOTES_TOKEN: notes,
    }
    for token, value in substitutions.items():
        if page.count(token) != 1:
            raise ValueError(f"Download page template must contain {token} exactly once")
        page = page.replace(token, html.escape(value, quote=True))
    if "{{" in page or "}}" in page:
        raise ValueError("Download page template contains an unresolved placeholder")

    release_dir = root / "dist/release"
    release_dir.mkdir(parents=True, exist_ok=True)
    output_page = release_dir / "index.html"
    compatibility_page = release_dir / "download-index.html"
    assets_dir = release_dir / "assets"
    assets_dir.mkdir(parents=True, exist_ok=True)
    output_image = assets_dir / "spring-fresh-campaign.jpg"
    output_page.write_text(page, encoding="utf-8", newline="\n")
    source_preview.write_text(page, encoding="utf-8", newline="\n")
    shutil.copyfile(output_page, compatibility_page)
    shutil.copyfile(image_path, output_image)
    for filename, source in PAGE_ASSETS.items():
        shutil.copyfile(root / source, assets_dir / filename)
    return output_page, output_image


def main() -> None:
    root = Path(__file__).resolve().parents[2]
    manifest_path = root / "dist/release/android-release.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    page, image = render_download_page(root, manifest)
    print(json.dumps({"index_page": str(page), "local_preview": str(root / "infrastructure/download-page/index.html"), "template": str(root / "infrastructure/download-page/index.template.html"), "download_index_compatibility_copy": str(root / "dist/release/download-index.html"), "campaign_image": str(image), "page_assets": str(image.parent)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
