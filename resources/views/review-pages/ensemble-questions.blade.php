<title>{{ $title }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600&family=Lato:ital,wght@0,400;0,700;1,400&display=swap">
<style>
  :root {
    --bg: #f3f6f6;
    --surface: #ffffff;
    --sunk: #e8eeee;
    --ink: #1a2a2d;
    --muted: #5a6d71;
    --line: #d3dddd;
    --accent: #177a82;
    --accent-ink: #ffffff;
    --accent-soft: #dcefef;
    --mark: #a8680c;
    --mark-soft: #fbefd9;
    --good: #2f7a3e;
    --good-soft: #e2f1e4;
    --now: #fff6d6;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      color-scheme: dark;
      --bg: #111a1c; --surface: #182427; --sunk: #0f1719; --ink: #e2ecec; --muted: #9cb1b4;
      --line: #2b3b3e; --accent: #4fb3ba; --accent-ink: #0c1a1c; --accent-soft: #173537;
      --mark: #e3a64a; --mark-soft: #33280f; --good: #6cc27d; --good-soft: #16301c; --now: #2e2a16;
    }
  }
  :root[data-theme="dark"] {
    color-scheme: dark;
    --bg: #111a1c; --surface: #182427; --sunk: #0f1719; --ink: #e2ecec; --muted: #9cb1b4;
    --line: #2b3b3e; --accent: #4fb3ba; --accent-ink: #0c1a1c; --accent-soft: #173537;
    --mark: #e3a64a; --mark-soft: #33280f; --good: #6cc27d; --good-soft: #16301c; --now: #2e2a16;
  }
  body {
    background: var(--bg); color: var(--ink);
    font: 16px/1.55 Lato, "Helvetica Neue", Arial, sans-serif;
    padding-inline: 16px; padding-block: 24px 64px;
  }
  .wrap { max-width: 1120px; margin: 0 auto; display: flex; flex-direction: column; gap: 28px; }
  h1, h2 { font-family: Oswald, "Arial Narrow", Arial, sans-serif; font-weight: 600; letter-spacing: .01em; text-wrap: balance; margin: 0; }
  h1 { font-size: 30px; }
  h2 { font-size: 22px; }
  .eyebrow { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 700; }
  header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: end; gap: 12px 24px; }
  header p { margin: 6px 0 0; color: var(--muted); max-width: 62ch; }
  .progress { display: flex; align-items: center; gap: 10px; font-variant-numeric: tabular-nums; color: var(--muted); }
  .pips { display: flex; gap: 4px; }
  .pip { width: 22px; height: 6px; border-radius: 3px; background: var(--line); }
  .pip.done { background: var(--good); }
  .status { font-size: 13px; color: var(--muted); }
  .status.warn { color: var(--mark); }

  section.q { background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 20px; display: grid; gap: 16px; }
  .q-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 16px; align-items: baseline; }
  .chip { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: var(--sunk); color: var(--muted); }
  .chip.done { background: var(--good-soft); color: var(--good); }
  .context { margin: 0; color: var(--muted); max-width: 70ch; }
  .ask { margin: 0; font-weight: 700; }
  .media { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 16px; align-items: start; }
  @media (max-width: 820px) { .media { grid-template-columns: 1fr; } }
  .player { display: grid; gap: 8px; }
  audio { width: 100%; max-width: 100%; display: block; }
  video { width: 100%; }
  .speed-btn.on { background: var(--mark); color: var(--surface); }
  .clock { font-variant-numeric: tabular-nums; font-size: 14px; color: var(--muted); }
  .clock b { color: var(--ink); }
  .markers { display: flex; flex-wrap: wrap; gap: 6px; }
  .marker-btn {
    font: inherit; font-size: 13px; cursor: pointer; border: 1px solid var(--mark); color: var(--mark);
    background: var(--mark-soft); border-radius: 6px; padding: 3px 8px; font-variant-numeric: tabular-nums;
  }
  .marker-btn:focus-visible, .cue:focus-visible, button:focus-visible, textarea:focus-visible, input:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .transcript { max-height: 420px; overflow-y: auto; border: 1px solid var(--line); border-radius: 6px; background: var(--sunk); padding: 6px; }
  .cue {
    display: grid; grid-template-columns: 4.2em 1fr; gap: 8px; width: 100%; text-align: left; font: inherit; font-size: 14px;
    color: var(--ink); background: none; border: 0; padding: 2px 6px; border-radius: 4px; cursor: pointer;
  }
  .cue:hover { background: var(--surface); }
  .cue.now { background: var(--now); }
  .cue time { color: var(--muted); font-variant-numeric: tabular-nums; }
  .mark-line {
    display: flex; gap: 8px; align-items: center; margin: 4px 0; padding: 3px 6px; font-size: 12px; font-weight: 700;
    color: var(--mark); border-top: 2px solid var(--mark); background: var(--mark-soft); border-radius: 0 0 4px 4px;
    font-variant-numeric: tabular-nums;
  }
  form.rule { display: grid; gap: 10px; border-top: 1px solid var(--line); padding-top: 14px; }
  fieldset { border: 0; margin: 0; padding: 0; display: grid; gap: 6px; }
  legend { font-weight: 700; margin-bottom: 4px; }
  label.opt { display: flex; gap: 10px; align-items: flex-start; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px; cursor: pointer; }
  label.opt:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
  label.opt input { margin-top: 4px; accent-color: var(--accent); }
  textarea { font: inherit; font-size: 15px; color: var(--ink); background: var(--surface); border: 1px solid var(--line); border-radius: 6px; padding: 8px 10px; min-height: 64px; resize: vertical; width: 100%; box-sizing: border-box; }
  .actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
  button.save { font: inherit; font-weight: 700; cursor: pointer; border: 0; border-radius: 6px; padding: 9px 18px; background: var(--accent); color: var(--accent-ink); }
  button.save:disabled { opacity: .5; cursor: not-allowed; }
  .saved { font-size: 14px; color: var(--muted); }
  .saved.ok { color: var(--good); }
  .saved.err { color: var(--mark); }
  footer { color: var(--muted); font-size: 13px; }
  details.decided > summary { cursor: pointer; font-family: Oswald, "Arial Narrow", Arial, sans-serif; font-size: 20px; font-weight: 600; }
  details.decided > summary small { font-family: Lato, "Helvetica Neue", Arial, sans-serif; font-size: 14px; font-weight: 400; color: var(--muted); }
  details.decided[open] > summary { margin-bottom: 16px; }
  @media (prefers-reduced-motion: reduce) { * { scroll-behavior: auto !important; } }
