#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
from collections import Counter, defaultdict
from pathlib import Path
import sys


HANA_DB_TRANSFER = Path("/Users/jungsinyu/PycharmProjects/HanaDBTransfer")
if HANA_DB_TRANSFER.exists():
    sys.path.insert(0, str(HANA_DB_TRANSFER))


RECORD_LENGTH = 128


def decode_field(raw: bytes) -> str:
    return raw.decode("cp932", errors="replace").strip()


def int_field(raw: bytes) -> int:
    text = raw.decode("ascii", errors="ignore").strip()
    return int(text) if text else 0


def iter_records(path: Path):
    data = path.read_bytes()
    data = data.replace(b"\r\n", b"").replace(b"\n", b"").replace(b"\r", b"")

    if len(data) < RECORD_LENGTH:
        return

    for offset in range(0, len(data) - (len(data) % RECORD_LENGTH), RECORD_LENGTH):
        record = data[offset : offset + RECORD_LENGTH]
        if len(record) != RECORD_LENGTH:
            continue
        yield offset // RECORD_LENGTH + 1, record


def parse_d_record(path: Path, record_no: int, record: bytes) -> dict:
    return {
        "file": str(path),
        "record_no": record_no,
        "product_name": decode_field(record[5:69]),
        "ordering_code": decode_field(record[69:82]),
        "item_code": decode_field(record[82:88]),
        "capacity": int_field(record[88:94]),
        "case_quantity": int_field(record[94:101]),
        "piece_quantity": int_field(record[101:108]),
        "unit_price_raw": int_field(record[108:118]),
    }


def target_files(base: Path) -> list[Path]:
    files = []
    for path in base.rglob("*"):
        if not path.is_file() or path.name == ".DS_Store":
            continue
        if path.suffix.lower() not in {".txt", ".dat"}:
            continue

        haystack = f"{path.parent.name}/{path.name}".lower()
        if any(token in haystack for token in ["order", "発注", "注文"]):
            files.append(path)

    return sorted(files)


def load_db_six_pack_codes() -> dict[str, dict]:
    from lib.mysql_utils import create_mysql_connection

    conn = create_mysql_connection(force_new=True)
    if conn is None:
        raise RuntimeError("Could not connect to local MySQL via HanaDBTransfer.")

    cur = conn.cursor(dictionary=True)
    cur.execute(
        """
        SELECT
            LPAD(isi.search_string, 13, '0') AS ordering_code,
            isi.item_id,
            i.code AS item_code,
            i.name AS item_name,
            iqi.quantity AS quantity
        FROM item_search_information isi
        JOIN item_quantity_information iqi
          ON iqi.id = isi.item_quantity_information_id
        LEFT JOIN items i
          ON i.id = isi.item_id
        WHERE isi.is_active = 1
          AND iqi.quantity = 6
          AND isi.search_string REGEXP '[1-9]'
        """
    )
    rows = cur.fetchall()
    cur.close()
    conn.close()

    result = {}
    for row in rows:
        result[row["ordering_code"]] = {
            "db_item_id": row["item_id"],
            "db_item_code": row["item_code"],
            "db_item_name": row["item_name"],
            "db_quantity": row["quantity"],
        }

    return result


def analyze(base: Path, db_six_pack_codes: dict[str, dict] | None = None) -> tuple[list[dict], list[dict], dict]:
    files = target_files(base)
    all_d_rows = []
    six_pack_rows = []
    parsed_files = 0
    d_record_count = 0

    for path in files:
        file_d_count = 0
        for record_no, record in iter_records(path) or []:
            if record[:1] != b"D":
                continue

            row = parse_d_record(path, record_no, record)
            all_d_rows.append(row)
            d_record_count += 1
            file_d_count += 1

            if db_six_pack_codes is not None:
                db_match = db_six_pack_codes.get(row["ordering_code"])
                if db_match:
                    row.update(db_match)
                    six_pack_rows.append(row)
            elif row["capacity"] == 6:
                six_pack_rows.append(row)

        if file_d_count:
            parsed_files += 1

    stats = {
        "scanned_files": len(files),
        "parsed_order_files": parsed_files,
        "d_record_count": d_record_count,
        "six_pack_record_count": len(six_pack_rows),
        "db_six_pack_code_count": len(db_six_pack_codes) if db_six_pack_codes is not None else None,
    }

    return all_d_rows, six_pack_rows, stats


