#!/usr/bin/env python3
"""
Generate the CoiioT mobile app's colour tokens from this Laravel app's palette.

The web app owns the palette. It lives in two places, both machine-readable:

    config/colors.php        chart series, shift colours, machine states, and the
                             chrome/surface_warm/grey roles — resolved through
                             artisan so that env() slots are applied
    public/css/standard.css  the --pulse-* semantic action tokens (primary/edit/
                             danger/warning/neutral, inward/outward, state, quality)

This script reads both and emits a single Dart file of `static const Color`s for
the Flutter app to consume. It is the whole of the sync: re-run it after changing
a colour on the web side and review the diff, rather than hand-copying hexes.

Output is deterministic — running it twice on an unchanged palette produces a
byte-identical file — so an empty diff is meaningful.

Usage:
    python3 pyscripts/generate_dart_tokens.py [--out PATH] [--check]

    --out    where to write the Dart file
             (default: ../../Documents/GitHub/android/lib/theme/pulse_tokens.dart
              relative to this repo, i.e. the sibling Flutter checkout)
    --check  don't write; exit 1 if the file on disk differs from what would be
             generated. For CI, to catch a web palette change that never reached
             the app.
"""

import argparse
import json
import os
import re
import subprocess
import sys

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_OUT = os.path.normpath(
    os.path.join(REPO_ROOT, "..", "..", "Documents", "GitHub", "android",
                 "lib", "theme", "pulse_tokens.dart")
)
STANDARD_CSS = os.path.join(REPO_ROOT, "public", "css", "standard.css")


# ---------------------------------------------------------------- colour parsing

def to_argb(value):
    """Normalise a CSS colour to a Dart 0xAARRGGBB literal.

    Handles #rgb, #rrggbb, #rrggbbaa (the palette carries two of those — the
    QUATERNARY/QUINARY series slots) and rgba(r, g, b, a) as used by the
    --pulse-*-ring tokens.
    """
    value = value.strip().lower()

    match = re.fullmatch(r"rgba?\(([^)]+)\)", value)
    if match:
        parts = [p.strip() for p in match.group(1).replace("/", ",").split(",")]
        if len(parts) < 3:
            raise ValueError("unparseable rgb(): %s" % value)
        r, g, b = (int(round(float(p.rstrip("%")))) for p in parts[:3])
        a = 255
        if len(parts) > 3:
            alpha = float(parts[3].rstrip("%"))
            a = int(round(alpha * 255)) if alpha <= 1 else int(round(alpha))
        return "0x%02X%02X%02X%02X" % (a, r, g, b)

    hex_digits = value.lstrip("#")
    if len(hex_digits) == 3:
        hex_digits = "".join(c * 2 for c in hex_digits)
    if len(hex_digits) == 6:
        return "0xFF" + hex_digits.upper()
    if len(hex_digits) == 8:
        # CSS is #RRGGBBAA; Dart wants 0xAARRGGBB.
        return "0x" + (hex_digits[6:8] + hex_digits[0:6]).upper()
    raise ValueError("unrecognised colour: %s" % value)


def is_colour(value):
    if not isinstance(value, str):
        return False
    value = value.strip().lower()
    if value.startswith("rgb"):
        return True
    return bool(re.fullmatch(r"#[0-9a-f]{3}|#[0-9a-f]{6}|#[0-9a-f]{8}", value))


# ---------------------------------------------------------------- name mangling

def camel(*parts):
    """Join config path segments into a lowerCamelCase Dart identifier.

    Numeric segments are kept as a suffix rather than made into words, so
    grey.500 becomes grey500 and shift.1 becomes shift1.
    """
    words = []
    for part in parts:
        for chunk in re.split(r"[_\-.\s]+", str(part)):
            if chunk:
                words.append(chunk)

    out = ""
    for i, word in enumerate(words):
        if word.isdigit():
            out += word
        elif i == 0:
            out += word[0].lower() + word[1:]
        else:
            out += word[0].upper() + word[1:]
    return out


def pascal(*parts):
    name = camel(*parts)
    return name[0].upper() + name[1:] if name else ""


# ---------------------------------------------------------------- sources

def read_laravel_config():
    """Resolve config('colors') through artisan so env() slots are applied."""
    result = subprocess.run(
        ["php", "artisan", "tinker", "--execute",
         'echo json_encode(config("colors"));'],
        cwd=REPO_ROOT, capture_output=True, text=True,
    )
    if result.returncode != 0:
        raise SystemExit("artisan failed:\n%s" % (result.stderr or result.stdout))

    # Tinker can prepend a banner or warnings; take the outermost JSON object.
    text = result.stdout
    start, end = text.find("{"), text.rfind("}")
    if start == -1 or end == -1:
        raise SystemExit("no JSON in artisan output:\n%s" % text)
    return json.loads(text[start:end + 1])


def read_pulse_tokens():
    """Pull the --pulse-* custom properties out of standard.css's :root block."""
    with open(STANDARD_CSS, encoding="utf-8") as handle:
        css = handle.read()

    match = re.search(r":root\s*\{(.*?)\}", css, re.S)
    if not match:
        raise SystemExit("no :root block in %s" % STANDARD_CSS)

    tokens = {}
    for name, value in re.findall(r"--pulse-([a-z0-9-]+)\s*:\s*([^;]+);",
                                  match.group(1), re.I):
        value = value.strip()
        if is_colour(value):
            tokens[name] = value
    return tokens


# ---------------------------------------------------------------- flattening

