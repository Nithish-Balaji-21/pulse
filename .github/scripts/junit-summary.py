"""Summarize Pest's JUnit report without using GitHub artifact storage."""

import os
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def summarize(report: Path) -> str:
    try:
        cases = list(ET.parse(report).getroot().iter("testcase"))
    except (OSError, ET.ParseError):
        return "### Test results\n\nJUnit report unavailable. See the test step logs for details.\n"

    failed = sum(case.find("failure") is not None or case.find("error") is not None for case in cases)
    skipped = sum(case.find("skipped") is not None for case in cases)
    passed = len(cases) - failed - skipped
    return (
        "### Test results\n\n"
        "| Passed | Failed / errors | Skipped | Total |\n"
        "| ---: | ---: | ---: | ---: |\n"
        f"| {passed} | {failed} | {skipped} | {len(cases)} |\n\n"
        "Full test names and failure details are in the test step logs. "
        "No artifact upload is required.\n"
    )


if __name__ == "__main__":
    summary = summarize(Path(sys.argv[1]))
    print(summary)
    if destination := os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(destination, "a", encoding="utf-8") as output:
            output.write(summary)
