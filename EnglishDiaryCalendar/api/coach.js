const OPENAI_MODEL = process.env.OPENAI_MODEL || "gpt-5.2";

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

export default async function handler(req, res) {
  if (req.method !== "POST") {
    res.setHeader("Allow", "POST");
    res.status(405).json({ error: "Method not allowed." });
    return;
  }

  if (!process.env.OPENAI_API_KEY) {
    res.status(500).json({ error: "OPENAI_API_KEY is not set." });
    return;
  }

  const japanese = String(req.body?.japanese || "").trim();
  const userEnglish = String(req.body?.userEnglish || "").trim();
  const targetLevel = String(req.body?.targetLevel || "toeic600");

  if (!japanese) {
    res.status(400).json({ error: "日本語の日記を入力してください。" });
    return;
  }

  try {
    const response = await fetch("https://api.openai.com/v1/responses", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${process.env.OPENAI_API_KEY}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
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
      })
    });

    if (!response.ok) {
      const detail = await response.text();
      res.status(response.status).json({ error: `OpenAI API error: ${detail}` });
      return;
    }

    const data = await response.json();
    const outputText = extractOutputText(data);
    if (!outputText) {
      res.status(500).json({ error: "OpenAI API returned no output_text." });
      return;
    }

    res.status(200).json(JSON.parse(outputText));
  } catch (error) {
    res.status(500).json({ error: error.message || "Server error." });
  }
}
