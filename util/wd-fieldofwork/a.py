
import csv
import json
import re
import html
import argparse
from pathlib import Path


def load_items(csv_path):
    items = []

    with open(csv_path, "r", encoding="utf-8-sig", newline="") as f:
        reader = csv.reader(f)

        for row in reader:
            if not row:
                continue

            # Support both ordinary CSV and lines quoted as a whole.
            if len(row) == 1 and "," in row[0]:
                row = row[0].split(",", 1)

            if len(row) < 2:
                continue

            url = row[0].strip().strip('"').strip()
            label = row[1].strip().rstrip("\\").strip().strip('"')

            # Undo escaped punctuation found in some CSV exports.
            url = re.sub(r"\\([:/\.])", r"\1", url)

            match = re.search(r"/entity/(Q\d+)", url)
            if not match:
                match = re.search(r"\b(Q\d+)\b", url)

            if not match:
                continue

            qid = match.group(1)

            items.append({
                "qid": qid,
                "label": label,
                "url": f"https://www.wikidata.org/wiki/{qid}",
            })

    return items


def generate_html(items, output_path, page_size):
    # Safely embed data in JavaScript.
    data = json.dumps(items, ensure_ascii=False)
    data = (
        data.replace("<", "\\u003c")
        .replace(">", "\\u003e")
        .replace("&", "\\u0026")
    )

    template = r"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wikidata Item Browser</title>
<style>
    body {
        font-family: system-ui, sans-serif;
        max-width: 760px;
        margin: 40px auto;
        padding: 0 20px;
        color: #202122;
        background: #f8f9fa;
    }
    h1 { font-size: 1.7rem; }
    #status { color: #54595d; margin-bottom: 16px; }
    #items { display: grid; gap: 10px; }
    .item {
        display: block;
        padding: 15px 18px;
        border: 1px solid #c8ccd1;
        border-radius: 8px;
        background: white;
        color: inherit;
        text-decoration: none;
        overflow-wrap: anywhere;
    }
    .item:hover {
        border-color: #36c;
        background: #f1f5ff;
    }
    .label { font-weight: 600; }
    .qid {
        display: block;
        margin-top: 5px;
        font-size: 0.85rem;
        color: #54595d;
    }
    button {
        display: block;
        margin: 22px auto;
        padding: 12px 24px;
        border: 0;
        border-radius: 6px;
        background: #36c;
        color: white;
        font-size: 1rem;
        cursor: pointer;
    }
    button:hover { background: #2a4b8d; }
    button:disabled { background: #72777d; cursor: default; }
</style>
</head>
<body>
<h1>Wikidata Item Browser</h1>
<div id="status"></div>
<div id="items"></div>
<button id="loadMore">Load more</button>

<script>
const allItems = __DATA__;
const pageSize = __PAGE_SIZE__;

const container = document.getElementById("items");
const status = document.getElementById("status");
const button = document.getElementById("loadMore");

let position = 0;

// Fisher-Yates shuffle: random order for each page load.
for (let i = allItems.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [allItems[i], allItems[j]] = [allItems[j], allItems[i]];
}

function loadMore() {
    const end = Math.min(position + pageSize, allItems.length);
    const fragment = document.createDocumentFragment();

    for (; position < end; position++) {
        const item = allItems[position];

        const link = document.createElement("a");
        link.className = "item";
        link.href = item.url;
        link.target = "_blank";
        link.rel = "noopener noreferrer";

        const label = document.createElement("span");
        label.className = "label";
        label.textContent = item.label || "(no label)";

        const qid = document.createElement("span");
        qid.className = "qid";
        qid.textContent = item.qid;

        link.append(label, qid);
        fragment.appendChild(link);
    }

    container.appendChild(fragment);

    status.textContent =
        `Showing ${position} of ${allItems.length} items`;

    button.disabled = position >= allItems.length;
    button.textContent = button.disabled ? "All items loaded" : "Load more";
}

button.addEventListener("click", loadMore);

if (allItems.length === 0) {
    status.textContent = "No valid items found in the CSV.";
    button.hidden = true;
} else {
    loadMore();
}
</script>
</body>
</html>
"""

    page = (
        template
        .replace("__DATA__", data)
        .replace("__PAGE_SIZE__", str(page_size))
    )

    Path(output_path).write_text(page, encoding="utf-8")
    print(f"Generated {output_path} with {len(items)} items.")


def main():
    parser = argparse.ArgumentParser(
        description="Convert a Wikidata CSV into a randomized HTML browser."
    )
    parser.add_argument("csv_file", help="Input CSV file")
    parser.add_argument(
        "-o", "--output",
        default="index.html",
        help="Output HTML filename (default: index.html)",
    )
    parser.add_argument(
        "--page-size",
        type=int,
        default=50,
        help="Number of items loaded at a time (default: 50)",
    )

    args = parser.parse_args()

    if args.page_size < 1:
        parser.error("--page-size must be at least 1")

    items = load_items(args.csv_file)
    generate_html(items, args.output, args.page_size)


if __name__ == "__main__":
    main()
