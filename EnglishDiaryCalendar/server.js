import { createServer } from "node:http";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { existsSync } from "node:fs";
import { extname, join, normalize } from "node:path";

const PORT = Number(process.env.PORT || 3000);
const OPENAI_API_KEY = process.env.OPENAI_API_KEY;
const OPENAI_MODEL = process.env.OPENAI_MODEL || "gpt-5.2";
const ROOT = process.cwd();
const PUBLIC_DIR = join(ROOT, "public");
const DATA_DIR = join(ROOT, "data");
const DATA_FILE = join(DATA_DIR, "entries.json");

const mimeTypes = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".svg": "image/svg+xml"
};

const coachSchema = {
  type: "object",
  additionalProperties: false,
  properties: {
    correctedEnglish: { type: "string" },
    translation: { type: "string" },
    correctionNotes: {
      type: "array",
      items: { type: "string" }
    },
    notes: {
      type: "array",
      items: { type: "string" }
    },
    vocabulary: {
      type: "array",
      items: {
        type: "object",
        additionalProperties: false,
        properties: {
          term: { type: "string" },
          meaning: { type: "string" },
          example: { type: "string" }
        },
        required: ["term", "meaning", "example"]
      }
    },
    shadowingLines: {
      type: "array",
      items: { type: "string" }
    },
    alternatives: {
      type: "array",
      items: {
        type: "object",
        additionalProperties: false,
        properties: {
          label: { type: "string" },
          text: { type: "string" }
        },
        required: ["label", "text"]
      }
    },
    mistakeTags: {
      type: "array",
      items: { type: "string" }
    }
  },
  required: [
    "correctedEnglish",
    "translation",
    "correctionNotes",
    "notes",
    "vocabulary",
    "shadowingLines",
    "alternatives",
    "mistakeTags"
  ]
};

async function ensureDataFile() {
  await mkdir(DATA_DIR, { recursive: true });
  if (!existsSync(DATA_FILE)) {
    await writeFile(DATA_FILE, "{}", "utf8");
  }
}

async function readEntries() {
  await ensureDataFile();
  const raw = await readFile(DATA_FILE, "utf8");
  return JSON.parse(raw || "{}");
}

async function writeEntries(entries) {
  await ensureDataFile();
  await writeFile(DATA_FILE, JSON.stringify(entries, null, 2), "utf8");
}

function sendJson(res, status, value) {
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8" });
  res.end(JSON.stringify(value));
}

function extractOutputText(data) {
  if (data.output_text) return data.output_text;

  for (const item of data.output || []) {
    for (const content of item.content || []) {
      if (typeof content.text === "string") {
        return content.text;
      }
    }
  }

  return "";
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) {
    chunks.push(chunk);
  }
  const raw = Buffer.concat(chunks).toString("utf8");
  return raw ? JSON.parse(raw) : {};
}

