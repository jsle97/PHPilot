// <Jakub Śledzikowski | jsledzikowski.web@gmail.com | jsle.eu | MIT>
var S = {p: "", ext: "", url: "", busy: false, abort: null, usage: {input: 0, output: 0, cached: 0}, contextTokens: null, images: [], hasImages: false, visionModel: "", focusBeforeOverlay: null};
var PHPILOT_CSRF = document.body.getAttribute("data-csrf") || "";
var E = function (id) { return document.getElementById(id); };

var MD = {
 esc: function (s) { return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\"/g, "&quot;").replace(/'/g, "&#39;"); },
 render: function (t) {
  var parts = t.split(/(```[\s\S]*?```)/g), out = "";
  for (var i = 0; i < parts.length; i++) {
   if (i % 2 === 1) {
    var b = parts[i].slice(3, -3), nl = b.indexOf("\n");
    out += "<pre><code>" + this.esc(nl === -1 ? b : b.slice(nl + 1)) + "</code></pre>";
   } else out += this.blk(parts[i]);
  }
  return out;
 },
 blk: function (t) {
  var lines = t.split("\n"), h = "";
  var listTag = null;
  var quoting = false;

  var closeList = function () { if (listTag) { h += "</" + listTag + ">"; listTag = null; } };
  var closeQuote = function () { if (quoting) { h += "</blockquote>"; quoting = false; } };

  for (var i = 0; i < lines.length; i++) {
   var l = lines[i];

   var hm = l.match(/^(#{1,3})\s+(.*)$/);
   var ulm = l.match(/^[-*]\s+(.*)$/);
   var olm = l.match(/^\d+[.)]\s+(.*)$/);
   var qm = l.match(/^>\s?(.*)$/);
   var hrm = l.match(/^(-{3,}|\*{3,}|_{3,})$/);

   if (l.indexOf("|") !== -1 && lines[i + 1] && /^\s*\|?[\s:|-]+\|?\s*$/.test(lines[i + 1]) && lines[i + 1].indexOf("-") !== -1) {
    closeList(); closeQuote();
    var tbl = this.table(lines, i);
    h += tbl.html;
    i = tbl.next - 1;
    continue;
   }

   if (hrm) { closeList(); closeQuote(); h += "<hr>"; continue; }

   if (hm) {
    closeList(); closeQuote();
    var n = hm[1].length;
    h += "<h" + n + ">" + this.inl(hm[2]) + "</h" + n + ">";
    continue;
   }

   if (qm) {
    closeList();
    if (!quoting) { h += "<blockquote>"; quoting = true; }
    if (qm[1].trim() === "") h += "<br>"; else h += "<p>" + this.inl(qm[1]) + "</p>";
    continue;
   }
   closeQuote();

   if (ulm) {
    if (listTag !== "ul") { closeList(); h += "<ul>"; listTag = "ul"; }
    h += "<li>" + this.inl(ulm[1]) + "</li>";
    continue;
   }
   if (olm) {
    if (listTag !== "ol") { closeList(); h += "<ol>"; listTag = "ol"; }
    h += "<li>" + this.inl(olm[1]) + "</li>";
    continue;
   }
   closeList();

   if (l.trim() === "") continue;
   h += "<p>" + this.inl(l) + "</p>";
  }
  closeList(); closeQuote();
  return h;
 },
 table: function (lines, start) {
  var self = this;
  var splitRow = function (row) {
   var r = row.trim();
   if (r.charAt(0) === "|") r = r.slice(1);
   if (r.charAt(r.length - 1) === "|") r = r.slice(0, -1);
   return r.split("|").map(function (c) { return c.trim(); });
  };
  var align = splitRow(lines[start + 1]).map(function (c) {
   var left = c.charAt(0) === ":", right = c.charAt(c.length - 1) === ":";
   if (left && right) return "center";
   if (right) return "right";
   if (left) return "left";
   return "";
  });
  var head = splitRow(lines[start]);
  var html = "<table><thead><tr>";
  head.forEach(function (c, idx) {
   html += "<th" + (align[idx] ? " style=\"text-align:" + align[idx] + "\"" : "") + ">" + self.inl(c) + "</th>";
  });
  html += "</tr></thead><tbody>";
  var i = start + 2;
  while (i < lines.length && lines[i].indexOf("|") !== -1 && lines[i].trim() !== "") {
   var cells = splitRow(lines[i]);
   html += "<tr>";
   cells.forEach(function (c, idx) {
    html += "<td" + (align[idx] ? " style=\"text-align:" + align[idx] + "\"" : "") + ">" + self.inl(c) + "</td>";
   });
   html += "</tr>";
   i++;
  }
  html += "</tbody></table>";
  return {html: html, next: i};
 },
 inl: function (s) {
  s = this.esc(s);
  s = s.replace(/`([^`]+)`/g, "<code>$1</code>");
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function (_, label, url) { return "<a href=\"" + MD.esc(url) + "\" target=\"_blank\" rel=\"noopener\">" + label + "</a>"; });
  s = s.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
  s = s.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, "$1<em>$2</em>");
  s = s.replace(/~~([^~]+)~~/g, "<del>$1</del>");
  return s;
 }
};

function api(u, o) {
 o = o || {};
 if (o.method === "POST") {
  o.headers = o.headers || {};
  o.headers["X-CSRF-Token"] = PHPILOT_CSRF;
 }
 return fetch(u, o).then(function (r) {
  var ct = r.headers.get("content-type") || "";
  if (ct.indexOf("application/json") === -1) {
   return r.text().then(function (t) { return {error: "HTTP " + r.status + (t ? " — " + t : "")}; });
  }
  return r.json();
 });
}
function workdirParams() { return S.ext ? "extpath=" + encodeURIComponent(S.ext) : "project=" + encodeURIComponent(S.p); }
function scr(force) {
 var log = E("log");
 if (force || log.scrollHeight - log.scrollTop - log.clientHeight < 80) log.scrollTop = log.scrollHeight;
}


var projectMeta = {};

function loadProjects(sel) {
 api("?action=projects").then(function (list) {
  E("sp").innerHTML = "";
  projectMeta = {};
  if (!list.length) {
   var o = document.createElement("option");
   o.textContent = "No projects";
   E("sp").appendChild(o);
   setProj("", "", "");
   return;
  }
  list.forEach(function (item) {
   var op = document.createElement("option");
   op.value = item.name;
   op.textContent = item.name;
   E("sp").appendChild(op);
   projectMeta[item.name] = item;
  });
  var t = sel && projectMeta[sel] ? sel : list[0].name;
  E("sp").value = t;
  setProj(t, "", projectMeta[t].url);
 });
}

function setProj(name, extpath, url) {
 S.p = name; S.ext = extpath || ""; S.url = url || "";
 S.contextTokens = null;
 var label = S.ext || S.p;

 E("cpath").innerHTML = label
  ? (S.ext ? "folder <b>" + MD.esc(S.ext) + "</b>" : "project <b>" + MD.esc(S.p) + "</b>")
  : "—";

 if (url) {
  E("curl").href = url;
  E("curl").textContent = url.replace(/^https?:\/\//, "");
  E("curl").hidden = false;
  E("csep").hidden = false;
 } else {
  E("curl").hidden = true;
  E("csep").hidden = true;
 }

 E("bd").disabled = !S.p || !!S.ext;
 E("bk").disabled = !S.p && !S.ext;
 loadOptions();

 if (!label) {
  E("ft").innerHTML = "";
  E("fcount").textContent = "";
  showEmpty("Select a project", "Create a new one with <kbd>+</kbd><br>or open an existing folder from the server.");
  return;
 }

 refreshTree();
 loadConversation();
}


function loadConversation() {
 api("?action=conversation&" + workdirParams()).then(function (r) {
  var turns = r.turns || [];
  S.hasImages = turns.some(function (turn) { return Array.isArray(turn.images) && turn.images.length > 0; });
  forceVisionModel();
  S.usage = r.usage || {input: 0, output: 0, cached: 0};
  renderUsageTotal(S.usage);
  if (!turns.length) {
   showEmpty("Empty conversation", "History is saved per folder —<br>you can come back later.");
   return;
  }
  E("loginner").innerHTML = "";
  turns.forEach(renderStoredTurn);
  requestAnimationFrame(function () { scr(true); });
 });
}

function renderUsageTotal(d) {
 var x = document.querySelector(".usage-total");
 if (!x) { x = document.createElement("span"); x.className = "usage-total meter"; E("cpath").parentNode.appendChild(x); }
 var context = S.contextTokens === null ? "context size: —" : "context size: ~" + S.contextTokens.toLocaleString("en-US") + " tokens";
 x.textContent = "tokens: " + (d.input || 0).toLocaleString("en-US") + " in · " + (d.output || 0).toLocaleString("en-US") + " out · " + (d.cached || 0).toLocaleString("en-US") + " cached · " + context;
}
function addUsage(d) {
 var details = d.prompt_tokens_details || d.input_tokens_details || {};
 S.usage.input += d.prompt_tokens || d.input_tokens || 0;
 S.usage.output += d.completion_tokens || d.output_tokens || 0;
 S.usage.cached += details.cached_tokens || d.prompt_cache_hit_tokens || d.cached_tokens || 0;
 renderUsageTotal(S.usage);
}

function optionKey() { return "phpilot:conversation-options:" + (S.ext ? "e:" + S.ext : "p:" + S.p); }
function loadOptions() {
 var d = {};
 try { d = JSON.parse(localStorage.getItem(optionKey()) || "{}") || {}; } catch (e) {}
 E("omtools").value = d.max_tools_per_round || 8;
 E("omtokens").value = d.max_tokens || 16384;
 E("omtemp").value = d.temperature === undefined || d.temperature === null ? "" : d.temperature;
 E("ominstr").value = d.instructions || "";
}
function readOptions() {
 var temperature = E("omtemp").value.trim();
 return {max_tools_per_round: E("omtools").value, max_tokens: E("omtokens").value, temperature: temperature === "" ? null : temperature, instructions: E("ominstr").value};
}

function showEmpty(title, hint) {
 E("loginner").innerHTML =
  "<div class=\"empty\">" +
  "<div class=\"empty-mark\">PHPilot</div>" +
  "<div class=\"empty-title\">" + title + "</div>" +
  "<div class=\"empty-hint\">" + hint + "</div>" +
  "</div>";
}

function renderStoredTurn(t) {
 var turn = mkTurn(t.id, t.user, t.images || []);
 (t.rounds || []).forEach(function (rd) {
  var v = turn.addRound();
  if (rd.reasoning) v.reason(rd.reasoning);
  (rd.tools || []).forEach(function (tc) {
   v.tool({id: tc.id, name: tc.name, arguments: tc.arguments});
   v.result({id: tc.id, result: tc.result});
  });
  if (rd.content) v.content(rd.content);
  v.settle();
  if (rd.usage) v.usage(rd.usage);
 });
}


function mkTurn(turnId, userText, images) {
 var inner = E("loginner");
 if (inner.querySelector(".empty")) inner.innerHTML = "";

 var turn = document.createElement("div");
 turn.className = "turn";
 turn.dataset.id = turnId;

 var del = document.createElement("button");
 del.className = "turn-del";
 del.title = "Delete this exchange";
 del.textContent = "✕";
 del.addEventListener("click", function () { deleteTurn(turn); });

 var who = document.createElement("div");
 who.className = "who who-me";
 who.textContent = "you";

 var said = document.createElement("div");
 said.className = "said";
 said.textContent = userText;

 turn.appendChild(del);
 turn.appendChild(who);
 turn.appendChild(said);
 if (images && images.length) {
  var previews = document.createElement("div");
  previews.className = "message-images";
  images.forEach(function (image) {
   var preview = document.createElement("img");
   preview.src = image.data_url;
   preview.alt = "Attached image";
   previews.appendChild(preview);
  });
  turn.appendChild(previews);
 }
 inner.appendChild(turn);

 return {
  el: turn,
  addRound: function () { return mkRound(turn); }
 };
}

function mkRound(turn) {
 var wrap = document.createElement("div");
 wrap.className = "round";

 var who = document.createElement("div");
 who.className = "who who-phpilot";
 who.textContent = "agent";
 wrap.appendChild(who);

 var think = document.createElement("div");
 think.className = "think";
 think.hidden = true;
 var tbtn = document.createElement("button");
 tbtn.className = "think-btn";
 tbtn.setAttribute("aria-expanded", "false");
 tbtn.innerHTML = "<span class=\"caret\">▸</span> reasoning";
 var tbody = document.createElement("div");
 tbody.className = "think-body";
 tbtn.addEventListener("click", function () {
  tbtn.setAttribute("aria-expanded", tbtn.getAttribute("aria-expanded") === "true" ? "false" : "true");
 });
 think.appendChild(tbtn);
 think.appendChild(tbody);
 wrap.appendChild(think);

 var tools = document.createElement("div");
 wrap.appendChild(tools);

 var reply = document.createElement("div");
 reply.className = "reply";
 reply.hidden = true;
 wrap.appendChild(reply);

 turn.appendChild(wrap);

 var text = "";

 return {
  reason: function (t) {
   think.hidden = false;
   tbody.textContent += t;
   tbody.scrollTop = tbody.scrollHeight;
   scr();
  },
  content: function (t) {
   text += t;
   reply.hidden = false;
   reply.dataset.streaming = "true";
   reply.innerHTML = MD.render(text);
   scr();
  },
  settle: function () { delete reply.dataset.streaming; },
  tool: function (d) {
   var box = document.createElement("div");
   box.className = "tool";
   box.dataset.id = d.id;
   box.dataset.state = "pending";

   var a = d.arguments || {};
   var head = a.path || a.old_path || "";
   var rest = [];
   Object.keys(a).forEach(function (k) {
    if (k === "path" || k === "old_path") return;
    var val = String(a[k]);
    if (val.length > 160) val = val.slice(0, 160) + "… (" + val.length + " chars)";
    rest.push(k + ": " + val);
   });

   box.innerHTML =
    "<div class=\"tool-head\">" +
    "<span class=\"tool-name\">" + MD.esc(d.name) + "</span>" +
    "<span class=\"tool-arg\">" + MD.esc(head) + "</span>" +
    "</div>" +
    (rest.length ? "<div class=\"tool-more\">" + MD.esc(rest.join("\n")) + "</div>" : "");

   tools.appendChild(box);
   scr();
  },
  result: function (d) {
   var box = tools.querySelector("[data-id=\"" + d.id + "\"]");
   if (!box) return;
   var isErr = /^error:/i.test(d.result || "");
   box.dataset.state = isErr ? "error" : "ok";
   var out = document.createElement("div");
   out.className = "tool-out";
   var txt = d.result || "";
   out.textContent = txt.length > 400 ? txt.slice(0, 400) + "…" : txt;
   box.appendChild(out);
   scr();
  },
  usage: function (d) {
   addUsage(d);
   var m = document.createElement("div");
   m.className = "meter";
   var inTok = d.prompt_tokens || 0, outTok = d.completion_tokens || 0;
   var reason = (d.completion_tokens_details || {}).reasoning_tokens || 0;
   var cached = d.prompt_cache_hit_tokens || 0;
    var s = inTok.toLocaleString("en-US") + " in";
   if (cached > 0) s += " (" + cached.toLocaleString("en-US") + " cached)";
   s += " → " + outTok.toLocaleString("en-US") + " out";
   if (reason > 0) s += " (" + reason.toLocaleString("en-US") + " reasoning)";
   m.textContent = s;
   wrap.appendChild(m);
  }
 };
}

function deleteTurn(el) {
 if (!confirm("Delete this exchange from history?")) return;
 var fd = new FormData();
 if (S.ext) fd.append("extpath", S.ext); else fd.append("project", S.p);
 fd.append("turn_id", el.dataset.id);
 api("?action=delete_turn", {method: "POST", body: fd}).then(function (r) {
  if (r.error) { alert(r.error); return; }
  el.dataset.state = "removing";
  setTimeout(function () {
   el.remove();
   if (!E("loginner").querySelector(".turn")) {
    showEmpty("Empty conversation", "History is saved per folder —<br>you can come back later.");
   }
  }, 200);
 });
}


function refreshTree() {
 if (!S.p && !S.ext) return;
 api("?action=files&" + workdirParams()).then(function (t) {
  var n = countFiles(t);
  E("fcount").textContent = n ? n : "";
  E("ft").innerHTML = t.length ? rtree(t, 0) : "<div class=\"tree-empty\">Empty directory. Ask the agent to create the first file.</div>";
 E("ft").querySelectorAll(".node-file").forEach(function (el) {
   el.addEventListener("click", function () { viewFile(el.dataset.p); });
  });
  E("ft").querySelectorAll(".node-delete").forEach(function (el) {
   el.addEventListener("click", function () { deleteFile(el.dataset.p); });
  });
 });
}

function countFiles(nodes) {
 var n = 0;
 nodes.forEach(function (x) { n += x.type === "dir" ? countFiles(x.children || []) : 1; });
 return n;
}

function rtree(nodes, depth) {
 var h = "", pad = depth * 12;
 nodes.forEach(function (n) {
  var name = n.path.split("/").pop();
  if (n.type === "dir") {
   h += "<div class=\"node node-dir\" style=\"padding-left:" + (8 + pad) + "px\">" + MD.esc(name) + "</div>" + rtree(n.children || [], depth + 1);
  } else {
   h += "<div class=\"node-row\" style=\"padding-left:" + (8 + pad) + "px\"><button class=\"node node-file\" data-p=\"" + MD.esc(n.path) + "\">" + MD.esc(name) + "</button><button class=\"node-delete\" data-p=\"" + MD.esc(n.path) + "\" title=\"Delete file\" aria-label=\"Delete " + MD.esc(name) + "\">×</button></div>";
  }
 });
 return h;
}

function viewFile(p) {
 api("?action=file&" + workdirParams() + "&path=" + encodeURIComponent(p)).then(function (r) {
  if (r.error) return;
  E("mp").textContent = p;
  E("mx").textContent = r.content;
  E("meditor").value = r.content;
  E("me").textContent = "edit";
  E("mx").hidden = false;
  E("meditor").hidden = true;
  E("mfoot").hidden = true;
  if (window.matchMedia("(max-width:760px)").matches) closeSide(true);
  openModal("mv");
 });
}

function deleteFile(path) {
 if (!confirm("Delete file \"" + path + "\"? This cannot be undone.")) return;
 var fd = new FormData();
 if (S.ext) fd.append("extpath", S.ext); else fd.append("project", S.p);
 fd.append("path", path);
 api("?action=delete_file", {method: "POST", body: fd}).then(function (r) {
  if (r.error) { alert(r.error); return; }
  refreshTree();
 });
}


function focusable(container) {
 return Array.prototype.slice.call(container.querySelectorAll("button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex=\"-1\"])")).filter(function (el) { return !el.hidden && el.offsetParent !== null; });
}
function syncOverlayState() {
 document.body.dataset.overlay = document.querySelector(".modal[data-open=\"true\"]") || E("side").dataset.open === "true" ? "true" : "false";
}
function openModal(id) {
 var modal = E(id);
 S.focusBeforeOverlay = document.activeElement;
 modal.dataset.open = "true";
 modal.setAttribute("aria-hidden", "false");
 syncOverlayState();
 requestAnimationFrame(function () { var items = focusable(modal); if (items.length) items[0].focus(); });
}
function closeModal(id) {
 var modal = E(id);
 if (modal.dataset.open !== "true") return;
 delete modal.dataset.open;
 modal.setAttribute("aria-hidden", "true");
 syncOverlayState();
 if (S.focusBeforeOverlay && document.contains(S.focusBeforeOverlay)) S.focusBeforeOverlay.focus();
 S.focusBeforeOverlay = null;
}

E("mc").addEventListener("click", function () { closeModal("mv"); });
E("me").addEventListener("click", function () {
 var editing = !E("meditor").hidden;
 E("mx").hidden = !editing;
 E("meditor").hidden = editing;
 E("mfoot").hidden = editing;
 E("me").textContent = editing ? "edit" : "cancel";
 if (!editing) E("meditor").focus();
});
E("msave").addEventListener("click", function () {
 var fd = new FormData();
 if (S.ext) fd.append("extpath", S.ext); else fd.append("project", S.p);
 fd.append("path", E("mp").textContent);
 fd.append("content", E("meditor").value);
 api("?action=update_file", {method: "POST", body: fd}).then(function (r) {
  if (r.error) { alert(r.error); return; }
  E("mx").textContent = E("meditor").value;
  E("me").click();
  refreshTree();
 });
});
E("mv").addEventListener("click", function (e) { if (e.target === E("mv")) closeModal("mv"); });
E("fbclose").addEventListener("click", function () { closeModal("fb"); });
E("fb").addEventListener("click", function (e) { if (e.target === E("fb")) closeModal("fb"); });
E("bo").addEventListener("click", function () { loadOptions(); openModal("om"); });
E("omclose").addEventListener("click", function () { closeModal("om"); });
E("om").addEventListener("click", function (e) { if (e.target === E("om")) closeModal("om"); });
E("omsave").addEventListener("click", function () {
 var options = readOptions();
 try { localStorage.setItem(optionKey(), JSON.stringify(options)); } catch (e) {}
 closeModal("om");
});
document.addEventListener("keydown", function (e) {
 if (e.key === "Escape") { closeModal("mv"); closeModal("fb"); closeModal("om"); closeSide(true); return; }
 if (e.key !== "Tab") return;
 var activeModal = document.querySelector(".modal[data-open=\"true\"]");
 var container = activeModal || (E("side").dataset.open === "true" && window.matchMedia("(max-width:760px)").matches ? E("side") : null);
 if (!container) return;
 var items = focusable(container);
 if (!items.length) return;
 var first = items[0], last = items[items.length - 1];
 if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
 else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});


function send() {
 var t = E("ti").value.trim();
 if ((!t && !S.images.length) || S.busy || (!S.p && !S.ext)) return;

 E("ti").value = "";
 E("ti").style.height = "auto";
 var images = S.images.slice();
 S.images = [];
 renderImageTray();
 if (images.length) S.hasImages = true;
 S.busy = true;
 E("bs").setAttribute("aria-busy", "true");
 E("bstop").hidden = false;

 var turn = mkTurn("pending", t, images);
 var v = turn.addRound();
 var rounds = 0;
 scr(true);

 var payload = {text: t, model: E("sm").value, thinking: E("st").value, options: readOptions()};
 if (images.length) payload.images = images.map(function (image) { return {data_url: image.data_url}; });
 forceVisionModel();
 payload.model = E("sm").value;
 if (S.ext) payload.extpath = S.ext; else payload.project = S.p;

 S.abort = new AbortController();
 fetch("?action=chat", {
  method: "POST",
  headers: {"Content-Type": "application/json", "X-CSRF-Token": PHPILOT_CSRF},
  body: JSON.stringify(payload), signal: S.abort.signal
 }).then(function (resp) {
  var ct = resp.headers.get("content-type") || "";
  if (!resp.ok || ct.indexOf("text/event-stream") !== 0) {
   return resp.text().then(function (t) {
    v.content("\n\n⚠ Error " + resp.status + (t ? " — " + t : ""));
    fin();
   });
  }
  var reader = resp.body.getReader(), dec = new TextDecoder(), buf = "";
  function pump() {
   return reader.read().then(function (r) {
    if (r.done) { fin(); return; }
    buf += dec.decode(r.value, {stream: true});
    var parts = buf.split("\n\n");
    buf = parts.pop();
    parts.forEach(proc);
    return pump();
   });
  }
  return pump();
 }).catch(function (e) { if (e.name !== "AbortError") { console.error(e); v.content("\n\n⚠ Connection error"); } fin(); });

 function proc(raw) {
  var ev = "", data = "";
  raw.split("\n").forEach(function (l) {
   if (l.indexOf("event: ") === 0) ev = l.slice(7);
   if (l.indexOf("data: ") === 0) data = l.slice(6);
  });
  if (!data) return;
  var d;
  try { d = JSON.parse(data); } catch (err) { return; }

  if (ev === "turn_start") { turn.el.dataset.id = d.turn_id; return; }
  if (ev === "round") { rounds++; if (rounds > 1) { v.settle(); v = turn.addRound(); } return; }
  if (ev === "context") { S.contextTokens = d.tokens_estimate || 0; renderUsageTotal(S.usage); return; }
  if (ev === "reasoning") { v.reason(d.t); return; }
  if (ev === "content") { v.content(d.t); return; }
  if (ev === "tool_call") { v.tool(d); return; }
  if (ev === "tool_result") { v.result(d); refreshTree(); return; }
  if (ev === "usage") { v.usage(d); return; }
  if (ev === "notice") { v.content("\n\n⚠ " + d.message); return; }
  if (ev === "context_limit") { v.content("\n\n⚠ Current context limit reached (estimated " + (d.tokens_estimate || 0).toLocaleString("en-US") + " / " + (d.limit_tokens || 0).toLocaleString("en-US") + " tokens). Start a new conversation or clear this history before continuing."); return; }
  if (ev === "error") { v.content("\n\n⚠ " + d.message); return; }
  if (ev === "done") { turn.el.dataset.id = d.turn_id; fin(); return; }
 }

function fin() {
  v.settle();
  S.busy = false;
  S.abort = null;
  E("bs").removeAttribute("aria-busy");
  E("bstop").hidden = true;
 refreshTree();
}
}

function forceVisionModel() {
 if ((S.images.length || S.hasImages) && S.visionModel) E("sm").value = S.visionModel;
}

function renderImageTray() {
 var tray = E("image-tray");
 tray.innerHTML = "";
 tray.hidden = S.images.length === 0;
 S.images.forEach(function (image, index) {
  var item = document.createElement("div");
  item.className = "image-chip";
  var preview = document.createElement("img");
  preview.src = image.data_url;
  preview.alt = image.name || "Attached image";
  var remove = document.createElement("button");
  remove.type = "button";
  remove.title = "Remove image";
  remove.textContent = "×";
  remove.addEventListener("click", function () { S.images.splice(index, 1); renderImageTray(); });
  item.appendChild(preview);
  item.appendChild(remove);
  tray.appendChild(item);
 });
}

function addImages(files) {
 var pending = Array.prototype.slice.call(files || []);
 if (!pending.length) return;
 if (S.images.length + pending.length > 2) { alert("A message can contain at most 2 images."); return; }
 var allowed = {"image/jpeg": true, "image/png": true, "image/gif": true, "image/webp": true};
 if (pending.some(function (file) { return !allowed[file.type]; })) { alert("Only JPEG, PNG, GIF, and WebP images are supported."); return; }
 Promise.all(pending.map(function (file) {
  return new Promise(function (resolve, reject) {
   var reader = new FileReader();
   reader.onload = function () { resolve({data_url: reader.result, name: file.name}); };
   reader.onerror = reject;
   reader.readAsDataURL(file);
  });
 })).then(function (images) {
  S.images = S.images.concat(images);
  renderImageTray();
  forceVisionModel();
 }).catch(function () { alert("Unable to read the selected image."); });
}


E("ti").addEventListener("input", function () {
 this.style.height = "auto";
 this.style.height = Math.min(this.scrollHeight, 180) + "px";
});

E("ti").addEventListener("keydown", function (e) {
 if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(); }
});

E("bs").addEventListener("click", send);
E("bstop").addEventListener("click", function () { if (S.abort) S.abort.abort(); });
E("bimage").addEventListener("click", function () { E("image-input").click(); });
E("image-input").addEventListener("change", function () { addImages(this.files); this.value = ""; });
E("ti").addEventListener("dragover", function (e) { e.preventDefault(); E("ti").dataset.dragging = "true"; });
E("ti").addEventListener("dragleave", function () { delete E("ti").dataset.dragging; });
E("ti").addEventListener("drop", function (e) { e.preventDefault(); delete E("ti").dataset.dragging; addImages(e.dataTransfer.files); });


E("bc").addEventListener("click", function () {
 var n = E("inp").value.trim();
 if (!n) { E("inp").focus(); return; }
 var fd = new FormData();
 fd.append("name", n);
 api("?action=create_project", {method: "POST", body: fd}).then(function (r) {
  if (r.error) { alert(r.error); return; }
  E("inp").value = "";
  loadProjects(r.name);
 });
});

E("inp").addEventListener("keydown", function (e) {
 if (e.key === "Enter") { e.preventDefault(); E("bc").click(); }
});

E("bd").addEventListener("click", function () {
 if (!S.p || S.ext) return;
 if (!confirm("Delete project \"" + S.p + "\" along with its files and history?")) return;
 var fd = new FormData();
 fd.append("name", S.p);
 api("?action=delete_project", {method: "POST", body: fd}).then(function () { loadProjects(); });
});

E("bk").addEventListener("click", function () {
 if (!S.p && !S.ext) return;
 var label = S.ext || S.p;
 if (!confirm("Clear the entire conversation history for \"" + label + "\"? Project files will not be affected.")) return;
 var fd = new FormData();
 if (S.ext) fd.append("extpath", S.ext); else fd.append("project", S.p);
 api("?action=clear_conversation", {method: "POST", body: fd}).then(function (r) {
  if (r.error) { alert(r.error); return; }
  loadConversation();
 });
});

E("sp").addEventListener("change", function () {
 var v = E("sp").value;
 var m = projectMeta[v];
 if (v.indexOf("ext:") === 0) setProj("", v.slice(4), m ? m.url : "");
 else setProj(v, "", m ? m.url : "");
});

function openSide() {
 S.focusBeforeOverlay = document.activeElement;
 E("side").dataset.open = "true";
 E("side").setAttribute("aria-hidden", "false");
 E("backdrop").dataset.open = "true";
 E("bt").setAttribute("aria-expanded", "true");
 syncOverlayState();
 requestAnimationFrame(function () { E("sideclose").focus(); });
}
function closeSide(restoreFocus) {
 if (E("side").dataset.open !== "true") return;
 delete E("side").dataset.open;
 E("side").setAttribute("aria-hidden", window.matchMedia("(max-width:760px)").matches ? "true" : "false");
 delete E("backdrop").dataset.open;
 E("bt").setAttribute("aria-expanded", "false");
 syncOverlayState();
 if (restoreFocus && S.focusBeforeOverlay && document.contains(S.focusBeforeOverlay)) S.focusBeforeOverlay.focus();
 S.focusBeforeOverlay = null;
}

E("bt").addEventListener("click", function () {
 if (E("side").dataset.open === "true") closeSide(); else openSide();
});
E("backdrop").addEventListener("click", closeSide);
E("sideclose").addEventListener("click", function () { closeSide(true); });
window.addEventListener("resize", function () {
 if (!window.matchMedia("(max-width:760px)").matches) closeSide(false);
 syncViewportHeight();
});

function syncViewportHeight() {
 if (window.visualViewport && window.matchMedia("(max-width:760px)").matches) document.documentElement.style.setProperty("--app-height", Math.round(window.visualViewport.height) + "px");
 else document.documentElement.style.removeProperty("--app-height");
}
if (window.visualViewport) window.visualViewport.addEventListener("resize", syncViewportHeight);
E("side").setAttribute("aria-hidden", window.matchMedia("(max-width:760px)").matches ? "true" : "false");
syncViewportHeight();


var fbPath = "";

E("bf").addEventListener("click", function () { fbLoad(""); openModal("fb"); });

function fbLoad(path) {
 fbPath = path;
 E("fbpath").textContent = path ? "public_html/" + path : "public_html";
 api("?action=browse_dirs&path=" + encodeURIComponent(path)).then(function (r) {
  if (r.error) return;
  var h = "";
  if (path !== "") h += "<button class=\"pick pick-up\" data-p=\"__up__\">↑ up</button>";
  r.dirs.forEach(function (d) {
   h += "<button class=\"pick\" data-p=\"" + MD.esc(d) + "\">" + MD.esc(d) + "/</button>";
  });
  if (!r.dirs.length && !path) h = "<div class=\"pick-none\">No subdirectories.</div>";
  E("fblist").innerHTML = h;
  E("fblist").querySelectorAll(".pick").forEach(function (el) {
   el.addEventListener("click", function () {
    var p = el.dataset.p;
    if (p === "__up__") { var pts = fbPath.split("/"); pts.pop(); fbLoad(pts.join("/")); }
    else fbLoad(fbPath ? fbPath + "/" + p : p);
   });
  });
  E("fbopen").disabled = !path;
 });
}

E("fbopen").addEventListener("click", function () {
 if (!fbPath) return;
 var fd = new FormData();
 fd.append("path", fbPath);
 api("?action=open_dir", {method: "POST", body: fd}).then(function (r) {
  if (r.error) { alert(r.error); return; }
  closeModal("fb");
  var key = "ext:" + r.rel;
  if (!projectMeta[key]) {
   var o = document.createElement("option");
   o.value = key;
   o.textContent = r.rel;
   E("sp").appendChild(o);
   projectMeta[key] = {name: key, url: r.url, type: "external"};
  }
  E("sp").value = key;
  setProj("", r.rel, r.url);
 });
});

function loadModels() {
 api("?action=models").then(function (r) {
  E("sm").innerHTML = "";
  (r.models || []).forEach(function (m) {
   var o = document.createElement("option");
   o.value = m.id;
   o.textContent = m.label;
   E("sm").appendChild(o);
   if (m.model === "deepseek-v4-flash-vision-exp") S.visionModel = m.id;
  });
  if (r.default) E("sm").value = r.default;
  forceVisionModel();
 });
}

loadModels();
loadProjects();

if ("serviceWorker" in navigator) {
 window.addEventListener("load", function () {
  navigator.serviceWorker.register("?action=sw").catch(function () {});
 });
}