</style>

<div class="wrap">
  <header>
    <div>
      <div class="eyebrow">{{ $eyebrow }}</div>
      <h1>{{ $heading }}</h1>
      <p>{{ $intro }}</p>
    </div>
    <div class="progress" aria-live="polite">
      <div class="pips" id="pips"></div>
      <span id="count">0 ruled</span>
    </div>
  </header>
  <div class="status" id="status">Connecting to saved rulings…</div>
  <div id="questions" class="wrap" style="gap:24px"></div>
  <details class="decided" id="decided-wrap">
    <summary>Settled by majority vote (<span id="decided-count">0</span>) <small>· open one only where the majority is wrong</small></summary>
    <div id="decided" class="wrap" style="gap:24px"></div>
  </details>
  <footer>Times are from the start of the service recording, not the clip. Tap a transcript line to play from there. Speed applies to every clip.</footer>
</div>

<script>
const QUESTIONS = @json($questions);
const DECIDED = @json($decided);
</script>
@verbatim
<script>
const ALL = [...QUESTIONS, ...DECIDED];

const fmt = (seconds) => {
  const s = Math.max(0, Math.round(seconds));
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
  return (h ? h + ":" + String(m).padStart(2, "0") : m) + ":" + String(r).padStart(2, "0");
};
const el = (tag, attrs = {}, ...children) => {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === "class") node.className = v;
    else if (k.startsWith("on")) node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v);
  }
  for (const child of children) node.append(child);
  return node;
};

