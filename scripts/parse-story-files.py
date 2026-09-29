#!/usr/bin/env python3
"""parse-story-files — run the kit's story parser over texts that are not at a ref.

bin/story-index reads stories through git, so it cannot see a file on a branch
diffed out by the board, or an untracked file in a checkout (SB-5). Rather than
re-implement its rules in PHP — a second parser that would drift from the one
the kit owns — this loads bin/story-index as a module and calls its own
`index_story()` on each text. The kit file is read, never modified.

stdin:  {"readme": <stories/README.md text or null>,
         "files": [{"path": "stories/x/X-1-a.md", "text": "..."}],
         "mockup_files": {"docs/mockups/X-1": ["option-a.html", ...]}}
stdout: a JSON list of story-index records, one per file, in input order.
"""

import importlib.machinery
import importlib.util
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
KIT = os.path.join(HERE, "..", "bin", "story-index")


def load_kit():
    """Import bin/story-index (no .py suffix) as a module."""
    loader = importlib.machinery.SourceFileLoader("story_index", KIT)
    spec = importlib.util.spec_from_loader("story_index", loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


def main():
    kit = load_kit()
    req = json.load(sys.stdin)
    vocab = kit.parse_vocabulary(req.get("readme"))
    mockups = req.get("mockup_files") or {}
    records = [kit.index_story(f["path"], f["text"], vocab, mockups) for f in req.get("files", [])]
    print(json.dumps(records, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