def write_csv(path: Path, rows: list[dict], fieldnames: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def build_ordering_code_summary(rows: list[dict]) -> list[dict]:
    grouped: dict[tuple[str, str, str], list[dict]] = defaultdict(list)
    for row in rows:
        grouped[(row["ordering_code"], row["item_code"], row["product_name"])].append(row)

    summary = []
    for (ordering_code, item_code, product_name), group in sorted(grouped.items()):
        case_counter = Counter(row["case_quantity"] for row in group)
        piece_counter = Counter(row["piece_quantity"] for row in group)
        send_pattern = (
            "CASE_ONLY"
            if all(row["case_quantity"] > 0 and row["piece_quantity"] == 0 for row in group)
            else "PIECE_ONLY"
            if all(row["case_quantity"] == 0 and row["piece_quantity"] > 0 for row in group)
            else "MIXED_OR_ZERO"
        )

        summary.append(
            {
                "ordering_code": ordering_code,
                "item_code": item_code,
                "product_name": product_name,
                "record_count": len(group),
                "file_count": len({row["file"] for row in group}),
                "send_pattern": send_pattern,
                "case_values": " ".join(f"{qty}:{count}" for qty, count in sorted(case_counter.items())),
                "piece_values": " ".join(f"{qty}:{count}" for qty, count in sorted(piece_counter.items())),
                "has_case_1": int(case_counter[1] > 0),
                "has_case_2": int(case_counter[2] > 0),
                "has_case_3": int(case_counter[3] > 0),
                "has_case_4": int(case_counter[4] > 0),
                "sample_file": group[0]["file"],
            }
        )

    return summary


def build_quantity_summary(rows: list[dict]) -> list[dict]:
    case_counter = Counter(row["case_quantity"] for row in rows)
    piece_counter = Counter(row["piece_quantity"] for row in rows)
    values = sorted(set(case_counter) | set(piece_counter))

    return [
        {
            "quantity": value,
            "case_count": case_counter[value],
            "piece_count": piece_counter[value],
        }
        for value in values
    ]


def main() -> int:
    parser = argparse.ArgumentParser(description="Analyze Kanakan historical JX order files for six-pack order records.")
    parser.add_argument(
        "--base",
        default="/Users/jungsinyu/Works/1.顧客対応/11.華様/問屋さんやり取り/3.過去データEOS/kanakan",
        help="Base directory containing historical EOS/JX files.",
    )
    parser.add_argument(
        "--output-dir",
        default="storage/reports/kanakan-six-pack-jx",
        help="Directory for CSV outputs.",
    )
    parser.add_argument(
        "--match-db-six-pack-jan",
        action="store_true",
        help="Filter by DB item_search_information joined to item_quantity_information.quantity=6 instead of D-record capacity=6.",
    )
    args = parser.parse_args()

    base = Path(args.base)
    output_dir = Path(args.output_dir)

    db_six_pack_codes = load_db_six_pack_codes() if args.match_db_six_pack_jan else None
    all_rows, six_pack_rows, stats = analyze(base, db_six_pack_codes)
    detail_fields = [
        "file",
        "record_no",
        "product_name",
        "ordering_code",
        "item_code",
        "db_item_id",
        "db_item_code",
        "db_item_name",
        "db_quantity",
        "capacity",
        "case_quantity",
        "piece_quantity",
        "unit_price_raw",
    ]
    summary_fields = [
        "ordering_code",
        "item_code",
        "product_name",
        "record_count",
        "file_count",
        "send_pattern",
        "case_values",
        "piece_values",
        "has_case_1",
        "has_case_2",
        "has_case_3",
        "has_case_4",
        "sample_file",
    ]
    quantity_fields = ["quantity", "case_count", "piece_count"]

    write_csv(output_dir / "six_pack_detail.csv", six_pack_rows, detail_fields)
    write_csv(output_dir / "six_pack_by_ordering_code.csv", build_ordering_code_summary(six_pack_rows), summary_fields)
    write_csv(output_dir / "six_pack_quantity_distribution.csv", build_quantity_summary(six_pack_rows), quantity_fields)

    print("base=", base)
    for key, value in stats.items():
        print(f"{key}={value}")
    print("six_pack_detail=", output_dir / "six_pack_detail.csv")
    print("six_pack_by_ordering_code=", output_dir / "six_pack_by_ordering_code.csv")
    print("six_pack_quantity_distribution=", output_dir / "six_pack_quantity_distribution.csv")

    send_patterns = Counter(
        "CASE_ONLY"
        if row["case_quantity"] > 0 and row["piece_quantity"] == 0
        else "PIECE_ONLY"
        if row["case_quantity"] == 0 and row["piece_quantity"] > 0
        else "MIXED_OR_ZERO"
        for row in six_pack_rows
    )
    print("send_patterns=", dict(send_patterns))
    print("case_values=", dict(sorted(Counter(row["case_quantity"] for row in six_pack_rows).items())))
    print("piece_values=", dict(sorted(Counter(row["piece_quantity"] for row in six_pack_rows).items())))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