def flatten(node, path=()):
    """Walk the config tree, yielding (dart_name, [colour, ...]) pairs.

    A list of colours (the machine palettes) yields one entry holding all of
    them; a scalar yields an entry of one.
    """
    entries = []
    if isinstance(node, dict):
        for key, value in node.items():
            entries.extend(flatten(value, path + (key,)))
    elif isinstance(node, list):
        colours = [v for v in node if is_colour(v)]
        if colours and len(colours) == len(node):
            entries.append((camel(*path), colours))
    elif is_colour(node):
        entries.append((camel(*path), [node]))
    return entries


def flatten_from(node, path=()):
    """Walk a node and yield relative path tuples from the supplied starting point."""
    entries = []
    if isinstance(node, dict):
        for key, value in node.items():
            entries.extend(flatten_from(value, path + (key,)))
    elif isinstance(node, list):
        colours = [v for v in node if is_colour(v)]
        if colours and len(colours) == len(node):
            entries.append((path, colours))
    elif is_colour(node):
        entries.append((path, [node]))
    return entries


def top_level_groups(config):
    """Return grouped token entries keyed by the top-level config section."""
    groups = []
    for key, value in config.items():
        group_entries = flatten_from(value)
        if group_entries:
            groups.append((key, group_entries))
    return groups


def grouped_member_name(top_key, path, colours):
    if not path:
        return "values" if len(colours) > 1 else "value"
    if str(path[0]).isdigit():
        return camel(top_key, *path)
    return camel(*path)


def flat_alias_name(top_key, path):
    return camel(top_key, *path) if path else camel(top_key)


def emit_constant(lines, indent, name, colours):
    if len(colours) == 1:
        lines.append("%sstatic const Color %s = Color(%s);" %
                     (indent, name, to_argb(colours[0])))
    else:
        lines.append("%sstatic const List<Color> %s = <Color>[" % (indent, name))
        for colour in colours:
            lines.append("%s  Color(%s)," % (indent, to_argb(colour)))
        lines.append("%s];" % indent)


def emit_group_class(lines, top_key, entries):
    class_name = "Pulse%sTokens" % pascal(top_key)
    lines.append("/// Tokens from `config/colors.php` `%s`." % top_key)
    lines.append("class %s {" % class_name)
    lines.append("  const %s._();" % class_name)
    lines.append("")
    for path, colours in entries:
        name = grouped_member_name(top_key, path, colours)
        emit_constant(lines, "  ", name, colours)
    lines.append("}")
    lines.append("")


def emit_palette_facade(lines, entries):
    lines.append("/// Backwards-compatible flat view of the grouped config tokens.")
    lines.append("class PulsePalette {")
    lines.append("  const PulsePalette._();")
    lines.append("")
    for top_key, group_entries in entries:
        class_name = "Pulse%sTokens" % pascal(top_key)
        for path, colours in group_entries:
            name = grouped_member_name(top_key, path, colours)
            alias = flat_alias_name(top_key, path)
            if len(colours) == 1:
                lines.append("  static const Color %s = %s.%s;" %
                             (alias, class_name, name))
            else:
                lines.append("  static const List<Color> %s = %s.%s;" %
                             (alias, class_name, name))
    lines.append("}")
    lines.append("")


# ---------------------------------------------------------------- emit

HEADER = """\
// GENERATED FILE — DO NOT EDIT BY HAND.
//
// The colour palette is owned by the Laravel web app, so that a shift colour or
// a brand red means the same thing on the portal and in this app. This file is
// produced from it by:
//
//     python3 pyscripts/generate_dart_tokens.py
//
// in the coirpower repo, which reads config/colors.php (resolved through
// artisan, so env() overrides apply) and the --pulse-* block in
// public/css/standard.css.
//
// To change a colour, change it there and re-run the generator. Editing this
// file directly will be overwritten on the next sync, and the app will drift
// back out of step with the portal.
//
// These are raw tokens. Widgets should not reach for them directly — AppColors
// in app_theme.dart maps them onto light and dark roles, and that is what
// screens consume.

import 'package:flutter/material.dart';
"""


def render(config, pulse):
    lines = [HEADER]

    lines.append("/// Semantic action tokens — what a control's colour *means*.")
    lines.append("/// Mirrors the `:root` block in public/css/standard.css.")
    lines.append("class PulseAction {")
    lines.append("  const PulseAction._();")
    lines.append("")
    for name in sorted(pulse):
        lines.append("  static const Color %s = Color(%s);"
                     % (camel(name), to_argb(pulse[name])))
    lines.append("}")
    lines.append("")

    groups = top_level_groups(config)

    seen = {}
    for top_key, group_entries in groups:
        for path, colours in group_entries:
            alias = flat_alias_name(top_key, path)
            if alias in seen:
                raise SystemExit(
                    "duplicate Dart identifier %r — rename one of the config keys" % alias
                )
            seen[alias] = colours

    lines.append("/// Grouped tokens from config/colors.php, preserving the web")
    lines.append("/// config's top-level sections for easier navigation in Flutter.")
    lines.append("")

    for top_key, group_entries in groups:
        emit_group_class(lines, top_key, group_entries)

    emit_palette_facade(lines, groups)

    return "\n".join(lines)


# ---------------------------------------------------------------- entry point

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", default=DEFAULT_OUT)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()

    rendered = render(read_laravel_config(), read_pulse_tokens())

    if args.check:
        if not os.path.exists(args.out):
            print("missing: %s" % args.out, file=sys.stderr)
            return 1
        with open(args.out, encoding="utf-8") as handle:
            if handle.read() != rendered:
                print("stale: %s — re-run the generator" % args.out, file=sys.stderr)
                return 1
        print("up to date: %s" % args.out)
        return 0

    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as handle:
        handle.write(rendered)

    count = rendered.count("static const")
    print("wrote %d tokens to %s" % (count, args.out))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
