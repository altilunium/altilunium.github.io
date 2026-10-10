
import csv
import html
import re
import sys
from pathlib import Path
from urllib.parse import unquote

INPUT_FILE = "items.csv"
OUTPUT_FILE = "index.html"
PAGE_SIZE = 100


def parse_count(value):
    match = re.search(r"\d+", value.strip())
    return int(match.group()) if match else 0


def read_items(filename):
    items = []

    with open(filename, "r", encoding="utf-8-sig", newline="") as f:
        for row in csv.reader(f):
            if len(row) < 3:
                continue

            uri = row[0].strip().replace(r"\:", ":")
            label = row[1].strip()
            count = parse_count(row[2])

            # Convert the Wikidata entity URI into a readable URL.
            uri = re.sub(r"\\([.:/])", r"\1", uri)
            uri = unquote(uri)

            items.append({
                "uri": uri,
                "label": label,
                "count": count,
            })

    items.sort(key=lambda item: item["count"], reverse=True)
    return items


def generate_html(items):
    # Escape data before embedding it in JavaScript.
    import json
    data = json.dumps(items, ensure_ascii=False)
    data = data.replace("<", "\\u003c").replace(">", "\\u003e")
    data = data.replace("&", "\\u0026")

    template = r"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wikidata Items by Count</title>
<style>
    body {
        max-width: 900px;
        margin: 40px auto;
        padding: 0 20px;
        font-family: system-ui, sans-serif;
        line-height: 1.5;
        color: #222;
    }
    h1 { margin-bottom: 8px; }
    #summary { color: #666; margin-bottom: 24px; }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 10px 12px;
        border-bottom: 1px solid #ddd;
        text-align: left;
    }
    th { background: #f5f5f5; }
    td.rank, td.count { text-align: right; font-variant-numeric: tabular-nums; }
    td.rank { color: #777; width: 55px; }
    td.count { white-space: nowrap; }
    a { color: #2457a7; text-decoration: none; }
    a:hover { text-decoration: underline; }
    #loadMore {
        display: block;
        margin: 24px auto;
        padding: 12px 24px;
        font-size: 1rem;
        cursor: pointer;
    }
    #loadMore:disabled { cursor: default; opacity: .5; }
    @media (max-width: 600px) {
        body { margin-top: 20px; padding: 0 10px; }
        th, td { padding: 8px 5px; }
    }
</style>
</head>
<body>
<h1>Wikidata Items by Count</h1>
<div id="summary"></div>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Item</th>
            <th style="text-align:right">Count</th>
        </tr>
    </thead>
    <tbody id="results"></tbody>
</table>
<button id="loadMore">Load more</button>

<script>
const items = __DATA__;
const pageSize = __PAGE_SIZE__;
let displayed = 0;

const results = document.getElementById("results");
const button = document.getElementById("loadMore");
const summary = document.getElementById("summary");

function loadMore() {
    const end = Math.min(displayed + pageSize, items.length);
    const fragment = document.createDocumentFragment();

    for (let i = displayed; i < end; i++) {
        const item = items[i];
        const tr = document.createElement("tr");

        const rank = document.createElement("td");
        rank.className = "rank";
        rank.textContent = i + 1;

        const name = document.createElement("td");
        const link = document.createElement("a");
        link.href = item.uri;
        link.textContent = item.label || item.uri;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        name.appendChild(link);

        const count = document.createElement("td");
        count.className = "count";
        count.textContent = Number(item.count).toLocaleString();

        tr.append(rank, name, count);
        fragment.appendChild(tr);
    }

    results.appendChild(fragment);
    displayed = end;

    summary.textContent =
        `Showing ${displayed.toLocaleString()} of ${items.length.toLocaleString()} items`;

    button.hidden = displayed >= items.length;
}

button.addEventListener("click", loadMore);

if (items.length === 0) {
    summary.textContent = "No items found.";
    button.hidden = true;
} else {
    loadMore();
}
</script>
</body>
</html>
"""

    return (
        template
        .replace("__DATA__", data)
        .replace("__PAGE_SIZE__", str(PAGE_SIZE))
    )


def main():
    input_path = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(INPUT_FILE)
    output_path = Path(sys.argv[2]) if len(sys.argv) > 2 else Path(OUTPUT_FILE)

    if not input_path.exists():
        print(f"Error: Input file not found: {input_path}")
        sys.exit(1)

    items = read_items(input_path)
    output_path.write_text(generate_html(items), encoding="utf-8")

    print(f"Read {len(items):,} items from {input_path}")
    print(f"Generated: {output_path.resolve()}")


if __name__ == "__main__":
    main()
