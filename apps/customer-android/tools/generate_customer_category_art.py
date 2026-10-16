"""Export the customer category icon sprite into Android drawable assets."""

from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
SOURCE = Path(__file__).with_name("customer-category-icon-sprite.png")
OUTPUT = ROOT / "app/src/main/res/drawable-nodpi"

# The source sprite is a uniform 3x4 grid, read left to right and top to bottom.
ICON_FILES = (
    "cust_cat_vegetables.png",
    "cust_cat_meat.png",
    "cust_cat_egg_dairy.png",
    "cust_cat_seafood.png",
    "cust_cat_prepared.png",
    "cust_cat_fruit.png",
    "cust_cat_grain_oil.png",
    "cust_cat_sale.png",
    "cust_cat_roots.png",
    "cust_cat_mushrooms.png",
    "cust_cat_seasoning.png",
    "cust_cat_fresh.png",
)

COLUMNS = 3
ROWS = 4
ICON_SIZE = 96
ICON_INSET = 5


def export_cell(cell: Image.Image, destination: Path, size: int, inset: int) -> None:
    """Scale a full cell into a padded transparent square, preserving its alpha."""
    alpha = cell.getchannel("A").point(lambda value: 0 if value < 8 else value)
    cell.putalpha(alpha)
    content_size = size - inset * 2
    cell = cell.resize((content_size, content_size), Image.Resampling.LANCZOS)
    canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    canvas.alpha_composite(cell, (inset, inset))
    canvas.save(destination, format="PNG", optimize=True)


def main() -> None:
    if not SOURCE.is_file():
        raise FileNotFoundError(f"Missing source sprite: {SOURCE}")

    sprite = Image.open(SOURCE).convert("RGBA")
    width, height = sprite.size
    if width < COLUMNS or height < ROWS:
        raise ValueError(f"Sprite size {sprite.size} is too small for a {COLUMNS}x{ROWS} grid")

    OUTPUT.mkdir(parents=True, exist_ok=True)
    cells = []
    for index, filename in enumerate(ICON_FILES):
        row, column = divmod(index, COLUMNS)
        left = round(column * width / COLUMNS)
        right = round((column + 1) * width / COLUMNS)
        top = round(row * height / ROWS)
        bottom = round((row + 1) * height / ROWS)
        cell = sprite.crop((left, top, right, bottom))
        cell_width, cell_height = cell.size
        side = min(cell_width, cell_height)
        x = (cell_width - side) // 2
        y = (cell_height - side) // 2
        cell = cell.crop((x, y, x + side, y + side))
        cells.append(cell.copy())
        export_cell(cell, OUTPUT / filename, ICON_SIZE, ICON_INSET)

    # Reuse the assorted-produce cell for the larger product-image fallback.
    export_cell(cells[-1], OUTPUT / "cust_product_fallback.png", 320, 16)
    print(f"Generated {len(ICON_FILES) + 1} customer illustrations in {OUTPUT}")


if __name__ == "__main__":
    main()
