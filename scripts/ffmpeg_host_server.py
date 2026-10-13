#!/usr/bin/env python3
"""
FFmpeg on the Mac as an HTTP service, for VideoToolbox's hardware H.264 encoder.

The Docker VM cannot reach Apple's media engines, so with
VIDEO_EXTRACTION_ENCODER=h264_videotoolbox the app sends each paired section
encode here (App\\Services\\Media\\Video\\HostFfmpeg), as transcription goes to
whisper-server on :2022 and audio classification to :2023. Production leaves it
unset and encodes with libx264 in its container.

Usage:
    python3 ffmpeg_host_server.py --map /mnt/historic-work=/Volumes/Staging/HistoricWork \\
        --map /var/www/html=/Users/<you>/Projects/crockenhill [--port 2024] [--jobs 2]

Run it with the native (arm64) FFmpeg: an Intel build under Rosetta decodes slower,
and on this Mac it also refused VideoToolbox's HEVC and constant-quality modes.

Bind 0.0.0.0: Docker Desktop's host.docker.internal cannot reach a service
listening on loopback only. FFmpeg can read and write any file it is pointed at,
so every path argument must lie under a mapped root, and arguments that name a
protocol or a filter that opens files are refused.

Endpoints:
    GET  /     {"status": "ok", "ffmpeg", "encoders", "roots", "jobs"}
    POST /run  {"arguments": [...]} as the container sees them, without the binary;
               200 {"exit_code", "output"} once FFmpeg exits, 400 {"error"} if refused.

At most --jobs encodes run at once (M1 Max has two media encode engines); later
requests wait.
"""

import argparse
import json
import os
import re
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# Protocols (concat:, pipe:, http://...) and filters that read files of their own choosing.
REFUSED = re.compile(r"^[a-z][a-z0-9+.-]*:(?!v\b|a\b)|://|\b(a?movie|sendcmd|zmq|azmq)\s*=", re.IGNORECASE)
# FFmpeg's own stream specifiers and options look like protocols: `-c:v`, `-b:a`, `-map 0:a:0`.
OPTION = re.compile(r"^-[a-z_0-9]+(:[a-z0-9]+)*$", re.IGNORECASE)


class Refused(Exception):
    pass


def map_arguments(arguments: list, roots: list) -> list:
    if not isinstance(arguments, list) or not arguments or not all(isinstance(a, str) for a in arguments):
        raise Refused("arguments must be a non-empty list of strings")

    mapped = []
    for argument in arguments:
        if argument.startswith("/"):
            mapped.append(map_path(argument, roots))
            continue

        if not OPTION.match(argument) and REFUSED.search(argument):
            raise Refused(f"argument refused: {argument[:200]}")

        mapped.append(argument)

    return mapped


def map_path(path: str, roots: list) -> str:
    for container, host in roots:
        if path == container or path.startswith(container + "/"):
            resolved = os.path.realpath(host + path[len(container):])
            if resolved == os.path.realpath(host) or resolved.startswith(os.path.realpath(host) + "/"):
                return resolved

    raise Refused(f"path outside the mapped roots: {path}")


def handler_for(ffmpeg: str, roots: list, slots: threading.BoundedSemaphore, jobs: int, timeout: int) -> type:
    version = subprocess.run([ffmpeg, "-hide_banner", "-version"], capture_output=True, text=True).stdout.splitlines()[:1]
    encoders = [line.split()[1] for line in subprocess.run([ffmpeg, "-hide_banner", "-encoders"], capture_output=True, text=True).stdout.splitlines()
                if "videotoolbox" in line]

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:
            if self.path != "/":
                self.respond(404, {"error": "not found"})

                return

            self.respond(200, {"status": "ok", "ffmpeg": version[0] if version else ffmpeg, "encoders": encoders,
                               "roots": [f"{c}={h}" for c, h in roots], "jobs": jobs})

        def do_POST(self) -> None:
            if self.path != "/run":
                self.respond(404, {"error": "not found"})

                return

            try:
                body = json.loads(self.rfile.read(int(self.headers.get("Content-Length") or 0)) or b"null")
                arguments = map_arguments(body.get("arguments") if isinstance(body, dict) else None, roots)
            except (Refused, ValueError) as exception:
                self.respond(400, {"error": str(exception)})

                return

            queued = time.monotonic()
            with slots:
                started = time.monotonic()
                try:
                    process = subprocess.run([ffmpeg, "-nostdin", *arguments], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                             stderr=subprocess.STDOUT, timeout=timeout)
                    result = {"exit_code": process.returncode, "output": process.stdout.decode("utf-8", "replace")}
                except subprocess.TimeoutExpired as exception:
                    result = {"exit_code": 124, "output": f"timed out after {timeout} s\n" + (exception.output or b"").decode("utf-8", "replace")}

            print(json.dumps({"event": "run", "exit_code": result["exit_code"], "waited": round(started - queued, 2),
                              "seconds": round(time.monotonic() - started, 2), "output": arguments[-1]}), flush=True)
            if os.environ.get("FFMPEG_HOST_LOG_ARGUMENTS"):
                print(json.dumps({"event": "arguments", "arguments": arguments}), flush=True)
            self.respond(200, result)

        def respond(self, status: int, payload: dict) -> None:
            body = json.dumps(payload).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, format: str, *args) -> None:
            print(f"{self.address_string()} {format % args}", flush=True)

    return Handler


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--host", default="0.0.0.0")
    parser.add_argument("--port", type=int, default=2024)
    parser.add_argument("--ffmpeg", default="/opt/homebrew/bin/ffmpeg")
    parser.add_argument("--map", action="append", required=True, metavar="CONTAINER=HOST",
                        help="a path root as the container sees it and where it is on this Mac; repeatable")
    parser.add_argument("--jobs", type=int, default=2)
    parser.add_argument("--timeout", type=int, default=3600, help="seconds before an encode is killed")
    options = parser.parse_args()

    roots = []
    for pair in options.map:
        container, _, host = pair.partition("=")
        if not container.startswith("/") or not os.path.isdir(host):
            parser.error(f"--map needs /container/path=/existing/host/path, got {pair}")
        roots.append((container.rstrip("/"), host.rstrip("/")))

    slots = threading.BoundedSemaphore(options.jobs)
    server = ThreadingHTTPServer((options.host, options.port), handler_for(options.ffmpeg, roots, slots, options.jobs, options.timeout))
    print(f"ffmpeg host server on {options.host}:{options.port}, {options.jobs} at a time, roots {roots}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
