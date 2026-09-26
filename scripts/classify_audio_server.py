#!/usr/bin/env python3
"""
The audio classifier as an HTTP service on the Mac, where it can use the GPU (MPS).

The app container has no GPU: there `classify_audio.py` runs on CPU at ~0.95 s a
5 s window, ~12 minutes a service. On the Mac's GPU the same model scores the
same audio at ~0.08 s a window with no window changing class (run 1304,
2026-09-26). Local runs point `AUDIO_CLASSIFIER_URL` here, as transcription
points at whisper-server on :2022; production leaves it unset and runs the
command in its container.

Usage:
    python3 classify_audio_server.py [--host 0.0.0.0] [--port 2023] [--device auto|cpu|mps]

Bind 0.0.0.0: Docker Desktop's host.docker.internal cannot reach a service
listening on loopback only.

Endpoints:
    GET  /          {"status": "ok", "model", "model_revision", "device"}
    POST /classify  body = the audio file's bytes; 200 with the classify_audio.py
                    JSON, 422 {"error"} when the audio cannot be classified.

One file is classified at a time; later requests wait for the model.
"""

import argparse
import json
import os
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from classify_audio import MODEL_ID, MODEL_REVISION, ClassificationError, Classifier  # noqa: E402

BATCH_SIZE = 16


def handler_for(classifier: Classifier) -> type:
    lock = threading.Lock()

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:
            if self.path != "/":
                self.respond(404, {"error": "not found"})

                return

            self.respond(200, {"status": "ok", "model": MODEL_ID, "model_revision": MODEL_REVISION, "device": classifier.device})

        def do_POST(self) -> None:
            if self.path != "/classify":
                self.respond(404, {"error": "not found"})

                return

            length = int(self.headers.get("Content-Length") or 0)
            if length <= 0:
                self.respond(422, {"error": "no audio in the request body"})

                return

            with tempfile.NamedTemporaryFile(prefix="classify-", suffix=".audio", delete=False) as handle:
                remaining = length
                while remaining > 0:
                    block = self.rfile.read(min(remaining, 1024 * 1024))
                    if not block:
                        break
                    handle.write(block)
                    remaining -= len(block)
                path = handle.name

            try:
                if remaining > 0:
                    self.respond(422, {"error": f"request body ended {remaining} bytes short"})

                    return

                with lock:
                    result = classifier.classify(path, BATCH_SIZE)

                self.respond(200, result)
            except ClassificationError as error:
                self.respond(422, {"error": str(error)})
            except Exception as error:  # the service must outlive one bad request
                self.respond(500, {"error": f"{type(error).__name__}: {error}"})
            finally:
                os.unlink(path)

        def respond(self, status: int, payload: dict) -> None:
            body = json.dumps(payload).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    return Handler


def main() -> None:
    parser = argparse.ArgumentParser(description="Audio classifier service")
    parser.add_argument("--host", default="0.0.0.0")
    parser.add_argument("--port", type=int, default=2023)
    parser.add_argument("--device", choices=["auto", "cpu", "mps"], default="auto")
    args = parser.parse_args()

    try:
        classifier = Classifier(args.device)
    except ClassificationError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)

    print(f"Classifying on {classifier.device} at http://{args.host}:{args.port}", flush=True)
    ThreadingHTTPServer((args.host, args.port), handler_for(classifier)).serve_forever()


if __name__ == "__main__":
    main()