const rulings = {};
const saveButtons = {};
const savedLabels = {};
let db = null;
const players = [];
let speed = 3;
function setSpeed(s) {
  speed = s;
  players.forEach((p) => { p.playbackRate = s; });
  document.querySelectorAll(".speed-btn").forEach((b) => { b.classList.toggle("on", Number(b.dataset.speed) === s); });
}

function renderProgress() {
  const done = QUESTIONS.filter((q) => rulings[q.id]).length;
  document.getElementById("count").textContent = `${done} of ${QUESTIONS.length} ruled`;
  const pips = document.getElementById("pips");
  pips.replaceChildren(...QUESTIONS.map((q) => el("span", { class: "pip" + (rulings[q.id] ? " done" : ""), title: q.title })));
  for (const q of ALL) {
    const chip = document.getElementById("chip-" + q.id);
    chip.textContent = rulings[q.id] ? "Ruled" : (q.decided ? "Majority stands" : "Not ruled");
    chip.className = "chip" + (rulings[q.id] ? " done" : "");
    const saved = rulings[q.id];
    const label = savedLabels[q.id];
    if (saved && !label.dataset.busy) {
      label.className = "saved ok";
      label.textContent = "Saved " + new Date(saved.saved_at).toLocaleString();
    }
  }
}

function applyRuling(q, ruling) {
  const form = document.getElementById("form-" + q.id);
  const radio = form.querySelector(`input[value="${ruling.choice}"]`);
  if (radio) radio.checked = true;
  form.querySelector("textarea").value = ruling.note || "";
  saveButtons[q.id].disabled = false;
}