function isDateKey(value) {
  return typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

function normalizeEntry(input) {
  return {
    date: input.date,
    japanese: String(input.japanese || "").trim(),
    userEnglish: String(input.userEnglish || "").trim(),
    targetLevel: String(input.targetLevel || "toeic600").trim(),
    correctedEnglish: String(input.correctedEnglish || "").trim(),
    translation: String(input.translation || input.level600 || input.english || "").trim(),
    topic: String(input.topic || "").trim(),
    correctionNotes: Array.isArray(input.correctionNotes)
      ? input.correctionNotes.map(String).slice(0, 8)
      : [],
    notes: Array.isArray(input.notes) ? input.notes.map(String).slice(0, 8) : [],
    vocabulary: Array.isArray(input.vocabulary)
      ? input.vocabulary
          .map((item) => ({
            term: String(item.term || "").trim(),
            meaning: String(item.meaning || "").trim(),
            example: String(item.example || "").trim()
          }))
          .filter((item) => item.term && item.meaning)
          .slice(0, 8)
      : [],
    shadowingLines: Array.isArray(input.shadowingLines)
      ? input.shadowingLines.map(String).slice(0, 6)
      : [],
    alternatives: Array.isArray(input.alternatives)
      ? input.alternatives
          .map((item) => ({
            label: String(item.label || "").trim(),
            text: String(item.text || "").trim()
          }))
          .filter((item) => item.label && item.text)
          .slice(0, 5)
      : [],
    mistakeTags: Array.isArray(input.mistakeTags) ? input.mistakeTags.map(String).slice(0, 8) : [],
    updatedAt: new Date().toISOString()
  };
}

async function callOpenAI(payload) {
  if (!OPENAI_API_KEY) {
    throw new Error("OPENAI_API_KEY is not set.");
  }

  const response = await fetch("https://api.openai.com/v1/responses", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${OPENAI_API_KEY}`,
      "Content-Type": "application/json"
    },
    body: JSON.stringify(payload)
  });

  if (!response.ok) {
    const detail = await response.text();
    throw new Error(`OpenAI API error ${response.status}: ${detail}`);
  }

  const data = await response.json();
  const outputText = extractOutputText(data);
  if (!outputText) {
    throw new Error("OpenAI API returned no output_text.");
  }
  return JSON.parse(outputText);
}

async function coachJapaneseDiary(japanese, userEnglish, targetLevel) {
  return callOpenAI({
    model: OPENAI_MODEL,
    input: [
      {
        role: "system",
        content:
          "You are an English diary coach for a Japanese learner. Return concise JSON only. Be encouraging, practical, and focused on daily study."
      },
      {
        role: "user",
        content: `Japanese diary:
${japanese}

Learner's English attempt:
${userEnglish || "(none)"}

Target level: ${targetLevel}

Important:
- Interpret targetLevel as:
  - toeic400: simple sentences and basic vocabulary suitable for TOEIC around 400.
  - toeic600: practical everyday English suitable for TOEIC around 600.
  - toeic800: more precise and expressive English suitable for TOEIC around 800.
  - natural: natural diary English without forcing TOEIC vocabulary.
- correctedEnglish, translation, shadowingLines, alternatives.text, and vocabulary.example must be English.
- correctionNotes, notes, and vocabulary.meaning should be Japanese.
- Do not translate English back into Japanese.

Return:
- correctedEnglish: a polished correction of the learner's attempt. If no attempt, provide a short natural English version.
- translation: one main English translation for the target level only.
- correctionNotes: 3-5 Japanese notes about the learner's mistakes or good choices.
- notes: 3-5 Japanese study notes about phrasing, grammar, and nuance.
- vocabulary: 3-6 useful expressions with Japanese meanings and short English examples.
- shadowingLines: split the best English into 2-4 short lines for speaking practice.
- alternatives: 3 items labeled Easy, Natural, TOEIC with one sentence each.
- mistakeTags: 1-5 short Japanese or English tags such as tense, articles, prepositions, word order, subject, naturalness.`
      }
    ],
    text: {
      format: {
        type: "json_schema",
        name: "diary_coach",
        schema: coachSchema
      }
    }
  });
}

async function suggestDiaryTopic() {
  return callOpenAI({
    model: OPENAI_MODEL,
    input: [
      {
        role: "system",
        content:
          "You suggest short diary prompts for a Japanese learner of English. Return JSON only."
      },
      {
        role: "user",
        content:
          "今日の英語日記のお題を1つください。日常的で書きやすく、1-2文で答えられるものにしてください。日本語で返してください。"
      }
    ],
    text: {
      format: {
        type: "json_schema",
        name: "diary_topic",
        schema: {
          type: "object",
          additionalProperties: false,
          properties: {
            topic: { type: "string" }
          },
          required: ["topic"]
        }
      }
    }
  });
}

async function answerCoachQuestion(question, context) {
  return callOpenAI({
    model: OPENAI_MODEL,
    input: [
      {
        role: "system",
        content:
          "You are an English diary coach. Answer the learner's question using the provided diary context. Reply in Japanese, but keep English examples in English. Be concise and practical."
      },
      {
        role: "user",
        content: `Question:
${question}

Context:
${JSON.stringify(context, null, 2)}

Answer in 2-5 short Japanese paragraphs or bullets. Explain why, not just what.`
      }
    ],
    text: {
      format: {
        type: "json_schema",
        name: "coach_chat_answer",
        schema: {
          type: "object",
          additionalProperties: false,
          properties: {
            answer: { type: "string" }
          },
          required: ["answer"]
        }
      }
    }
  });
}

async function handleApi(req, res, pathname) {
  if (req.method === "GET" && pathname === "/api/entries") {
    sendJson(res, 200, await readEntries());
    return;
  }

  if (req.method === "PUT" && pathname.startsWith("/api/entries/")) {
    const date = decodeURIComponent(pathname.replace("/api/entries/", ""));
    if (!isDateKey(date)) {
      sendJson(res, 400, { error: "Invalid date." });
      return;
    }
    const body = await readBody(req);
    const entries = await readEntries();
    entries[date] = normalizeEntry({ ...body, date });
    await writeEntries(entries);
    sendJson(res, 200, entries[date]);
    return;
  }

  if (req.method === "DELETE" && pathname.startsWith("/api/entries/")) {
    const date = decodeURIComponent(pathname.replace("/api/entries/", ""));
    const entries = await readEntries();
    delete entries[date];
    await writeEntries(entries);
    sendJson(res, 200, { ok: true });
    return;
  }

  if (req.method === "POST" && pathname === "/api/coach") {
    const body = await readBody(req);
    const japanese = String(body.japanese || "").trim();
    const userEnglish = String(body.userEnglish || "").trim();
    const targetLevel = String(body.targetLevel || "toeic600");
    if (!japanese) {
      sendJson(res, 400, { error: "日本語の日記を入力してください。" });
      return;
    }
    sendJson(res, 200, await coachJapaneseDiary(japanese, userEnglish, targetLevel));
    return;
  }

  if (req.method === "POST" && pathname === "/api/topic") {
    sendJson(res, 200, await suggestDiaryTopic());
    return;
  }

  if (req.method === "POST" && pathname === "/api/chat") {
    const body = await readBody(req);
    const question = String(body.question || "").trim();
    if (!question) {
      sendJson(res, 400, { error: "質問を入力してください。" });
      return;
    }
    sendJson(res, 200, await answerCoachQuestion(question, body.context || {}));
    return;
  }

  sendJson(res, 404, { error: "Not found." });
}

async function serveStatic(req, res, pathname) {
  const requested = pathname === "/" ? "/index.html" : pathname;
  const safePath = normalize(decodeURIComponent(requested)).replace(/^(\.\.[/\\])+/, "");
  const filePath = join(PUBLIC_DIR, safePath);

  if (!filePath.startsWith(PUBLIC_DIR)) {
    res.writeHead(403);
    res.end("Forbidden");
    return;
  }

  try {
    const content = await readFile(filePath);
    res.writeHead(200, {
      "Content-Type": mimeTypes[extname(filePath)] || "application/octet-stream"
    });
    res.end(content);
  } catch {
    res.writeHead(404, { "Content-Type": "text/plain; charset=utf-8" });
    res.end("Not found");
  }
}

createServer(async (req, res) => {
  try {
    const url = new URL(req.url || "/", `http://${req.headers.host}`);
    if (url.pathname.startsWith("/api/")) {
      await handleApi(req, res, url.pathname);
      return;
    }
    await serveStatic(req, res, url.pathname);
  } catch (error) {
    sendJson(res, 500, { error: error.message || "Server error." });
  }
}).listen(PORT, () => {
  console.log(`English diary calendar: http://localhost:${PORT}`);
});
