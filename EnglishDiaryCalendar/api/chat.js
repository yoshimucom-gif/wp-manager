const OPENAI_MODEL = process.env.OPENAI_MODEL || "gpt-5.2";

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

  const question = String(req.body?.question || "").trim();
  const context = req.body?.context || {};

  if (!question) {
    res.status(400).json({ error: "質問を入力してください。" });
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