function buildQuestion(q, index) {
  const clipBlocks = q.clips.map((c) => {
    const video = el("audio", { controls: "", preload: "none", src: c.src });
    players.push(video);
    video.addEventListener("play", () => { video.playbackRate = speed; players.forEach((p) => { if (p !== video) p.pause(); }); });
    const clock = el("div", { class: "clock" }, c.label + " · service time ", el("b", {}, fmt(c.offset)));
    const seek = (t) => { video.currentTime = Math.max(0, t - c.offset); video.play().catch(() => {}); };
    const transcript = el("div", { class: "transcript", role: "list", "aria-label": "Transcript, " + c.label });
    const cueNodes = [];
    c.cues.forEach((cue) => {
      const node = el("button", { class: "cue", type: "button", role: "listitem", onclick: () => seek(cue.t) },
        el("time", {}, fmt(cue.t)), el("span", {}, cue.x));
      node.dataset.t = cue.t;
      cueNodes.push(node);
      transcript.append(node);
    });
    let current = null;
    video.addEventListener("timeupdate", () => {
      const now = c.offset + video.currentTime;
      clock.querySelector("b").textContent = fmt(now);
      let active = null;
      for (const node of cueNodes) {
        if (Number(node.dataset.t) <= now + 0.2) active = node; else break;
      }
      if (active !== current) {
        current?.classList.remove("now");
        active?.classList.add("now");
        current = active;
        if (active && !video.paused) {
          const box = transcript.getBoundingClientRect(), row = active.getBoundingClientRect();
          if (row.top < box.top || row.bottom > box.bottom) transcript.scrollTop += row.top - box.top - box.height / 3;
        }
      }
    });
    const speeds = el("div", { class: "markers" }, ...[1, 2, 3].map((s) => {
      const b = el("button", { class: "marker-btn speed-btn", type: "button", "data-speed": String(s), onclick: () => setSpeed(s) }, s + "×");
      return b;
    }));
    return el("div", { class: "media" }, el("div", { class: "player" }, video, clock, speeds), transcript);
  });

  const save = el("button", { class: "save", type: "submit", disabled: "" }, "Save ruling");
  const saved = el("span", { class: "saved" }, "Not saved yet");
  saveButtons[q.id] = save;
  savedLabels[q.id] = saved;

  const fieldset = el("fieldset", {}, el("legend", {}, q.question),
    ...q.options.map(([value, label]) => el("label", { class: "opt" },
      el("input", { type: "radio", name: "choice-" + q.id, value, id: `opt-${q.id}-${value}`, onchange: () => { save.disabled = false; } }),
      el("span", {}, label))));

  const note = el("textarea", { id: "note-" + q.id, placeholder: "Note (optional): what you heard, or a better boundary time" });

  const form = el("form", { class: "rule", id: "form-" + q.id }, fieldset, note, el("div", { class: "actions" }, save, saved));
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const choice = form.querySelector("input[type=radio]:checked");
    if (!choice) return;
    if (!db) {
      saved.className = "saved err";
      saved.textContent = "Saving isn't available in this view. Open the page on claude.ai while signed in.";
      return;
    }
    const option = q.options.find(([value]) => value === choice.value);
    const body = {
      question_id: q.id, run: q.run, title: q.title, question: q.question,
      choice: choice.value, choice_label: option ? option[1] : choice.value,
      note: note.value.trim(), saved_at: new Date().toISOString(),
    };
    save.disabled = true;
    saved.dataset.busy = "1";
    saved.className = "saved";
    saved.textContent = "Saving…";
    try {
      await db.doc("rulings/" + q.id).set(body);
      delete saved.dataset.busy;
      rulings[q.id] = body;
      renderProgress();
    } catch (error) {
      delete saved.dataset.busy;
      saved.className = "saved err";
      saved.textContent = "Not saved: " + (error?.message || error?.code || "the store refused the write") + ". Try again.";
    } finally {
      save.disabled = false;
    }
  });

  return el("section", { class: "q", id: q.id },
    el("div", { class: "q-head" },
      el("div", {}, el("div", { class: "eyebrow" }, q.decided ? `Settled by majority · run ${q.run}` : `${index + 1} of ${QUESTIONS.length} · run ${q.run}`), el("h2", {}, q.title)),
      el("span", { class: "chip", id: "chip-" + q.id }, "Not ruled")),
    el("p", { class: "context" }, q.context),
    ...clipBlocks,
    form);
}

const container = document.getElementById("questions");
QUESTIONS.forEach((q, i) => container.append(buildQuestion(q, i)));
const decidedContainer = document.getElementById("decided");
DECIDED.forEach((q, i) => decidedContainer.append(buildQuestion(q, i)));
document.getElementById("decided-count").textContent = String(DECIDED.length);
document.getElementById("decided-wrap").hidden = DECIDED.length === 0;
renderProgress();
setSpeed(3);

(async () => {
  const status = document.getElementById("status");
  db = await window.claude?.use?.("db") ?? null;
  if (!db) {
    status.className = "status warn";
    status.textContent = "Saved rulings aren't available in this view. You can watch the clips, but saving needs the page open on claude.ai while signed in.";
    return;
  }
  db.collection("rulings").onSnapshot((snap) => {
    status.className = "status";
    status.textContent = "Rulings save to this page. Claude reads them from here.";
    for (const doc of snap.docs) {
      const body = doc.data();
      const q = ALL.find((x) => x.id === doc.id);
      if (!q || !body) continue;
      const first = !rulings[q.id];
      rulings[q.id] = body;
      if (first) applyRuling(q, body);
    }
    renderProgress();
  }, (error) => {
    status.className = "status warn";
    status.textContent = "Lost the connection to saved rulings (" + (error?.code || "error") + "). Reload the page to reconnect.";
  });
})();
</script>
@endverbatim
